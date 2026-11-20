<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/autoload.php';
$db = App\Config\Database::connection();
if ($db->query('SELECT current_database()')->fetchColumn() !== 'ticketing_test') {
    exit(1);
}
$body = json_decode(file_get_contents($argv[3]), true, 32, JSON_THROW_ON_ERROR);
echo "READY\n";
fflush(STDOUT);
try {
    $result = (new App\Tickets\TicketSubmission($db))->submit($argv[1], $body, $argv[2], 'concurrent-integration');
    echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . PHP_EOL);
    exit(1);
}
