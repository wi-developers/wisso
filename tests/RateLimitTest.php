<?php

declare(strict_types=1);

use GuzzleHttp\Cookie\CookieJar;
use OCA\Wisso\Service\KeyChecker;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Server;

/** Soft limits: only requests with a valid key count, a bad key can never lock the real one out. */
class RateLimitTest extends WissoTestCase {
	public function testSameEmailIsLimitedToTenPerMinute(): void {
		$email = $this->randomEmail();
		for ($i = 1; $i <= 10; $i++) {
			$this->assertSame(200, $this->apiPost('login', ['email' => $email])->getStatusCode(), "call $i");
		}
		$r = $this->apiPost('login', ['email' => $email]);
		$this->assertErrorShape($r, 429);
		$this->assertSame('60', $r->getHeaderLine('Retry-After'));
		$this->assertSame(200, $this->apiPost('login', ['email' => $this->randomEmail()])->getStatusCode(), 'other addresses are not affected');
	}

	public function testBadKeysNeverFillTheBucket(): void {
		$email = $this->randomEmail();
		for ($i = 0; $i < 15; $i++) {
			$this->assertSame(401, $this->apiPost('login', ['email' => $email], KeyChecker::generate()[0])->getStatusCode());
		}
		$this->assertSame(200, $this->apiPost('login', ['email' => $email])->getStatusCode(), 'the real key is not locked out');
	}

	public function testGlobalBucketIsLimitedToOneHundredTwentyPerMinute(): void {
		$limiter = Server::get(ILimiter::class);
		for ($i = 0; $i < 120; $i++) {
			$limiter->registerAnonRequest('wisso_global', 120, 60, '127.0.0.1');
		}
		$this->assertErrorShape($this->apiPost('login', ['email' => $this->randomEmail()]), 429);
		$this->assertErrorShape($this->apiPost('update', ['email' => $this->randomEmail()]), 429);
		// and a bad key is still a plain 401, never a 429
		$this->assertSame(401, $this->apiPost('login', ['email' => $this->randomEmail()], KeyChecker::generate()[0])->getStatusCode());
	}

	public function testConsumeIsLimitedPerClientAddress(): void {
		$client = $this->client(new CookieJar());
		for ($i = 0; $i < 30; $i++) {
			$client->get(self::APP . 'sso/consume?code=nope');
		}
		$url = $this->loginFor($this->randomEmail());
		$r = $client->get($url);
		$this->assertSame(self::BASE . '/index.php/login', $r->getHeaderLine('Location'));
		$this->assertNull($this->whoAmI($client));
		$this->assertSame(1, $this->countRows('wisso_codes'), 'the code was not even looked at');
	}

	public function testClaimConfirmIsLimitedPerUser(): void {
		$email = $this->randomEmail('rl');
		$this->mkUser('t_rl', $email);
		$url = $this->loginFor($email);
		$client = $this->webLogin('t_rl');
		// a valid request token is needed to get past the framework check, which runs before the throttle
		$page = (string)$client->get($url)->getBody();
		$this->assertSame(1, preg_match('/name="requesttoken" value="([^"]+)"/', $page, $m));
		$token = html_entity_decode($m[1]);
		$codes = [];
		for ($i = 1; $i <= 6; $i++) {
			$r = $client->post(self::APP . 'sso/claim', ['form_params' => ['code' => $this->codeOf($url), 'claim_token' => 'x', 'requesttoken' => $token, 'to' => '']]);
			$codes[] = $r->getStatusCode();
		}
		$this->assertContains(429, $codes);
		$this->assertSame(0, $this->countRows('wisso_links'));
	}
}
