<?php

declare(strict_types=1);

$app = require dirname(__DIR__, 2) . '/config/bootstrap.php';
$app['auth']->requireAuth();

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '/settings/users.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $target, true, 302);
exit;
