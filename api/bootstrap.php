<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
ini_set('session.use_strict_mode', '1');
session_name('env_portal_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

$configPath = dirname(__DIR__, 2) . '/portal-config.php';
if (!is_file($configPath)) {
    respond(['error' => 'Server configuration is missing.'], 500);
}

$config = require $configPath;

try {
    $pdo = new PDO($config['dsn'], $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $error) {
    respond(['error' => 'Database connection failed.'], 500);
}

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    exit;
}

function requestBody(): array
{
    $body = json_decode((string) file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

function portalBaseUrl(array $config): string
{
    $baseUrl = trim((string) ($config['portal_base_url'] ?? 'https://portal.e-nv.dk'));
    return rtrim($baseUrl, '/');
}

function requireAdmin(): void
{
    if (($_SESSION['role'] ?? null) !== 'admin') {
        respond(['error' => 'Admin login required.'], 403);
    }
}

function requireLogin(): void
{
    if (!isset($_SESSION['user_id'])) {
        respond(['error' => 'Login required.'], 401);
    }
}

function slugify(string $value): string
{
    $value = strtr($value, [
        'æ' => 'ae', 'Æ' => 'Ae',
        'ø' => 'oe', 'Ø' => 'Oe',
        'å' => 'aa', 'Å' => 'Aa',
    ]);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii), '-'));
    return $slug !== '' ? $slug : 'item';
}
