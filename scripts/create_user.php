<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * @return non-empty-string
 */
function prompt(string $label, ?string $default = null): string
{
    $suffix = $default === null ? ': ' : " [{$default}]: ";
    fwrite(STDOUT, $label . $suffix);
    $value = trim((string) fgets(STDIN));
    $value = $value === '' ? (string) $default : $value;

    if ($value === '') {
        fwrite(STDERR, "{$label} is required.\n");
        exit(2);
    }

    return $value;
}

function readHidden(string $label): string
{
    fwrite(STDOUT, "{$label}: ");
    $canHide = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec');

    if ($canHide) {
        shell_exec('stty -echo');
    }

    try {
        $value = rtrim((string) fgets(STDIN), "\r\n");
    } finally {
        if ($canHide) {
            shell_exec('stty echo');
            fwrite(STDOUT, "\n");
        }
    }

    return $value;
}

$options = getopt('', [
    'username:',
    'email:',
    'name:',
    'manager-id:',
    'admin',
    'role:', // deprecated; ignored except for help note
    'password-env:',
    'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<'HELP'
Create a NexWAYPOINT local user.

Usage:
  php scripts/create_user.php [options]

Options:
  --username=NAME       Login username
  --email=ADDRESS       User email address
  --name="Full Name"    Display name
  --manager-id=ID       Solid-line manager's numeric user ID (reports to)
  --admin               Grant site-admin flag (Users / Site settings)
  --password-env=NAME   Read the password from this environment variable
  --help                Show this help

Org structure is who reports to whom (--manager-id), not a role dropdown.
--role is deprecated and ignored. Missing values are prompted interactively.
Passwords are never accepted as command-line arguments.

HELP);
    exit(0);
}

$username = trim((string) ($options['username'] ?? prompt('Username')));
$email = trim((string) ($options['email'] ?? prompt('Email address')));
$displayName = trim((string) ($options['name'] ?? prompt('Display name')));
$isAdmin = isset($options['admin']);
$managerId = isset($options['manager-id']) ? filter_var($options['manager-id'], FILTER_VALIDATE_INT) : null;

if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
    fwrite(STDERR, "Username must be 3-100 characters using letters, numbers, dot, underscore, or hyphen.\n");
    exit(2);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "A valid email address is required.\n");
    exit(2);
}
if (isset($options['manager-id']) && ($managerId === false || $managerId < 1)) {
    fwrite(STDERR, "Manager ID must be a positive integer.\n");
    exit(2);
}
if (isset($options['role'])) {
    fwrite(STDERR, "Note: --role is deprecated; use --manager-id for reporting line and --admin for site admin.\n");
}

$passwordEnvironmentName = (string) ($options['password-env'] ?? '');
if ($passwordEnvironmentName !== '') {
    $password = getenv($passwordEnvironmentName);
    if ($password === false) {
        fwrite(STDERR, "Environment variable {$passwordEnvironmentName} is not set.\n");
        exit(2);
    }
} else {
    $password = readHidden('Password (minimum 12 characters)');
    $confirmation = readHidden('Confirm password');
    if (!hash_equals($password, $confirmation)) {
        fwrite(STDERR, "Passwords do not match.\n");
        exit(2);
    }
}

if (strlen($password) < 12) {
    fwrite(STDERR, "Password must be at least 12 characters.\n");
    exit(2);
}

try {
    /** @var array{users: \NexWaypoint\Users\UserRepository} $app */
    $app = require dirname(__DIR__) . '/config/bootstrap.php';
    if ($app['users']->findByUsername($username) !== null) {
        fwrite(STDERR, "Username '{$username}' already exists.\n");
        exit(1);
    }

    $user = $app['users']->create(
        $username,
        $email,
        $password,
        $displayName,
        'subordinate',
        $managerId === false ? null : $managerId,
        null,
        $isAdmin,
    );
    $adminNote = $user->isAdmin ? ', site admin' : '';
    $mgrNote = $user->managerId !== null ? ", reports to #{$user->managerId}" : '';
    fwrite(STDOUT, "Created {$user->username} (user ID {$user->id}{$mgrNote}{$adminNote}).\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "User creation failed: {$exception->getMessage()}\n");
    exit(1);
}
