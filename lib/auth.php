<?php

declare(strict_types=1);

function stt_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('stt_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function stt_auth_file(): array
{
    $data = stt_read_json('admin-auth.json', []);
    return is_array($data) ? $data : [];
}

function stt_auth_configured(): bool
{
    $auth = stt_auth_file();
    return isset($auth['hash']) && is_string($auth['hash']) && $auth['hash'] !== '';
}

function stt_auth_set_password(string $password): void
{
    $password = trim($password);
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('Mot de passe trop court (8 caractères min.).');
    }
    stt_write_json('admin-auth.json', [
        'hash' => password_hash($password, PASSWORD_DEFAULT),
        'updated_at' => time(),
    ]);
}

function stt_admin_logged(): bool
{
    stt_session_start();
    return !empty($_SESSION['stt_admin']);
}

function stt_admin_csrf(): string
{
    stt_session_start();
    if (empty($_SESSION['stt_csrf'])) {
        $_SESSION['stt_csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['stt_csrf'];
}

function stt_admin_csrf_ok(?string $token): bool
{
    stt_session_start();
    return is_string($token) && isset($_SESSION['stt_csrf']) && hash_equals((string) $_SESSION['stt_csrf'], $token);
}

function stt_admin_login(string $password): bool
{
    $auth = stt_auth_file();
    if (!isset($auth['hash']) || !password_verify($password, (string) $auth['hash'])) {
        return false;
    }
    stt_session_start();
    session_regenerate_id(true);
    $_SESSION['stt_admin'] = true;
    stt_admin_csrf();
    return true;
}

function stt_admin_logout(): void
{
    stt_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'] ?? '/', $p['domain'] ?? '', (bool) ($p['secure'] ?? false), (bool) ($p['httponly'] ?? true));
    }
    session_destroy();
}

function stt_admin_require(): void
{
    if (stt_admin_logged()) {
        return;
    }
    $next = (string) ($_SERVER['REQUEST_URI'] ?? '/admin');
    header('Location: /admin?next=' . rawurlencode($next));
    exit;
}
