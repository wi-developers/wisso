<?php

declare(strict_types=1);

namespace OCA\Wisso\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/** oc_wisso_links(uid, wi_email_sha256, linked_at): which Nextcloud account belongs to which wi e-mail. */
class LinkStore {
	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $time,
	) {
	}

	public function uidForEmailHash(string $hash): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('uid')->from('wisso_links')->where($qb->expr()->eq('wi_email_sha256', $qb->createNamedParameter($hash)));
		$uid = $qb->executeQuery()->fetchOne();
		return $uid === false ? null : (string)$uid;
	}

	public function hashForUid(string $uid): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('wi_email_sha256')->from('wisso_links')->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$h = $qb->executeQuery()->fetchOne();
		return $h === false ? null : (string)$h;
	}

	/** @return bool false when the uid or the e-mail hash is already linked */
	public function insert(string $uid, string $hash): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('wisso_links')->values([
			'uid' => $qb->createNamedParameter($uid),
			'wi_email_sha256' => $qb->createNamedParameter($hash),
			'linked_at' => $qb->createNamedParameter($this->time->getTime()),
		]);
		try {
			$qb->executeStatement();
			return true;
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}
			throw $e;
		}
	}

	/** Moves an existing link to a new wi e-mail. False when the new e-mail is linked to another account. */
	public function rekey(string $uid, string $newHash): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update('wisso_links')
			->set('wi_email_sha256', $qb->createNamedParameter($newHash))
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		try {
			return $qb->executeStatement() === 1;
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}
			throw $e;
		}
	}

	public function deleteForUid(string $uid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('wisso_links')->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		return $qb->executeStatement();
	}

	public function count(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('wisso_links');
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** @return list<array{uid: string, linked_at: int}> */
	public function all(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('uid', 'linked_at')->from('wisso_links')->orderBy('linked_at');
		$rows = [];
		foreach ($qb->executeQuery()->fetchAll() as $r) {
			$rows[] = ['uid' => (string)$r['uid'], 'linked_at' => (int)$r['linked_at']];
		}
		return $rows;
	}
}
