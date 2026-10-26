<?php

declare(strict_types=1);

use OCP\Group\ISubAdmin;
use OCP\IUser;
use OCP\Server;

/** Accounts that must be refused with one generic 403, and the e-mail squatting cases. */
class RefusalTest extends WissoTestCase {
	private const FORBIDDEN = '{"errors":[{"title":"Forbidden."}]}';

	private function assertRefused(string $email): void {
		$r = $this->apiPost('login', ['email' => $email, 'name' => 'X']);
		$this->assertErrorShape($r, 403);
		$this->assertSame(self::FORBIDDEN, (string)$r->getBody(), 'one generic body for every reason');
		$this->assertSame(0, $this->countRows('wisso_codes'), 'no code issued');
	}

	/** Each way of being privileged or unsafe, as a closure that prepares the account. @return array<string, callable> */
	private function badStates(): array {
		return [
			'admin' => fn (IUser $u) => $this->groups->get('admin')->addUser($u),
			'delegated admin' => function (IUser $u): void {
				$g = $this->groups->createGroup('t_delegated');
				$this->madeGroups[] = 't_delegated';
				$g->addUser($u);
				$mapper = Server::get(\OC\Settings\AuthorizedGroupMapper::class);
				$row = new \OC\Settings\AuthorizedGroup();
				$row->setGroupId('t_delegated');
				$row->setClass('OCA\\Settings\\Settings\\Admin\\Sharing');
				$mapper->insert($row);
				$this->onTearDown(fn () => $mapper->removeGroup('t_delegated'));
			},
			'sub admin' => function (IUser $u): void {
				$g = $this->groups->createGroup('t_subadmin_of');
				$this->madeGroups[] = 't_subadmin_of';
				Server::get(ISubAdmin::class)->createSubAdmin($u, $g);
			},
			'disabled' => fn (IUser $u) => $u->setEnabled(false),
			'two factor enrolled' => fn (IUser $u) => $this->enrolTwoFactor($u),
		];
	}

	public function testPrivilegedAndUnsafeAccountsAreRefusedBeforeAnyLink(): void {
		foreach ($this->badStates() as $label => $prepare) {
			$email = $this->randomEmail('bad');
			$u = $this->mkUser('t_bad', $email);
			$prepare($u);
			$this->assertRefused($email);
			$this->assertSame(0, $this->countRows('wisso_links'), $label);
			$this->tearDown();
			$this->setUp();
		}
	}

	public function testLinkedAccountThatBecomesPrivilegedIsRefusedAtLogin(): void {
		foreach ($this->badStates() as $label => $prepare) {
			$email = $this->randomEmail('lk');
			$u = $this->mkUser('t_lk', $email);
			$this->linkNow($u, $email);
			$prepare($u);
			$this->assertRefused($email);
			$this->tearDown();
			$this->setUp();
		}
	}

	public function testPrivilegedAccountIsRefusedOnUpdateToo(): void {
		$email = $this->randomEmail('up');
		$u = $this->mkUser('t_up', $email);
		$this->linkNow($u, $email);
		$this->groups->get('admin')->addUser($u);
		$r = $this->apiPost('update', ['email' => $email, 'name' => 'Hacked', 'new_email' => $this->randomEmail()]);
		$this->assertErrorShape($r, 403);
		$this->assertSame('Test User', $this->um->get('t_up')->getDisplayName());
	}

	public function testTwoAccountsWithTheSameEmailAreAmbiguous(): void {
		$email = $this->randomEmail('dup');
		$this->mkUser('t_dup1', $email);
		$this->mkUser('t_dup2', $email);
		$this->assertRefused($email);
		$this->assertSame(0, $this->countRows('wisso_links'));
	}

	/** A collation-only match (accent, case, trailing space) is not the same address. */
	public function testLookAlikeProfileEmailIsNotAMatch(): void {
		$this->mkUser('t_look', 'jose@wi.example');
		self::$secrets[] = 'josé@wi.example';
		$this->um->get('t_look')->setSystemEMailAddress('josé@wi.example');
		$r = $this->apiPost('login', ['email' => 'jose@wi.example']);
		$this->assertSame(200, $r->getStatusCode());
		$this->assertStringContainsString('/sso/consume?', $this->json($r)['data']['url'], 'a new account, not the look-alike');
		$this->assertNotSame('t_look', $this->linkedUid('jose@wi.example'));
	}

	// ---- squatting -------------------------------------------------------------------------------------------

	private function editOwnEmailThroughTheApi(string $uid, string $newEmail): void {
		$client = $this->webLogin($uid);
		$r = $client->put(self::BASE . '/ocs/v2.php/cloud/users/' . $uid . '?format=json', [
			'headers' => ['OCS-APIRequest' => 'true'],
			'form_params' => ['key' => 'email', 'value' => $newEmail],
		]);
		$this->assertSame(200, $r->getStatusCode(), 'a user can edit the own profile e-mail: ' . $r->getBody());
		$this->assertSame($newEmail, $this->profileEmail($uid), 'the edit is applied at once, without any verification');
	}

	/** The lever exists (open sign-up plus editable e-mail); the test pins that it only blocks, never signs in. */
	public function testSquatterWithNoVictimAccountOnlyGetsAClaimOfferNobodyCanUse(): void {
		$victim = $this->randomEmail('victim');
		$this->mkUser('t_squat', 'squat@wi.example');
		$this->editOwnEmailThroughTheApi('t_squat', $victim);

		$url = $this->loginFor($victim);
		$this->assertStringContainsString('/sso/claim?', $url, 'offered as a claim, not as a sign-in');
		$jar = new \GuzzleHttp\Cookie\CookieJar();
		$client = $this->client($jar);
		// the code is useless as a sign-in code
		$r = $client->get(str_replace('/sso/claim?', '/sso/consume?', $url));
		$this->assertSame(self::BASE . '/index.php/login', $r->getHeaderLine('Location'));
		$this->assertNull($this->whoAmI($client));
		// and the claim page without the squatter's own signed-in session links nothing
		$page = $client->get($url);
		$this->assertSame(200, $page->getStatusCode());
		$this->assertStringContainsString('Sign in', (string)$page->getBody());
		$this->assertNull($this->whoAmI($client));
		$this->assertSame(0, $this->countRows('wisso_links'));
	}

	/** The mitigation core offers: allow_user_to_change_email=false closes the self-edit paths (owner point D-NC-003 item 3). */
	public function testSelfServiceEmailEditIsBlockedByTheCoreSettingAndAdditionalMailIsNotMatched(): void {
		$this->config->setSystemValue('allow_user_to_change_email', false);
		$this->onTearDown(fn () => $this->config->deleteSystemValue('allow_user_to_change_email'));
		$this->mkUser('t_noedit', 'squat@wi.example');
		$client = $this->webLogin('t_noedit');
		$put = fn (string $key, string $value) => $client->put(self::BASE . '/ocs/v2.php/cloud/users/t_noedit?format=json', [
			'headers' => ['OCS-APIRequest' => 'true'],
			'form_params' => ['key' => $key, 'value' => $value],
		]);
		$victim = $this->randomEmail('victim');
		$this->assertSame(113, json_decode((string)$put('email', $victim)->getBody(), true)['ocs']['meta']['statuscode']);
		$this->assertSame('squat@wi.example', $this->profileEmail('t_noedit'));
		// an additional address is accepted by core but is not the matching field, so it cannot squat
		$extra = $this->randomEmail('extra');
		$put('additional_mail', $extra);
		$this->assertStringContainsString('/sso/consume?', $this->loginFor($extra), 'a fresh account, not t_noedit');
		$this->assertNotSame('t_noedit', $this->linkedUid($extra));
	}

	public function testSquatterNextToAnExistingVictimAccountBlocksOnlyTheVictim(): void {
		$victim = $this->randomEmail('victim');
		$this->mkUser('t_victim', $victim);
		$this->mkUser('t_squat', 'squat@wi.example');
		$this->editOwnEmailThroughTheApi('t_squat', $victim);

		$this->assertRefused($victim);
		$this->assertSame(0, $this->countRows('wisso_links'));
		$this->assertNull($this->linkedUid($victim), 'neither account is linked or signed in');
	}

	public function testLinkedAccountWhoseProfileEmailIsEditedToAVictimBlocksOnlyTheVictim(): void {
		$mine = $this->randomEmail('mine');
		$a = $this->mkUser('t_att', $mine);
		$this->linkNow($a, $mine);
		$victim = $this->randomEmail('victim');
		$a->setSystemEMailAddress($victim);

		$this->assertRefused($victim);
		// the attacker's own wi e-mail still signs the attacker in, whatever the profile says
		$this->assertSame(200, $this->apiPost('login', ['email' => $mine])->getStatusCode());
	}

	public function testProfileEmailChangeNeitherGrantsNorRemovesAccess(): void {
		$wi = $this->randomEmail('wi');
		$u = $this->mkUser('t_prof', $wi);
		$this->linkNow($u, $wi);
		$u->setSystemEMailAddress('somewhere-else@wi.example');
		$this->assertSame(200, $this->apiPost('login', ['email' => $wi])->getStatusCode());
		$url = $this->json($this->apiPost('login', ['email' => $wi]))['data']['url'];
		$this->assertStringContainsString('/sso/consume?', $url);
	}

	// ---- link lifecycle --------------------------------------------------------------------------------------

	public function testStaleLinkOfAMissingAccountIsDroppedAndAFreshAccountIsMade(): void {
		$email = $this->randomEmail('ghost');
		$hash = hash('sha256', strtolower($email));
		$qb = $this->db->getQueryBuilder();
		$qb->insert('wisso_links')->values([
			'uid' => $qb->createNamedParameter('t_no_such_account'),
			'wi_email_sha256' => $qb->createNamedParameter($hash),
			'linked_at' => $qb->createNamedParameter(time()),
		])->executeStatement();
		$this->loginFor($email);
		$uid = $this->linkedUid($email);
		$this->assertNotSame('t_no_such_account', $uid);
		$this->assertNotNull($this->um->get($uid));
	}

	public function testDeletingAnAccountRemovesItsLinkAndCodes(): void {
		$email = $this->randomEmail('del');
		$this->loginFor($email);
		$uid = $this->linkedUid($email);
		$this->assertSame(1, $this->countRows('wisso_codes'));
		$this->um->get($uid)->delete();
		$this->assertSame(0, $this->countRows('wisso_links'));
		$this->assertSame(0, $this->countRows('wisso_codes'));
	}

	/** After a delete, someone who registers the freed account id must not be reachable through the old wi e-mail. */
	public function testReusedAccountIdDoesNotInheritTheOldLink(): void {
		$email = $this->randomEmail('reuse');
		$this->loginFor($email);
		$uid = $this->linkedUid($email);
		$this->um->get($uid)->delete();
		$this->mkUser($uid, 'attacker@wi.example');
		$url = $this->loginFor($email);
		$this->assertNotSame($uid, $this->linkedUid($email), 'the e-mail now maps to a fresh account');
		$this->assertNotNull($url);
	}

	private function linkNow(IUser $user, string $email): void {
		$store = new \OCA\Wisso\Service\LinkStore($this->db, Server::get(\OCP\AppFramework\Utility\ITimeFactory::class));
		$this->assertTrue($store->insert($user->getUID(), hash('sha256', strtolower($email))));
	}
}
