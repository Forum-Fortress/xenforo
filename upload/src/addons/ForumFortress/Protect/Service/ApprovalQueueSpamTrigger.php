<?php

namespace ForumFortress\Protect\Service;

use XF\Entity\ApprovalQueue;

use function is_array;
use function json_decode;
use function json_encode;
use function strlen;
use function trim;

/**
 * Durable Forum Fortress metadata attached to XenForo approval queue rows.
 *
 * Spam checks run before new content has an ID. Deferred metadata is therefore
 * kept for the current request and consumed by the ApprovalQueue entity
 * extension when XenForo inserts the corresponding approval row.
 */
final class ApprovalQueueSpamTrigger
{
	public const TABLE_NAME = 'xf_ff_protect_approval_meta';
	public const MAX_JSON_BYTES = 262144;

	/** @var array<string, list<array{content_id: int, data: array<string, mixed>}>> */
	protected static array $deferredByContentType = [];

	public static function read(ApprovalQueue $item): ?array
	{
		return static::readByContent((string) $item->content_type, (int) $item->content_id);
	}

	public static function readByContent(string $contentType, int $contentId): ?array
	{
		$contentType = trim($contentType);
		if ($contentType === '' || $contentId <= 0)
		{
			return null;
		}

		try
		{
			$raw = \XF::db()->fetchOne(
				'SELECT metadata_json FROM ' . static::TABLE_NAME . ' WHERE content_type = ? AND content_id = ?',
				[$contentType, $contentId]
			);
		}
		catch (\Throwable $e)
		{
			return null;
		}

		if (!is_string($raw) || $raw === '' || strlen($raw) > static::MAX_JSON_BYTES)
		{
			return null;
		}

		$decoded = json_decode($raw, true);

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * @param callable(array):void $mutate Receives the merged record; modify in place.
	 */
	public static function write(ApprovalQueue $queue, callable $mutate): bool
	{
		return static::writeByContent(
			(string) $queue->content_type,
			(int) $queue->content_id,
			$mutate
		);
	}

	/**
	 * @param callable(array):void $mutate Receives the merged record; modify in place.
	 */
	public static function writeByContent(string $contentType, int $contentId, callable $mutate): bool
	{
		$contentType = trim($contentType);
		if ($contentType === '' || $contentId <= 0)
		{
			return false;
		}

		$data = static::readByContent($contentType, $contentId) ?? [];
		$mutate($data);
		$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!is_string($json) || strlen($json) > static::MAX_JSON_BYTES)
		{
			\XF::logError('[ForumFortress] Approval queue metadata exceeded the storage limit.', false);
			return false;
		}

		try
		{
			\XF::db()->insert(static::TABLE_NAME, [
				'content_type' => $contentType,
				'content_id' => $contentId,
				'metadata_json' => $json,
				'updated_at' => time(),
			], false, 'metadata_json = VALUES(metadata_json), updated_at = VALUES(updated_at)');

			return true;
		}
		catch (\Throwable $e)
		{
			\XF::logError('[ForumFortress] Could not persist approval queue metadata: ' . $e->getMessage(), false);
			return false;
		}
	}

	/** @param array<string, mixed> $forumFortressData */
	public static function deferForQueueReference(
		string $contentType,
		int $contentId,
		array $forumFortressData
	): void
	{
		$contentType = trim($contentType);
		if ($contentType === '' || !$forumFortressData)
		{
			return;
		}

		self::$deferredByContentType[$contentType][] = [
			'content_id' => max(0, $contentId),
			'data' => $forumFortressData,
		];
	}

	/**
	 * Attach an API decision to an existing approval row, or carry it through
	 * the native save so a newly-created approval row receives it.
	 */
	public static function recordDecision(string $contentType, int $contentId, int $decisionId): void
	{
		$contentType = trim($contentType);
		if ($contentType === '' || $decisionId <= 0)
		{
			return;
		}

		$queueExists = false;
		if ($contentId > 0)
		{
			try
			{
				$queueExists = (bool) \XF::db()->fetchOne(
					'SELECT 1 FROM xf_approval_queue WHERE content_type = ? AND content_id = ?',
					[$contentType, $contentId]
				);
			}
			catch (\Throwable $e)
			{
			}
		}

		if ($queueExists)
		{
			static::writeByContent($contentType, $contentId, static function (array &$data) use ($decisionId): void
			{
				if (!isset($data['forum_fortress']) || !is_array($data['forum_fortress']))
				{
					$data['forum_fortress'] = [];
				}
				$data['forum_fortress']['decision_id'] = $decisionId;
			});
			return;
		}

		static::deferForQueueReference($contentType, $contentId, ['decision_id' => $decisionId]);
	}

	public static function consumeDeferredForQueue(ApprovalQueue $queue): bool
	{
		$contentType = (string) $queue->content_type;
		if (empty(self::$deferredByContentType[$contentType]))
		{
			return false;
		}

		$matchIndex = null;
		$queueContentId = (int) $queue->content_id;
		foreach (self::$deferredByContentType[$contentType] as $index => $pending)
		{
			$expectedContentId = (int) ($pending['content_id'] ?? 0);
			if ($expectedContentId === 0 || $expectedContentId === $queueContentId)
			{
				$matchIndex = $index;
				break;
			}
		}
		if ($matchIndex === null)
		{
			return false;
		}

		$pending = self::$deferredByContentType[$contentType][$matchIndex];
		unset(self::$deferredByContentType[$contentType][$matchIndex]);
		self::$deferredByContentType[$contentType] = array_values(self::$deferredByContentType[$contentType]);
		if (!self::$deferredByContentType[$contentType])
		{
			unset(self::$deferredByContentType[$contentType]);
		}

		$forumFortressData = $pending['data'] ?? null;
		if (!is_array($forumFortressData))
		{
			return false;
		}

		return static::write($queue, static function (array &$data) use ($forumFortressData): void
		{
			$current = isset($data['forum_fortress']) && is_array($data['forum_fortress'])
				? $data['forum_fortress']
				: [];
			$data['forum_fortress'] = array_replace($current, $forumFortressData);
		});
	}

	public static function delete(ApprovalQueue $queue): void
	{
		static::deleteByContent((string) $queue->content_type, (int) $queue->content_id);
	}

	public static function deleteByContent(string $contentType, int $contentId): void
	{
		if ($contentType === '' || $contentId <= 0)
		{
			return;
		}

		try
		{
			\XF::db()->delete(static::TABLE_NAME, 'content_type = ? AND content_id = ?', [$contentType, $contentId]);
		}
		catch (\Throwable $e)
		{
		}
	}

	public static function pruneOrphans(): void
	{
		try
		{
			\XF::db()->query(
				'DELETE meta
				FROM ' . static::TABLE_NAME . ' AS meta
				LEFT JOIN xf_approval_queue AS queue_item
					ON queue_item.content_type = meta.content_type
					AND queue_item.content_id = meta.content_id
				WHERE queue_item.content_id IS NULL'
			);
		}
		catch (\Throwable $e)
		{
		}
	}

	public static function isTimeout(ApprovalQueue $queue): bool
	{
		$data = static::read($queue);
		$ff = is_array($data['forum_fortress'] ?? null) ? $data['forum_fortress'] : [];

		return trim((string) ($ff['challenge_tag'] ?? '')) === TimeoutApprovalMirror::TIMEOUT_TAG;
	}
}
