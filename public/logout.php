<?php

declare(strict_types=1);

$app = require dirname(__DIR__) . '/config/bootstrap.php';
$app['auth']->logout();
header('Location: /login.php');
exit;
