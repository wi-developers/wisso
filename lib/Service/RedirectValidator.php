<?php

declare(strict_types=1);

namespace OCA\Wisso\Service;

use OCP\IConfig;

/**
 * The redirect target. Applied at login (to build `to`) and again at consume (`to` sits in a URL).
 * Only a path under /apps/<app>/ is accepted; the output is always re-assembled from the matched text.
 */
class RedirectValidator {
	public const PATTERN = '#^/(?:index\.php/)?(apps/[a-z0-9_]+(?:/[A-Za-z0-9_-]+)*)/?$#D';
	public const MAX_LENGTH = 200;

	public function __construct(private IConfig $config) {
	}

	public function defaultPath(): string {
		$first = explode(',', $this->config->getSystemValueString('defaultapp', 'dashboard,files'))[0];
		$first = trim($first);
		if (preg_match('/^[a-z0-9_]+$/D', $first) !== 1) {
			$first = 'dashboard';
		}
		return '/index.php/apps/' . $first . '/';
	}

	public function normalize(mixed $input): string {
		if (!is_string($input) || $input === '' || strlen($input) > self::MAX_LENGTH) {
			return $this->defaultPath();
		}
		// decode once, then judge the decoded text
		$decoded = rawurldecode($input);
		// the one dot allowed is the front controller's own: it is in our own output, which consume judges again
		$rest = str_starts_with($decoded, '/index.php/') ? substr($decoded, strlen('/index.php')) : $decoded;
		if (preg_match('/[\x00-\x1F\x7F%\\\\.]/', $rest) === 1) {
			return $this->defaultPath();
		}
		if (preg_match(self::PATTERN, $decoded, $m) !== 1) {
			return $this->defaultPath();
		}
		return '/index.php/' . $m[1] . '/';
	}
}
