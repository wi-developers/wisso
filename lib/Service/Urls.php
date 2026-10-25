<?php

declare(strict_types=1);

namespace OCA\Wisso\Service;

use OCP\IConfig;

/** Absolute URLs are built from `overwrite.cli.url`, never from the request Host. */
class Urls {
	public function __construct(private IConfig $config) {
	}

	/** @throws \RuntimeException when overwrite.cli.url is unset or not an https URL */
	public function base(): string {
		$base = rtrim($this->config->getSystemValueString('overwrite.cli.url', ''), '/');
		if (preg_match('#^https://[A-Za-z0-9.-]+(?::\d{1,5})?(?:/[A-Za-z0-9._~/-]*)?$#D', $base) !== 1) {
			throw new \RuntimeException('overwrite.cli.url is not usable');
		}
		return $base;
	}

	public function appUrl(string $path): string {
		return $this->base() . '/index.php/apps/wisso/' . ltrim($path, '/');
	}

	public function loginUrl(): string {
		return $this->base() . '/index.php/login';
	}

	public function destination(string $normalizedPath): string {
		return $this->base() . $normalizedPath;
	}
}
