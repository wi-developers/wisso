<?php

declare(strict_types=1);

namespace OCA\Wisso\Service;

/** A refusal with an internal reason code. The caller answers with one generic text per HTTP status. */
class SsoRefusal extends \RuntimeException {
	public function __construct(public readonly string $reason, public readonly int $status = 403) {
		parent::__construct($reason);
	}
}
