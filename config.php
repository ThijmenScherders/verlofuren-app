<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'verlofuren';
const DB_USER = 'root';
const DB_PASS = '';

const APP_NAME = 'Verlofuren';
const REMEMBER_COOKIE = 'verlofuren_remember';

session_name('verlofuren_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function db(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    return $pdo;
}

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function verify_csrf(): void {
    $sent = $_POST['csrf'] ?? '';

    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Sessie verlopen of ongeldig verzoek. Vernieuw de pagina en probeer opnieuw.');
    }
}

function current_user(): ?array {
    if (!empty($_SESSION['user_id'])) {
        $stmt = db()->prepare(
            'SELECT id,name,email,role,allowance_hours,leave_day_hours,
            monday_hours,tuesday_hours,wednesday_hours,thursday_hours,
            friday_hours,saturday_hours,sunday_hours
            FROM users WHERE id = ?'
        );

        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if ($user) {
            return $user;
        }

        unset($_SESSION['user_id']);
    }

    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';

    if (is_string($cookie) && preg_match('/^[a-f0-9]{64}$/', $cookie)) {
        $stmt = db()->prepare(
            'SELECT id FROM users
             WHERE remember_token_hash = ? AND remember_expires > NOW()'
        );

        $stmt->execute([hash('sha256', $cookie)]);
        $row = $stmt->fetch();

        if ($row) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$row['id'];

            $stmt = db()->prepare(
                'SELECT id,name,email,role,allowance_hours,leave_day_hours,
                monday_hours,tuesday_hours,wednesday_hours,thursday_hours,
                friday_hours,saturday_hours,sunday_hours
                FROM users WHERE id = ?'
            );

            $stmt->execute([$_SESSION['user_id']]);

            return $stmt->fetch() ?: null;
        }

        setcookie(REMEMBER_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
        ]);
    }

    return null;
}

function require_login(): array {
    $user = current_user();

    if (!$user) {
        header('Location: login.php');
        exit;
    }

    return $user;
}

function require_admin(array $user): void {
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Je hebt geen toegang tot deze pagina.');
    }
}

function set_flash(string $message, string $type = 'success'): void {
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type
    ];
}

function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function flash_html(): string {
    if (empty($_SESSION['flash'])) {
        return '';
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return '<div class="alert ' . e($flash['type']) . '">' .
        e($flash['message']) . '</div>';
}

function logout_user(): void {
    $userId = $_SESSION['user_id'] ?? null;

    if ($userId) {
        db()->prepare(
            'UPDATE users SET remember_token_hash=NULL, remember_expires=NULL WHERE id=?'
        )->execute([$userId]);
    }

    setcookie(REMEMBER_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
    ]);

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            '',
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}