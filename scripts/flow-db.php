<?php

/*
| Read-only helper for scripts/flow-check.mjs: runs one SELECT against the app's database (credentials from .env) and prints the rows as JSON.
|   php scripts/flow-db.php "SELECT id, status FROM orders WHERE id = 1"
*/

require __DIR__.'/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

// a real environment variable wins over .env (so the run can point at another database: DB_DATABASE=... node scripts/flow-check.mjs)
$env = fn (string $key, string $default = '') => getenv($key) !== false ? (string) getenv($key) : (string) ($_ENV[$key] ?? $default);

$sql = $argv[1] ?? '';
if (! preg_match('/^\s*select\b/i', $sql)) {
    fwrite(STDERR, "Only SELECT statements are allowed.\n");
    exit(1);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env('DB_HOST', '127.0.0.1'), $env('DB_PORT', '3306'), $env('DB_DATABASE')),
    $env('DB_USERNAME', 'root'),
    $env('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);

echo json_encode($pdo->query($sql)->fetchAll(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
