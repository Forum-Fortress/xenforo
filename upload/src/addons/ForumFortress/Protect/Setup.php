<?php

namespace ForumFortress\Protect;

use XF\AddOn\AbstractSetup;
use XF\Db\Schema\Create;

class Setup extends AbstractSetup
{
	public function install(array $stepParams = [])
	{
		$this->createApprovalQueueMetadataTable();
	}

	public function upgrade(array $stepParams = [])
	{
		$this->createApprovalQueueMetadataTable();
	}

	public function uninstall(array $stepParams = [])
	{
		$this->dropApprovalQueueMetadataTable();
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
