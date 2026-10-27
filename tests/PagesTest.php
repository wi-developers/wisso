<?php

declare(strict_types=1);

use OCA\Wisso\Service\Pages;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\Defaults;
use PHPUnit\Framework\TestCase;

/**
 * The look of the browser pages (D-NC-008): name, logo and colours come from the host's theming, are validated
 * before they reach HTML or CSS, and any failure falls back to the built-in values. In-process: the pages class is
 * built with a stand-in for OCP\Defaults.
 */
class PagesTest extends TestCase {
	private function defaults(array $values, bool $throw = false): Defaults {
		return new class($values, $throw) extends Defaults {
			public function __construct(private array $v, private bool $throw) {
			}

			private function pick(string $k): string {
				if ($this->throw) {
					throw new \RuntimeException('theming broke');
				}
				return $this->v[$k] ?? '';
			}

			public function getName(): string {
				return $this->pick('name');
			}

			public function getLogo(bool $useSvg = true): string {
				return $this->pick('logo');
			}

			public function getColorPrimary(): string {
				return $this->pick('primary');
			}

			public function getTextColorPrimary(): string {
				return $this->pick('on');
			}
		};
	}

	private function html(Pages $pages): DataDisplayResponse {
		$r = $pages->claimSignIn('/index.php/login');
		$this->assertInstanceOf(DataDisplayResponse::class, $r);
		return $r;
	}

	private function csp(DataDisplayResponse $r): string {
		return $r->getContentSecurityPolicy()->buildPolicy();
	}

	public function testThemeValuesReachThePage(): void {
		$r = $this->html(new Pages($this->defaults([
			'name' => 'منصة بث',
			'logo' => '/index.php/apps/theming/image/logo?useSvg=1&v=7',
			'primary' => '#0a7d3b',
			'on' => '#fafafa',
		])));
		$h = $r->render();
		$this->assertStringContainsString('<title>منصة بث</title>', $h);
		$this->assertStringContainsString('src="/index.php/apps/theming/image/logo?useSvg=1&amp;v=7" alt="منصة بث"', $h);
		$this->assertStringContainsString('--primary:#0a7d3b;--on-primary:#fafafa', $h);
		$this->assertStringContainsString("img-src 'self'", $this->csp($r));
		$this->assertStringNotContainsString('wordmark">', $h, 'a logo replaces the text mark');
	}

	public function testNoLogoShowsTheNameAsAWordmarkAndAllowsNoImages(): void {
		$r = $this->html(new Pages($this->defaults(['name' => 'Acme', 'primary' => '#112233', 'on' => '#ffffff'])));
		$h = $r->render();
		$this->assertStringContainsString('<p class="wordmark">Acme</p>', $h);
		$this->assertStringNotContainsString('<img', $h);
		$this->assertStringNotContainsString('img-src', $this->csp($r));
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('hostileLogos')]
	public function testHostileLogoIsNeverUsed(string $logo): void {
		$r = $this->html(new Pages($this->defaults(['name' => 'Path', 'logo' => $logo])));
		$h = $r->render();
		$this->assertStringNotContainsString('<img', $h, $logo);
		$this->assertStringNotContainsString('evil', $h, $logo);
		$this->assertStringNotContainsString('onerror', $h, $logo);
		$this->assertStringNotContainsString('img-src', $this->csp($r), $logo);
	}

	public static function hostileLogos(): array {
		return [
			'javascript scheme' => ['javascript:alert(1)'],
			'data uri' => ['data:image/svg+xml;base64,PHN2Zz4='],
			'protocol relative' => ['//evil.example/x.png'],
			'absolute other host' => ['https://evil.example/x.png'],
			'quote breakout' => ['/x" onerror="alert(1)'],
			'angle bracket' => ['/x><script>alert(1)</script>'],
			'space' => ['/a b.png'],
			'trailing newline' => ["/x.png\n"],
			'relative' => ['logo.png'],
			'too long' => ['/' . str_repeat('a', 300)],
			'empty' => [''],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('hostileColours')]
	public function testHostileColourFallsBack(string $value): void {
		$h = $this->html(new Pages($this->defaults(['primary' => $value, 'on' => $value])))->render();
		$this->assertStringContainsString('--primary:' . Pages::FALLBACK_PRIMARY . ';--on-primary:' . Pages::FALLBACK_ON_PRIMARY, $h, $value);
		$this->assertStringNotContainsString('}body{display', $h, $value);
	}

	public static function hostileColours(): array {
		return [
			'css breakout' => ['red;}body{display:none'],
			'three digits' => ['#fff'],
			'named' => ['red'],
			'url' => ['url(https://evil.example/x)'],
			'no hash' => ['0a7d3b'],
			'trailing newline' => ["#0a7d3b\n"],
			'eight digits' => ['#0a7d3bff'],
			'empty' => [''],
		];
	}

	public function testHostileNameIsEscapedAndALongNameFallsBack(): void {
		$h = $this->html(new Pages($this->defaults(['name' => '<script>alert(1)</script>'])))->render();
		$this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $h);
		$this->assertStringNotContainsString('<script', $h);
		$long = $this->html(new Pages($this->defaults(['name' => str_repeat('x', 61)])))->render();
		$this->assertStringContainsString('<title>' . Pages::FALLBACK_NAME . '</title>', $long);
		$ok = $this->html(new Pages($this->defaults(['name' => str_repeat('x', 60)])))->render();
		$this->assertStringContainsString('<title>' . str_repeat('x', 60) . '</title>', $ok, 'sixty characters is allowed');
		$blank = $this->html(new Pages($this->defaults(['name' => "  \t "])))->render();
		$this->assertStringContainsString('<title>' . Pages::FALLBACK_NAME . '</title>', $blank);
		// the name also lands in the logo's alt attribute
		$alt = $this->html(new Pages($this->defaults(['name' => 'a" onerror="alert(1)', 'logo' => '/l.svg'])))->render();
		$this->assertStringContainsString('<img class="logo"', $alt);
		$this->assertStringNotContainsString('onerror="', $alt);
		$this->assertStringContainsString('alt="a&quot; onerror=&quot;alert(1)"', $alt);
	}

	public function testLinksAndFormValuesAreEscaped(): void {
		$p = new Pages($this->defaults([]));
		$h = $p->claimSignIn('/l"onmouseover="x')->render();
		$this->assertStringContainsString('href="/l&quot;onmouseover=&quot;x"', $h);
		$this->assertStringNotContainsString('onmouseover="x', $h);
		$c = $p->claimConfirm('<u>n</u>', '<i>x</i>@e.org', 'a"b', 't"o', 'c"t', 'r"t', '/c"x')->render();
		$this->assertStringContainsString('&lt;u&gt;n&lt;/u&gt;', $c, 'display name');
		$this->assertStringContainsString('&lt;i&gt;x&lt;/i&gt;@e.org', $c, 'e-mail');
		$this->assertStringNotContainsString('<u>', $c);
		$this->assertStringNotContainsString('<i>', $c);
		foreach (['code' => 'a&quot;b', 'to' => 't&quot;o', 'claim_token' => 'c&quot;t', 'requesttoken' => 'r&quot;t'] as $name => $escaped) {
			$this->assertStringContainsString('name="' . $name . '" value="' . $escaped . '"', $c, $name);
		}
		$this->assertStringContainsString('href="/c&quot;x"', $c, 'cancel link');
	}

	/** The bitbox hotfix-54 finding: a theme read that throws must not turn every page into a 500. */
	public function testAThemingFailureStillRendersEveryPage(): void {
		$pages = new Pages($this->defaults([], true));
		foreach ($this->allPages($pages) as $label => [$status, $r]) {
			$this->assertSame($status, $r->getStatus(), $label);
			$h = $r->render();
			$this->assertStringContainsString('<p class="wordmark">' . Pages::FALLBACK_NAME . '</p>', $h, $label);
			$this->assertStringContainsString('--primary:' . Pages::FALLBACK_PRIMARY, $h, $label);
			$this->assertStringNotContainsString('<img', $h, $label);
		}
	}

	public function testEveryPageIsBilingualBrandedAndScriptFree(): void {
		$pages = new Pages($this->defaults(['name' => 'Path', 'logo' => '/core/img/logo/logo.svg?v=0', 'primary' => '#00679e', 'on' => '#ffffff']));
		foreach ($this->allPages($pages) as $label => [$status, $r]) {
			$this->assertSame($status, $r->getStatus(), $label);
			$h = $r->render();
			$this->assertStringContainsString('lang="ar" dir="rtl"', $h, $label);
			$this->assertStringContainsString('lang="en" dir="ltr"', $h, $label);
			$this->assertMatchesRegularExpression('/\p{Arabic}{3,}/u', $h, $label);
			$this->assertStringContainsString('<img class="logo"', $h, $label);
			$this->assertStringContainsString('<meta name="referrer" content="no-referrer">', $h, $label);
			$this->assertStringContainsString('noindex', $h, $label);
			$this->assertDoesNotMatchRegularExpression('/<script|<link|\bsrc=["\'](?!\/(?!\/))/i', $h, $label);
			$csp = $this->csp($r);
			$this->assertStringContainsString("frame-ancestors 'none'", $csp, $label);
			$this->assertStringContainsString("form-action 'self'", $csp, $label);
			$this->assertStringNotContainsString('script-src', $csp, $label);
			$this->assertStringContainsString('no-store', $r->getHeaders()['Cache-Control'] ?? '', $label);
		}
	}

	public function testConfirmPageNamesTheAccountAndKeepsTheFormFields(): void {
		$pages = new Pages($this->defaults([]));
		$h = $pages->claimConfirm('أحمد', 'a@example.org', 'CODE1', '/index.php/apps/files/', 'CTOKEN', 'RTOKEN', '/index.php/logout')->render();
		$this->assertStringContainsString('<span class="avatar" aria-hidden="true">أ</span>', $h);
		$this->assertStringContainsString('<strong>أحمد</strong><bdi dir="ltr">a@example.org</bdi>', $h);
		foreach (['code' => 'CODE1', 'to' => '/index.php/apps/files/', 'claim_token' => 'CTOKEN', 'requesttoken' => 'RTOKEN'] as $name => $value) {
			$this->assertStringContainsString('<input type="hidden" name="' . $name . '" value="' . $value . '">', $h, $name);
		}
		$this->assertStringContainsString('<button type="submit">', $h);
		$this->assertStringContainsString('<a class="cancel" href="/index.php/logout">', $h);
		// the wording is the 0.1.0 text, cut at its sentence ends
		$this->assertStringContainsString('This account will be linked to your wi account.', $h);
		$this->assertStringContainsString('anyone who controls your wi account will be able to open this account.', $h);
		$this->assertStringContainsString('Your current password keeps working.', $h);

		$noName = $pages->claimConfirm('', 'zed@example.org', 'C', '/t', 'T', 'R', '/x')->render();
		$this->assertStringContainsString('<span class="avatar" aria-hidden="true">Z</span>', $noName, 'initial from the e-mail');
		$this->assertStringNotContainsString('<strong>', $noName);
	}

	/** @return array<string, array{int, DataDisplayResponse}> */
	private function allPages(Pages $p): array {
		return [
			'other user' => [409, $p->otherUser('/index.php/logout')],
			'expired' => [400, $p->claimExpired()],
			'sign in' => [200, $p->claimSignIn('/index.php/login')],
			'stale' => [200, $p->claimStale('/index.php/logout')],
			'denied' => [403, $p->claimDenied()],
			'too many' => [429, $p->tooMany()],
			'confirm' => [200, $p->claimConfirm('Name', 'n@example.org', 'C', '/t', 'T', 'R', '/x')],
		];
	}
}
