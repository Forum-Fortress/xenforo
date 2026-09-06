<?php

declare(strict_types=1);

namespace XF\Entity {
	class ApprovalQueue
	{
		public string $content_type = 'post';
		public int $content_id = 42;
	}

	class User
	{
	}
}

namespace XF {
	class App
	{
	}
}

namespace {
	final class MetadataDbStub
	{
		/** @var array<string, array<string, mixed>> */
		public array $rows = [];

		public function fetchOne(string $sql, array $params)
		{
			$key = (string) $params[0] . ':' . (int) $params[1];

			return $this->rows[$key]['metadata_json'] ?? false;
		}

		public function insert(string $table, array $data, bool $throw, string $onDuplicate): int
		{
			$key = (string) $data['content_type'] . ':' . (int) $data['content_id'];
			$this->rows[$key] = $data;

			return 1;
		}

		public function delete(string $table, string $where, array $params): int
		{
			$key = (string) $params[0] . ':' . (int) $params[1];
			unset($this->rows[$key]);

			return 1;
		}
	}

	class XF
	{
		public static MetadataDbStub $db;

		public static function db(): MetadataDbStub
		{
			return self::$db;
		}

		public static function logError(string $message, bool $trace = true): void
		{
		}
	}

	require_once __DIR__ . '/../upload/src/addons/ForumFortress/Protect/Service/TimeoutApprovalMirror.php';
	require_once __DIR__ . '/../upload/src/addons/ForumFortress/Protect/Service/ApprovalQueueSpamTrigger.php';
	require_once __DIR__ . '/../upload/src/addons/ForumFortress/Protect/Service/ModerationBridge.php';

	use ForumFortress\Protect\Service\ApprovalQueueSpamTrigger;
	use ForumFortress\Protect\Service\ModerationBridge;
	use XF\Entity\ApprovalQueue;

	function assertSameValue($expected, $actual, string $label): void
	{
		if ($expected !== $actual)
		{
			fwrite(STDERR, $label . "\nexpected: " . var_export($expected, true) . "\nactual: " . var_export($actual, true) . "\n");
			exit(1);
		}
	}

	\XF::$db = new MetadataDbStub();
	$queue = new ApprovalQueue();
	ApprovalQueueSpamTrigger::deferForQueueReference('post', 0, [
		'decision_id' => 987,
		'challenge_tag' => 'FFTimeout',
	]);
	assertSameValue(true, ApprovalQueueSpamTrigger::consumeDeferredForQueue($queue), 'deferred metadata is consumed after queue insert');
	assertSameValue(
		987,
		ApprovalQueueSpamTrigger::read($queue)['forum_fortress']['decision_id'] ?? null,
		'decision ID survives durable metadata round-trip'
	);
	assertSameValue(true, ApprovalQueueSpamTrigger::isTimeout($queue), 'timeout tag is available to the entity getter');
	ApprovalQueueSpamTrigger::delete($queue);
	assertSameValue(null, ApprovalQueueSpamTrigger::read($queue), 'metadata is deleted with its approval row');
	ApprovalQueueSpamTrigger::deferForQueueReference('post', 99, ['decision_id' => 654]);
	assertSameValue(false, ApprovalQueueSpamTrigger::consumeDeferredForQueue($queue), 'existing-content metadata cannot attach to a different row');
	$queue->content_id = 99;
	assertSameValue(true, ApprovalQueueSpamTrigger::consumeDeferredForQueue($queue), 'existing-content metadata attaches to the matching row');
	assertSameValue(
		654,
		ApprovalQueueSpamTrigger::read($queue)['forum_fortress']['decision_id'] ?? null,
		'matching deferred decision is durable'
	);

	$reflection = new \ReflectionClass(ModerationBridge::class);
	$bridge = $reflection->newInstanceWithoutConstructor();
	$mapAction = $reflection->getMethod('mapActionName');
	$mapAction->setAccessible(true);
	assertSameValue('reject', $mapAction->invoke($bridge, 'reject', 'user'), 'user rejection uses the native reject handler');
	assertSameValue('delete', $mapAction->invoke($bridge, 'reject', 'post'), 'content rejection uses the native delete handler');

	echo "OK XenForo moderation metadata\n";
}
