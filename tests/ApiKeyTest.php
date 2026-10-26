<?php

declare(strict_types=1);

use OCA\Wisso\Service\KeyChecker;

/** Rules: key check fails closed; every failure on the admin-api prefix is the JSON shape wi can read. */
class ApiKeyTest extends WissoTestCase {
	/** Replaces the stored hash (null deletes it); the correct one is restored and settled in tearDown. */
	private function withKeyHash(?string $value): void {
		static $registered = false;
		if (!$registered) {
			$registered = true;
		}
		$good = hash('sha256', $this->key);
		if ($value === null) {
			$this->appConfig->deleteKey('wisso', 'key_sha256');
		} else {
			$this->appConfig->setValueString('wisso', 'key_sha256', $value, false, true);
		}
		$this->onTearDown(function () use ($good): void {
			$this->appConfig->setValueString('wisso', 'key_sha256', $good, false, true);
			$this->settle();
		});
		$this->settle();
	}

	private function form(): array {
		return ['email' => $this->randomEmail(), 'name' => 'Key Test'];
	}

	public function testMissingKeyIs401Json(): void {
		$this->assertErrorShape($this->apiPost('login', $this->form(), null), 401);
	}

	public function testWrongKeyIs401Json(): void {
		$this->assertErrorShape($this->apiPost('login', $this->form(), KeyChecker::generate()[0]), 401);
	}

	public function testMalformedKeysAre401(): void {
		foreach (['short', str_repeat('a', 42), str_repeat('a', 129), str_repeat('a', 43) . ' x', 'é' . str_repeat('a', 43)] as $bad) {
			$this->assertErrorShape($this->apiPost('login', $this->form(), $bad), 401);
		}
	}

	public function testNonBearerSchemeIs401(): void {
		// Nextcloud answers Basic credentials itself before the app runs (its own JSON body); wi never sends Basic.
		$r = $this->client()->post(self::ADMIN_API . 'login', [
			'form_params' => $this->form(),
			'headers' => ['Authorization' => 'Basic ' . base64_encode('a:' . $this->key)],
		]);
		$this->assertSame(401, $r->getStatusCode());
		$this->assertSame(0, $this->countRows('wisso_links'));
	}

	public function testUnsetKeyFailsClosedEvenForAnyToken(): void {
		$this->withKeyHash(null);
		$this->assertErrorShape($this->apiPost('login', $this->form(), $this->key), 401);
		$this->assertErrorShape($this->apiPost('update', $this->form(), $this->key), 401);
		$this->assertSame(0, $this->countRows('wisso_links'));
	}

	/** A raw key pasted into the hash field (BitBox D-BDR-095) must never work, not even with itself. */
	public function testRawKeyInHashFieldIsRefused(): void {
		$this->withKeyHash($this->key);
		$this->assertErrorShape($this->apiPost('login', $this->form(), $this->key), 401);
	}

	public function testHashShapeIsEnforcedOnTheStoredValue(): void {
		$hash = hash('sha256', $this->key);
		foreach ([strtoupper($hash), substr($hash, 0, 63), $hash . '0', ' ' . $hash] as $bad) {
			$this->withKeyHash($bad);
			$this->assertErrorShape($this->apiPost('login', $this->form(), $this->key), 401);
		}
		$this->withKeyHash($hash);
		$this->assertSame(200, $this->apiPost('login', $this->form(), $this->key)->getStatusCode());
	}

	public function testKeyCheckerRefusesToStoreARawKey(): void {
		$this->expectException(\InvalidArgumentException::class);
		(new KeyChecker($this->appConfig))->setHash($this->key);
	}

	public function testCorrectKeyWorks(): void {
		$r = $this->apiPost('login', $this->form());
		$this->assertSame(200, $r->getStatusCode());
		$this->assertStringContainsString('application/json', $r->getHeaderLine('Content-Type'));
		$this->assertSame(['data'], array_keys($this->json($r)));
	}

	public function testManyBadKeysThenGoodKeyStillWorks(): void {
		for ($i = 0; $i < 30; $i++) {
			$this->assertSame(401, $this->apiPost('login', $this->form(), KeyChecker::generate()[0])->getStatusCode());
		}
		$this->assertSame(200, $this->apiPost('login', $this->form())->getStatusCode());
	}

	public function testBadKeyLogLinesAreCapped(): void {
		$count = fn () => substr_count($this->nextcloudLog(), 'event=login_denied reason=bad_key');
		$before = $count();
		for ($i = 0; $i < 30; $i++) {
			$this->apiPost('login', $this->form(), KeyChecker::generate()[0]);
		}
		$this->assertLessThanOrEqual(20, $count() - $before);
		$this->assertStringContainsString('suppressing_further_lines', $this->nextcloudLog());
	}

	// ---- JSON for every failure ----------------------------------------------------------------------------

	public function testGetOnLoginIs405Json(): void {
		$this->assertErrorShape($this->client()->get(self::ADMIN_API . 'login'), 405);
	}

	public function testUnknownPathsAre404Json(): void {
		$this->assertErrorShape($this->client()->get(self::APP . 'admin-api/nothing/here'), 404);
		$this->assertErrorShape($this->client()->post(self::APP . 'admin-api/sso/delete', ['form_params' => ['a' => 'b']]), 404);
		$this->assertErrorShape($this->client()->request('DELETE', self::ADMIN_API . 'login'), 405);
		$this->assertErrorShape($this->client()->request('PUT', self::ADMIN_API . 'update'), 405);
		$this->assertErrorShape($this->client()->request('OPTIONS', self::ADMIN_API . 'login'), 405);
	}

	public function testBadInputIs422Json(): void {
		foreach ([
			[],
			['email' => ''],
			['email' => 'not-an-email'],
			['email' => 'a@b'],
			['email' => "a@b.co\r\nBcc: x@y.co"],
			['email' => 'ünïcode@wi.example'],
			['email' => 'a b@wi.example'],
			['email' => str_repeat('a', 250) . '@wi.example'],
			['email' => ['x@y.co']],
			['email' => $this->randomEmail(), 'name' => str_repeat('x', 101)],
			['email' => $this->randomEmail(), 'name' => "bad\xC3\x28utf8"],
		] as $form) {
			$this->assertErrorShape($this->apiPost('login', $form), 422);
		}
		$this->assertSame(0, $this->countRows('wisso_links'));
	}

	public function testBadInputOnUpdateIs422Json(): void {
		$this->assertErrorShape($this->apiPost('update', ['email' => 'nope']), 422);
		$this->assertErrorShape($this->apiPost('update', ['email' => $this->randomEmail(), 'new_email' => 'nope']), 422);
	}

	/** A failure inside the controller (here: the code table is missing) is still the generic JSON, also with debug on. */
	public function testInternalFailureIsGenericJsonEvenWithDebug(): void {
		$this->config->setSystemValue('debug', true);
		$this->onTearDown(fn () => $this->config->deleteSystemValue('debug'));
		$prefix = (string)$this->config->getSystemValue('dbtableprefix', 'oc_');
		$conn = $this->db;
		$conn->executeStatement("RENAME TABLE `{$prefix}wisso_codes` TO `{$prefix}wisso_codes_x`");
		$this->onTearDown(fn () => $conn->executeStatement("RENAME TABLE `{$prefix}wisso_codes_x` TO `{$prefix}wisso_codes`"));

		$r = $this->apiPost('login', $this->form());
		$this->assertErrorShape($r, 500);
		$body = (string)$r->getBody();
		$this->assertSame('{"errors":[{"title":"Server error."}]}', $body);
		$this->assertStringNotContainsString('wisso_codes', $body);
		$this->assertStringNotContainsString('.php', $body);
	}
}
