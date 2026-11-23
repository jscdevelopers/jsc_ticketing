<?php
declare(strict_types=1);

use App\Config\Database;
use App\Config\Migrator;
use App\Security\Provisioner;
use App\Tickets\CatalogConfigurator;

$root = require dirname(__DIR__) . '/bootstrap/autoload.php';
$command = $argv[1] ?? 'help';
if ($command === 'help') {
    echo "Commands (DB_DSN / DB_USER / DB_PASSWORD_FILE or DB_PASSWORD):\n";
    echo "  migrate\n  directory:import FILE.json [--dry-run]\n  token:issue USER_UUID HOURS(1..24)\n";
    echo "  scope:grant-read USER_UUID KIND REF_UUID_OR_DASH REASON\n  revoke token|grant UUID REASON\n";
    echo "  scope:grant-submit USER_UUID REASON\n  catalog:configure FILE.json REASON\n";
    echo "  auth:set-password USER_UUID USERNAME REASON (password on stdin)\n  admin:grant USER_UUID REASON\n  worker:once\n";
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
        'scope:grant-submit' => $provisioner->grantSubmit($argv[2] ?? '', $argv[3] ?? ''),
        'admin:grant' => $provisioner->grantOperations($argv[2]??'',$argv[3]??''),
        'auth:set-password' => $provisioner->setPassword($argv[2]??'',$argv[3]??'',rtrim((string)fgets(STDIN),"\r\n"),$argv[4]??''),
        'worker:once' => (function()use($db):array{$worker=new App\Workflow\Worker($db);return['timers'=>$worker->timers(),'escalations'=>$worker->escalate(),'notifications'=>$worker->outbox(),'attachments'=>(new App\Tickets\Attachments($db))->scanPending()];})(),
        'catalog:configure' => (function () use ($argv, $db): array {
            if (!isset($argv[2]) || !is_file($argv[2]) || filesize($argv[2]) > 65536) {
                throw new InvalidArgumentException('Intake configuration JSON file required (maximum 64 KiB).');
            }
            $data = json_decode(file_get_contents($argv[2]), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_is_list($data)) {
                throw new InvalidArgumentException('Intake configuration must be an object.');
            }
            return (new CatalogConfigurator($db))->configure($data, $argv[3] ?? '');
        })(),
        'revoke' => $provisioner->revoke($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''),
        default => throw new InvalidArgumentException('Unknown command; run help.'),
    };
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $error) {
    // Never print SQL parameters, credentials, directory contents or exception traces.
    fwrite(STDERR, ($error instanceof InvalidArgumentException ? $error->getMessage() : 'Command failed (' . $error::class . '). Check database/configuration and input constraints.') . PHP_EOL);
    exit(1);
}
