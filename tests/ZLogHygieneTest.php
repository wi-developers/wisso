<?php

declare(strict_types=1);

/**
 * Runs last (file name order). Reads every wisso line the whole run wrote to nextcloud.log and checks it for
 * the key, the passwords, the e-mail addresses and names the tests used. Nextcloud adds the request URL to
 * every log line's context, so a one-time code can appear in that url field; it must never be in the message.
 */
class ZLogHygieneTest extends WissoTestCase {
	/** @return list<array<string,mixed>> */
	private function wissoLines(): array {
		$out = [];
		foreach (explode("\n", $this->nextcloudLog()) as $line) {
			if ($line === '' || !str_contains($line, '"message":"wisso event=')) {
				continue;
			}
			$data = json_decode($line, true);
			$this->assertIsArray($data, 'log line is not JSON');
			$out[] = $data;
		}
		return $out;
	}

	public function testTheRunLeftWissoLogLinesToCheck(): void {
		$this->assertGreaterThan(100, count($this->wissoLines()), 'the earlier tests should have written many outcome lines');
	}

	public function testWissoMessagesHaveTheFixedShapeAndNoValues(): void {
		$this->assertNotEmpty($this->wissoLines(), 'no wisso line to judge: the test would pass for nothing');
		foreach ($this->wissoLines() as $d) {
			$msg = (string)$d['message'];
			$this->assertMatchesRegularExpression(
				'/^wisso event=[a-z_]+( reason=[a-z_]+)?( uid_ref=[0-9a-f]{12})?( [a-z_]+=[A-Za-z0-9_.-]+)* rid=[A-Za-z0-9]+$/D',
				$msg,
				'unexpected message shape: ' . substr($msg, 0, 120)
			);
			// the generic key=value tail would also accept a raw uid under the name uid_ref: pin the reference itself
			if (preg_match_all('/ uid_ref=(\S+)/', $msg, $refs)) {
				$this->assertCount(1, $refs[1], 'uid_ref appears more than once: ' . $msg);
				$this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/D', $refs[1][0], 'uid_ref is not a 12-hex reference: ' . $msg);
			}
		}
	}

	public function testNoSecretAppearsInAnyWissoLineExceptCodesInTheUrlContext(): void {
		$secrets = array_values(array_unique(array_filter(self::$secrets, fn ($s) => strlen($s) >= 6)));
		$this->assertGreaterThan(1, count($secrets), 'no secret was used before this test');
		$this->assertNotEmpty($this->wissoLines(), 'no wisso line to judge: the test would pass for nothing');
		$codes = array_filter($secrets, fn ($s) => preg_match('/^[A-Za-z0-9_-]{43}$/', $s) === 1);
		foreach ($this->wissoLines() as $d) {
			$url = (string)($d['url'] ?? '');
			$d['url'] = '';
			$flat = json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			foreach ($secrets as $s) {
				$this->assertStringNotContainsString($s, $flat, 'a test secret reached a wisso log line (outside the url context)');
			}
			// the url context itself may only hold codes, never a key, e-mail or password
			foreach (array_diff($secrets, $codes) as $s) {
				$this->assertStringNotContainsString($s, $url, 'a non-code secret is in the request url of a log line');
			}
		}
	}

	public function testNoRawKeyOrHashIsInTheWholeLogEvenFromOtherApps(): void {
		$log = $this->nextcloudLog();
		$this->assertStringNotContainsString($this->key, $log);
		$this->assertStringNotContainsString(hash('sha256', $this->key), $log);
	}

	public function testEveryEventNameAndReasonIsOneOfTheDocumentedOnes(): void {
		$events = ['login_ok', 'claim_offered', 'user_created', 'consume_ok', 'consume_fail', 'claim_linked', 'claim_denied',
			'update_ok', 'update_denied', 'login_denied', 'unlinked', 'key_generated', 'bad_key_summary'];
		foreach ($this->wissoLines() as $d) {
			preg_match('/event=([a-z_]+)/', (string)$d['message'], $m);
			$this->assertContains($m[1], $events, (string)$d['message']);
		}
	}
}
