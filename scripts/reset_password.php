<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/_cli_common.php';

use NexWaypoint\Core\Env;

$options = getopt('', [
    'username:',
    'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<'HELP'
Reset a local user's password to a new random value.

Usage:
  php scripts/reset_password.php [options]

Options:
  --username=NAME   Login username (default: admin, or ADMIN_USERNAME from .env)
  --help            Show this help

The new password is printed once to stdout only.

HELP);
    exit(0);
}

try {
    /** @var array{users: \NexWaypoint\Users\UserRepository} $app */
    $app = require dirname(__DIR__) . '/config/bootstrap.php';

    $username = trim((string) ($options['username'] ?? Env::get('ADMIN_USERNAME', 'admin')));
    if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
        throw new InvalidArgumentException('Username must be 3-100 characters using letters, numbers, dot, underscore, or hyphen.');
    }

    $user = $app['users']->findByUsername($username);
    if ($user === null) {
        fwrite(STDERR, "User '{$username}' not found.\n");
        exit(1);
    }

    $password = cli_generate_password();
    $app['users']->updatePassword($user->id, $password);

    fwrite(STDOUT, "Password reset for {$user->username} (user ID {$user->id}).\n\n");
    fwrite(STDOUT, "New password (save it now):\n");
    fwrite(STDOUT, "  {$password}\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Password reset failed: {$exception->getMessage()}\n");
    exit(1);
}
