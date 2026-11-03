<?php

/*
| Read-only helper for scripts/flow-check.mjs: runs one SELECT against the app's database (credentials from .env) and prints the rows as JSON.
|   php scripts/flow-db.php "SELECT id, status FROM orders WHERE id = 1"
*/

require __DIR__.'/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$sql = $argv[1] ?? '';
if (! preg_match('/^\s*select\b/i', $sql)) {
    fwrite(STDERR, "Only SELECT statements are allowed.\n");
    exit(1);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $_ENV['DB_HOST'] ?? '127.0.0.1', $_ENV['DB_PORT'] ?? '3306', $_ENV['DB_DATABASE'] ?? ''),
    $_ENV['DB_USERNAME'] ?? 'root',
    $_ENV['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);

echo json_encode($pdo->query($sql)->fetchAll(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
