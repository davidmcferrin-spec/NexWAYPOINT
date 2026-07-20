<?php

declare(strict_types=1);

/**
 * @return non-empty-string
 */
function cli_generate_password(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

function cli_write_credentials_file(string $path, string $username, string $password): void
{
    $contents = implode(PHP_EOL, [
        '# NexWAYPOINT admin credentials — delete after first login',
        'username=' . $username,
        'password=' . $password,
        'generated_at=' . gmdate('c'),
        '',
    ]);
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException("Unable to write credentials file: {$path}");
    }
    chmod($path, 0600);
}
