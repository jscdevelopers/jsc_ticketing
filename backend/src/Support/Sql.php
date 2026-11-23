<?php
declare(strict_types=1);
namespace App\Support;

use PDO;

final class Sql
{
    public static function run(PDO $db, string $sql, array $parameters = []): \PDOStatement
    {
        $statement = $db->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }
}
