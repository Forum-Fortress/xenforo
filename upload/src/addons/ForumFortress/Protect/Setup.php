<?php

namespace ForumFortress\Protect;

use XF\AddOn\AbstractSetup;
use XF\Db\Schema\Create;

class Setup extends AbstractSetup
{
	public function install(array $stepParams = [])
	{
		$this->createApprovalQueueMetadataTable();
		$this->createRepeatOffenderTables();
		$this->createRepeatCleanupTables();
	}

	public function upgrade(array $stepParams = [])
	{
		$this->createApprovalQueueMetadataTable();
		$this->createRepeatOffenderTables();
		$this->createRepeatCleanupTables();
	}

	public function uninstall(array $stepParams = [])
	{
		$this->dropApprovalQueueMetadataTable();
		$this->schemaManager()->dropTable('xf_ff_protect_repeat_event');
		$this->schemaManager()->dropTable('xf_ff_protect_repeat_state');
		$this->schemaManager()->dropTable('xf_ff_protect_repeat_cleanup_event');
		$this->schemaManager()->dropTable('xf_ff_protect_repeat_cleanup_state');
	}

	protected function createRepeatCleanupTables(): void
	{
		$sm = $this->schemaManager();
		if (!$sm->tableExists('xf_ff_protect_repeat_cleanup_state'))
		{
			$sm->createTable('xf_ff_protect_repeat_cleanup_state', function (Create $table): void
			{
				$table->addColumn('user_id', 'int')->unsigned();
				$table->addColumn('last_cleanup_at', 'int')->unsigned()->setDefault(0);
				$table->addPrimaryKey('user_id');
			});
		}
		if (!$sm->tableExists('xf_ff_protect_repeat_cleanup_event'))
		{
			$sm->createTable('xf_ff_protect_repeat_cleanup_event', function (Create $table): void
			{
				$table->addColumn('user_id', 'int')->unsigned();
				$table->addColumn('event_hash', 'varbinary', 64);
				$table->addColumn('reference_hash', 'varbinary', 64);
				$table->addColumn('observed_at', 'int')->unsigned();
				$table->addColumn('consumed', 'tinyint')->unsigned()->setDefault(0);
				$table->addPrimaryKey(['user_id', 'event_hash']);
				$table->addUniqueKey(['user_id', 'reference_hash']);
				$table->addKey('observed_at');
			});
		}
	}

	protected function createRepeatOffenderTables(): void
	{
		$sm = $this->schemaManager();
		if (!$sm->tableExists('xf_ff_protect_repeat_state'))
		{
			$sm->createTable('xf_ff_protect_repeat_state', function (Create $table): void
			{
				$table->addColumn('user_id', 'int')->unsigned();
				$table->addColumn('last_ban_at', 'int')->unsigned()->setDefault(0);
				$table->addColumn('ban_end', 'int')->unsigned()->setDefault(0);
				$table->addPrimaryKey('user_id');
			});
		}
		if (!$sm->tableExists('xf_ff_protect_repeat_event'))
		{
			$sm->createTable('xf_ff_protect_repeat_event', function (Create $table): void
			{
				$table->addColumn('user_id', 'int')->unsigned();
				$table->addColumn('event_hash', 'varbinary', 64);
				$table->addColumn('reference_hash', 'varbinary', 64);
				$table->addColumn('observed_at', 'int')->unsigned();
				$table->addColumn('consumed', 'tinyint')->unsigned()->setDefault(0);
				$table->addPrimaryKey(['user_id', 'event_hash']);
				$table->addUniqueKey(['user_id', 'reference_hash']);
				$table->addKey('observed_at');
			});
		}
	}

	protected function createApprovalQueueMetadataTable(): void
	{
		$this->schemaManager()->createTable('xf_ff_protect_approval_meta', function (Create $table): void
		{
			$table->addColumn('content_type', 'varbinary', 25);
			$table->addColumn('content_id', 'int')->unsigned();
			$table->addColumn('metadata_json', 'mediumblob');
			$table->addColumn('updated_at', 'int')->unsigned()->setDefault(0);
			$table->addPrimaryKey(['content_type', 'content_id']);
		});
	}

	protected function dropApprovalQueueMetadataTable(): void
	{
		$this->schemaManager()->dropTable('xf_ff_protect_approval_meta');
	}
}
