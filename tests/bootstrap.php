<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap. Loads the manual autoloader (no Composer dependency
 * for the app itself) and the test-support base class.
 */

define('NEXWAYPOINT_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $map = [
        'NexWaypoint\\' => NEXWAYPOINT_ROOT . '/src/',
        'NexWaypoint\\Tests\\' => NEXWAYPOINT_ROOT . '/tests/',
    ];
    foreach ($map as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            $path = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require $path;
                return;
            }
        }
    }
});

date_default_timezone_set('America/Chicago');
