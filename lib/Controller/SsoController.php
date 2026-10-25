<?php

declare(strict_types=1);

namespace OCA\Wisso\Controller;

use OC\Authentication\TwoFactorAuth\Manager as TwoFactorManager;
use OC\Security\CSRF\CsrfTokenManager;
use OC\User\Session as UserSession;
use OCA\Wisso\Service\AccountPolicy;
use OCA\Wisso\Service\CodeStore;
use OCA\Wisso\Service\Input;
use OCA\Wisso\Service\LinkStore;
use OCA\Wisso\Service\Pages;
use OCA\Wisso\Service\RedirectValidator;
use OCA\Wisso\Service\SsoLog;
use OCA\Wisso\Service\Throttle;
use OCA\Wisso\Service\Urls;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Authentication\Token\IToken;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/** The browser steps: consume (signed in directly) and claim (an existing account is linked by its owner). */
class SsoController extends Controller {
	/** A claim needs a password sign-in this recent (the session's last-password-confirm). */
	private const FRESH_PASSWORD_SECONDS = 1800;
	private const SESSION_CLAIM_TOKEN = 'wisso_claim_token';

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private ISession $session,
		private IUserManager $users,
		private IDBConnection $db,
		private ITimeFactory $time,
		private ContainerInterface $container,
		private CodeStore $codes,
		private LinkStore $links,
		private AccountPolicy $policy,
		private RedirectValidator $redirects,
		private Urls $urls,
		private Pages $pages,
		private Throttle $throttle,
		private SsoLog $log,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[UseSession]
	public function consume(): Response {
		try {
			if ($this->isNotARealVisit()) {
				return $this->noContent();
			}
			if (!$this->throttle->browser('wisso_consume', 30, $this->request->getRemoteAddress())) {
				return $this->fail('rate');
			}
			$code = $this->param('code');
			$row = $this->codes->peek($code, CodeStore::TYPE_LOGIN);
			if ($row === null) {
				return $this->fail('code');
			}
			$current = $this->userSession->getUser();
			if ($current !== null && $current->getUID() !== $row['uid']) {
				// switching silently would be a login CSRF: leave the code unused and say so
				return $this->pages->otherUser($this->logoutUrl());
			}
			$consumed = $this->codes->consume($code, CodeStore::TYPE_LOGIN);
			if ($consumed === null) {
				return $this->fail('code');
			}

			// the account may have changed since the code was issued: decide again
			$user = $this->users->get($consumed['uid']);
			if ($user === null) {
				return $this->fail('no_user');
			}
			if ($this->links->hashForUid($consumed['uid']) !== $consumed['email_sha256']) {
				return $this->fail('not_linked', $consumed['uid']);
			}
			$refusal = $this->policy->refusal($user);
			if ($refusal !== null) {
				return $this->fail($refusal, $consumed['uid']);
			}

			if ($current === null) {
				$this->signIn($user);
			}
			$this->log->event('consume_ok', null, $consumed['uid']);
			return $this->redirect($this->urls->destination($this->redirects->normalize($this->param('to'))));
		} catch (\Throwable $e) {
			return $this->fail('exception', null, ['class' => str_replace('\\', '.', $e::class)]);
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[UseSession]
	public function claim(): Response {
		try {
			if ($this->isNotARealVisit()) {
				return $this->noContent();
			}
			if (!$this->throttle->browser('wisso_claim', 30, $this->request->getRemoteAddress())) {
				return $this->pages->tooMany();
			}
			$row = $this->codes->peek($this->param('code'), CodeStore::TYPE_CLAIM);
			if ($row === null) {
				$this->log->event('claim_denied', 'claim_expired');
				return $this->pages->claimExpired();
			}
			$current = $this->userSession->getUser();
			$blocked = $this->claimGate($row, $current);
			if ($blocked !== null) {
				return $blocked;
			}
			$to = $this->redirects->normalize($this->param('to'));
			if ($this->links->hashForUid($row['uid']) === $row['email_sha256']) {
				// already linked (a second tab, a repeated click): nothing to confirm
				return $this->redirect($this->urls->destination($to));
			}

			$claimToken = bin2hex(random_bytes(20));
			$this->session->set(self::SESSION_CLAIM_TOKEN, $claimToken);
			$csrf = $this->container->get(CsrfTokenManager::class)->getToken()->getEncryptedValue();
			return $this->pages->claimConfirm(
				$current->getDisplayName(),
				(string)$current->getSystemEMailAddress(),
				$this->param('code'),
				$to,
				$claimToken,
				$csrf,
				$this->urls->destination($this->redirects->defaultPath()),
			);
		} catch (\Throwable $e) {
			$this->log->event('claim_denied', 'exception', null, ['class' => str_replace('\\', '.', $e::class)]);
			return $this->pages->claimDenied();
		}
	}

	/** A form POST: Nextcloud's own request-token and strict-cookie checks run first (no NoCSRFRequired). */
	#[PublicPage]
	#[UseSession]
	public function claimConfirm(): Response {
		try {
			$current = $this->userSession->getUser();
			$ip = $this->request->getRemoteAddress();
			if (!$this->throttle->browser('wisso_claim_post', 30, $ip)
				|| ($current !== null && !$this->throttle->perUser('wisso_claim_user', 5, $current->getUID()))) {
				return $this->pages->tooMany();
			}
			$code = $this->param('code');
			$row = $this->codes->peek($code, CodeStore::TYPE_CLAIM);
			if ($row === null) {
				$this->log->event('claim_denied', 'claim_expired');
				return $this->pages->claimExpired();
			}
			$blocked = $this->claimGate($row, $current);
			if ($blocked !== null) {
				return $blocked;
			}

			$expected = $this->session->get(self::SESSION_CLAIM_TOKEN);
			$this->session->remove(self::SESSION_CLAIM_TOKEN);
			$given = $this->param('claim_token');
			if (!is_string($expected) || $expected === '' || !hash_equals($expected, $given)) {
				$this->log->event('claim_denied', 'claim_bad_token', $current->getUID());
				return $this->pages->claimDenied();
			}

			$to = $this->redirects->normalize($this->param('to'));
			if (!$this->link($code, $current)) {
				return $this->pages->claimDenied();
			}
			$this->log->event('claim_linked', null, $current->getUID());
			return $this->redirect($this->urls->destination($to));
		} catch (\Throwable $e) {
			$this->log->event('claim_denied', 'exception', null, ['class' => str_replace('\\', '.', $e::class)]);
			return $this->pages->claimDenied();
		}
	}

	/**
	 * Everything that must hold for the page shown to, or the link made by, the signed-in owner of the code.
	 * @param array{uid: string, email_sha256: string} $row
	 */
	private function claimGate(array $row, ?IUser $current): ?Response {
		if ($current === null || $this->secondFactorPending($current)) {
			$this->log->event('claim_denied', 'claim_no_session');
			return $this->pages->claimSignIn($this->urls->loginUrl());
		}
		if ($current->getUID() !== $row['uid']) {
			$this->log->event('claim_denied', 'claim_wrong_user');
			return $this->pages->otherUser($this->logoutUrl());
		}
		$confirmed = $this->session->get('last-password-confirm');
		if (!is_int($confirmed) || $this->time->getTime() - $confirmed > self::FRESH_PASSWORD_SECONDS) {
			$this->log->event('claim_denied', 'claim_stale_login', $current->getUID());
			return $this->pages->claimStale($this->logoutUrl());
		}
		$refusal = $this->policy->refusal($current);
		if ($refusal !== null) {
			$this->log->event('claim_denied', $refusal, $current->getUID());
			return $this->pages->claimDenied();
		}
		$stored = Input::storedEmail($current->getSystemEMailAddress());
		if ($stored === null || Input::emailHash($stored) !== $row['email_sha256']) {
			$this->log->event('claim_denied', 'email_changed', $current->getUID());
			return $this->pages->claimDenied();
		}
		$linkedHash = $this->links->hashForUid($current->getUID());
		if ($linkedHash !== null && $linkedHash !== $row['email_sha256']) {
			$this->log->event('claim_denied', 'linked_elsewhere', $current->getUID());
			return $this->pages->claimDenied();
		}
		return null;
	}

	/** Consumes the code and writes the link in one transaction; false leaves nothing linked. */
	private function link(string $code, IUser $current): bool {
		$this->db->beginTransaction();
		try {
			$consumed = $this->codes->consume($code, CodeStore::TYPE_CLAIM);
			// the id the consume returns, not an earlier peek, decides whose code this was
			if ($consumed === null || $consumed['uid'] !== $current->getUID()) {
				$this->db->rollBack();
				$this->log->event('claim_denied', 'claim_code_lost', $current->getUID());
				return false;
			}
			$linked = $this->links->insert($current->getUID(), $consumed['email_sha256']);
			if (!$linked) {
				$this->db->rollBack();
				$this->log->event('claim_denied', 'email_linked', $current->getUID());
				return false;
			}
			$this->db->commit();
			return true;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	private function signIn(IUser $user): void {
		if (!$this->userSession instanceof UserSession) {
			throw new \RuntimeException('unexpected user session class');
		}
		$uid = $user->getUID();
		// completeLogin regenerates the session id; no password is known, so none is passed on
		$this->userSession->completeLogin($user, ['loginName' => $uid, 'password' => ''], true);
		$this->userSession->createSessionToken($this->request, $uid, $uid, null, IToken::DO_NOT_REMEMBER);
		$this->userSession->updateTokens($uid, '');
	}

	private function secondFactorPending(IUser $user): bool {
		return $this->container->get(TwoFactorManager::class)->needsSecondFactor($user);
	}

	/** HEAD and browser prefetch / preview must neither read nor use a code. */
	private function isNotARealVisit(): bool {
		if ($this->request->getMethod() === 'HEAD') {
			return true;
		}
		foreach (['Sec-Purpose', 'Purpose', 'X-Purpose', 'X-Moz'] as $header) {
			$value = strtolower($this->request->getHeader($header));
			if ($value !== '' && preg_match('/prefetch|prerender|preview/', $value) === 1) {
				return true;
			}
		}
		return false;
	}

	private function param(string $name): string {
		$v = $this->request->getParam($name);
		return is_string($v) ? $v : '';
	}

	private function logoutUrl(): string {
		$token = $this->container->get(CsrfTokenManager::class)->getToken()->getEncryptedValue();
		return $this->urls->base() . '/index.php/logout?requesttoken=' . rawurlencode($token);
	}

	private function fail(string $reason, ?string $uid = null, array $extra = []): Response {
		$this->log->event('consume_fail', $reason, $uid, $extra);
		try {
			$location = $this->urls->loginUrl();
		} catch (\Throwable) {
			return $this->noContent(Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return $this->redirect($location);
	}

	private function redirect(string $location): RedirectResponse {
		$response = new RedirectResponse($location);
		$response->addHeader('Cache-Control', 'no-store');
		return $response;
	}

	private function noContent(int $status = Http::STATUS_NO_CONTENT): Response {
		$response = new Response();
		$response->setStatus($status);
		$response->addHeader('Cache-Control', 'no-store');
		return $response;
	}
}
