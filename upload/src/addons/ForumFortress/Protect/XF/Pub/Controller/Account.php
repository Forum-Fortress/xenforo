<?php

namespace ForumFortress\Protect\XF\Pub\Controller;

use ForumFortress\Protect\Service\ApiClient;
use ForumFortress\Protect\Service\DecisionMapper;
use XF\Entity\User;
use XF\Mvc\FormAction;

class Account extends XFCP_Account
{
	protected function accountDetailsSaveProcess(User $visitor)
	{
		$form = parent::accountDetailsSaveProcess($visitor);
		$input = $this->filter([
			'profile' => [
				'website' => 'str',
			],
		]);

		$form->validate(function (FormAction $form) use ($input, $visitor)
		{
			if (!$visitor->isSpamCheckRequired())
			{
				return;
			}

			$website = trim((string) ($input['profile']['website'] ?? ''));
			if ($website === '')
			{
				return;
			}

			$client = new ApiClient($this->app());
			if (!$client->isEnabled())
			{
				return;
			}
			$failOpen = $client->getBoolOption('ffProtectFailOpen', true);

			try
			{
				$client->bootstrapIfNeeded();

				$links = [];
				$normalized = ApiClient::extractDomain($website);
				if ($normalized)
				{
					$links[] = 'https://' . $normalized;
				}
				$links = ApiClient::filterExternalLinks(
					array_values(array_unique($links)),
					$client->getDomain()
				);

				$response = $client->check('profile_edit', $client->buildUserPayload($visitor, [
					'profile_fields' => [
						'website' => $website,
					],
					'links' => $links,
					'content' => null,
					'content_id' => (string) $visitor->user_id,
				]));
			}
			catch (\Throwable $e)
			{
				$response = null;
			}

			$decision = DecisionMapper::toContentDecision($response, $failOpen);
			if ($decision === 'denied')
			{
				$form->logError(\XF::phrase('ff_profile_blocked'));
			}
			elseif ($decision === 'moderated')
			{
				$form->logError(\XF::phrase('ff_api_request_failed'));
			}
		});

		return $form;
	}
}
