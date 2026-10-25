<?php

declare(strict_types=1);

namespace OCA\Wisso\Listener;

use OCA\Wisso\Service\CodeStore;
use OCA\Wisso\Service\LinkStore;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/** @template-implements IEventListener<UserDeletedEvent> */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private LinkStore $links,
		private CodeStore $codes,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$uid = $event->getUser()->getUID();
		$this->links->deleteForUid($uid);
		$this->codes->deleteForUid($uid);
	}
}
