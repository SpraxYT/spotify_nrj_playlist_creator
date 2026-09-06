<?php

declare(strict_types=1);

/**
 * Protection des pages web via CRON_SECRET (session ou ?key=).
 */
final class WebAuth
{
    private const SESSION_KEY = 'nrj_spotify_auth';

    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function isAuthenticated(string $secret): bool
    {
        self::startSession();
        $stored = $_SESSION[self::SESSION_KEY] ?? null;
        return is_string($stored) && $stored !== '' && hash_equals($secret, $stored);
    }

    public static function tryLogin(string $secret, ?string $provided): bool
    {
        if ($secret === '' || $provided === null || $provided === '') {
            return false;
        }
        if (!hash_equals($secret, $provided)) {
            return false;
        }
        self::startSession();
        $_SESSION[self::SESSION_KEY] = $secret;
        return true;
    }

    public static function logout(): void
    {
        self::startSession();
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Accepte ?key=… ou POST key=, puis mémorise en session.
     */
    public static function guard(string $secret): bool
    {
        if (self::isAuthenticated($secret)) {
            return true;
        }

        $provided = null;
        if (isset($_GET['key']) && is_string($_GET['key'])) {
            $provided = $_GET['key'];
        } elseif (isset($_POST['key']) && is_string($_POST['key'])) {
            $provided = $_POST['key'];
        }

        return self::tryLogin($secret, $provided);
    }
}
