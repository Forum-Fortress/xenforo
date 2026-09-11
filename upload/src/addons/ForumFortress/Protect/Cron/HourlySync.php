<?php

namespace ForumFortress\Protect\Cron;

use ForumFortress\Protect\Service\ApiClient;

class HourlySync
{
	/**
	 * XenForo passes a CronEntry entity during scheduled execution and an array
	 * when an administrator runs the entry manually.
	 *
	 * @param mixed $_entry
	 */
	public static function run($_entry): void
	{
		SyncLock::run(static function (): void
		{
			$client = new ApiClient(\XF::app());
			$client->hourlySync();
		});
	}
}
