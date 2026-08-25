<?php

namespace ForumFortress\Protect\XF\Service\User;

use ForumFortress\Protect\Job\DeleteRejectedUser;
use XF\Entity\User;

/**
 * Thin RegistrationService extension: customize FF spam reject messaging and optional auto-delete.
 * Blocking uses XenForo's native user spam checker (logDecision denied/moderated), not pre-save validation hacks.
 */
class Registration extends XFCP_Registration
{
	public function checkForSpam()
	{
		parent::checkForSpam();
		$this->enforceForumFortressBlock();
	}

	protected function _save()
	{
		/** @var User $user */
		$user = parent::_save();
		$this->queueRejectedUserCleanup($user);

		return $user;
	}

	/**
	 * A provider decision is authoritative. XenForo normally applies ``denied``
	 * itself, but other spam providers/extensions can leave the registration user
	 * valid after the checker has run. Never let that turn a Fortress block into a
	 * successful account.
	 */
	protected function enforceForumFortressBlock(): void
	{
		$user = $this->user;
		$userChecker = $this->app->spam()->userChecker();
		if ($userChecker->getDecision('ForumFortressUser') !== 'denied')
		{
			return;
		}

		$session = $this->app->session();
		if ($session)
		{
			$session->set('ffProtectRejectedByForumFortress', true);
		}

		if ($user->user_state !== 'rejected')
		{
			$phrase = $this->resolveForumFortressRejectPhrase($userChecker);
			$user->setUserRejected($phrase->render());
		}
	}

	protected function resolveForumFortressRejectPhrase(\XF\Spam\UserChecker $userChecker): \XF\Phrase
	{
		$detail = $userChecker->getDetails()['ForumFortressUser'] ?? null;
		$reason = is_array($detail) ? (string) ($detail['data']['reason'] ?? '') : '';

		if ($reason === 'above_limit')
		{
			return \XF::phrase('ff_above_limit');
		}

		return \XF::phrase('ff_register_blocked');
	}

	protected function queueRejectedUserCleanup(User $user): void
	{
		if ($user->user_state !== 'rejected')
		{
			return;
		}

		if ($this->getRegistrationBlockMode() !== 'auto_delete')
		{
			return;
		}

		$session = $this->app->session();
		if (!$session || !$session->get('ffProtectRejectedByForumFortress'))
		{
			return;
		}

		$session->remove('ffProtectRejectedByForumFortress');
		$this->app->jobManager()->enqueueUnique(
			'ffProtectDeleteRejectedUser' . $user->user_id,
			DeleteRejectedUser::class,
			['user_id' => $user->user_id]
		);
	}

	protected function getRegistrationBlockMode(): string
	{
		$options = \XF::options();
		$mode = isset($options->ffProtectRegistrationBlockMode)
			? (string) $options->ffProtectRegistrationBlockMode
			: 'rejected_keep';

		return in_array($mode, ['rejected_keep', 'auto_delete'], true)
			? $mode
			: 'rejected_keep';
	}
}
