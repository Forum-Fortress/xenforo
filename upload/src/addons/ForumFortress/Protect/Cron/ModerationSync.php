<?php

namespace ForumFortress\Protect\Cron;

use ForumFortress\Protect\Service\ApiClient;

class ModerationSync
{
	/**
	 * Pull Forum Fortress moderation actions and push the XenForo approval queue on a fixed cadence.
	 *
	 * @param mixed $_entry XenForo passes an entity for scheduled runs and an array for manual runs.
	 */
	public static function run($_entry): void
	{
		SyncLock::run(static function (): void
		{
			$client = new ApiClient(\XF::app());
			$client->runModerationSyncCycle(true);
		});
	}
}
