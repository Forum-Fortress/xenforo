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
				'location' => 'str',
				'website' => 'str',
			],
			'user' => [
				'custom_title' => 'str',
			],
		]);
		$input['profile']['about'] = $this->plugin('XF:Editor')->fromInput('about');

		$form->validate(function (FormAction $form) use ($input, $visitor)
		{
			if (!$visitor->isSpamCheckRequired())
			{
				return;
			}

			$website = trim((string) ($input['profile']['website'] ?? ''));
			$location = trim((string) ($input['profile']['location'] ?? ''));
			$about = trim((string) ($input['profile']['about'] ?? ''));
			$customTitle = trim((string) ($input['user']['custom_title'] ?? ''));

			$profileFields = [
				'website' => $website,
				'location' => $location,
				'about' => $about,
				'custom_title' => $customTitle,
			];
			$profileFields = array_filter($profileFields, static function ($value)
			{
				return trim((string) $value) !== '';
			});
			if (!$profileFields)
			{
				return;
			}

			$content = implode("\n", array_values($profileFields));

			$client = new ApiClient($this->app());
			if (!$client->isEnabled())
			{
				return;
			}
			$failOpen = $client->getBoolOption('ffProtectFailOpen', true);

			try
			{
				$client->bootstrapIfNeeded();

				$links = ApiClient::extractLinks($content);
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
					'profile_fields' => $profileFields,
					'links' => $links,
					'content' => $content,
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
				(new \ForumFortress\Protect\Service\RepeatOffender($this->app()))->record(
					$visitor, (int) \XF::visitor()->user_id, $response, 'user',
					$content, (int) $visitor->user_id
				);
			}
			elseif ($decision === 'moderated')
			{
				$form->logError(\XF::phrase('ff_api_request_failed'));
			}
		});

		return $form;
	}
}
