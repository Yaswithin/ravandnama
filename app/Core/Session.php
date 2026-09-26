<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Session
{
    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $savePath = dirname(__DIR__, 2) . '/storage/sessions';

        if (!is_dir($savePath) || !is_writable($savePath)) {
            throw new RuntimeException('Session storage is unavailable.');
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        if (ini_set('session.save_path', $savePath) === false) {
            throw new RuntimeException('Session storage could not be configured.');
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!@session_start()) {
            throw new RuntimeException('Unable to start the session.');
        }
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        $this->start();

        return array_key_exists($key, $_SESSION);
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function regenerate(bool $deleteOldSession = true): void
    {
        $this->start();

        if (!session_regenerate_id($deleteOldSession)) {
            throw new RuntimeException('Unable to regenerate the session ID.');
        }
    }

    public function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (!isset($_COOKIE[session_name()])) {
                return;
            }

            $this->start();
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $cookie['path'],
                'domain' => $cookie['domain'],
                'secure' => $cookie['secure'],
                'httponly' => $cookie['httponly'],
                'samesite' => $cookie['samesite'] ?? 'Lax',
            ]);
        }

        if (!session_destroy()) {
            throw new RuntimeException('Unable to destroy the session.');
        }
    }

    private function isHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));

        return ($https !== '' && $https !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }
}
