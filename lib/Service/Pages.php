<?php

declare(strict_types=1);

namespace OCA\Wisso\Service;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\Response;
use OCP\Defaults;

/**
 * Static, bilingual (Arabic and English together) pages for the browser steps. No script, every dynamic value
 * escaped. These pages may carry a one-time code in a hidden field: no-store, noindex, no-referrer, not framable.
 * Look (D-NC-008): the site's own name, logo and primary colour are read from the host's theming at render time
 * (OCP\Defaults), each read on its own inside try/catch and validated before it reaches CSS or HTML; any failure
 * means the built-in fallback, never an error page. The only subresource is that same-origin logo image.
 */
class Pages {
	/** A same-origin path with an optional query, as the theming app returns it; anything else is not used as a logo. */
	public const LOGO_PATH = '#^/(?!/)[A-Za-z0-9._~%/-]*(\?[A-Za-z0-9._~%=&/-]*)?$#D';
	public const HEX_COLOR = '/^#[0-9a-fA-F]{6}$/D';
	public const FALLBACK_NAME = 'Path';
	public const FALLBACK_PRIMARY = '#00679e';
	public const FALLBACK_ON_PRIMARY = '#ffffff';

	private const FONT = 'system-ui,-apple-system,"Segoe UI",Tahoma,"Noto Sans Arabic","Noto Sans",Roboto,Arial,sans-serif';

	private const STYLE = ':root{--bg:#f5f6f8;--fg:#1b1f23;--card:#fff;--muted:#f1f3f5;--border:rgba(0,0,0,.12);--radius:.75rem;--pill:1.5rem;color-scheme:light}'
		. '@media (prefers-color-scheme:dark){:root{--bg:#171a1d;--fg:#f0f2f4;--card:#1f2328;--muted:#2a2f35;--border:rgba(255,255,255,.16);color-scheme:dark}}'
		. '*{box-sizing:border-box}'
		. 'body{margin:0;padding:1rem;background:var(--bg);color:var(--fg);line-height:1.7}'
		. '.wrap{max-width:34rem;margin:2.5rem auto}'
		// the host's logo is made to sit on its primary colour (as on its own sign-in page), so it gets a bar of that colour
		. '.brandbar{background:var(--primary);border-radius:var(--radius);padding:1.1rem 1.25rem;margin:0 0 1rem;text-align:center}'
		. 'img.logo{display:block;margin:0 auto;max-width:11rem;max-height:3.5rem;width:auto;height:auto}'
		. '.wordmark{margin:0;font-size:1.5rem;font-weight:700;color:var(--on-primary)}'
		. '.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1.6rem}'
		. 'section.lang+section.lang{margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid var(--border)}'
		. 'h1{font-size:1.2rem;font-weight:700;line-height:1.4;margin:0 0 .6rem}'
		. 'p{margin:.5rem 0}'
		. '.acct{display:flex;align-items:center;gap:.85rem;background:var(--muted);border:1px solid var(--border);border-radius:var(--radius);padding:.85rem 1rem;margin:0 0 1.4rem}'
		. '.avatar{flex:none;width:2.6rem;height:2.6rem;border-radius:50%;background:var(--primary);color:var(--on-primary);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.1rem}'
		. '.acct strong{display:block;font-weight:600;overflow-wrap:anywhere}'
		. '.acct bdi{display:block;font-size:.92rem;opacity:.75;overflow-wrap:anywhere}'
		. '.warn{background:var(--muted);border:1px solid var(--border);border-inline-start:4px solid var(--primary);border-radius:.5rem;padding:.7rem .95rem;margin:.9rem 0}'
		. '.act{display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;margin:1.7rem 0 0}'
		. 'button,a.btn{font:inherit;font-weight:600;background:var(--primary);color:var(--on-primary);border:0;border-radius:var(--pill);padding:.7rem 1.6rem;cursor:pointer;min-height:2.9rem;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}'
		. 'button:hover,a.btn:hover{filter:brightness(1.08)}'
		. 'a.cancel{color:var(--fg);text-decoration:none;border:1px solid var(--border);border-radius:var(--pill);padding:.7rem 1.3rem;min-height:2.9rem;display:inline-flex;align-items:center}'
		. 'a.cancel:hover{background:var(--muted)}'
		. 'button:focus-visible,a:focus-visible{outline:3px solid var(--primary);outline-offset:3px}'
		. '@media (max-width:30rem){.wrap{margin:.5rem auto}.card{padding:1.1rem}button,a.btn,a.cancel{width:100%;text-align:center;justify-content:center}}';

	public function __construct(private Defaults $defaults) {
	}

	public function otherUser(string $logoutUrl): Response {
		return $this->page(Http::STATUS_CONFLICT,
			'أنت مسجّل الدخول بحساب آخر في Path. سجّل الخروج أولاً أو افتح نافذة خاصة (Private)، ثم اضغط على Path في wi مرة أخرى.',
			'You are signed in to another account on Path. Sign out first, or open a private window, then click Path in wi again.',
			'<p class="act"><a class="btn" href="' . $this->e($logoutUrl) . '">تسجيل الخروج / Sign out</a></p>');
	}

	public function claimExpired(): Response {
		return $this->page(Http::STATUS_BAD_REQUEST,
			'انتهت صلاحية هذا الرابط. اضغط على Path في wi مرة أخرى.',
			'This link has expired. Click Path in wi again.');
	}

	public function claimSignIn(string $loginUrl): Response {
		return $this->page(Http::STATUS_OK,
			'لربط هذا الحساب، سجّل الدخول إلى Path أولاً بكلمة مرور الحساب الذي يحمل نفس البريد الإلكتروني الخاص بحسابك في wi، ثم اضغط على Path في wi مرة أخرى.',
			'To link this account, first sign in to Path with the password of the account that has the same e-mail address as your wi account, then click Path in wi again.',
			'<p class="act"><a class="btn" href="' . $this->e($loginUrl) . '">تسجيل الدخول / Sign in</a></p>');
	}

	public function claimStale(string $logoutUrl): Response {
		return $this->page(Http::STATUS_OK,
			'لأمان حسابك، سجّل الخروج ثم سجّل الدخول من جديد بكلمة المرور، ثم اضغط على Path في wi مرة أخرى.',
			'For your security, sign out and sign in again with your password, then click Path in wi again.',
			'<p class="act"><a class="btn" href="' . $this->e($logoutUrl) . '">تسجيل الخروج / Sign out</a></p>');
	}

	public function claimDenied(): Response {
		return $this->page(Http::STATUS_FORBIDDEN,
			'تعذّر ربط الحساب. اضغط على Path في wi مرة أخرى.',
			'The account could not be linked. Click Path in wi again.');
	}

	public function tooMany(): Response {
		return $this->page(Http::STATUS_TOO_MANY_REQUESTS,
			'محاولات كثيرة. انتظر دقيقة ثم حاول مرة أخرى.',
			'Too many attempts. Wait a minute and try again.');
	}

	/** The confirm page names the account to its own signed-in owner only. */
	public function claimConfirm(string $displayName, string $email, string $code, string $to, string $claimToken, string $requestToken, string $cancelUrl): Response {
		$who = trim($displayName) !== '' ? trim($displayName) : trim($email);
		$initial = mb_strtoupper(mb_substr($who, 0, 1, 'UTF-8'), 'UTF-8');
		$account = '<p class="acct"><span class="avatar" aria-hidden="true">' . $this->e($initial !== '' ? $initial : '?') . '</span>'
			. '<span>' . ($displayName !== '' ? '<strong>' . $this->e($displayName) . '</strong>' : '')
			. '<bdi dir="ltr">' . $this->e($email) . '</bdi></span></p>';
		// the wording of the 0.1.0 page, cut at its sentence ends: heading, the warning, the rest
		$block = fn (string $lang, string $dir, string $head, string $warn, string $rest): string => '<section class="lang" lang="' . $lang . '" dir="' . $dir . '">'
			. '<h1>' . $this->e($head) . '</h1><p class="warn">' . $this->e($warn) . '</p><p>' . $this->e($rest) . '</p></section>';
		$form = '<form method="post" action="">'
			. '<input type="hidden" name="code" value="' . $this->e($code) . '">'
			. '<input type="hidden" name="to" value="' . $this->e($to) . '">'
			. '<input type="hidden" name="claim_token" value="' . $this->e($claimToken) . '">'
			. '<input type="hidden" name="requesttoken" value="' . $this->e($requestToken) . '">'
			. '<p class="act"><button type="submit">ربط ومتابعة / Link and continue</button> '
			. '<a class="cancel" href="' . $this->e($cancelUrl) . '">إلغاء / Cancel</a></p></form>';
		return $this->shell(Http::STATUS_OK, $account
			. $block('ar', 'rtl', 'سيتم ربط هذا الحساب بحسابك في wi.',
				'بعد الربط تفتح Path من wi دون كلمة مرور، وكل من يتحكم بحسابك في wi سيتمكن من فتح هذا الحساب.',
				'كلمة مرورك الحالية تبقى صالحة.')
			. $block('en', 'ltr', 'This account will be linked to your wi account.',
				'After linking you open Path from wi without a password, and anyone who controls your wi account will be able to open this account.',
				'Your current password keeps working.')
			. $form);
	}

	private function page(int $status, string $ar, string $en, string $extra = ''): Response {
		return $this->shell($status,
			'<section class="lang" lang="ar" dir="rtl"><p>' . $this->e($ar) . '</p></section>'
			. '<section class="lang" lang="en" dir="ltr"><p>' . $this->e($en) . '</p></section>'
			. $extra);
	}

	/** @return array{name: string, logo: ?string, primary: string, onPrimary: string} every value validated; see the class comment */
	private function brand(): array {
		$read = static function (callable $get): string {
			try {
				$v = $get();
				return is_string($v) ? $v : '';
			} catch (\Throwable) {
				return '';
			}
		};
		$name = trim($read(fn () => $this->defaults->getName()));
		$logo = $read(fn () => $this->defaults->getLogo());
		$primary = $read(fn () => $this->defaults->getColorPrimary());
		$onPrimary = $read(fn () => $this->defaults->getTextColorPrimary());
		return [
			'name' => $name !== '' && mb_strlen($name, 'UTF-8') <= 60 ? $name : self::FALLBACK_NAME,
			'logo' => strlen($logo) <= 300 && preg_match(self::LOGO_PATH, $logo) === 1 ? $logo : null,
			'primary' => preg_match(self::HEX_COLOR, $primary) === 1 ? $primary : self::FALLBACK_PRIMARY,
			'onPrimary' => preg_match(self::HEX_COLOR, $onPrimary) === 1 ? $onPrimary : self::FALLBACK_ON_PRIMARY,
		];
	}

	private function shell(int $status, string $content): Response {
		$brand = $this->brand();
		$mark = $brand['logo'] !== null
			? '<div class="brandbar"><img class="logo" src="' . $this->e($brand['logo']) . '" alt="' . $this->e($brand['name']) . '"></div>'
			: '<div class="brandbar"><p class="wordmark">' . $this->e($brand['name']) . '</p></div>';
		$html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="referrer" content="no-referrer"><meta name="robots" content="noindex">'
			. '<meta name="color-scheme" content="light dark">'
			. '<title>' . $this->e($brand['name']) . '</title><style>'
			. ':root{--primary:' . $brand['primary'] . ';--on-primary:' . $brand['onPrimary'] . '}'
			. 'body{font-family:' . self::FONT . '}' . self::STYLE
			. '</style></head><body><div class="wrap">' . $mark . '<main class="card">' . $content . '</main></div></body></html>';

		$response = new DataDisplayResponse($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
		$csp = new EmptyContentSecurityPolicy();
		$csp->allowInlineStyle(true);
		$csp->addAllowedFormActionDomain("'self'");
		if ($brand['logo'] !== null) {
			$csp->addAllowedImageDomain("'self'");
		}
		$response->setContentSecurityPolicy($csp);
		$response->addHeader('Cache-Control', 'no-store');
		$response->addHeader('X-Frame-Options', 'DENY');
		return $response;
	}

	private function e(string $v): string {
		return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
