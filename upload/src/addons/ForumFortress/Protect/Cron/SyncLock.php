<?php

namespace ForumFortress\Protect\Cron;

/**
 * Serializes Forum Fortress background network work across PHP workers and hosts
 * that share the XenForo database.
 */
final class SyncLock
{
	private const LOCK_NAME = 'ForumFortress.Protect.backgroundSync';

	public static function run(callable $callback): bool
	{
		$db = \XF::db();
		$acquired = (int) $db->fetchOne(
			'SELECT GET_LOCK(?, 0)',
			[self::LOCK_NAME]
		) === 1;

		if (!$acquired)
		{
			return false;
		}

		try
		{
			$callback();
			return true;
		}
		finally
		{
			try
			{
				$db->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
			}
			catch (\Throwable $e)
			{
				\XF::logException($e, false, '[ForumFortress] background lock release: ');
			}
		}
	}
}
