<?php

declare(strict_types=1);

/**
 * Application bootstrap. Included by every entry point (public/index.php,
 * cron/*.php, scripts/*.php, tests/bootstrap.php).
 *
 * Zero-Composer-dependency by design: NexWAYPOINT runs on shared hosting
 * (DreamHost) where `composer install` may not be practical mid-trip, so a
 * manual PSR-4-ish autoloader is used instead of vendor/autoload.php.
 * Composer is still used for *dev* tooling (PHPUnit) -- see composer.json.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak errors to output; log them instead

define('NEXWAYPOINT_ROOT', dirname(__DIR__));

// --- Autoloader: NexWaypoint\Foo\Bar -> src/Foo/Bar.php ---------------------
spl_autoload_register(static function (string $class): void {
    $prefix = 'NexWaypoint\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = NEXWAYPOINT_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use NexWaypoint\Core\Env;
use NexWaypoint\Core\Logger;
use NexWaypoint\Core\Database;
use NexWaypoint\Core\Auth;
use NexWaypoint\Users\UserRepository;

$envPath = getenv('NEXWAYPOINT_ENV_PATH') ?: NEXWAYPOINT_ROOT . '/.env';
Env::load($envPath);

date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Chicago'));

$logger = new Logger(
    Env::get('LOG_FILE', NEXWAYPOINT_ROOT . '/storage/logs/app.log'),
    Env::get('LOG_LEVEL', 'info')
);

set_exception_handler(static function (\Throwable $e) use ($logger): void {
    $logger->critical('Uncaught exception', [
        'exception' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    http_response_code(500);
    if (PHP_SAPI !== 'cli') {
        echo 'An internal error occurred. It has been logged.';
    }
});

set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($logger): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    $logger->error('PHP error', ['severity' => $severity, 'message' => $message, 'file' => $file, 'line' => $line]);
    return true;
});

$db = Database::fromEnv($logger);
$userRepository = new UserRepository($db, $logger);
$auth = new Auth($userRepository);

return [
    'logger' => $logger,
    'db' => $db,
    'users' => $userRepository,
    'auth' => $auth,
];
