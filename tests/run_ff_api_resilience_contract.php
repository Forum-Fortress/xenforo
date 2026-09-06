<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/upload/src/addons/ForumFortress/Protect/Service/FfApiResilience.php';
require_once __DIR__ . '/FfApiResilienceContract.php';

exit(FfApiResilienceContract::run());
