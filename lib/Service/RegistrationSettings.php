<?php

declare(strict_types=1);

namespace OCA\Wisso\Service;

use OCP\Exceptions\AppConfigUnknownKeyException;
use OCP\IAppConfig;

/**
 * Reads the on/off flags of the registration app whatever type they are stored with: its admin page writes a typed
 * boolean, while occ and older versions write plain strings. A string read of a boolean key throws in Nextcloud 34.
 * Any other stored type still throws, so the caller fails visibly instead of guessing.
 */
class RegistrationSettings {
	private const APP = 'registration';

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function flag(string $key): bool {
		try {
			if ($this->appConfig->getValueType(self::APP, $key) === IAppConfig::VALUE_BOOL) {
				return $this->appConfig->getValueBool(self::APP, $key);
			}
		} catch (AppConfigUnknownKeyException) {
			return false;
		}
		return in_array(strtolower($this->appConfig->getValueString(self::APP, $key, '')), ['1', 'yes', 'true', 'on'], true);
	}

	public function allowedDomains(): string {
		return $this->appConfig->getValueString(self::APP, 'allowed_domains', '');
	}
}
