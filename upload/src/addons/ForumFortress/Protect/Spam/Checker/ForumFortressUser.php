<?php

namespace ForumFortress\Protect\Spam\Checker;

use ForumFortress\Protect\Service\ApiClient;
use ForumFortress\Protect\Service\DecisionMapper;
use XF\Entity\User;
use XF\Spam\Checker\AbstractProvider;
use XF\Spam\Checker\UserCheckerInterface;

class ForumFortressUser extends AbstractProvider implements UserCheckerInterface
{
	protected function getType()
	{
		return 'ForumFortressUser';
	}

	public function check(User $user, array $extraParams = [])
	{
		$client = new ApiClient($this->app());
		if (!$client->isEnabled())
		{
			return;
		}

		$client->bootstrapIfNeeded();

		$payload = $client->buildUserPayload($user, [
			'content' => null,
			'links' => [],
		]);

		$failOpen = $client->getBoolOption('ffProtectFailOpen', true);
		$response = $client->check('register', $payload);

		if ($response === null)
		{
			$decision = DecisionMapper::toUnavailableDecision($failOpen);
			if ($decision === 'moderated')
			{
				(new \ForumFortress\Protect\Service\TimeoutApprovalMirror($this->app()))
					->mirrorRegistrationUnavailable(
						$user,
						$payload,
						'register',
						$client->lastCheckHadTimeout() ? 'timeout' : 'api_unavailable'
					);
			}
			$this->logDecision($decision);
			return;
		}

		$decision = DecisionMapper::toUserDecision($response, $failOpen);
		if (DecisionMapper::requiresAvailabilityRecovery($response, $decision))
		{
			(new \ForumFortress\Protect\Service\TimeoutApprovalMirror($this->app()))
				->mirrorRegistrationUnavailable(
					$user,
					$payload,
					'register',
					DecisionMapper::isAboveLimit($response) ? 'above_limit' : 'invalid_response'
				);
		}
		elseif (DecisionMapper::shouldRecordDecision($response, $decision))
		{
			\ForumFortress\Protect\Service\ApprovalQueueSpamTrigger::recordDecision(
				'user',
				(int) $user->user_id,
				(int) $response['decision_id']
			);
		}
		$this->logDecision($decision);

		$this->logParam('ff_endpoint', 'register');
		$this->logParam('ff_domain', $client->getDomain());
		$this->logParam('ff_ip', $payload['ip'] ?? '');
		$this->logParam('ff_username', $payload['username'] ?? '');
		$this->logParam('ff_email', $payload['email'] ?? '');
		$this->logParam('ff_email_domain', ApiClient::emailDomain($user->email) ?? '');
		$this->logParam('ff_decision', $decision);
	}

	public function submit(User $user, array $extraParams = [])
	{
		$client = new ApiClient($this->app());
		if (!$client->isEnabled())
		{
			return;
		}

		$payload = $client->buildUserPayload($user, [
			'forum_id' => null,
			'links' => [],
			'payload' => [
				'source' => 'xenforo_registration',
			],
			'email_domain' => ApiClient::emailDomain($user->email),
		]);

		unset($payload['email'], $payload['account_age_seconds'], $payload['post_count'], $payload['ip']);

		$client->report('register', $payload);
	}
}
