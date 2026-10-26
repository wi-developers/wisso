<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use OCA\Wisso\Service\KeyChecker;
use OCP\Accounts\IAccountManager;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Black-box base class: the app is exercised over real HTTPS against the Apache of the rig; Nextcloud services
 * from the CLI are used only to prepare accounts and to read state back (same DB, Redis and config).
 */
abstract class WissoTestCase extends TestCase {
	protected const BASE = 'https://localhost:8443';
	protected const APP = self::BASE . '/index.php/apps/wisso/';
	protected const ADMIN_API = self::APP . 'admin-api/sso/';

	/** Every secret-looking string a test used (keys, codes, e-mails, names); the log hygiene test greps for them. */
	public static array $secrets = [];

	protected IUserManager $um;
	protected IGroupManager $groups;
	protected IConfig $config;
	protected IAppConfig $appConfig;
	protected IDBConnection $db;
	protected string $key;
	/** One key for the whole run: Nextcloud keeps app config in APCu for 3 s, so a changing key would be stale. */
	private static ?string $suiteKey = null;
	private static ?string $suiteHash = null;
	/** @var array<string, string> uid => password of accounts made by this test */
	protected array $made = [];
	protected array $madeGroups = [];
	/** @var list<callable> */
	private array $undo = [];

	protected function setUp(): void {
		$this->um = Server::get(IUserManager::class);
		$this->groups = Server::get(IGroupManager::class);
		$this->config = Server::get(IConfig::class);
		$this->appConfig = Server::get(IAppConfig::class);
		$this->db = Server::get(IDBConnection::class);
		$this->assertProductionShape();
		$this->resetState();
		if (self::$suiteKey === null) {
			// a fresh log for this run, so the hygiene test judges only what this run wrote
			$log = (string)$this->config->getSystemValue('datadirectory') . '/nextcloud.log';
			if (is_file($log)) {
				file_put_contents($log, '');
			}
			[self::$suiteKey, self::$suiteHash] = KeyChecker::generate();
			self::$secrets[] = self::$suiteKey;
			(new KeyChecker($this->appConfig))->setHash(self::$suiteHash);
			$this->settle();
		}
		$this->key = self::$suiteKey;
		$this->clearRateLimits();
		Server::get(ICacheFactory::class)->createDistributed('wisso')->clear();
	}

	/** ICache::clear() builds a glob from the class name, whose backslashes break it; scan for the name part instead. */
	protected function clearRateLimits(): void {
		$redis = Server::get('RedisFactory')->getInstance();
		$it = null;
		do {
			$keys = $redis->scan($it, '*MemoryCacheBackend*', 500);
			if ($keys) {
				$redis->unlink($keys);
			}
		} while ($it > 0);
	}

	protected function tearDown(): void {
		foreach (array_reverse($this->undo) as $fn) {
			$fn();
		}
		$this->undo = [];
		foreach (array_keys($this->made) as $uid) {
			$this->um->get($uid)?->delete();
		}
		foreach ($this->um->search('wi_') as $u) {
			if (str_starts_with($u->getUID(), 'wi_')) {
				$u->delete();
			}
		}
		foreach ($this->madeGroups as $gid) {
			$this->groups->get($gid)?->delete();
		}
		$this->made = [];
		$this->madeGroups = [];
		$this->resetState();
	}

	/** The tests must run under production settings, not defaults (BitBox hotfix-52: a harness pin hid a crash). */
	private function assertProductionShape(): void {
		$c = $this->config;
		$this->assertSame('ar', $c->getSystemValue('default_locale'), 'rig is not production-shaped: default_locale');
		$this->assertSame(2, (int)$c->getSystemValue('loglevel'), 'rig is not production-shaped: loglevel');
		$this->assertStringContainsString('APCu', (string)$c->getSystemValue('memcache.local'), 'rig: memcache.local');
		$this->assertStringContainsString('Redis', (string)$c->getSystemValue('memcache.locking'), 'rig: memcache.locking');
		$this->assertSame('https', $c->getSystemValue('overwriteprotocol'), 'rig: overwriteprotocol');
		$this->assertSame('', (string)$c->getSystemValue('skeletondirectory'), 'rig: skeletondirectory');
		$this->assertSame(self::BASE, $c->getSystemValue('overwrite.cli.url'), 'rig: overwrite.cli.url');
		$apps = Server::get(\OCP\App\IAppManager::class);
		$this->assertTrue($apps->isEnabledForAnyone('registration'), 'rig: registration app');
		$this->assertTrue($apps->isEnabledForAnyone('twofactor_totp'), 'rig: twofactor_totp app');
		$this->assertTrue($apps->isEnabledForAnyone('password_policy'), 'rig: password_policy app');
	}

	private function resetState(): void {
		foreach (['wisso_codes', 'wisso_links'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)->executeStatement();
		}
	}

	/** App config read by the Apache side is cached for 3 s (OCAppConfig::LOCAL_CACHE_TTL); wait it out after a change. */
	protected function settle(): void {
		usleep(4300000); // TTL 3 s plus APCu clock granularity
	}

	/** Sets an app config value for the duration of one test; set and restore are both settled. */
	protected function withAppConfig(string $app, string $key, string $value, bool $sensitive = false): void {
		$old = $this->appConfig->hasKey($app, $key) ? $this->appConfig->getValueString($app, $key) : null;
		$this->appConfig->setValueString($app, $key, $value, false, $sensitive);
		$this->onTearDown(function () use ($app, $key, $old, $sensitive): void {
			if ($old === null) {
				$this->appConfig->deleteKey($app, $key);
			} else {
				$this->appConfig->setValueString($app, $key, $old, false, $sensitive);
			}
			$this->settle();
		});
		$this->settle();
	}

	protected function onTearDown(callable $fn): void {
		$this->undo[] = $fn;
	}

	/** Typed variant for apps that read their settings as int or bool; the key did not exist before, so undo deletes it. */
	protected function withTypedAppConfig(string $app, string $key, int|bool $value): void {
		if (is_bool($value)) {
			$this->appConfig->setValueBool($app, $key, $value);
		} else {
			$this->appConfig->setValueInt($app, $key, $value);
		}
		$this->onTearDown(function () use ($app, $key): void {
			$this->appConfig->deleteKey($app, $key);
			$this->settle();
		});
		$this->settle();
	}

	// ---- accounts -------------------------------------------------------------------------------------------

	protected function mkUser(string $uid, ?string $email, string $display = 'Test User'): IUser {
		$password = 'Pw!' . bin2hex(random_bytes(10)) . 'Zz9';
		self::$secrets[] = $password;
		$user = $this->um->createUser($uid, $password);
		$this->assertNotFalse($user, 'could not create the test account');
		$this->made[$uid] = $password;
		if ($email !== null) {
			$user->setSystemEMailAddress($email);
			self::$secrets[] = $email;
		}
		$user->setDisplayName($display);
		return $user;
	}

	protected function mkAdmin(string $uid, ?string $email): IUser {
		$u = $this->mkUser($uid, $email);
		$admin = $this->groups->get('admin');
		$admin->addUser($u);
		return $u;
	}

	/** Enrols a TOTP provider for the account, the way the 2FA settings page does. */
	protected function enrolTwoFactor(IUser $user): void {
		$registry = Server::get(\OCP\Authentication\TwoFactorAuth\IRegistry::class);
		$provider = Server::get(\OCA\TwoFactorTOTP\Provider\TotpProvider::class);
		$registry->enableProviderFor($provider, $user);
	}

	protected function randomEmail(string $local = 'u'): string {
		$e = $local . bin2hex(random_bytes(4)) . '@wi.example';
		self::$secrets[] = $e;
		return $e;
	}

	// ---- http -----------------------------------------------------------------------------------------------

	protected function client(?CookieJar $jar = null): Client {
		return new Client([
			'verify' => false,
			'http_errors' => false,
			'allow_redirects' => false,
			'cookies' => $jar ?? false,
			'timeout' => 30,
		]);
	}

	/** @param array<string,mixed> $form */
	protected function apiPost(string $path, array $form, ?string $key = '', array $headers = []): ResponseInterface {
		$key = $key === '' ? $this->key : $key;
		if ($key !== null) {
			$headers['Authorization'] = 'Bearer ' . $key;
			self::$secrets[] = $key; // a wrong key is a secret too: nothing the caller sends may be echoed into the log
		}
		return $this->client()->post(self::ADMIN_API . $path, ['form_params' => $form, 'headers' => $headers]);
	}

	/** @return array<mixed> */
	protected function json(ResponseInterface $r): array {
		$data = json_decode((string)$r->getBody(), true);
		$this->assertIsArray($data, 'body is not JSON: ' . substr((string)$r->getBody(), 0, 120));
		return $data;
	}

	protected function assertErrorShape(ResponseInterface $r, int $status): void {
		$this->assertSame($status, $r->getStatusCode(), 'status; body: ' . substr((string)$r->getBody(), 0, 160));
		$this->assertStringContainsString('application/json', $r->getHeaderLine('Content-Type'));
		$body = $this->json($r);
		$this->assertSame(['errors'], array_keys($body));
		$this->assertCount(1, $body['errors']);
		$this->assertSame(['title'], array_keys($body['errors'][0]));
		$this->assertIsString($body['errors'][0]['title']);
	}

	/** Calls login and returns the URL wi would redirect to. */
	protected function loginFor(string $email, string $name = 'Wi User', string $redirect = 'dashboard'): string {
		$r = $this->apiPost('login', ['email' => $email, 'name' => $name, 'redirect' => $redirect]);
		$this->assertSame(200, $r->getStatusCode(), (string)$r->getBody());
		$url = $this->json($r)['data']['url'];
		$this->assertIsString($url);
		self::$secrets[] = $this->codeOf($url);
		return $url;
	}

	protected function codeOf(string $url): string {
		parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
		return (string)($q['code'] ?? '');
	}

	protected function withParam(string $url, string $name, string $value): string {
		$parts = parse_url($url);
		parse_str($parts['query'], $q);
		$q[$name] = $value;
		return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $parts['path'] . '?' . http_build_query($q);
	}

	/** Real password sign-in through the login form; returns a client holding the session cookies. */
	protected function webLogin(string $uid): Client {
		$jar = new CookieJar();
		$client = $this->client($jar);
		$page = (string)$client->get(self::BASE . '/index.php/login')->getBody();
		$this->assertSame(1, preg_match('/data-requesttoken="([^"]+)"/', $page, $m), 'no request token on the login page');
		// the login form refuses a POST without a same-origin Origin header (loginErrors: invalidOrigin), as a browser sends one
		$r = $client->post(self::BASE . '/index.php/login', ['headers' => ['Origin' => self::BASE, 'Referer' => self::BASE . '/index.php/login'], 'form_params' => [
			'user' => $uid,
			'password' => $this->made[$uid],
			'timezone' => 'UTC',
			'timezone_offset' => '0',
			'requesttoken' => html_entity_decode($m[1]),
		]]);
		$this->assertContains($r->getStatusCode(), [302, 303], 'web login failed for the test account');
		$this->assertSame($uid, $this->whoAmI($client), 'web login did not give a session');
		return $client;
	}

	protected function whoAmI(Client $client): ?string {
		$r = $client->get(self::BASE . '/ocs/v2.php/cloud/user?format=json', ['headers' => ['OCS-APIRequest' => 'true']]);
		$data = json_decode((string)$r->getBody(), true);
		return $data['ocs']['data']['id'] ?? null;
	}

	// ---- state read-back ------------------------------------------------------------------------------------

	protected function linkedUid(string $email): ?string {
		return (new \OCA\Wisso\Service\LinkStore($this->db, Server::get(\OCP\AppFramework\Utility\ITimeFactory::class)))
			->uidForEmailHash(hash('sha256', strtolower(trim($email))));
	}

	protected function countRows(string $table): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table);
		return (int)$qb->executeQuery()->fetchOne();
	}

	protected function expireCodes(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('wisso_codes')->set('expires_at', $qb->createNamedParameter(time() - 5))->executeStatement();
	}

	protected function accountVerified(IUser $user): string {
		$acc = Server::get(IAccountManager::class)->getAccount($user);
		return $acc->getProperty(IAccountManager::PROPERTY_EMAIL)->getLocallyVerified();
	}

	/** The profile e-mail as stored now (user objects in this process cache what an Apache request later changed). */
	protected function profileEmail(string $uid): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('configvalue')->from('preferences')
			->where($qb->expr()->eq('userid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter('settings')))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('email')));
		$v = $qb->executeQuery()->fetchOne();
		return $v === false ? null : (string)$v;
	}

	/** The stored key hash as it is now: this process caches app config and an occ child changes it, so reload first. */
	protected function storedKeyHash(): ?string {
		$this->appConfig->clearCache(true);
		return $this->appConfig->hasKey('wisso', 'key_sha256') ? $this->appConfig->getValueString('wisso', 'key_sha256') : null;
	}

	protected function nextcloudLog(): string {
		$f = (string)$this->config->getSystemValue('datadirectory') . '/nextcloud.log';
		return is_file($f) ? (string)file_get_contents($f) : '';
	}
}
