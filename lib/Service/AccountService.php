<?php

declare(strict_types=1);

namespace OCA\Wisso\Service;

use OCP\Accounts\IAccountManager;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;

/**
 * The account rules of the login and update calls (spec TOPIC-NC-001 sections 3, 8; owner decision D-NC-002).
 * The link table is keyed on the e-mail wi sends. The Nextcloud profile e-mail (which users can edit) is only
 * used to notice an existing account, never to sign anyone in.
 */
class AccountService {
	public const DEFAULT_QUOTA = '1 GB';

	public function __construct(
		private IUserManager $users,
		private IAppConfig $appConfig,
		private IAccountManager $accounts,
		private ISecureRandom $random,
		private LinkStore $links,
		private AccountPolicy $policy,
		private SsoLog $log,
	) {
	}

	/**
	 * Decides what the login call answers for a wi e-mail.
	 * @return array{type: string, uid: string, hash: string} type is 'login' (direct) or 'claim' (existing account)
	 * @throws SsoRefusal
	 */
	public function resolve(string $email, string $name, bool $retry = true): array {
		$hash = Input::emailHash($email);

		$uid = $this->links->uidForEmailHash($hash);
		if ($uid !== null) {
			$user = $this->users->get($uid);
			if ($user === null) {
				// the account is gone and the listener missed it: the stale link must not survive
				$this->links->deleteForUid($uid);
			} else {
				$this->refuseIfIneligible($user);
				return ['type' => CodeStore::TYPE_LOGIN, 'uid' => $uid, 'hash' => $hash];
			}
		}

		$holders = $this->profileHolders($email);
		if (count($holders) > 1) {
			throw new SsoRefusal('email_ambiguous');
		}
		if (count($holders) === 1) {
			$holder = $holders[0];
			if ($this->links->hashForUid($holder->getUID()) !== null) {
				// that account already belongs to another wi e-mail: its profile e-mail was edited
				throw new SsoRefusal('email_conflict');
			}
			$this->refuseIfIneligible($holder);
			return ['type' => CodeStore::TYPE_CLAIM, 'uid' => $holder->getUID(), 'hash' => $hash];
		}

		$this->assertRegistrationRules($email);
		$user = $this->createUser($email, $name);
		if (!$this->links->insert($user->getUID(), $hash)) {
			// a concurrent first login won the link: drop ours and answer from theirs
			$user->delete();
			if (!$retry) {
				throw new SsoRefusal('email_conflict');
			}
			return $this->resolve($email, $name, false);
		}
		$this->log->event('user_created', null, $user->getUID());
		return ['type' => CodeStore::TYPE_LOGIN, 'uid' => $user->getUID(), 'hash' => $hash];
	}

	/**
	 * `update`: only linked accounts, never creates one.
	 * @throws SsoRefusal
	 */
	public function update(string $email, string $name, ?string $newEmail): void {
		$hash = Input::emailHash($email);
		$uid = $this->links->uidForEmailHash($hash);
		$user = $uid === null ? null : $this->users->get($uid);
		if ($user === null) {
			throw new SsoRefusal('not_linked', 404);
		}
		$this->refuseIfIneligible($user);

		if ($newEmail !== null) {
			$newHash = Input::emailHash($newEmail);
			if ($newHash !== $hash) {
				$taken = $this->links->uidForEmailHash($newHash);
				if ($taken !== null && $taken !== $uid) {
					throw new SsoRefusal('email_conflict', 422);
				}
				foreach ($this->profileHolders($newEmail) as $holder) {
					if ($holder->getUID() !== $uid) {
						throw new SsoRefusal('email_conflict', 422);
					}
				}
				if (!$this->links->rekey($uid, $newHash)) {
					throw new SsoRefusal('email_conflict', 422);
				}
				// the profile e-mail follows only when the user had not changed it away from the wi one
				if (Input::storedEmail($user->getSystemEMailAddress()) === $email) {
					$user->setSystemEMailAddress($newEmail);
					$this->markEmailVerified($user);
				}
			}
		}
		if ($name !== '') {
			$user->setDisplayName(Input::displayName($name, $email));
		}
	}

	/** Accounts whose profile e-mail is exactly E (a collation-only match does not count). @return list<IUser> */
	public function profileHolders(string $email): array {
		$out = [];
		foreach ($this->users->getByEmail($email) as $candidate) {
			if (Input::storedEmail($candidate->getSystemEMailAddress()) === $email) {
				$out[] = $candidate;
			}
		}
		return $out;
	}

	private function refuseIfIneligible(IUser $user): void {
		$reason = $this->policy->refusal($user);
		if ($reason !== null) {
			throw new SsoRefusal($reason);
		}
	}

	/** Nextcloud's own sign-up rules for a NEW account: the registration app's domain list and admin approval. */
	private function assertRegistrationRules(string $email): void {
		$domains = array_filter(array_map('trim', explode(';', strtolower($this->appConfig->getValueString('registration', 'allowed_domains', '')))));
		if ($domains !== []) {
			$mailDomain = explode('@', $email, 2)[1] ?? '';
			$listed = false;
			foreach ($domains as $domain) {
				if (str_contains($domain, '*')) {
					$regex = '/^' . str_replace('\*', '.+', preg_quote($domain, '/')) . '$/D';
					$listed = $listed || preg_match($regex, $mailDomain) === 1;
				} else {
					$listed = $listed || $mailDomain === $domain;
				}
			}
			$blocklist = $this->truthy($this->appConfig->getValueString('registration', 'domains_is_blocklist', ''));
			if ($blocklist === $listed) {
				throw new SsoRefusal('domain_refused');
			}
		}
		if ($this->truthy($this->appConfig->getValueString('registration', 'admin_approval_required', ''))) {
			throw new SsoRefusal('reg_approval');
		}
	}

	private function truthy(string $value): bool {
		return in_array(strtolower($value), ['1', 'yes', 'true', 'on'], true);
	}

	private function createUser(string $email, string $name): IUser {
		$uid = 'wi_' . substr(Input::emailHash($email), 0, 16);
		for ($i = 0; $this->users->userExists($uid); $i++) {
			if ($i >= 3) {
				throw new SsoRefusal('uid_taken');
			}
			// someone took the predictable id: the link row, not the id, ties the account to the wi e-mail
			$uid = 'wi_' . bin2hex(random_bytes(8));
		}
		$user = $this->users->createUser($uid, $this->randomPassword());
		if ($user === false) {
			throw new SsoRefusal('create_failed', 500);
		}
		$user->setSystemEMailAddress($email);
		$this->markEmailVerified($user);
		$user->setDisplayName(Input::displayName($name, $email));
		$user->setQuota($this->quota());
		return $user;
	}

	private function markEmailVerified(IUser $user): void {
		$account = $this->accounts->getAccount($user);
		$account->getProperty(IAccountManager::PROPERTY_EMAIL)->setLocallyVerified(IAccountManager::VERIFIED);
		$this->accounts->updateAccount($account);
	}

	private function quota(): string {
		$q = trim($this->appConfig->getValueString('wisso', 'default_quota', self::DEFAULT_QUOTA));
		return preg_match('/^(none|default|\d+(\.\d+)? ?(B|KB|MB|GB|TB))$/Di', $q) === 1 ? $q : self::DEFAULT_QUOTA;
	}

	/** Nobody knows this password; it only has to satisfy the password policy (length and every character class). */
	private function randomPassword(): string {
		return $this->random->generate(16, ISecureRandom::CHAR_LOWER)
			. $this->random->generate(16, ISecureRandom::CHAR_UPPER)
			. $this->random->generate(16, ISecureRandom::CHAR_DIGITS)
			. $this->random->generate(8, ISecureRandom::CHAR_SYMBOLS);
	}
}
