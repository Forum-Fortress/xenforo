<?php

namespace ForumFortress\Protect;

class Listener
{
	public static function addUserProvider(\XF\SubContainer\Spam $container, \XF\Container $parentContainer, array &$providers): void
	{
		if (!in_array('ForumFortress\\Protect:ForumFortressUser', $providers, true))
		{
			$providers[] = 'ForumFortress\\Protect:ForumFortressUser';
		}
	}

	public static function addUserSubmitter(\XF\SubContainer\Spam $container, \XF\Container $parentContainer, \XF\Spam\UserChecker &$checker): void
	{
		$checker->addProvider($container->container()->create('provider', 'ForumFortress\\Protect:ForumFortressUser', [$checker, \XF::app()]));
	}

	public static function addContentProvider(\XF\SubContainer\Spam $container, \XF\Container $parentContainer, array &$providers): void
	{
		if (!in_array('ForumFortress\\Protect:ForumFortressContent', $providers, true))
		{
			$providers[] = 'ForumFortress\\Protect:ForumFortressContent';
		}
	}

	public static function addContentSubmitter(\XF\SubContainer\Spam $container, \XF\Container $parentContainer, \XF\Spam\ContentChecker &$checker): void
	{
		$checker->addProvider($container->container()->create('provider', 'ForumFortress\\Protect:ForumFortressContent', [$checker, \XF::app()]));
	}

	public static function addContentHamSubmitter(\XF\SubContainer\Spam $container, \XF\Container $parentContainer, \XF\Spam\ContentChecker &$checker): void
	{
		$checker->addProvider($container->container()->create('provider', 'ForumFortress\\Protect:ForumFortressContent', [$checker, \XF::app()]));
	}
}
