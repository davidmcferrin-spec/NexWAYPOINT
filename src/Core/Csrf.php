<?php

declare(strict_types=1);

namespace NexWaypoint\Core;

/**
 * Minimal per-session CSRF token helper. Every state-changing form must
 * include the token from Csrf::token() and every POST handler must call
 * Csrf::verify($_POST['csrf_token'] ?? '') before touching the DB.
 */
final class Csrf
{
    private const SESSION_KEY = 'nexwaypoint_csrf_token';

    private static function ensureSessionStarted(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function token(): string
    {
        self::ensureSessionStarted();
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function verify(string $submitted): bool
    {
        self::ensureSessionStarted();
        $expected = $_SESSION[self::SESSION_KEY] ?? null;
        return $expected !== null && hash_equals($expected, $submitted);
    }
}
