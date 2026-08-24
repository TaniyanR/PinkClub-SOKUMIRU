<?php

declare(strict_types=1);

function auth_user(): ?array
{
    return $_SESSION['admin'] ?? null;
}

function auth_set_last_error(?string $error): void
{
    $GLOBALS['auth_last_error'] = $error;
}

function auth_last_error(): ?string
{
    $error = $GLOBALS['auth_last_error'] ?? null;
    return is_string($error) ? $error : null;
}

function auth_attempt(string $username, string $password): bool
{
    if (function_exists('pcf_session_start')) {
        pcf_session_start();
    }
    auth_set_last_error(null);

    try {
        $stmt = db()->prepare('SELECT id, username, password_hash, initial_setup_completed, session_version FROM admins WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $username]);
        $user = $stmt->fetch();
    } catch (PDOException|RuntimeException $exception) {
        auth_set_last_error('db_error');
        if (function_exists('installer_log')) {
            installer_log('auth db error: ' . $exception->getMessage());
        }
        return false;
    }

    if (!$user || !password_verify($password, (string)$user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['admin'] = [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'initial_setup_completed' => (bool)$user['initial_setup_completed'],
        'session_version' => (int)$user['session_version'],
    ];

    return true;
}

function auth_require_admin(): void
{
    if (function_exists('pcf_session_start')) {
        pcf_session_start();
    }
    if (!auth_user()) {
        app_redirect(LOGIN_PATH);
    }

    $sessionUser = auth_user();
    $sessionCheck = db()->prepare('SELECT username,initial_setup_completed,session_version FROM admins WHERE id=:id LIMIT 1');
    $sessionCheck->execute([':id' => (int)($sessionUser['id'] ?? 0)]);
    $current = $sessionCheck->fetch(PDO::FETCH_ASSOC);
    if (!is_array($current) || (int)$current['session_version'] !== (int)($sessionUser['session_version'] ?? 0)) {
        auth_logout();
        app_redirect(LOGIN_PATH);
    }

    if ((installer_status()['completed'] ?? false) !== true) {
        app_redirect('/public/setup_check.php');
    }

    if (!headers_sent()) {
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
    }
}

function auth_logout(): void
{
    if (function_exists('pcf_session_start')) {
        pcf_session_start();
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600, 'path' => $params['path'], 'domain' => $params['domain'],
            'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}
