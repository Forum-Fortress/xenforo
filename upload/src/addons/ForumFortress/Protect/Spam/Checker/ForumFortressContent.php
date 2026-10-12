<?php

namespace ForumFortress\Protect\Spam\Checker;

use ForumFortress\Protect\Service\ApiClient;
use ForumFortress\Protect\Service\DecisionMapper;
use XF\Entity\User;
use XF\Spam\Checker\AbstractProvider;
use XF\Spam\Checker\ContentCheckerInterface;

class ForumFortressContent extends AbstractProvider implements ContentCheckerInterface
{
	protected function getType()
	{
		return 'ForumFortressContent';
	}

	public function check(User $user, $message, array $extraParams = [])
	{
		$client = new ApiClient($this->app());
		if (!$client->isEnabled())
		{
			return;
		}

		$client->bootstrapIfNeeded();

		$contentType = (string) ($extraParams['content_type'] ?? 'post');
		$endpoint = $this->mapContentTypeToEndpoint($contentType, $extraParams);
		$links = ApiClient::filterExternalLinks(ApiClient::extractLinks((string) $message), $client->getDomain());

		$payload = $client->buildUserPayload($user, [
			'content' => (string) $message,
			'links' => $links,
			'forum_id' => null,
			'content_id' => isset($extraParams['content_id']) ? (string) $extraParams['content_id'] : null,
			'thread_id' => isset($extraParams['thread_id']) ? (string) $extraParams['thread_id'] : null,
		]);

		if (substr($endpoint, -5) === '_edit')
		{
			$payload = array_merge($payload, $this->loadPersistedEditBaseline($contentType, $extraParams));
		}

		if ($endpoint === 'signature_edit')
		{
			$payload['signature_text'] = (string) $message;
			unset($payload['content']);
		}

		$response = $client->check($endpoint, $payload);
		$failOpen = $client->getBoolOption('ffProtectFailOpen', true);
		if ($response === null)
		{
			$decision = DecisionMapper::toUnavailableDecision($failOpen);
			if ($decision === 'moderated')
			{
				(new \ForumFortress\Protect\Service\TimeoutApprovalMirror($this->app()))
					->mirrorContentUnavailable(
						$extraParams,
						$payload,
						$endpoint,
						$client->lastCheckHadTimeout() ? 'timeout' : 'api_unavailable'
					);
			}
			$this->logDecision($decision);
			return;
		}
		$decision = DecisionMapper::toContentDecision($response, $failOpen);
		$queueContentType = $this->approvalQueueContentType($contentType);
		if (
			$queueContentType !== null
			&& DecisionMapper::requiresAvailabilityRecovery($response, $decision)
		)
		{
			(new \ForumFortress\Protect\Service\TimeoutApprovalMirror($this->app()))
				->mirrorContentUnavailable(
					$extraParams,
					$payload,
					$endpoint,
					DecisionMapper::isAboveLimit($response) ? 'above_limit' : 'invalid_response'
				);
		}
		elseif ($queueContentType !== null && DecisionMapper::shouldRecordDecision($response, $decision))
		{
			\ForumFortress\Protect\Service\ApprovalQueueSpamTrigger::recordDecision(
				$queueContentType,
				(int) ($extraParams['content_id'] ?? 0),
				(int) $response['decision_id']
			);
		}
		$this->logDecision($decision);

		if ($decision === 'denied')
		{
			(new \ForumFortress\Protect\Service\RepeatOffender($this->app()))->record(
				$user, (int) \XF::visitor()->user_id, $response, $contentType,
				(string) $message, (int) ($extraParams['content_id'] ?? 0)
			);
		}

		$this->logParam('ff_endpoint', $endpoint);
		$this->logParam('ff_content_type', $contentType);
		$this->logParam('ff_domain', $client->getDomain());
		$this->logParam('ff_ip', $payload['ip'] ?? '');
		$this->logParam('ff_username', $payload['username'] ?? '');
		$this->logParam('ff_email', $payload['email'] ?? '');
		$this->logParam('ff_email_domain', ApiClient::emailDomain($user->email) ?? '');
		$this->logParam('ff_links', $links);
		$this->logParam('ff_content_hash', sha1((string) $message));
		$this->logParam('ff_decision', $decision);
	}

	public function submitSpam($contentType, $contentIds)
	{
		$client = new ApiClient($this->app());
		if (!$client->isEnabled())
		{
			return;
		}

		$params = $this->getContentSpamCheckParams($contentType, $contentIds);
		foreach ($params as $param)
		{
			if (!is_array($param))
			{
				continue;
			}

			$client->report('moderation', [
				'domain' => $param['ff_domain'] ?? $client->getDomain(),
				'api_key' => $client->getStringOption('ffProtectApiKey'),
				'forum_id' => null,
				'username' => $param['ff_username'] ?? null,
				'email_domain' => $param['ff_email_domain'] ?? null,
				'ip' => $param['ff_ip'] ?? null,
				'links' => is_array($param['ff_links'] ?? null) ? $param['ff_links'] : [],
				'content_hash' => $param['ff_content_hash'] ?? null,
				'payload' => [
					'content_type' => $contentType,
					'endpoint' => $param['ff_endpoint'] ?? null,
				],
			]);
		}
	}

	public function submitHam($contentType, $contentIds)
	{
		if (!(new ApiClient($this->app()))->getBoolOption('ffProtectSendHam', true))
		{
			return;
		}

		$client = new ApiClient($this->app());
		if (!$client->isEnabled())
		{
			return;
		}

		$params = $this->getContentSpamCheckParams($contentType, $contentIds);
		foreach ($params as $param)
		{
			if (!is_array($param))
			{
				continue;
			}

			$client->report('ham', [
				'domain' => $param['ff_domain'] ?? $client->getDomain(),
				'api_key' => $client->getStringOption('ffProtectApiKey'),
				'forum_id' => null,
				'username' => $param['ff_username'] ?? null,
				'email_domain' => $param['ff_email_domain'] ?? null,
				'ip' => $param['ff_ip'] ?? null,
				'links' => is_array($param['ff_links'] ?? null) ? $param['ff_links'] : [],
				'content_hash' => $param['ff_content_hash'] ?? null,
				'payload' => [
					'content_type' => $contentType,
					'endpoint' => $param['ff_endpoint'] ?? null,
				],
			]);
		}
	}

	/**
	 * Read the persisted pre-save body instead of the mutable entity instance.
	 * XenForo's content spam checker runs while the edited entity may already
	 * contain the submitted message in memory, while the database still holds
	 * the version that Forum Fortress needs for an edit delta.
	 */
	protected function loadPersistedEditBaseline(string $contentType, array $extraParams): array
	{
		$contentId = isset($extraParams['content_id']) ? (int) $extraParams['content_id'] : 0;
		if ($contentId <= 0)
		{
			return [];
		}

		$db = $this->app()->db();
		$row = null;
		try
		{
			switch (strtolower($contentType))
			{
				case 'thread':
					$row = $db->fetchRow(
						'SELECT p.message, p.post_date FROM xf_thread AS t ' .
						'INNER JOIN xf_post AS p ON (p.post_id = t.first_post_id) ' .
						'WHERE t.thread_id = ?',
						$contentId
					);
					break;

				case 'post':
					$row = $db->fetchRow(
						'SELECT message, post_date FROM xf_post WHERE post_id = ?',
						$contentId
					);
					break;

				default:
					return [];
			}
		}
		catch (\Throwable $e)
		{
			// Edit history is additive intelligence. Never make posting depend on
			// an optional baseline lookup succeeding on a customer installation.
			return [];
		}

		if (!is_array($row) || !array_key_exists('message', $row))
		{
			return [];
		}

		$baseline = ['previous_content' => (string) $row['message']];
		$postDate = (int) ($row['post_date'] ?? 0);
		if ($postDate > 0)
		{
			$baseline['edit_age_seconds'] = max(0, time() - $postDate);
		}

		return $baseline;
	}

	protected function mapContentTypeToEndpoint(string $contentType, array $extraParams = []): string
	{
		$isEdit = isset($extraParams['content_id']) && (int) $extraParams['content_id'] > 0;
		switch ($contentType)
		{
			case 'thread':
				return $isEdit ? 'topic_edit' : 'topic';
			case 'user_signature':
				return 'signature_edit';
			case 'contact':
				return 'contact_page';
			case 'user':
				return $isEdit ? 'profile_edit' : 'profile';
			case 'profile_post':
			case 'post':
			default:
				return $isEdit ? 'reply_edit' : 'reply';
		}
	}

	protected function approvalQueueContentType(string $contentType): ?string
	{
		switch ($contentType)
		{
			case 'thread':
			case 'post':
			case 'profile_post':
			case 'profile_post_comment':
				return $contentType;
			default:
				return null;
		}
	}
}
