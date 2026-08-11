<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR, "Usage: php create-admin.php email@example.com password\n");
    exit(1);
}

$configuredPath = trim((string) getenv('PORTAL_CONFIG_PATH'));
$configPath = $configuredPath !== ''
    ? $configuredPath
    : dirname(__DIR__, 2) . '/portal-config.php';
$config = require $configPath;
$pdo = new PDO($config['dsn'], $config['user'], $config['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$statement = $pdo->prepare(
    "INSERT INTO users (email, password_hash, role)
     VALUES (:email, :password_hash, 'admin')
     ON CONFLICT (email) DO UPDATE SET password_hash = EXCLUDED.password_hash, role = 'admin'"
);
$statement->execute([
    'email' => strtolower(trim($argv[1])),
    'password_hash' => password_hash($argv[2], PASSWORD_DEFAULT),
]);

echo "Admin user created or updated.\n";
