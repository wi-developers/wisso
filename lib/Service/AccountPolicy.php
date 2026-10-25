<?php

declare(strict_types=1);

namespace OCA\Wisso\Service;

use OC\Authentication\TwoFactorAuth\Manager as TwoFactorManager;
use OC\Settings\AuthorizedGroupMapper;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IUser;
use Psr\Container\ContainerInterface;

/**
 * Who must never be signed in (or linked) through wi. One generic 403 for every reason, the reason goes to the log.
 * Any error while deciding counts as a refusal (fail closed).
 */
class AccountPolicy {
	public function __construct(
		private IGroupManager $groups,
		private ISubAdmin $subAdmins,
		private ContainerInterface $container,
	) {
	}

	/** @return string|null a reason code when the account is refused, null when it may use wi sign-in */
	public function refusal(IUser $user): ?string {
		try {
			if (!$user->isEnabled()) {
				return 'disabled';
			}
			if ($user->getBackendClassName() !== 'Database') {
				return 'backend';
			}
			if ($this->groups->isAdmin($user->getUID())) {
				return 'admin';
			}
			if ($this->subAdmins->isSubAdmin($user)) {
				return 'subadmin';
			}
			// two private core classes, resolved here so that a change in them is a refusal, not a crash on every call
			if ($this->container->get(AuthorizedGroupMapper::class)->findAllClassesForUser($user) !== []) {
				return 'delegated_admin';
			}
			if ($this->container->get(TwoFactorManager::class)->isTwoFactorAuthenticated($user)) {
				return 'two_factor';
			}
			return null;
		} catch (\Throwable) {
			return 'policy_error';
		}
	}
}
