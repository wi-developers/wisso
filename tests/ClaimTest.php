<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

/** The claim step: the owner of an existing account links it, with a fresh password session and two form tokens. */
class ClaimTest extends WissoTestCase {
	private string $email;
	private string $claimUrl;

	private function holder(string $uid = 't_claim', string $display = 'Claim Owner'): void {
		$this->email = $this->randomEmail('claim');
		$this->mkUser($uid, $this->email, $display);
		$this->claimUrl = $this->loginFor($this->email);
		$this->assertStringContainsString('/sso/claim?code=', $this->claimUrl, 'offered as a claim');
	}

	/** @return array{0: \Psr\Http\Message\ResponseInterface, 1: array<string,string>} page and its hidden fields */
	private function view(Client $client, ?string $url = null): array {
		$r = $client->get($url ?? $this->claimUrl);
		$fields = [];
		if (preg_match_all('/name="([a-z_]+)" value="([^"]*)"/', (string)$r->getBody(), $m, PREG_SET_ORDER)) {
			foreach ($m as $f) {
				$fields[$f[1]] = html_entity_decode($f[2], ENT_QUOTES);
			}
		}
		return [$r, $fields];
	}

	private function post(Client $client, array $fields): \Psr\Http\Message\ResponseInterface {
		return $client->post(self::APP . 'sso/claim', ['form_params' => $fields]);
	}

	private function assertNothingLinked(): void {
		$this->assertSame(0, $this->countRows('wisso_links'), 'nothing linked');
	}

	public function testNobodySignedInGetsTheSignInPageAndNoSession(): void {
		$this->holder();
		$client = $this->client(new CookieJar());
		[$r] = $this->view($client);
		$this->assertSame(200, $r->getStatusCode());
		$this->assertStringContainsString('first sign in to Path', (string)$r->getBody());
		$this->assertNull($this->whoAmI($client));
		$this->assertNothingLinked();
		$this->assertSame(1, $this->countRows('wisso_codes'), 'code kept');
	}

	public function testOtherSignedInUserGetsThe409Page(): void {
		$this->holder();
		$this->mkUser('t_claim_other', $this->randomEmail());
		$other = $this->webLogin('t_claim_other');
		[$r, $fields] = $this->view($other);
		$this->assertSame(409, $r->getStatusCode());
		$this->assertArrayNotHasKey('claim_token', $fields);
		$this->assertNothingLinked();
	}

	public function testOwnerSeesTheConfirmPageWithSafeHeaders(): void {
		$this->holder('t_claim', 'Owner <b>"Q"</b> & Co');
		$client = $this->webLogin('t_claim');
		[$r, $f] = $this->view($client);
		$body = (string)$r->getBody();
		$this->assertSame(200, $r->getStatusCode());
		foreach (['code', 'to', 'claim_token', 'requesttoken'] as $name) {
			$this->assertNotSame('', $f[$name] ?? '', $name);
		}
		$this->assertStringContainsString('Owner &lt;b&gt;&quot;Q&quot;&lt;/b&gt; &amp; Co', $body, 'display name escaped');
		$this->assertStringNotContainsString('<b>', $body);
		$this->assertStringContainsString(strtolower($this->email), $body, 'named to its own signed-in owner');
		$this->assertStringContainsString('<meta name="referrer" content="no-referrer">', $body);
		$this->assertStringContainsString('noindex', $body);
		// Nextcloud sets X-Frame-Options itself (SAMEORIGIN) and the page cannot replace it; the CSP below is the strict one
		$this->assertNotSame('', $r->getHeaderLine('X-Frame-Options'));
		$csp = $r->getHeaderLine('Content-Security-Policy');
		$this->assertStringContainsString("frame-ancestors 'none'", $csp);
		$this->assertStringContainsString("form-action 'self'", $csp);
		$this->assertStringNotContainsString('script-src', $csp);
		$this->assertStringContainsString('no-store', $r->getHeaderLine('Cache-Control'));
		$this->assertDoesNotMatchRegularExpression('/<script|src=|<link/i', $body, 'no script and no subresources');
		$this->assertNothingLinked();
	}

	public function testConfirmLinksOnceAndTheNextWiClickSignsInDirectly(): void {
		$this->holder();
		$client = $this->webLogin('t_claim');
		[, $f] = $this->view($client);
		$r = $this->post($client, $f);
		$this->assertContains($r->getStatusCode(), [302, 303], (string)$r->getBody());
		$this->assertSame(self::BASE . '/index.php/apps/dashboard/', $r->getHeaderLine('Location'));
		$this->assertSame('t_claim', $this->linkedUid($this->email));
		$this->assertSame(0, $this->countRows('wisso_codes'));

		// replay of the same form
		$again = $this->post($client, $f);
		$this->assertNothingChangedAfterReplay($again);

		// next wi click: a normal sign-in code
		$url = $this->loginFor($this->email);
		$this->assertStringContainsString('/sso/consume?', $url);
		$fresh = $this->client(new CookieJar());
		$fresh->get($url);
		$this->assertSame('t_claim', $this->whoAmI($fresh));
		// the password still works and nothing about the account changed
		$this->assertSame('Claim Owner', $this->um->get('t_claim')->getDisplayName());
		$this->webLogin('t_claim');
	}

	private function assertNothingChangedAfterReplay(\Psr\Http\Message\ResponseInterface $again): void {
		$this->assertSame(400, $again->getStatusCode(), 'the used code is just an expired link');
		$this->assertSame(1, $this->countRows('wisso_links'));
	}

	public function testPostWithoutOwnTokenOrWithAWrongOneIsRefusedAndKeepsTheCode(): void {
		$this->holder();
		$client = $this->webLogin('t_claim');
		[, $f] = $this->view($client);
		$noToken = $f;
		unset($noToken['claim_token']);
		$this->assertSame(403, $this->post($client, $noToken)->getStatusCode());
		$this->assertNothingLinked();
		// the first refusal consumed the session token: view again for a new one
		[, $f] = $this->view($client);
		$wrong = $f;
		$wrong['claim_token'] = str_repeat('0', 40);
		$this->assertSame(403, $this->post($client, $wrong)->getStatusCode());
		$this->assertNothingLinked();
		$this->assertSame(1, $this->countRows('wisso_codes'), 'a refusal never consumes the code');
		[, $f] = $this->view($client);
		$this->assertContains($this->post($client, $f)->getStatusCode(), [302, 303], 'a fresh view still works');
	}

	public function testPostWithoutNextcloudRequestTokenIsRejected(): void {
		$this->holder();
		$client = $this->webLogin('t_claim');
		[, $f] = $this->view($client);
		unset($f['requesttoken']);
		$r = $this->post($client, $f);
		$this->assertNotContains($r->getStatusCode(), [200, 302, 303], 'CSRF check of the framework applies');
		$this->assertNothingLinked();
		$f['requesttoken'] = 'forged:token';
		$this->assertNothingLinkedAfter($this->post($client, $f));
	}

	private function assertNothingLinkedAfter(\Psr\Http\Message\ResponseInterface $r): void {
		$this->assertNotContains($r->getStatusCode(), [302, 303]);
		$this->assertNothingLinked();
	}

	public function testSecondTabWithTheOlderFormTokenFails(): void {
		$this->holder();
		$client = $this->webLogin('t_claim');
		[, $first] = $this->view($client);
		[, $second] = $this->view($client);
		$this->assertNotSame($first['claim_token'], $second['claim_token']);
		$this->assertSame(403, $this->post($client, $first)->getStatusCode());
		$this->assertNothingLinked();
	}

	public function testPostFromTheWrongSignedInUserLinksNothing(): void {
		$this->holder();
		$this->mkUser('t_claim_thief', $this->randomEmail());
		$owner = $this->webLogin('t_claim');
		[, $f] = $this->view($owner);
		$thief = $this->webLogin('t_claim_thief');
		$r = $this->post($thief, $f);
		// the request token of the owner's session is not valid in the thief's session (412), and if it were, the page is a 409
		$this->assertContains($r->getStatusCode(), [409, 412]);
		$this->assertNothingLinked();
		$this->assertSame(1, $this->countRows('wisso_codes'));
	}

	public function testSessionWithoutAFreshPasswordSignInMustSignInAgain(): void {
		// a session made by the wi sign-in itself has no password confirmation
		$email = $this->randomEmail('stale');
		$this->mkUser('t_stale', $email);
		$this->linkNowByCall($email, 't_stale');
		$client = $this->client(new CookieJar());
		$client->get($this->loginFor($email));
		$this->assertSame('t_stale', $this->whoAmI($client));
		// the owner (or an admin) unlinks it, the next wi click offers a claim, the SSO session cannot confirm it
		$this->db->getQueryBuilder()->delete('wisso_links')->executeStatement();
		$url = $this->loginFor($email);
		$this->assertStringContainsString('/sso/claim?', $url);
		[$r, $f] = $this->view($client, $url);
		$this->assertSame(200, $r->getStatusCode());
		$this->assertStringContainsString('sign out and sign in again', (string)$r->getBody());
		$this->assertArrayNotHasKey('claim_token', $f);
		$this->assertNothingLinked();
	}

	private function linkNowByCall(string $email, string $uid): void {
		$store = new \OCA\Wisso\Service\LinkStore($this->db, \OCP\Server::get(\OCP\AppFramework\Utility\ITimeFactory::class));
		$this->assertTrue($store->insert($uid, hash('sha256', strtolower($email))));
	}

	public function testAccountStateChangesBeforeTheConfirmAreCaught(): void {
		$changes = [
			'e-mail edited away' => fn () => $this->um->get('t_claim')->setSystemEMailAddress('else@wi.example'),
			'became admin' => fn () => $this->groups->get('admin')->addUser($this->um->get('t_claim')),
			'2FA enrolled' => fn () => $this->enrolTwoFactor($this->um->get('t_claim')),
		];
		foreach ($changes as $label => $change) {
			$this->holder();
			$client = $this->webLogin('t_claim');
			[$page, $f] = $this->view($client);
			$this->assertSame(200, $page->getStatusCode(), $label);
			$change();
			$r = $this->post($client, $f);
			$this->assertNotContains($r->getStatusCode(), [302, 303], $label);
			$this->assertNothingLinked();
			$this->tearDown();
			$this->setUp();
		}
	}

	public function testCodeReplacedBetweenViewAndConfirmCannotBeUsed(): void {
		$this->holder();
		$client = $this->webLogin('t_claim');
		[, $f] = $this->view($client);
		$this->loginFor($this->email); // issues a new claim code, the old one is gone
		$r = $this->post($client, $f);
		$this->assertSame(400, $r->getStatusCode());
		$this->assertNothingLinked();
	}

	public function testExpiredCodeIsAnExpiredLinkPage(): void {
		$this->holder();
		$this->expireCodes();
		$client = $this->webLogin('t_claim');
		[$r] = $this->view($client);
		$this->assertSame(400, $r->getStatusCode());
		$this->assertStringContainsString('This link has expired', (string)$r->getBody());
	}

	public function testHeadAndPrefetchDoNotReadTheClaimCode(): void {
		$this->holder();
		$client = $this->client(new CookieJar());
		$this->assertSame(204, $client->head($this->claimUrl)->getStatusCode());
		$this->assertSame(204, $client->get($this->claimUrl, ['headers' => ['Sec-Purpose' => 'prefetch']])->getStatusCode());
		$this->assertSame(1, $this->countRows('wisso_codes'));
	}

	public function testAlreadyLinkedAccountGoesStraightOn(): void {
		$this->holder();
		$client = $this->webLogin('t_claim');
		[, $f] = $this->view($client);
		$this->post($client, $f);
		// a second claim URL for the same link (an old tab): no confirmation needed
		$qb = $this->db->getQueryBuilder();
		$qb->insert('wisso_codes')->values([
			'code_hash' => $qb->createNamedParameter(hash('sha256', str_repeat('Q', 43))),
			'type' => $qb->createNamedParameter('claim'),
			'uid' => $qb->createNamedParameter('t_claim'),
			'email_sha256' => $qb->createNamedParameter(hash('sha256', strtolower($this->email))),
			'expires_at' => $qb->createNamedParameter(time() + 100),
		])->executeStatement();
		$r = $client->get(self::APP . 'sso/claim?code=' . str_repeat('Q', 43));
		$this->assertContains($r->getStatusCode(), [302, 303]);
		$this->assertSame(1, $this->countRows('wisso_links'));
	}

	public function testTamperedToIsNormalisedOnPost(): void {
		$this->holder();
		$client = $this->webLogin('t_claim');
		[, $f] = $this->view($client);
		$f['to'] = 'https://evil.example/';
		$r = $this->post($client, $f);
		$this->assertSame(self::BASE . '/index.php/apps/dashboard/', $r->getHeaderLine('Location'));
	}

	/** Every user-facing page carries Arabic and English text. */
	public function testEveryPageIsBilingual(): void {
		$this->holder();
		$bodies = [];
		$bodies['sign in'] = (string)$this->client(new CookieJar())->get($this->claimUrl)->getBody();
		$bodies['expired'] = (string)$this->client(new CookieJar())->get(self::APP . 'sso/claim?code=' . str_repeat('A', 43))->getBody();
		$owner = $this->webLogin('t_claim');
		[$confirm] = $this->view($owner);
		$bodies['confirm'] = (string)$confirm->getBody();
		$this->mkUser('t_claim_b', $this->randomEmail());
		[$other] = $this->view($this->webLogin('t_claim_b'));
		$bodies['other user'] = (string)$other->getBody();
		foreach ($bodies as $label => $body) {
			$this->assertMatchesRegularExpression('/\p{Arabic}{3,}/u', $body, "$label: Arabic text");
			$this->assertMatchesRegularExpression('/[A-Za-z]{4,}/', strip_tags($body), "$label: English text");
			$this->assertStringContainsString('lang="ar" dir="rtl"', $body, $label);
			$this->assertStringContainsString('lang="en" dir="ltr"', $body, $label);
		}
	}
}
