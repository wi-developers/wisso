<?php

declare(strict_types=1);

use OCP\IUser;
use OCP\Server;

/** The update call: linked accounts only, never creates, name and new_email rules. */
class UpdateTest extends WissoTestCase {
	private function linked(string $uid = 't_upd', string $display = 'Before'): array {
		$email = $this->randomEmail('upd');
		$this->mkUser($uid, $email, $display);
		$store = new \OCA\Wisso\Service\LinkStore($this->db, Server::get(\OCP\AppFramework\Utility\ITimeFactory::class));
		$this->assertTrue($store->insert($uid, hash('sha256', strtolower($email))));
		return [$uid, $email];
	}

	private function displayName(string $uid): string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('displayname')->from('users')->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		return (string)$qb->executeQuery()->fetchOne();
	}

	private function ok(array $form): void {
		$r = $this->apiPost('update', $form);
		$this->assertSame(200, $r->getStatusCode(), (string)$r->getBody());
		$this->assertSame('{"data":{}}', (string)$r->getBody());
	}

	public function testUnknownEmailIs404AndCreatesNothing(): void {
		$r = $this->apiPost('update', ['email' => $this->randomEmail(), 'name' => 'Ghost']);
		$this->assertErrorShape($r, 404);
		$this->assertSame(0, $this->countRows('wisso_links'));
		$this->assertCount(0, array_filter($this->um->search('wi_'), fn (IUser $u) => str_starts_with($u->getUID(), 'wi_')));
	}

	public function testUnlinkedAccountWithTheSameEmailIsNotUpdated(): void {
		$email = $this->randomEmail('unl');
		$this->mkUser('t_unl', $email, 'Keep Me');
		$this->assertErrorShape($this->apiPost('update', ['email' => $email, 'name' => 'Changed']), 404);
		$this->assertSame('Keep Me', $this->displayName('t_unl'));
	}

	public function testNameIsUpdatedCleanedAndCut(): void {
		[$uid, $email] = $this->linked();
		$this->ok(['email' => $email, 'name' => "New\x00 Name\u{202E}"]);
		$this->assertSame('New Name', $this->displayName($uid));
		$this->ok(['email' => $email, 'name' => str_repeat('م', 100)]);
		$this->assertSame(str_repeat('م', 64), $this->displayName($uid));
		$this->ok(['email' => $email, 'name' => '']);
		$this->assertSame(str_repeat('م', 64), $this->displayName($uid), 'an empty name keeps the old one');
	}

	public function testNewEmailMovesTheLinkAndTheProfileFollows(): void {
		[$uid, $email] = $this->linked();
		$new = $this->randomEmail('moved');
		$this->ok(['email' => $email, 'new_email' => strtoupper($new)]);
		$this->assertSame($uid, $this->linkedUid($new));
		$this->assertNull($this->linkedUid($email));
		$this->assertSame(strtolower($new), $this->profileEmail($uid));
		// the new address signs the same account in; the old one is just a new wi user now
		$this->assertStringContainsString('/sso/consume?', $this->loginFor($new));
		$this->assertSame($uid, $this->linkedUid($new));
		$this->loginFor($email);
		$this->assertNotSame($uid, $this->linkedUid($email));
	}

	public function testProfileEmailTheUserChangedAwayIsLeftAlone(): void {
		[$uid, $email] = $this->linked();
		$this->um->get($uid)->setSystemEMailAddress('mine@wi.example');
		$new = $this->randomEmail('moved');
		$this->ok(['email' => $email, 'new_email' => $new]);
		$this->assertSame($uid, $this->linkedUid($new));
		$this->assertSame('mine@wi.example', $this->profileEmail($uid));
	}

	public function testNewEmailCollisionsAre422AndNothingChanges(): void {
		[$uid, $email] = $this->linked();
		[, $other] = $this->linked('t_upd_other');
		// linked to another account
		$this->assertErrorShape($this->apiPost('update', ['email' => $email, 'new_email' => $other]), 422);
		// held in the profile of an unlinked account
		$held = $this->randomEmail('held');
		$this->mkUser('t_upd_held', $held);
		$this->assertErrorShape($this->apiPost('update', ['email' => $email, 'new_email' => $held]), 422);
		// held in the profile only in another letter case
		$this->assertErrorShape($this->apiPost('update', ['email' => $email, 'new_email' => strtoupper($held)]), 422);
		$this->assertSame($uid, $this->linkedUid($email));
		$this->assertNull($this->linkedUid($held));
		$this->assertSame($email, $this->profileEmail($uid));
	}

	public function testNewEmailEqualToTheCurrentOneIsHarmless(): void {
		[$uid, $email] = $this->linked();
		$this->ok(['email' => $email, 'new_email' => $email]);
		$this->assertSame($uid, $this->linkedUid($email));
	}

	public function testBadKeyIs401AndChangesNothing(): void {
		[$uid, $email] = $this->linked();
		$r = $this->apiPost('update', ['email' => $email, 'name' => 'Hacked', 'new_email' => $this->randomEmail()], 'x' . str_repeat('a', 50));
		$this->assertErrorShape($r, 401);
		$this->assertSame('Before', $this->displayName($uid));
		$this->assertSame($uid, $this->linkedUid($email));
	}

	public function testDisabledAccountIsRefusedOnUpdate(): void {
		[$uid, $email] = $this->linked();
		$this->um->get($uid)->setEnabled(false);
		$this->assertErrorShape($this->apiPost('update', ['email' => $email, 'name' => 'X']), 403);
		$this->assertSame('Before', $this->displayName($uid));
	}
}
