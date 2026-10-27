<?php
// php -S 127.0.0.1:8000 -t public public/router.php
// Load simple KEY=value settings without a Composer package. Real environment values win.
$environmentFile = dirname(__DIR__) . '/.env';
if (is_readable($environmentFile)) {
    foreach (file($environmentFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        if (getenv($name) === false) {
            putenv($name . '=' . trim($value));
        }
    }
}
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/New_York');

// Return a PDO connection. The entry point catches errors and sends a safe JSON message.
function connectDatabase(): PDO
{
    $host = getenv('DB_HOST') ?: 'localhost';
    $port = getenv('DB_PORT') ?: '5432';
    $databaseName = getenv('DB_NAME') ?: 'task_manager';
    return new PDO("pgsql:host=$host;port=$port;dbname=$databaseName",
        getenv('DB_USER') ?: '', getenv('DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
}
