<?php

declare(strict_types=1);

namespace OCA\Wisso\Service;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\Response;

/**
 * Static, bilingual (Arabic and English together) pages for the browser steps. No script, no subresources,
 * every dynamic value escaped. These pages may carry a one-time code in a hidden field: no-store, noindex,
 * no-referrer, not framable.
 */
class Pages {
	public function otherUser(string $logoutUrl): Response {
		return $this->page(Http::STATUS_CONFLICT,
			'أنت مسجّل الدخول بحساب آخر في Path. سجّل الخروج أولاً أو افتح نافذة خاصة (Private)، ثم اضغط على Path في wi مرة أخرى.',
			'You are signed in to another account on Path. Sign out first, or open a private window, then click Path in wi again.',
			'<p><a href="' . $this->e($logoutUrl) . '">تسجيل الخروج / Sign out</a></p>');
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
			'<p><a href="' . $this->e($loginUrl) . '">تسجيل الدخول / Sign in</a></p>');
	}

	public function claimStale(string $logoutUrl): Response {
		return $this->page(Http::STATUS_OK,
			'لأمان حسابك، سجّل الخروج ثم سجّل الدخول من جديد بكلمة المرور، ثم اضغط على Path في wi مرة أخرى.',
			'For your security, sign out and sign in again with your password, then click Path in wi again.',
			'<p><a href="' . $this->e($logoutUrl) . '">تسجيل الخروج / Sign out</a></p>');
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
		$form = '<form method="post" action="">'
			. '<input type="hidden" name="code" value="' . $this->e($code) . '">'
			. '<input type="hidden" name="to" value="' . $this->e($to) . '">'
			. '<input type="hidden" name="claim_token" value="' . $this->e($claimToken) . '">'
			. '<input type="hidden" name="requesttoken" value="' . $this->e($requestToken) . '">'
			. '<p class="who">' . $this->e($displayName) . ' &lt;' . $this->e($email) . '&gt;</p>'
			. '<p><button type="submit">ربط ومتابعة / Link and continue</button> '
			. '<a href="' . $this->e($cancelUrl) . '">إلغاء / Cancel</a></p></form>';
		return $this->page(Http::STATUS_OK,
			'سيتم ربط هذا الحساب بحسابك في wi. بعد الربط تفتح Path من wi دون كلمة مرور، وكل من يتحكم بحسابك في wi سيتمكن من فتح هذا الحساب. كلمة مرورك الحالية تبقى صالحة.',
			'This account will be linked to your wi account. After linking you open Path from wi without a password, and anyone who controls your wi account will be able to open this account. Your current password keeps working.',
			$form);
	}

	private function page(int $status, string $ar, string $en, string $extra = ''): Response {
		$html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="referrer" content="no-referrer"><meta name="robots" content="noindex">'
			. '<title>Path</title><style>'
			. 'body{font-family:system-ui,sans-serif;margin:0;background:#f4f6f8;color:#1b1f23}'
			. 'main{max-width:34rem;margin:3rem auto;padding:0 1rem}'
			. 'section{background:#fff;border-radius:8px;padding:1rem 1.25rem;margin-bottom:1rem;box-shadow:0 1px 3px rgba(0,0,0,.12)}'
			. '.who{font-weight:600;word-break:break-all}button{padding:.5rem 1rem;font-size:1rem}'
			. '</style></head><body><main>'
			. '<section lang="ar" dir="rtl"><p>' . $this->e($ar) . '</p></section>'
			. '<section lang="en" dir="ltr"><p>' . $this->e($en) . '</p></section>'
			. $extra
			. '</main></body></html>';

		$response = new DataDisplayResponse($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
		$csp = new EmptyContentSecurityPolicy();
		$csp->allowInlineStyle(true);
		$csp->addAllowedFormActionDomain("'self'");
		$response->setContentSecurityPolicy($csp);
		$response->addHeader('Cache-Control', 'no-store');
		$response->addHeader('X-Frame-Options', 'DENY');
		return $response;
	}

	private function e(string $v): string {
		return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
