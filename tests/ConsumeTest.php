<?php

declare(strict_types=1);

use GuzzleHttp\Cookie\CookieJar;
use OCP\Server;

/** The sign-in step: one-time code, HEAD and prefetch, other signed-in user, re-checks, redirect, session. */
class ConsumeTest extends WissoTestCase {
	private const LOGIN = self::BASE . '/index.php/login';

	/** @return array{0: \GuzzleHttp\Client, 1: CookieJar} */
	private function browser(): array {
		$jar = new CookieJar();
		return [$this->client($jar), $jar];
	}

	private function sessionCookie(CookieJar $jar): ?string {
		foreach ($jar->toArray() as $c) {
			if ($c['Name'] === $this->config->getSystemValue('instanceid')) {
				return $c['Value'];
			}
		}
		return null;
	}

	public function testHappyPathSignsTheUserInAndRegeneratesTheSession(): void {
		$email = $this->randomEmail('ok');
		$url = $this->loginFor($email, 'Happy Path', 'dashboard');
		[$client, $jar] = $this->browser();
		$client->get(self::LOGIN);
		$before = $this->sessionCookie($jar);
		$this->assertNotNull($before);

		$r = $client->get($url);
		$this->assertContains($r->getStatusCode(), [302, 303]);
		$this->assertSame(self::BASE . '/index.php/apps/dashboard/', $r->getHeaderLine('Location'));
		$this->assertStringContainsString('no-store', $r->getHeaderLine('Cache-Control'));
		$this->assertSame($this->linkedUid($email), $this->whoAmI($client));
		$this->assertNotSame($before, $this->sessionCookie($jar), 'session id regenerated');
		foreach ($r->getHeader('Set-Cookie') as $cookie) {
			if (str_starts_with($cookie, (string)$this->config->getSystemValue('instanceid') . '=')) {
				$this->assertStringContainsString('secure', strtolower($cookie));
				$this->assertStringContainsString('httponly', strtolower($cookie));
			}
		}
	}

	/** Gate finding: the code read the key default_app; core's key is defaultapp, so the owner's choice was ignored. */
	public function testNoRequestedPathGoesToTheInstanceDefaultApp(): void {
		$this->config->setSystemValue('defaultapp', 'files');
		$this->onTearDown(fn () => $this->config->deleteSystemValue('defaultapp'));
		$url = $this->loginFor($this->randomEmail(), 'P', 'not-a-path');
		$r = $this->client(new CookieJar())->get($url);
		$this->assertSame(self::BASE . '/index.php/apps/files/', $r->getHeaderLine('Location'));
	}

	public function testRequestedPathUnderAppsIsHonoured(): void {
		$url = $this->loginFor($this->randomEmail(), 'P', '/apps/files/');
		$r = $this->client(new CookieJar())->get($url);
		$this->assertSame(self::BASE . '/index.php/apps/files/', $r->getHeaderLine('Location'));
	}

	public function testCodeCannotBeReplayed(): void {
		$url = $this->loginFor($this->randomEmail());
		[$first] = $this->browser();
		$this->assertSame($this->linkedUidForUrl($url), $this->whoAmIAfter($first, $url));
		[$second] = $this->browser();
		$r = $second->get($url);
		$this->assertSame(self::LOGIN, $r->getHeaderLine('Location'));
		$this->assertNull($this->whoAmI($second));
	}

	public function testExpiredCodeIsRefused(): void {
		$url = $this->loginFor($this->randomEmail());
		$this->expireCodes();
		[$client] = $this->browser();
		$r = $client->get($url);
		$this->assertSame(self::LOGIN, $r->getHeaderLine('Location'));
		$this->assertNull($this->whoAmI($client));
	}

	public function testUnknownAndMalformedCodesRedirectToLoginWithNoReason(): void {
		[$client] = $this->browser();
		foreach (['', 'short', str_repeat('A', 43), str_repeat('A', 44), "a'b", '../../x', str_repeat('%00', 20)] as $code) {
			$r = $client->get(self::APP . 'sso/consume?code=' . rawurlencode($code));
			$this->assertSame(self::LOGIN, $r->getHeaderLine('Location'), $code);
		}
		$r = $client->get(self::APP . 'sso/consume');
		$this->assertSame(self::LOGIN, $r->getHeaderLine('Location'));
		$this->assertNull($this->whoAmI($client));
	}

	public function testHeadAndPrefetchNeitherReadNorConsume(): void {
		$url = $this->loginFor($this->randomEmail());
		[$client] = $this->browser();
		$this->assertSame(204, $client->head($url)->getStatusCode());
		foreach ([['Sec-Purpose' => 'prefetch'], ['Sec-Purpose' => 'prefetch;prerender'], ['Purpose' => 'prefetch'], ['X-Purpose' => 'preview'], ['X-Moz' => 'prefetch']] as $h) {
			$r = $client->get($url, ['headers' => $h]);
			$this->assertSame(204, $r->getStatusCode(), json_encode($h));
			$this->assertSame('', $r->getHeaderLine('Location'));
		}
		$this->assertSame(1, $this->countRows('wisso_codes'), 'the code survives');
		$this->assertNotNull($this->whoAmIAfter($client, $url), 'and still works for the real visit');
	}

	/** Four browsers present the same code at the same moment: exactly one is signed in. */
	public function testParallelPresentationsOfOneCodeSignInExactlyOne(): void {
		for ($round = 0; $round < 4; $round++) {
			$url = $this->loginFor($this->randomEmail('race'));
			$promises = [];
			for ($i = 0; $i < 4; $i++) {
				$promises[] = $this->client(new CookieJar())->getAsync($url);
			}
			$signedIn = 0;
			foreach (\GuzzleHttp\Promise\Utils::settle($promises)->wait() as $res) {
				$this->assertSame('fulfilled', $res['state']);
				if ($res['value']->getHeaderLine('Location') !== self::LOGIN) {
					$signedIn++;
				}
			}
			$this->assertSame(1, $signedIn, "round $round");
			$this->clearRateLimits();
		}
	}

	public function testClaimCodeIsNotASignInCodeAndTheReverse(): void {
		$holder = $this->randomEmail('holder');
		$this->mkUser('t_holder', $holder);
		$claimUrl = $this->loginFor($holder);
		$this->assertStringContainsString('/sso/claim?', $claimUrl);
		[$client] = $this->browser();
		$r = $client->get(str_replace('/sso/claim?', '/sso/consume?', $claimUrl));
		$this->assertSame(self::LOGIN, $r->getHeaderLine('Location'));
		$this->assertNull($this->whoAmI($client));

		$loginUrl = $this->loginFor($this->randomEmail('other'));
		$page = $client->get(str_replace('/sso/consume?', '/sso/claim?', $loginUrl));
		$this->assertSame(400, $page->getStatusCode(), 'a sign-in code at the claim page is just an expired link');
		$this->assertSame(1, $this->countRows('wisso_codes') - 1, 'only the claim code of the first user is left besides the sign-in code');
	}

	public function testDifferentSignedInUserGetsAPageAndTheCodeSurvives(): void {
		$other = $this->mkUser('t_other', $this->randomEmail('other'));
		$otherClient = $this->webLogin('t_other');
		$url = $this->loginFor($this->randomEmail('target'));
		$r = $otherClient->get($url);
		$this->assertSame(409, $r->getStatusCode());
		$body = (string)$r->getBody();
		$this->assertStringContainsString('signed in to another account', $body);
		$this->assertStringContainsString('أنت مسجّل الدخول بحساب آخر', $body);
		$this->assertStringNotContainsString('t_other', $body);
		$this->assertStringNotContainsString($other->getSystemEMailAddress(), $body);
		$this->assertSame('t_other', $this->whoAmI($otherClient), 'not switched');
		$this->assertSame(1, $this->countRows('wisso_codes'));
		$this->assertNotNull($this->whoAmIAfter($this->client(new CookieJar()), $url));
	}

	public function testSameUserAlreadySignedInConsumesAndKeepsTheSession(): void {
		$email = $this->randomEmail('same');
		$url = $this->loginFor($email);
		[$client] = $this->browser();
		$uid = $this->whoAmIAfter($client, $url);
		$url2 = $this->loginFor($email);
		$r = $client->get($url2);
		$this->assertContains($r->getStatusCode(), [302, 303]);
		$this->assertSame($uid, $this->whoAmI($client));
		$this->assertSame(0, $this->countRows('wisso_codes'));
	}

	// ---- the account is decided again at consume -------------------------------------------------------------

	public function testStateChangesBetweenLoginAndConsumeAreCaught(): void {
		$cases = [
			'became admin' => fn ($uid) => $this->groups->get('admin')->addUser($this->um->get($uid)),
			'became delegated admin' => function ($uid): void {
				$g = $this->groups->createGroup('t_deleg_c');
				$this->madeGroups[] = 't_deleg_c';
				$g->addUser($this->um->get($uid));
				$mapper = Server::get(\OC\Settings\AuthorizedGroupMapper::class);
				$row = new \OC\Settings\AuthorizedGroup();
				$row->setGroupId('t_deleg_c');
				$row->setClass('OCA\\Settings\\Settings\\Admin\\Sharing');
				$mapper->insert($row);
				$this->onTearDown(fn () => $mapper->removeGroup('t_deleg_c'));
			},
			'became sub admin' => function ($uid): void {
				$g = $this->groups->createGroup('t_sub_c');
				$this->madeGroups[] = 't_sub_c';
				Server::get(\OCP\Group\ISubAdmin::class)->createSubAdmin($this->um->get($uid), $g);
			},
			'disabled' => fn ($uid) => $this->um->get($uid)->setEnabled(false),
			'enrolled 2FA' => fn ($uid) => $this->enrolTwoFactor($this->um->get($uid)),
			'link removed' => fn ($uid) => $this->db->getQueryBuilder()->delete('wisso_links')->executeStatement(),
			'link moved to another e-mail' => function ($uid): void {
				$qb = $this->db->getQueryBuilder();
				$qb->update('wisso_links')->set('wi_email_sha256', $qb->createNamedParameter(hash('sha256', 'moved@wi.example')))->executeStatement();
			},
		];
		foreach ($cases as $label => $change) {
			$email = $this->randomEmail('chg');
			$url = $this->loginFor($email);
			$uid = $this->linkedUid($email);
			$change($uid);
			[$client] = $this->browser();
			$r = $client->get($url);
			$this->assertSame(self::LOGIN, $r->getHeaderLine('Location'), $label);
			$this->assertNull($this->whoAmI($client), $label);
			$this->tearDown();
			$this->setUp();
		}
	}

	public function testDeletedAccountBetweenLoginAndConsumeIsRefused(): void {
		$email = $this->randomEmail('gone');
		$url = $this->loginFor($email);
		$this->um->get($this->linkedUid($email))->delete();
		[$client] = $this->browser();
		$this->assertSame(self::LOGIN, $client->get($url)->getHeaderLine('Location'));
	}

	// ---- redirect --------------------------------------------------------------------------------------------

	public static function badRedirects(): array {
		return [
			'scheme' => ['https://evil.example/'],
			'protocol relative' => ['//evil.example/x'],
			'backslash' => ['/\\evil.example'],
			'encoded backslash' => ['/apps/%5Cevil'],
			'dot dot' => ['/apps/../index.php'],
			'encoded dot dot' => ['/apps/%2e%2e/x'],
			'double encoded dot dot' => ['/apps/%252e%252e/x'],
			'crlf' => ["/apps/files/\r\nSet-Cookie: x=y"],
			'encoded crlf' => ['/apps/files%0d%0aX: y'],
			'javascript' => ['javascript:alert(1)'],
			'at sign' => ['/apps/files@evil.example'],
			'query' => ['/apps/files/?x=//evil.example'],
			'not under apps' => ['/settings/admin'],
			'double slash' => ['/apps//files'],
			'dashboard word' => ['dashboard'],
			'empty' => [''],
			'array-ish' => ['/apps/files/[]'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('badRedirects')]
	public function testBadRedirectsFallBackToTheDefaultAtLoginAndAtConsume(string $bad): void {
		$default = '/index.php/apps/dashboard/';
		$email = $this->randomEmail('rd');
		$url = $this->loginFor($email, 'R', $bad);
		parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
		$this->assertSame($default, $q['to'], 'normalised at login');

		// a tampered `to` in the URL is judged again at consume
		$url = $this->loginFor($email, 'R', 'dashboard');
		$tampered = $this->withParam($url, 'to', $bad);
		$r = $this->client(new CookieJar())->get($tampered);
		$this->assertSame(self::BASE . $default, $r->getHeaderLine('Location'));
	}

	public function testTooLongRedirectFallsBack(): void {
		$long = '/apps/' . str_repeat('a', 300);
		parse_str((string)parse_url($this->loginFor($this->randomEmail(), 'R', $long), PHP_URL_QUERY), $q);
		$this->assertSame('/index.php/apps/dashboard/', $q['to']);
	}

	// ---- helpers ---------------------------------------------------------------------------------------------

	private function whoAmIAfter(\GuzzleHttp\Client $client, string $url): ?string {
		$client->get($url);
		return $this->whoAmI($client);
	}

	private function linkedUidForUrl(string $url): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('uid')->from('wisso_codes')->where($qb->expr()->eq('code_hash', $qb->createNamedParameter(hash('sha256', $this->codeOf($url)))));
		$uid = $qb->executeQuery()->fetchOne();
		return $uid === false ? null : (string)$uid;
	}
}
