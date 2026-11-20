<?php
declare(strict_types=1);

use App\Config\Database;
use App\Config\Migrator;
use App\Security\Provisioner;

$root = require dirname(__DIR__) . '/bootstrap/autoload.php';
$command = $argv[1] ?? 'help';
if ($command === 'help') {
    echo "Commands (DB_DSN / DB_USER / DB_PASSWORD_FILE or DB_PASSWORD):\n";
    echo "  migrate\n  directory:import FILE.json [--dry-run]\n  token:issue USER_UUID HOURS(1..24)\n";
    echo "  scope:grant-read USER_UUID KIND REF_UUID_OR_DASH REASON\n  revoke token|grant UUID REASON\n";
    exit(0);
}
try {
    $db = Database::connection();
    $provisioner = new Provisioner($db);
    $result = match ($command) {
        'migrate' => (new Migrator($db, $root . '/database'))->run(),
        'directory:import' => (function () use ($argv, $provisioner): array {
            if (!isset($argv[2]) || !is_file($argv[2]) || filesize($argv[2]) > 10 * 1024 * 1024) {
                throw new InvalidArgumentException('Directory JSON file required (maximum 10 MiB).');
            }
            $data = json_decode(file_get_contents($argv[2]), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_is_list($data)) {
                throw new InvalidArgumentException('Directory JSON object required.');
            }
            return $provisioner->importDirectory($data, ($argv[3] ?? '') === '--dry-run');
        })(),
        'token:issue' => ['token' => $provisioner->issueToken($argv[2] ?? '', (int)($argv[3] ?? 0)), 'notice' => 'Store securely; displayed once.'],
        'scope:grant-read' => ['grant_id' => $provisioner->grantRead($argv[2] ?? '', $argv[3] ?? '', ($argv[4] ?? '-') === '-' ? null : $argv[4], $argv[5] ?? '')],
        'revoke' => $provisioner->revoke($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''),
        default => throw new InvalidArgumentException('Unknown command; run help.'),
    };
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $error) {
    // Never print SQL parameters, credentials, directory contents or exception traces.
    fwrite(STDERR, ($error instanceof InvalidArgumentException ? $error->getMessage() : 'Command failed (' . $error::class . '). Check database/configuration and input constraints.') . PHP_EOL);
    exit(1);
}
