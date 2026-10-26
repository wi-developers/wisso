<?php

declare(strict_types=1);

namespace OCA\Wisso\Command;

use OCA\Wisso\Service\CodeStore;
use OCA\Wisso\Service\KeyChecker;
use OCA\Wisso\Service\LinkStore;
use OCA\Wisso\Service\SsoLog;
use OCP\IAppConfig;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Read-only. Never prints the key or its hash. */
class Status extends Command {
	public function __construct(
		private KeyChecker $key,
		private LinkStore $links,
		private CodeStore $codes,
		private IAppConfig $appConfig,
		private IConfig $config,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('wisso:status')
			->setDescription('Show the state of wi single sign-on (read-only)')
			->addOption('links', null, InputOption::VALUE_NONE, 'List linked accounts (account id, reference used in the log, link time)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln('key configured: ' . ($this->key->isConfigured() ? 'yes' : 'no'));
		$output->writeln('linked accounts: ' . $this->links->count());
		$output->writeln('live one-time codes: ' . $this->codes->count());
		$output->writeln('overwrite.cli.url set: ' . ($this->config->getSystemValueString('overwrite.cli.url', '') !== '' ? 'yes' : 'no'));
		$output->writeln('registration admin_approval_required: ' . ($this->flag('admin_approval_required') ? 'yes' : 'no'));
		$output->writeln('registration allowed_domains set: ' . ($this->appConfig->getValueString('registration', 'allowed_domains', '') !== '' ? 'yes' : 'no'));
		$output->writeln('users may edit their own profile e-mail: ' . ($this->config->getSystemValueBool('allow_user_to_change_email', $this->config->getSystemValueBool('allow_user_to_change_display_name', true)) ? 'yes (squat lever open)' : 'no'));
		$output->writeln('instance default quota for new users: ' . $this->appConfig->getValueString('files', 'default_quota', 'none'));
		if ($input->getOption('links')) {
			foreach ($this->links->all() as $row) {
				$output->writeln(SsoLog::ref($row['uid']) . ' ' . $row['uid'] . ' ' . gmdate('Y-m-d\TH:i:s\Z', $row['linked_at']));
			}
		}
		return 0;
	}

	private function flag(string $key): bool {
		return in_array(strtolower($this->appConfig->getValueString('registration', $key, '')), ['1', 'yes', 'true', 'on'], true);
	}
}
