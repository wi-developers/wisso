<?php

declare(strict_types=1);

namespace OCA\Wisso\Service;

use OCP\IAppConfig;

/**
 * The wi bearer key. The server stores only sha256 hex of the key, in a sensitive app config value.
 * Unset, or not 64 lowercase hex characters, means every call is refused (fail closed).
 */
class KeyChecker {
	public const APP = 'wisso';
	public const CONFIG_KEY = 'key_sha256';
	public const TOKEN_PATTERN = '/^[A-Za-z0-9._~+\/=-]{43,128}$/D';
	public const HASH_PATTERN = '/^[0-9a-f]{64}$/D';

	public function __construct(private IAppConfig $appConfig) {
	}

	public static function isHashShape(string $value): bool {
		return preg_match(self::HASH_PATTERN, $value) === 1;
	}

	private function storedHash(): string {
		return $this->appConfig->getValueString(self::APP, self::CONFIG_KEY, '');
	}

	public function isConfigured(): bool {
		return self::isHashShape($this->storedHash());
	}

	/** @return string|null the bearer token of an Authorization header, null when there is none */
	public static function bearer(string $header): ?string {
		if (preg_match('/^Bearer (\S+)$/D', trim($header), $m) !== 1) {
			return null;
		}
		return $m[1];
	}

	public function matches(string $token): bool {
		$stored = $this->storedHash();
		if (!self::isHashShape($stored) || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
			return false;
		}
		return hash_equals($stored, hash('sha256', $token));
	}

	/** Stores a hash. Refuses anything that is not 64 lowercase hex (a raw key pasted into the hash field). */
	public function setHash(string $hash): void {
		if (!self::isHashShape($hash)) {
			throw new \InvalidArgumentException('not a sha256 hex digest');
		}
		$this->appConfig->setValueString(self::APP, self::CONFIG_KEY, $hash, false, true);
	}

	/** @return array{0: string, 1: string} raw key (43 chars, 32 random bytes) and its hash */
	public static function generate(): array {
		$raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		return [$raw, hash('sha256', $raw)];
	}
}
