<?php

declare(strict_types=1);

namespace OCA\Wisso\Command;

use OCA\Wisso\Service\KeyChecker;
use OCA\Wisso\Service\SsoLog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates the wi key ON the server and prints it once. Only its sha256 is stored. Rotation replaces the hash:
 * wi must get the new key at the same moment (no overlap window is built).
 */
class KeyGenerate extends Command {
	public function __construct(
		private KeyChecker $key,
		private SsoLog $log,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('wisso:key:generate')
			->setDescription('Generate the wi key, print it once, store only its hash')
			->addOption('rotate', null, InputOption::VALUE_NONE, 'Replace an existing key');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->key->isConfigured() && !$input->getOption('rotate')) {
			$output->writeln('<error>A key is already set. Use --rotate to replace it (wi must receive the new key at once).</error>');
			return 1;
		}
		[$raw, $hash] = KeyChecker::generate();
		$this->key->setHash($hash);
		$this->log->event('key_generated');
		$output->writeln('New wi key (shown once, not stored; paste it into wi and nowhere else):');
		$output->writeln($raw);
		return 0;
	}
}
