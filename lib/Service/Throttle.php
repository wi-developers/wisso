<?php

declare(strict_types=1);

namespace OCA\Wisso\Service;

use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;

/**
 * Soft limits on Nextcloud's own limiter (its Limiter wants an IP; the buckets here are not per IP, so a fixed
 * address is passed and the bucket is named in the identifier). A request with an invalid key never reaches
 * this class: a bad key is always 401 and never fills a bucket, so it cannot lock the real key out.
 */
class Throttle {
	private const BUCKET_ADDRESS = '127.0.0.1';

	public function __construct(private ILimiter $limiter) {
	}

	/** @throws SsoRefusal 429 */
	public function apiCall(string $emailHash): void {
		try {
			$this->limiter->registerAnonRequest('wisso_global', 120, 60, self::BUCKET_ADDRESS);
			$this->limiter->registerAnonRequest('wisso_email_' . $emailHash, 10, 60, self::BUCKET_ADDRESS);
		} catch (IRateLimitExceededException) {
			throw new SsoRefusal('rate', 429);
		}
	}

	/** Browser routes: per client address (forgeable behind a proxy, harmless against a 256-bit code). */
	public function browser(string $bucket, int $limit, string $ip): bool {
		try {
			$this->limiter->registerAnonRequest($bucket, $limit, 60, $ip);
			return true;
		} catch (IRateLimitExceededException) {
			return false;
		}
	}

	public function perUser(string $bucket, int $limit, string $uid): bool {
		try {
			$this->limiter->registerAnonRequest($bucket . '_' . hash('sha256', $uid), $limit, 60, self::BUCKET_ADDRESS);
			return true;
		} catch (IRateLimitExceededException) {
			return false;
		}
	}
}
