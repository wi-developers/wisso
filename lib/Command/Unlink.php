<?php

declare(strict_types=1);

namespace OCA\Wisso\Command;

use OCA\Wisso\Service\CodeStore;
use OCA\Wisso\Service\LinkStore;
use OCA\Wisso\Service\SsoLog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Removes the wi link of one account (and its pending codes). The account itself is not touched. */
class Unlink extends Command {
	public function __construct(
		private LinkStore $links,
		private CodeStore $codes,
		private SsoLog $log,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('wisso:unlink')
			->setDescription('Remove the wi single sign-on link of an account (the account is kept)')
			->addArgument('uid', InputArgument::REQUIRED, 'Nextcloud account id');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = (string)$input->getArgument('uid');
		$removed = $this->links->deleteForUid($uid);
		$this->codes->deleteForUid($uid);
		if ($removed === 0) {
			$output->writeln('no link for this account');
			return 1;
		}
		$this->log->event('unlinked', null, $uid);
		$output->writeln('unlinked');
		return 0;
	}
}
