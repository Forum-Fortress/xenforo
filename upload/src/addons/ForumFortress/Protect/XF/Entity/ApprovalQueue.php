<?php

namespace ForumFortress\Protect\XF\Entity;

use ForumFortress\Protect\Service\ApprovalQueueSpamTrigger;
use XF\Mvc\Entity\Structure;

class ApprovalQueue extends XFCP_ApprovalQueue
{
	protected function _postSave()
	{
		parent::_postSave();

		if ($this->isInsert())
		{
			ApprovalQueueSpamTrigger::consumeDeferredForQueue($this);
		}
	}

	protected function _postDelete()
	{
		parent::_postDelete();
		ApprovalQueueSpamTrigger::delete($this);
	}

	public function getFfProtectTimeoutLabel(): bool
	{
		return ApprovalQueueSpamTrigger::isTimeout($this);
	}

	public static function getStructure(Structure $structure)
	{
		$structure = parent::getStructure($structure);
		$structure->getters['ffProtectTimeoutLabel'] = true;

		return $structure;
	}
}
