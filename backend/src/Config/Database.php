<?php
declare(strict_types=1);
namespace App\Config;

use PDO;
use RuntimeException;

final class Database
{
    public static function connection(): PDO
    {
        $dsn = getenv('DB_DSN') ?: '';
        if (!str_starts_with($dsn, 'pgsql:')) {
            throw new RuntimeException('Set a PostgreSQL DB_DSN for a dedicated ticketing database.');
        }
        $password = getenv('DB_PASSWORD') ?: '';
        if ($file = getenv('DB_PASSWORD_FILE')) {
            $password = file_get_contents($file);
            if ($password === false) {
                throw new RuntimeException('Database secret is unavailable.');
            }
            $password = rtrim($password, "\r\n");
        }
        $db = new PDO($dsn, getenv('DB_USER') ?: '', $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $db->exec("SET TIME ZONE 'UTC'");
        return $db;
    }
}
