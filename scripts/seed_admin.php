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
    'email:',
    'name:',
    'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<'HELP'
Create the initial admin account with a random password if no users exist.

Usage:
  php scripts/seed_admin.php [options]

Options:
  --username=NAME   Login username (default: admin, or ADMIN_USERNAME from .env)
  --email=ADDRESS   Email address (default: ADMIN_EMAIL from .env)
  --name="Name"     Display name (default: Administrator)
  --help            Show this help

The generated password is printed once and saved to storage/admin-credentials.txt
(mode 600). Delete that file after first login.

HELP);
    exit(0);
}

try {
    /** @var array{users: \NexWaypoint\Users\UserRepository} $app */
    $app = require dirname(__DIR__) . '/config/bootstrap.php';

    $existing = $app['users']->findAllActive();
    if ($existing !== []) {
        fwrite(STDOUT, "Users already exist (" . count($existing) . "); skipping admin seed.\n");
        fwrite(STDOUT, "Reset a password with: ./setup.sh reset-password\n");
        exit(0);
    }

    $username = trim((string) ($options['username'] ?? Env::get('ADMIN_USERNAME', 'admin')));
    $email = trim((string) ($options['email'] ?? Env::get('ADMIN_EMAIL', 'admin@nexwaypoint.area51consulting.com')));
    $displayName = trim((string) ($options['name'] ?? 'Administrator'));

    if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
        throw new InvalidArgumentException('Admin username must be 3-100 characters using letters, numbers, dot, underscore, or hyphen.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('A valid admin email address is required.');
    }
    if ($app['users']->findByUsername($username) !== null) {
        fwrite(STDERR, "Username '{$username}' already exists.\n");
        exit(1);
    }

    $password = cli_generate_password();
    $user = $app['users']->create(
        $username,
        $email,
        $password,
        $displayName,
        'subordinate', // legacy column; site access is is_admin
        null,
        null,
        true, // is_admin
    );

    $credentialsPath = dirname(__DIR__) . '/storage/admin-credentials.txt';
    cli_write_credentials_file($credentialsPath, $username, $password);

    fwrite(STDOUT, "Created admin user {$user->username} (user ID {$user->id}).\n\n");
    fwrite(STDOUT, "Save these credentials now — the password is not stored in plain text elsewhere:\n");
    fwrite(STDOUT, "  Username: {$username}\n");
    fwrite(STDOUT, "  Password: {$password}\n\n");
    fwrite(STDOUT, "Also saved to {$credentialsPath} (mode 600). Delete after first login.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Admin seed failed: {$exception->getMessage()}\n");
    exit(1);
}
