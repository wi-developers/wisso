<?php

declare(strict_types=1);

namespace OCA\Wisso\Service;

use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * One line per outcome: event name, reason code, a short reference of the account id and the request id.
 * Never the key, a code, an e-mail or a name. Production runs loglevel 2 (warning), so every outcome is
 * logged at warning on purpose: at a lower level the lines would be invisible there.
 */
class SsoLog {
	public function __construct(
		private LoggerInterface $logger,
		private IRequest $request,
	) {
	}

	/** Short, non-reversible reference of an account id (ids can look like e-mail addresses). */
	public static function ref(string $uid): string {
		return substr(hash('sha256', $uid), 0, 12);
	}

	public function event(string $event, ?string $reason = null, ?string $uid = null, array $extra = []): void {
		$line = 'wisso event=' . $event;
		if ($reason !== null) {
			$line .= ' reason=' . $reason;
		}
		if ($uid !== null) {
			$line .= ' uid_ref=' . self::ref($uid);
		}
		foreach ($extra as $k => $v) {
			$line .= ' ' . preg_replace('/[^a-z_]/', '', (string)$k) . '=' . preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$v);
		}
		$line .= ' rid=' . preg_replace('/[^A-Za-z0-9]/', '', $this->request->getId());
		$this->logger->warning($line, ['app' => 'wisso']);
	}
}
