<?php

namespace ForumFortress\Protect\Job;

use XF\Job\AbstractJob;
use XF\Service\User\DeleteService;

class DeleteRejectedUser extends AbstractJob
{
	protected $defaultData = [
		'user_id' => 0,
	];

	public function run($maxRunTime)
	{
		$userId = (int) ($this->data['user_id'] ?? 0);
		if (!$userId)
		{
			return $this->complete();
		}

		/** @var \XF\Entity\User|null $user */
		$user = $this->app->finder('XF:User')->where('user_id', $userId)->fetchOne();
		if (!$user || $user->user_state !== 'rejected')
		{
			return $this->complete();
		}

		/** @var DeleteService $deleter */
		$deleter = $this->app->service(DeleteService::class, $user);
		$deleter->delete();

		return $this->complete();
	}

	public function getStatusMessage()
	{
		return (string) \XF::phrase('deleting');
	}

	public function canCancel()
	{
		return false;
	}

	public function canTriggerByChoice()
	{
		return false;
	}
}
