<?php

declare(strict_types=1);

use OCP\IUser;

/** The create path: a wi user with no Nextcloud account gets one, from the wi e-mail, and is signed in directly. */
class LoginCreateTest extends WissoTestCase {
	private function created(string $email): IUser {
		$uid = $this->linkedUid($email);
		$this->assertNotNull($uid, 'no link row written');
		$user = $this->um->get($uid);
		$this->assertNotNull($user);
		return $user;
	}

	public function testNewWiUserGetsAnAccountFromTheWiEmail(): void {
		$email = $this->randomEmail('New.User');
		$url = $this->loginFor(strtoupper($email) . ' ', 'Wi Person');
		$this->assertStringContainsString('/index.php/apps/wisso/sso/consume?code=', $url);

		$user = $this->created($email);
		$this->assertSame('wi_' . substr(hash('sha256', strtolower($email)), 0, 16), $user->getUID());
		$this->assertSame(strtolower($email), $user->getSystemEMailAddress());
		$this->assertSame('Wi Person', $user->getDisplayName());
		$this->assertSame([], $this->groups->getUserGroupIds($user), 'no group');
		$this->assertFalse($this->groups->isAdmin($user->getUID()));
		$this->assertTrue($user->isEnabled());
		$this->assertSame('', $this->config->getUserValue($user->getUID(), 'files', 'quota', ''), 'no per-user quota is set by wisso');
		$this->assertSame('Database', $user->getBackendClassName());
		$this->assertSame(\OCP\Accounts\IAccountManager::VERIFIED, $this->accountVerified($user), 'e-mail marked verified');
		$this->assertStringNotContainsString($email, $user->getUID());
	}

	/** Nobody knows the password of a created account, and no two accounts share one (BitBox RW-03). */
	public function testCreatedPasswordsAreRandomPerAccount(): void {
		$hashes = [];
		foreach ([1, 2] as $_) {
			$email = $this->randomEmail();
			$this->loginFor($email);
			$qb = $this->db->getQueryBuilder();
			$qb->select('password')->from('users')->where($qb->expr()->eq('uid', $qb->createNamedParameter($this->linkedUid($email))));
			$hashes[] = (string)$qb->executeQuery()->fetchOne();
		}
		$this->assertNotSame($hashes[0], $hashes[1]);
		$this->assertNotSame('', $hashes[0]);
		foreach (['', 'password', 'wi'] as $guess) {
			$this->assertFalse($this->um->checkPassword($this->linkedUid($email), $guess));
		}
	}

	/** The generated password is built from fresh random parts every time (salted hashes always differ, so look at the source). */
	public function testGeneratedPasswordIsRandomAndLongEnough(): void {
		$svc = \OCP\Server::get(\OCA\Wisso\Service\AccountService::class);
		$m = new \ReflectionMethod($svc, 'randomPassword');
		$seen = [];
		for ($i = 0; $i < 5; $i++) {
			$p = $m->invoke($svc);
			$this->assertGreaterThanOrEqual(48, strlen($p));
			$this->assertNotContains($p, $seen);
			$seen[] = $p;
		}
		$this->assertGreaterThan(40, strlen(count_chars(implode('', $seen), 3)) + 40, 'variety');
		$this->assertGreaterThanOrEqual(30, strlen(count_chars(implode('', $seen), 3)), 'many distinct characters across the samples');
	}

	public function testUrlComesFromOverwriteCliUrlNotFromTheHostHeader(): void {
		$r = $this->apiPost('login', ['email' => $this->randomEmail()], '', ['Host' => 'localhost', 'X-Forwarded-Host' => 'evil.example']);
		$this->assertSame(200, $r->getStatusCode());
		$url = $this->json($r)['data']['url'];
		$this->assertStringStartsWith(self::BASE . '/index.php/apps/wisso/sso/consume?code=', $url);
		$this->assertStringNotContainsString('evil.example', $url);
	}

	public function testSecondLoginUsesTheSameAccount(): void {
		$email = $this->randomEmail();
		$this->loginFor($email);
		$first = $this->linkedUid($email);
		$this->loginFor($email, 'Other Name');
		$this->assertSame($first, $this->linkedUid($email));
		$this->assertSame(1, $this->countRows('wisso_links'));
		$this->assertCount(1, array_filter($this->um->search('wi_'), fn (IUser $u) => str_starts_with($u->getUID(), 'wi_')));
	}

	public function testCodeIsStoredHashedAndOnePerAccount(): void {
		$email = $this->randomEmail();
		$url1 = $this->loginFor($email);
		$url2 = $this->loginFor($email);
		$this->assertNotSame($this->codeOf($url1), $this->codeOf($url2));
		$qb = $this->db->getQueryBuilder();
		$qb->select('code_hash', 'type', 'expires_at')->from('wisso_codes');
		$rows = $qb->executeQuery()->fetchAll();
		$this->assertCount(1, $rows, 'a new code removes the older one');
		$this->assertSame(hash('sha256', $this->codeOf($url2)), $rows[0]['code_hash']);
		$this->assertSame('login', $rows[0]['type']);
		$this->assertEqualsWithDelta(time() + 60, (int)$rows[0]['expires_at'], 3);
	}

	public function testNameRules(): void {
		$cases = [
			'strip control characters' => ["Ab\x00c\x07d\r\ne", 'Abcde'],
			'fall back to the local part' => ['', null],
			'arabic name wider than the column is cut to 64 characters' => [str_repeat('م', 100), str_repeat('م', 64)],
			'bidi marks are removed' => ["\u{202E}Name\u{200F}", 'Name'],
		];
		foreach ($cases as $label => [$name, $expect]) {
			$email = $this->randomEmail('nm');
			$this->loginFor($email, $name);
			$got = $this->created($email)->getDisplayName();
			$this->assertSame($expect ?? explode('@', $email)[0], $got, $label);
			$this->um->get($this->linkedUid($email))->delete();
		}
	}

	/** Widths come from the real columns, not from guesses (BitBox hotfix-52: varchar(6) vs a 7-letter locale). */
	public function testColumnWidthsMatchTheInputRules(): void {
		$prefix = (string)$this->config->getSystemValue('dbtableprefix', 'oc_');
		$width = function (string $table, string $col) use ($prefix): int {
			return (int)$this->db->executeQuery(
				'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
				[$prefix . $table, $col]
			)->fetchOne();
		};
		$this->assertSame(64, $width('users', 'displayname'));
		$this->assertGreaterThanOrEqual(19, $width('users', 'uid'));
		$this->assertSame(64, $width('wisso_links', 'uid'));
		$this->assertSame(64, $width('wisso_codes', 'uid'));
		$this->assertSame(\OCA\Wisso\Service\Input::DISPLAY_NAME_MAX, $width('users', 'displayname'));
	}

	public function testLongestAllowedEmailIsAccepted(): void {
		$email = str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 57) . '.com';
		$this->assertSame(254, strlen($email), 'fixture length');
		self::$secrets[] = $email;
		$this->loginFor($email);
		$this->assertSame($email, $this->created($email)->getSystemEMailAddress());
	}

	public function testLocaleIsTheInstanceDefaultArabic(): void {
		$email = $this->randomEmail();
		$this->loginFor($email);
		$uid = $this->linkedUid($email);
		$this->assertSame('', $this->config->getUserValue($uid, 'core', 'lang', ''), 'no per-user language: the instance default applies');
		$this->assertSame('ar', $this->config->getSystemValue('default_locale'));
	}

	/** Owner decision D-NC-003: wisso sets no quota; a created user gets the instance default for new users. */
	public function testCreatedUserGetsTheInstanceDefaultQuota(): void {
		$this->withAppConfig('files', 'default_quota', '250 MB');
		$email = $this->randomEmail();
		$this->loginFor($email);
		$user = $this->created($email);
		$this->assertSame('250 MB', $user->getQuota(), 'resolves to the instance default');
		$this->assertSame('', $this->config->getUserValue($user->getUID(), 'files', 'quota', ''));
		$this->assertStringNotContainsString('1 GB', $user->getQuota());
	}

	public function testSquattedUidDoesNotBlockTheNewAccount(): void {
		$email = $this->randomEmail();
		$predictable = 'wi_' . substr(hash('sha256', strtolower($email)), 0, 16);
		$squatter = $this->mkUser($predictable, 'unrelated@wi.example');
		$this->loginFor($email);
		$uid = $this->linkedUid($email);
		$this->assertNotNull($uid);
		$this->assertNotSame($predictable, $uid, 'the squatted id must not be reused');
		$this->assertNotNull($this->um->get($predictable), 'the squatter account is untouched');
		$this->assertSame('unrelated@wi.example', $squatter->getSystemEMailAddress());
	}

	// ---- Nextcloud's own sign-up rules apply to new accounts -------------------------------------------------

	public function testAllowedDomainsListIsHonoured(): void {
		$this->withAppConfig('registration', 'allowed_domains', 'good.example; *.corp.example');
		$ok1 = 'a' . bin2hex(random_bytes(3)) . '@good.example';
		$ok2 = 'b' . bin2hex(random_bytes(3)) . '@team.corp.example';
		$bad = 'c' . bin2hex(random_bytes(3)) . '@other.example';
		array_push(self::$secrets, $ok1, $ok2, $bad);
		$this->assertSame(200, $this->apiPost('login', ['email' => $ok1])->getStatusCode());
		$this->assertSame(200, $this->apiPost('login', ['email' => $ok2])->getStatusCode());
		$this->assertErrorShape($this->apiPost('login', ['email' => $bad]), 403);
		$this->assertNull($this->linkedUid($bad));
		$this->assertCount(2, array_filter($this->um->search('wi_'), fn (IUser $u) => str_starts_with($u->getUID(), 'wi_')));
	}

	public function testBlocklistModeIsHonoured(): void {
		$this->withAppConfig('registration', 'allowed_domains', 'blocked.example');
		$this->withAppConfig('registration', 'domains_is_blocklist', 'yes');
		$bad = 'x' . bin2hex(random_bytes(3)) . '@blocked.example';
		self::$secrets[] = $bad;
		$this->assertErrorShape($this->apiPost('login', ['email' => $bad]), 403);
		$this->assertSame(200, $this->apiPost('login', ['email' => $this->randomEmail()])->getStatusCode());
	}

	public function testAdminApprovalRequiredRefusesNewAccounts(): void {
		$this->withAppConfig('registration', 'admin_approval_required', 'yes');
		$email = $this->randomEmail();
		$this->assertErrorShape($this->apiPost('login', ['email' => $email]), 403);
		$this->assertNull($this->linkedUid($email));
	}

	public function testRegistrationRulesDoNotBlockAnAlreadyLinkedUser(): void {
		$email = $this->randomEmail();
		$this->loginFor($email);
		$this->withAppConfig('registration', 'admin_approval_required', 'yes');
		$this->assertSame(200, $this->apiPost('login', ['email' => $email])->getStatusCode());
	}

	/** The generated password has to satisfy the strictest password policy the owner could set. */
	public function testCreationWorksUnderAStrictPasswordPolicy(): void {
		$this->withTypedAppConfig('password_policy', 'minLength', 20);
		$this->withTypedAppConfig('password_policy', 'enforceUpperLowerCase', true);
		$this->withTypedAppConfig('password_policy', 'enforceNumericCharacters', true);
		$this->withTypedAppConfig('password_policy', 'enforceSpecialCharacters', true);
		$email = $this->randomEmail();
		$this->assertSame(200, $this->apiPost('login', ['email' => $email])->getStatusCode());
		$this->assertNotNull($this->linkedUid($email));
	}
}
