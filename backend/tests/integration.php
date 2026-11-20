<?php
declare(strict_types=1);

use App\Config\Database;
use App\Config\Migrator;
use App\Http\Application;
use App\Http\Request;
use App\Security\Provisioner;

$root = require dirname(__DIR__) . '/bootstrap/autoload.php';
$db = Database::connection();
if ($db->query('SELECT current_database()')->fetchColumn() !== 'ticketing_test') {
    fwrite(STDERR, "Tests require an isolated database named ticketing_test. Use scripts/test-backend.ps1.\n");
    exit(1);
}
$schema = 'test_' . bin2hex(random_bytes(8));
$db->exec('CREATE SCHEMA ' . $schema);
$db->exec('SET search_path TO ' . $schema . ',public');
$checks = 0;
$server = null;
$tempDirectory = null;
function expect(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $checks++;
}
function id(int $number): string
{
    return '00000000-0000-4000-8000-' . str_pad(dechex($number), 12, '0', STR_PAD_LEFT);
}
function sql(string $query, array $params = []): mixed
{
    global $db;
    $statement = $db->prepare($query);
    $statement->execute($params);
    return $statement;
}
function callApi(string $path, ?string $token = null, array $query = [], string $method = 'GET'): App\Http\Response
{
    global $db;
    return (new Application($db))->handle(new Request($method, '/api/v1' . $path, $query,
        $token === null ? [] : ['Authorization' => 'Bearer ' . $token], [], []));
}
try {
    $migrator = new Migrator($db, $root . '/database');
    expect(count($migrator->run()) === 2, 'fresh migrations apply');
    expect($migrator->run() === [], 'repeat migration is a no-op');
    expect((int)sql('SELECT count(*) FROM schema_migrations')->fetchColumn() === 2, 'migration ledger persisted');
    $tempDirectory = sys_get_temp_dir() . '/ticketing-migrations-' . bin2hex(random_bytes(6));
    mkdir($tempDirectory);
    copy($root . '/database/001_foundation.sql', $tempDirectory . '/001_foundation.sql');
    file_put_contents($tempDirectory . '/001_foundation.sql', "\n-- edited", FILE_APPEND);
    try {
        (new Migrator($db, $tempDirectory))->run();
        expect(false, 'migration checksum must reject modifications');
    } catch (RuntimeException $error) {
        expect(str_contains($error->getMessage(), 'checksum mismatch'), 'migration checksum rejects modifications');
    }
    unlink($tempDirectory . '/001_foundation.sql');
    file_put_contents($tempDirectory . '/003_broken.sql', 'BEGIN; CREATE TABLE should_rollback(id integer); SELECT missing_column; COMMIT;');
    try {
        (new Migrator($db, $tempDirectory))->run();
        expect(false, 'broken migration must fail');
    } catch (PDOException) {
        expect(sql("SELECT to_regclass('should_rollback')")->fetchColumn() === null, 'DDL rollback on migration failure');
        expect((int)sql('SELECT count(*) FROM schema_migrations')->fetchColumn() === 2, 'failed migration absent from ledger');
    }

    $directory = [
        'provinces' => [
            ['id'=>id(1),'code'=>'P1','name'=>'Test Province One','active'=>true],
            ['id'=>id(2),'code'=>'P2','name'=>'Test Province Two','active'=>true],
        ],
        'stations' => [
            ['id'=>id(11),'code'=>'SECRET','name'=>'Secretariat','province_id'=>id(1),'is_secretariat_station'=>true,'active'=>true],
            ['id'=>id(12),'code'=>'REMOTE','name'=>'Remote Test Station','province_id'=>id(2),'is_secretariat_station'=>false,'active'=>true],
        ],
        'departments' => [
            ['id'=>id(21),'code'=>'ICT','name'=>'Test ICT','active'=>true],
            ['id'=>id(22),'code'=>'FIN','name'=>'Test Finance','active'=>true],
        ],
        'positions' => [['id'=>id(31),'code'=>'OFFICER','title'=>'Test Officer','active'=>true]],
        'app_users' => [], 'staff_assignments' => [],
    ];
    for ($i=101; $i<=106; $i++) {
        $directory['app_users'][] = ['id'=>id($i),'external_subject'=>'test:'.$i,'display_name'=>'Test Staff '.$i,'email'=>null,'active'=>true];
        $directory['staff_assignments'][] = ['id'=>id($i+100),'user_id'=>id($i),'position_id'=>id(31),
            'department_id'=>id(21),'station_id'=>id($i===101 ? 11 : 12),
            'begins_at'=>'2020-01-01T00:00:00Z','ends_at'=>null,'is_acting'=>false];
    }
    $admin = new Provisioner($db);
    $admin->importDirectory($directory, true);
    expect((int)sql('SELECT count(*) FROM app_users')->fetchColumn() === 0, 'dry-run leaves no directory records');
    expect((int)sql('SELECT count(*) FROM security_events')->fetchColumn() === 0, 'dry-run leaves no audit records');
    $admin->importDirectory($directory, false);
    $admin->importDirectory($directory, false);
    expect((int)sql('SELECT count(*) FROM app_users')->fetchColumn() === 6, 'reimport updates without duplicate identities');
    $bad = $directory;
    $bad['provinces'][0]['name'] = 'Must Roll Back';
    $bad['staff_assignments'][0]['station_id'] = id(999);
    try {
        $admin->importDirectory($bad, false);
        expect(false, 'invalid directory must fail');
    } catch (PDOException) {
        expect(sql('SELECT name FROM provinces WHERE id=?', [id(1)])->fetchColumn() === 'Test Province One', 'directory import rolls back all prior updates');
    }
    expect((int)sql('SELECT count(*) FROM security_events')->fetchColumn() === 2, 'only committed imports audited');
    $grantLocal = $admin->grantRead(id(101), 'station', id(11), 'Test local scope');
    $grantNational = $admin->grantRead(id(102), 'national', null, 'Test explicit oversight');
    $admin->grantRead(id(103), 'station', id(11), 'Test one station');
    $grantDepartment = $admin->grantRead(id(103), 'department', id(22), 'Test second independent scope');
    $admin->grantRead(id(104), 'self', null, 'Test own tickets');
    $expiredGrant = $admin->grantRead(id(103), 'national', null, 'Test expired scope');
    sql("UPDATE scope_grants SET valid_from='2020-01-01',valid_to='2021-01-01' WHERE id=?", [$expiredGrant]);
    $futureGrant = $admin->grantRead(id(103), 'national', null, 'Test future scope');
    sql("UPDATE scope_grants SET valid_from='2999-01-01' WHERE id=?", [$futureGrant]);
    $tokens = [];
    for ($i=101; $i<=106; $i++) {
        $tokens[$i] = $admin->issueToken(id($i), 1);
    }
    expect(sql('SELECT token_hash FROM api_tokens WHERE user_id=?', [id(101)])->fetchColumn() === hash('sha256', $tokens[101]), 'only token hash stored');
    expect(callApi('/me')->status === 401, 'anonymous profile rejected');
    expect(callApi('/me', str_repeat('a',64))->status === 401, 'unknown bearer rejected');
    expect(callApi('/me', $tokens[101])->payload['data']['id'] === id(101), 'bearer resolves trusted identity');
    expect(callApi('/tickets', $tokens[106])->payload['data'] === [], 'identity without grants has no ticket visibility');
    sql('INSERT INTO categories(id,department_id,code,name) VALUES (?,?,?,?)', [id(41),id(21),'TEST','Test Category']);
    sql('INSERT INTO workflow_templates(id,code,name,owner_department_id,category_id) VALUES (?,?,?,?,?)', [id(51),'TEST','Test Workflow',id(21),id(41)]);
    sql("INSERT INTO workflow_versions(id,template_id,version,status,definition,published_at) VALUES (?,?,1,'published','{}',now())", [id(61),id(51)]);
    for ($i=1; $i<=14; $i++) {
        sql('INSERT INTO tickets(id,requester_id,category_id,workflow_version_id,origin_station_id,origin_province_id,
          origin_department_id,responsible_department_id,subject,description,confidentiality,created_at)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [id(300+$i),id($i===1 ? 104 : 105),id(41),id(61),id($i===1 ? 11 : 12),
            id($i===1 ? 1 : 2),id(21),id($i===2 ? 22 : 21), 'Ticket '.$i,'Private test narrative',
            $i===14 ? 'confidential' : 'internal','2026-01-01T00:00:00Z']);
    }
    expect(count(callApi('/tickets', $tokens[101])->payload['data']) === 1, 'Secretariat station grant sees one local ticket');
    expect(callApi('/tickets/'.id(302), $tokens[101])->status === 404, 'Secretariat user cannot enumerate remote tickets');
    expect(callApi('/tickets/'.id(999), $tokens[101])->body === callApi('/tickets/'.id(302), $tokens[101])->body, 'missing and unauthorized details identical');
    expect(callApi('/tickets/'.id(301), $tokens[101])->status === 200, 'local detail authorized');
    $first = callApi('/tickets', $tokens[102])->payload;
    expect(count($first['data']) === 10, 'national scope first page capped at ten');
    $second = callApi('/tickets', $tokens[102], ['cursor'=>$first['meta']->next_cursor])->payload;
    expect(count($second['data']) === 3, 'national scope next page excludes confidential ticket');
    expect(count(array_unique(array_column([...$first['data'],...$second['data']], 'id'))) === 13, 'stable cursor tie-breaker produces no duplicates');
    expect(count(callApi('/tickets', $tokens[103])->payload['data']) === 2, 'independent station and department scopes produce correct union');
    expect(callApi('/tickets/'.id(303), $tokens[103])->status === 404, 'multiple scopes do not broaden to unrelated department and station');
    expect(count(callApi('/tickets', $tokens[104])->payload['data']) === 1, 'self scope uses immutable requester identity');
    expect(callApi('/tickets/'.id(314), $tokens[102])->status === 404, 'national read does not imply sensitive clearance');
    sql("INSERT INTO role_permissions(role_id,permission_code) SELECT id,'ticket.read_sensitive' FROM roles WHERE code='ticket_reader'");
    sql("INSERT INTO scope_grants(user_id,permission_code,scope_kind,grant_reason) VALUES (?,'ticket.read_sensitive','national','Test separate sensitive clearance')", [id(102)]);
    expect(callApi('/tickets/'.id(314), $tokens[102])->status === 200, 'separate sensitive permission plus scoped grant enables access');
    expect(callApi('/tickets', $tokens[102], ['q'=>'Ticket 13'])->payload['data'][0]['id'] === id(313), 'server search filters authorized rows');
    expect(callApi('/tickets', $tokens[102], ['q'=>'%'])->payload['data'] === [], 'search wildcards treated literally');
    expect(callApi('/tickets', $tokens[101], ['q'=>'Ticket 13'])->payload['data'] === [], 'search never bypasses scope');
    foreach ([['limit'=>'11'],['cursor'=>'bad'],['status'=>'invalid'],['q'=>['nested']],['actor'=>id(102)]] as $filter) {
        expect(callApi('/tickets', $tokens[102], $filter)->status === 422, 'invalid query rejected');
    }
    expect(callApi('/tickets', $tokens[101], [], 'HEAD')->body === '', 'HEAD omits body');
    expect(callApi('/tickets', $tokens[101], [], 'POST')->status === 405, 'unimplemented mutations fail explicitly');
    expect(callApi('/tickets', $tokens[101])->headers['Cache-Control'] === 'private, no-store', 'private records never cached');
    $admin->revoke('grant', $grantLocal, 'Test immediate revocation');
    expect(callApi('/tickets', $tokens[101])->payload['data'] === [], 'grant revocation effective on next request');
    sql("UPDATE user_roles SET valid_to=now()-interval '1 second', valid_from=now()-interval '1 hour' WHERE user_id=?", [id(102)]);
    expect(callApi('/tickets', $tokens[102])->payload['data'] === [], 'expired role removes national permission');
    sql('UPDATE app_users SET active=false WHERE id=?', [id(103)]);
    expect(callApi('/me', $tokens[103])->status === 401, 'disabled identity rejected');
    sql('UPDATE api_tokens SET expires_at=now()-interval \'1 second\' WHERE user_id=?', [id(104)]);
    expect(callApi('/me', $tokens[104])->status === 401, 'expired token rejected');
    $tokenId = sql('SELECT id FROM api_tokens WHERE user_id=?', [id(105)])->fetchColumn();
    $admin->revoke('token', $tokenId, 'Test token revocation');
    $eventCount = (int)sql('SELECT count(*) FROM security_events')->fetchColumn();
    $admin->revoke('token', $tokenId, 'Test repeated revocation');
    expect((int)sql('SELECT count(*) FROM security_events')->fetchColumn() === $eventCount, 'repeated revocation idempotent');
    expect(callApi('/me', $tokens[105])->status === 401, 'revoked token rejected');
    foreach (["UPDATE workflow_versions SET definition='{\"changed\":true}'", 'DELETE FROM security_events'] as $query) {
        try {
            sql($query);
            expect(false, 'immutable history must reject changes');
        } catch (PDOException) {
            expect(true, 'immutable workflow or security history enforced');
        }
    }
    // Real HTTP entrypoint, not just direct application dispatch.
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int)substr(strrchr($address, ':'), 1);
    $env = getenv();
    $env['PGOPTIONS'] = '-c search_path=' . $schema . ',public';
    $log = tempnam(sys_get_temp_dir(), 'ticketing-http-');
    $server = proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$root.'/public',$root.'/public/index.php'],
        [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']], $pipes, $root, $env);
    expect(is_resource($server), 'HTTP server starts');
    fclose($pipes[0]);
    $base = 'http://127.0.0.1:' . $port . '/api/v1';
    for ($i=0; $i<50; $i++) {
        $health = @file_get_contents($base.'/health/live');
        if ($health !== false) {
            break;
        }
        usleep(100000);
    }
    expect(json_decode($health, true)['data']['alive'] === true, 'real HTTP liveness');
    $profile = file_get_contents($base.'/me', false, stream_context_create(['http'=>['header'=>'Authorization: Bearer '.$tokens[106]]]));
    expect(json_decode($profile, true)['data']['id'] === id(106), 'real HTTP authentication and profile');
    expect(str_contains(implode('\n', $http_response_header), 'X-Request-ID:'), 'real HTTP correlation header');
    $ready = file_get_contents($base.'/health/ready');
    expect(json_decode($ready, true)['data']['ready'] === true, 'real HTTP database readiness');
    echo "PASS: $checks PostgreSQL integration and HTTP checks.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (isset($log) && is_file($log)) {
        unlink($log);
    }
    if ($tempDirectory !== null && is_dir($tempDirectory)) {
        foreach (glob($tempDirectory . '/*.sql') as $file) {
            unlink($file);
        }
        rmdir($tempDirectory);
    }
    $db->exec('SET search_path TO public');
    $db->exec('DROP SCHEMA ' . $schema . ' CASCADE');
}
