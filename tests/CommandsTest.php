<?php

declare(strict_types=1);

use OCA\Wisso\Service\KeyChecker;
use OCP\Server;

/** The three occ commands: status (read-only), unlink, key:generate. */
class CommandsTest extends WissoTestCase {
	/** @return array{0: int, 1: string} exit code and output */
	private function occ(string $args): array {
		$out = [];
		exec('php /var/www/html/occ --no-warnings ' . $args . ' 2>&1', $out, $code);
		return [$code, implode("\n", $out)];
	}

	public function testStatusIsReadOnlyAndNeverPrintsTheKeyOrItsHash(): void {
		$this->loginFor($this->randomEmail());
		$before = [$this->countRows('wisso_links'), $this->countRows('wisso_codes')];
		[$code, $out] = $this->occ('wisso:status');
		$this->assertSame(0, $code, $out);
		$this->assertStringContainsString('key configured: yes', $out);
		$this->assertStringContainsString('linked accounts: 1', $out);
		$this->assertStringContainsString('live one-time codes: 1', $out);
		$this->assertStringContainsString('registration admin_approval_required: no', $out);
		$this->assertStringNotContainsString($this->key, $out);
		$this->assertStringNotContainsString(hash('sha256', $this->key), $out);
		$this->assertSame($before, [$this->countRows('wisso_links'), $this->countRows('wisso_codes')]);
	}

	/** The gate finding: a typed boolean flag made wisso:status exit 1 with a type conflict. */
	public function testStatusReadsTypedBooleanRegistrationFlags(): void {
		[$code, $out] = $this->occ('wisso:status');
		$this->assertSame(0, $code, $out);
		$this->assertStringContainsString('registration admin_approval_required: no', $out);
		$this->withTypedAppConfig('registration', 'admin_approval_required', true);
		[$code, $out] = $this->occ('wisso:status');
		$this->assertSame(0, $code, $out);
		$this->assertStringContainsString('registration admin_approval_required: yes', $out);
	}

	public function testStatusLinksListsAccountsWithTheLogReference(): void {
		$email = $this->randomEmail();
		$this->loginFor($email);
		$uid = $this->linkedUid($email);
		[, $out] = $this->occ('wisso:status --links');
		$this->assertStringContainsString(\OCA\Wisso\Service\SsoLog::ref($uid) . ' ' . $uid . ' ', $out);
		$this->assertStringNotContainsString($email, $out);
	}

	public function testUnlinkRemovesTheLinkKeepsTheAccountAndTheNextClickIsAClaim(): void {
		$email = $this->randomEmail();
		$this->loginFor($email);
		$uid = $this->linkedUid($email);
		$this->assertNotNull($this->um->get($uid));
		[$code, $out] = $this->occ('wisso:unlink ' . escapeshellarg($uid));
		$this->assertSame(0, $code, $out);
		$this->assertNull($this->linkedUid($email));
		$this->assertSame(0, $this->countRows('wisso_codes'), 'pending codes go too');
		$this->assertNotNull($this->um->get($uid), 'the account stays');
		$this->assertStringContainsString('/sso/claim?', $this->loginFor($email), 'an existing account is offered a claim again');
	}

	public function testUnlinkOfAnUnlinkedAccountSaysSo(): void {
		[$code, $out] = $this->occ('wisso:unlink t_nobody');
		$this->assertSame(1, $code);
		$this->assertStringContainsString('no link', $out);
	}

	public function testKeyGenerateRefusesToReplaceWithoutRotate(): void {
		$hash = $this->storedKeyHash();
		[$code, $out] = $this->occ('wisso:key:generate');
		$this->assertSame(1, $code);
		$this->assertStringContainsString('--rotate', $out);
		$this->assertSame($hash, $this->storedKeyHash());
	}

	public function testKeyGenerateAndRotateStoreOnlyTheHashAndTheOldKeyDies(): void {
		$good = hash('sha256', $this->key);
		$this->onTearDown(function () use ($good): void {
			$this->appConfig->setValueString('wisso', 'key_sha256', $good, false, true);
			$this->settle();
		});

		[$code, $out] = $this->occ('wisso:key:generate --rotate');
		$this->assertSame(0, $code, $out);
		$lines = explode("\n", trim($out));
		$newKey = trim((string)end($lines));
		self::$secrets[] = $newKey;
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $newKey);
		$stored = (string)$this->storedKeyHash();
		$this->assertSame(hash('sha256', $newKey), $stored, 'the stored value is the hash of the printed key');
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $stored);
		$this->assertNotSame($newKey, $stored);

		$this->settle();
		$this->assertErrorShape($this->apiPost('login', ['email' => $this->randomEmail()], $this->key), 401);
		$this->assertSame(200, $this->apiPost('login', ['email' => $this->randomEmail()], $newKey)->getStatusCode());
	}

	public function testKeyGenerateWorksWhenNoKeyIsSet(): void {
		$good = hash('sha256', $this->key);
		$this->onTearDown(function () use ($good): void {
			$this->appConfig->setValueString('wisso', 'key_sha256', $good, false, true);
			$this->settle();
		});
		$this->appConfig->deleteKey('wisso', 'key_sha256');
		[$code, $out] = $this->occ('wisso:key:generate');
		$this->assertSame(0, $code, $out);
		$lines = explode("\n", trim($out));
		self::$secrets[] = trim((string)end($lines));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$this->storedKeyHash());
		$this->assertSame(hash('sha256', trim((string)end($lines))), $this->storedKeyHash());
	}

	public function testTheKeyIsStoredAsASensitiveValue(): void {
		$list = $this->occ('config:list wisso');
		$this->assertStringNotContainsString(hash('sha256', $this->key), $list[1], 'config:list masks it');
		$this->assertSame(0, $list[0]);
		$this->assertNotNull(Server::get(\OCP\IAppConfig::class));
	}
}
