<?php

namespace ForumFortress\Protect\Cron;

use ForumFortress\Protect\Service\ApiClient;
use XF\Entity\CronEntry;

class HourlySync
{
	/**
	 * XenForo invokes cron callbacks with the {@see CronEntry} as the first argument.
	 *
	 * @param CronEntry $entry
	 */
	public static function run(CronEntry $_entry): void
	{
		SyncLock::run(static function (): void
		{
			$client = new ApiClient(\XF::app());
			$client->hourlySync();
		});
	}
}
