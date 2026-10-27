<?php

declare(strict_types=1);

namespace OCA\Wisso\Controller;

use OCA\Wisso\Service\AccountService;
use OCA\Wisso\Service\CodeStore;
use OCA\Wisso\Service\Input;
use OCA\Wisso\Service\KeyChecker;
use OCA\Wisso\Service\RedirectValidator;
use OCA\Wisso\Service\SsoLog;
use OCA\Wisso\Service\SsoRefusal;
use OCA\Wisso\Service\Throttle;
use OCA\Wisso\Service\Urls;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;

/**
 * The server-to-server calls wi makes. Every failure is `{"errors":[{"title":"<generic text>"}]}` and nothing else,
 * because wi reads errors[0]->title on any status >= 400 and throws on another body shape.
 */
class ApiController extends Controller {
	private const TITLES = [
		401 => 'Unauthorized.',
		403 => 'Forbidden.',
		404 => 'Not found.',
		405 => 'Method not allowed.',
		422 => 'Invalid input.',
		429 => 'Too many requests.',
		500 => 'Server error.',
	];
	private const BAD_KEY_LOG_PER_MINUTE = 20;

	private ?ICache $cache = null;

	public function __construct(
		string $appName,
		IRequest $request,
		private KeyChecker $key,
		private Throttle $throttle,
		private AccountService $accounts,
		private CodeStore $codes,
		private RedirectValidator $redirects,
		private Urls $urls,
		private SsoLog $log,
		ICacheFactory $cacheFactory,
	) {
		parent::__construct($appName, $request);
		if ($cacheFactory->isAvailable()) {
			$this->cache = $cacheFactory->createDistributed('wisso');
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function login(): JSONResponse {
		try {
			$this->requireKey();
			$email = Input::canonicalEmail($this->request->getParam('email'));
			$name = Input::cleanName($this->request->getParam('name'));
			if ($email === null || $name === null) {
				throw new SsoRefusal('bad_input', 422);
			}
			$this->throttle->apiCall(Input::emailHash($email));
			$to = $this->redirects->normalize($this->request->getParam('redirect'));

			$result = $this->accounts->resolve($email, $name);
			$code = $this->codes->issue($result['type'], $result['uid'], $result['hash']);
			$route = $result['type'] === CodeStore::TYPE_CLAIM ? 'sso/claim' : 'sso/consume';
			$url = $this->urls->appUrl($route) . '?code=' . $code . '&to=' . rawurlencode($to);

			$this->log->event($result['type'] === CodeStore::TYPE_CLAIM ? 'claim_offered' : 'login_ok', null, $result['uid']);
			return $this->json(['data' => ['url' => $url]], 200);
		} catch (SsoRefusal $r) {
			return $this->refuse($r, 'login_denied');
		} catch (\Throwable $e) {
			return $this->failed($e, 'login_denied');
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function update(): JSONResponse {
		try {
			$this->requireKey();
			$email = Input::canonicalEmail($this->request->getParam('email'));
			$name = Input::cleanName($this->request->getParam('name'));
			$rawNew = $this->request->getParam('new_email');
			$newEmail = $rawNew === null || $rawNew === '' ? null : Input::canonicalEmail($rawNew);
			if ($email === null || $name === null || ($rawNew !== null && $rawNew !== '' && $newEmail === null)) {
				throw new SsoRefusal('bad_input', 422);
			}
			$this->throttle->apiCall(Input::emailHash($email));

			$this->accounts->update($email, $name, $newEmail);
			$this->log->event('update_ok', null, null, ['name' => $name !== '' ? 'y' : 'n', 'email' => $newEmail !== null ? 'y' : 'n']);
			return $this->json(['data' => new \stdClass()], 200);
		} catch (SsoRefusal $r) {
			return $this->refuse($r, 'update_denied');
		} catch (\Throwable $e) {
			return $this->failed($e, 'update_denied');
		}
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function fallbackGet(string $path = ''): JSONResponse {
		return $this->unknownRoute($path);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function fallbackPost(string $path = ''): JSONResponse {
		return $this->unknownRoute($path);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function fallbackPut(string $path = ''): JSONResponse {
		return $this->unknownRoute($path);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function fallbackDelete(string $path = ''): JSONResponse {
		return $this->unknownRoute($path);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function fallbackPatch(string $path = ''): JSONResponse {
		return $this->unknownRoute($path);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function fallbackOptions(string $path = ''): JSONResponse {
		return $this->unknownRoute($path);
	}

	private function unknownRoute(string $path): JSONResponse {
		$known = in_array($path, ['sso/login', 'sso/update'], true);
		return $this->json($this->errorBody($known ? 405 : 404), $known ? 405 : 404);
	}

	/** @throws SsoRefusal 401 for no header, no configured key, or a wrong key (never rate limited, never a bucket) */
	private function requireKey(): void {
		$token = KeyChecker::bearer($this->request->getHeader('Authorization'));
		if ($token === null) {
			$this->badKeyLog('no_bearer');
			throw new SsoRefusal('no_bearer', 401);
		}
		if (!$this->key->isConfigured()) {
			$this->badKeyLog('no_key_config');
			throw new SsoRefusal('no_key_config', 401);
		}
		if (!$this->key->matches($token)) {
			$this->badKeyLog('bad_key');
			throw new SsoRefusal('bad_key', 401);
		}
	}

	/** At most 20 bad-key lines a minute, then one summary line, so a flood cannot fill the log. */
	private function badKeyLog(string $reason): void {
		if ($this->cache === null) {
			$this->log->event('login_denied', $reason);
			return;
		}
		$bucket = 'badkey_' . intdiv(time(), 60);
		$n = (int)($this->cache->get($bucket) ?? 0) + 1;
		$this->cache->set($bucket, $n, 120);
		if ($n <= self::BAD_KEY_LOG_PER_MINUTE) {
			$this->log->event('login_denied', $reason);
		} elseif ($n === self::BAD_KEY_LOG_PER_MINUTE + 1) {
			$this->log->event('bad_key_summary', 'suppressing_further_lines');
		}
	}

	private function refuse(SsoRefusal $r, string $event): JSONResponse {
		if ($r->status !== 401) {
			$this->log->event($event, $r->reason);
		}
		$headers = $r->status === 429 ? ['Retry-After' => '60'] : [];
		return $this->json($this->errorBody($r->status), $r->status, $headers);
	}

	/** Only the exception class is logged: database exception messages can contain e-mail addresses. */
	private function failed(\Throwable $e, string $event): JSONResponse {
		$this->log->event($event, 'exception', null, ['class' => str_replace('\\', '.', $e::class)]);
		return $this->json($this->errorBody(500), 500);
	}

	private function errorBody(int $status): array {
		return ['errors' => [['title' => self::TITLES[$status] ?? self::TITLES[500]]]];
	}

	private function json(array $body, int $status, array $headers = []): JSONResponse {
		$response = new JSONResponse($body, $status);
		$response->addHeader('Cache-Control', 'no-store');
		foreach ($headers as $k => $v) {
			$response->addHeader($k, $v);
		}
		return $response;
	}
}
