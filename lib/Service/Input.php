<?php

declare(strict_types=1);

namespace OCA\Wisso\Service;

/**
 * Input rules for the wi calls. wi sends whatever the wi user typed; nothing here trusts it.
 */
class Input {
	public const EMAIL_MAX = 255;
	public const NAME_MAX = 100;
	/** oc_users.displayname is varchar(64) */
	public const DISPLAY_NAME_MAX = 64;

	/**
	 * Canonical e-mail E = lowercase(trim(input)), printable ASCII only, RFC-valid, at most 255 characters.
	 * Anything else is rejected (a look-alike or accented address must never match a collation-insensitive lookup).
	 */
	public static function canonicalEmail(mixed $value): ?string {
		if (!is_string($value)) {
			return null;
		}
		$e = strtolower(trim($value));
		if ($e === '' || strlen($e) > self::EMAIL_MAX) {
			return null;
		}
		if (preg_match('/^[\x21-\x7E]+$/D', $e) !== 1) {
			return null;
		}
		if (filter_var($e, FILTER_VALIDATE_EMAIL) === false) {
			return null;
		}
		return $e;
	}

	/**
	 * Optional name: trimmed, control and format characters stripped, at most 100 characters.
	 * Returns '' when absent, null when invalid (not a string, bad UTF-8, too long).
	 */
	public static function cleanName(mixed $value): ?string {
		if ($value === null) {
			return '';
		}
		if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
			return null;
		}
		$clean = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', '', $value);
		if ($clean === null) {
			return null;
		}
		$clean = trim($clean);
		if (mb_strlen($clean) > self::NAME_MAX) {
			return null;
		}
		return $clean;
	}

	/** The value stored as the Nextcloud display name (the column holds 64 characters). */
	public static function displayName(string $name, string $email): string {
		if ($name === '') {
			$name = explode('@', $email, 2)[0];
		}
		return mb_substr($name, 0, self::DISPLAY_NAME_MAX);
	}

	public static function emailHash(string $canonicalEmail): string {
		return hash('sha256', $canonicalEmail);
	}

	/** Canonical form of a stored Nextcloud profile e-mail, or null when it has none. */
	public static function storedEmail(?string $stored): ?string {
		return $stored === null || $stored === '' ? null : strtolower(trim($stored));
	}
}
