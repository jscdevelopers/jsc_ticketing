<?php
declare(strict_types=1);
namespace App\Config;

use PDO;
use RuntimeException;
use Throwable;

final class Migrator
{
    public function __construct(private PDO $db, private string $directory) {}

    public function run(): array
    {
        $applied = [];
        $this->db->query("SELECT pg_advisory_lock(741903218)");
        try {
            $this->db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
                name text PRIMARY KEY, checksum text NOT NULL, applied_at timestamptz NOT NULL DEFAULT now())');
            $files = glob($this->directory . '/*.sql') ?: [];
            sort($files, SORT_STRING);
            foreach ($files as $file) {
                $name = basename($file);
                $sql = file_get_contents($file);
                if ($sql === false) {
                    throw new RuntimeException('Migration cannot be read.');
                }
                $checksum = hash('sha256', str_replace("\r\n", "\n", $sql));
                $query = $this->db->prepare('SELECT checksum FROM schema_migrations WHERE name = ?');
                $query->execute([$name]);
                $prior = $query->fetchColumn();
                if ($prior !== false) {
                    if (!hash_equals($prior, $checksum)) {
                        throw new RuntimeException('Applied migration checksum mismatch: ' . $name);
                    }
                    continue;
                }
                // Blueprint files own BEGIN/COMMIT. Move the ledger into the same transaction.
                if (!preg_match('/\bBEGIN;\s*(.*?)\s*COMMIT;\s*$/s', $sql, $matches)) {
                    throw new RuntimeException('Migration must have an outer BEGIN/COMMIT: ' . $name);
                }
                $this->db->beginTransaction();
                try {
                    $this->db->exec($matches[1]);
                    $insert = $this->db->prepare('INSERT INTO schema_migrations(name,checksum) VALUES (?,?)');
                    $insert->execute([$name, $checksum]);
                    $this->db->commit();
                    $applied[] = $name;
                } catch (Throwable $error) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    throw $error;
                }
            }
        } finally {
            $this->db->query("SELECT pg_advisory_unlock(741903218)");
        }
        return $applied;
    }
}
