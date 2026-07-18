<?php

declare(strict_types=1);

namespace NexWaypont\Core;

use NexWaypont\Users\User;
use NexWaypont\Users\UserRepository;

/**
 * Session-based local authentication (username + password_hash). Azure AD /
 * M365 SSO is a documented future phase (see README roadmap) -- v1 auth is
 * intentionally simple and self-contained so the app is usable without an
 * enterprise app registration in place.
 */
final class Auth
{
    private const SESSION_KEY = 'nexwaypont_user_id';

    public function __construct(private readonly UserRepository $users)
    {
    }

    private function ensureSessionStarted(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Strict',
                'secure' => (($_SERVER['HTTPS'] ?? '') !== ''),
            ]);
            session_start();
        }
    }

    public function attempt(string $username, string $password): ?User
    {
        $row = $this->users->findAuthRowByUsername($username);
        if ($row === null) {
            return null;
        }
        if (!password_verify($password, (string) $row['password_hash'])) {
            return null;
        }

        $this->ensureSessionStarted();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = (int) $row['id'];

        return User::fromRow($row);
    }

    public function currentUser(): ?User
    {
        $this->ensureSessionStarted();
        $id = $_SESSION[self::SESSION_KEY] ?? null;
        if ($id === null) {
            return null;
        }
        return $this->users->find((int) $id);
    }

    /**
     * Redirects to /login.php and exits if there is no authenticated user.
     * Call at the top of every protected page.
     */
    public function requireAuth(): User
    {
        $user = $this->currentUser();
        if ($user === null) {
            header('Location: /login.php');
            exit;
        }
        return $user;
    }

    public function logout(): void
    {
        $this->ensureSessionStarted();
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }
}
