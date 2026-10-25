<?php

declare(strict_types=1);

namespace OCA\Wisso\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Two small tables. Times are unix seconds written from PHP, never SQL NOW() and never a TIMESTAMP column.
 */
class Version1000Date20261025000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('wisso_codes')) {
			$table = $schema->createTable('wisso_codes');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('code_hash', 'string', ['notnull' => true, 'length' => 64]);
			$table->addColumn('type', 'string', ['notnull' => true, 'length' => 8]);
			$table->addColumn('uid', 'string', ['notnull' => true, 'length' => 64]);
			$table->addColumn('email_sha256', 'string', ['notnull' => true, 'length' => 64]);
			$table->addColumn('expires_at', 'bigint', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['code_hash'], 'wisso_codes_hash');
			$table->addIndex(['uid'], 'wisso_codes_uid');
		}

		if (!$schema->hasTable('wisso_links')) {
			$table = $schema->createTable('wisso_links');
			$table->addColumn('uid', 'string', ['notnull' => true, 'length' => 64]);
			$table->addColumn('wi_email_sha256', 'string', ['notnull' => true, 'length' => 64]);
			$table->addColumn('linked_at', 'bigint', ['notnull' => true]);
			$table->setPrimaryKey(['uid']);
			$table->addUniqueIndex(['wi_email_sha256'], 'wisso_links_email');
		}

		return $schema;
	}
}
