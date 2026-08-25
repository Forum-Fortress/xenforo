<?php

namespace ForumFortress\Protect\Cron;

use ForumFortress\Protect\Service\ApiClient;
use XF\Entity\CronEntry;

class ModerationSync
{
	/**
	 * Pull Forum Fortress moderation actions and push the XenForo approval queue on a fixed cadence.
	 *
	 * @param CronEntry $_entry
	 */
	public static function run(CronEntry $_entry): void
	{
		SyncLock::run(static function (): void
		{
			$client = new ApiClient(\XF::app());
			$client->runModerationSyncCycle(true);
		});
	}
}
