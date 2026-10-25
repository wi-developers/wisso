<?php

declare(strict_types=1);

namespace OCA\Wisso\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * One-time codes in a table (a cache cannot give an atomic get-and-forget). Only sha256 of the code is stored.
 * Two disjoint types: 'login' (60 s) and 'claim' (300 s). Every lookup filters on the type.
 */
class CodeStore {
	public const TYPE_LOGIN = 'login';
	public const TYPE_CLAIM = 'claim';
	public const TTL = [self::TYPE_LOGIN => 60, self::TYPE_CLAIM => 300];
	public const CODE_PATTERN = '/^[A-Za-z0-9_-]{43}$/D';

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $time,
	) {
	}

	/** Issues a code and removes the older codes of the same account and type. Returns the plain code. */
	public function issue(string $type, string $uid, string $emailHash): string {
		$this->assertType($type);
		$this->purgeExpired();
		$del = $this->db->getQueryBuilder();
		$del->delete('wisso_codes')->where($del->expr()->eq('uid', $del->createNamedParameter($uid)))
			->andWhere($del->expr()->eq('type', $del->createNamedParameter($type)));
		$del->executeStatement();

		$code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		$ins = $this->db->getQueryBuilder();
		$ins->insert('wisso_codes')->values([
			'code_hash' => $ins->createNamedParameter(hash('sha256', $code)),
			'type' => $ins->createNamedParameter($type),
			'uid' => $ins->createNamedParameter($uid),
			'email_sha256' => $ins->createNamedParameter($emailHash),
			'expires_at' => $ins->createNamedParameter($this->time->getTime() + self::TTL[$type]),
		]);
		$ins->executeStatement();
		return $code;
	}

	/** Reads a live code without consuming it. @return array{id: int, uid: string, email_sha256: string}|null */
	public function peek(string $code, string $type): ?array {
		$this->assertType($type);
		if (preg_match(self::CODE_PATTERN, $code) !== 1) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'uid', 'email_sha256')->from('wisso_codes')
			->where($qb->expr()->eq('code_hash', $qb->createNamedParameter(hash('sha256', $code))))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($this->time->getTime())));
		$row = $qb->executeQuery()->fetch();
		if ($row === false) {
			return null;
		}
		return ['id' => (int)$row['id'], 'uid' => (string)$row['uid'], 'email_sha256' => (string)$row['email_sha256']];
	}

	/**
	 * Consumes a code: success only when exactly one row went. Concurrent presenters race on the DELETE, one wins.
	 * @return array{uid: string, email_sha256: string}|null
	 */
	public function consume(string $code, string $type): ?array {
		$row = $this->peek($code, $type);
		if ($row === null) {
			return null;
		}
		$del = $this->db->getQueryBuilder();
		$del->delete('wisso_codes')
			->where($del->expr()->eq('id', $del->createNamedParameter($row['id'])))
			->andWhere($del->expr()->eq('code_hash', $del->createNamedParameter(hash('sha256', $code))))
			->andWhere($del->expr()->eq('type', $del->createNamedParameter($type)))
			->andWhere($del->expr()->gt('expires_at', $del->createNamedParameter($this->time->getTime())));
		if ($del->executeStatement() !== 1) {
			return null;
		}
		return ['uid' => $row['uid'], 'email_sha256' => $row['email_sha256']];
	}

	public function purgeExpired(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('wisso_codes')->where($qb->expr()->lte('expires_at', $qb->createNamedParameter($this->time->getTime())));
		$qb->executeStatement();
	}

	public function deleteForUid(string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('wisso_codes')->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$qb->executeStatement();
	}

	public function count(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('wisso_codes')
			->where($qb->expr()->gt('expires_at', $qb->createNamedParameter($this->time->getTime())));
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function assertType(string $type): void {
		if (!isset(self::TTL[$type])) {
			throw new \InvalidArgumentException('unknown code type');
		}
	}
}
