<?php

declare(strict_types=1);

use NexWaypoint\Users\UserRepository;

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$user = $app['auth']->requireAuth();
$repo = new UserRepository($app['db'], $app['logger']);

if ($repo->isAdmin($user)) {
    header('Location: /settings/site.php#rail-operators', true, 302);
} else {
    header('Location: /trains/add.php', true, 302);
}
exit;
