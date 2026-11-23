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
    $migrationFiles=glob($root.'/database/*.sql');sort($migrationFiles);$migrationNames=array_map('basename',$migrationFiles);$migrationCount=count($migrationFiles);
    expect(count($migrator->run()) === $migrationCount, 'fresh migrations apply');
    expect($migrator->run() === [], 'repeat migration is a no-op');
    expect((int)sql('SELECT count(*) FROM schema_migrations')->fetchColumn() === $migrationCount, 'migration ledger persisted');
    $upgradeSchema = $schema . '_upgrade';
    $upgradeDirectory = sys_get_temp_dir() . '/ticketing-upgrade-' . bin2hex(random_bytes(6));
    mkdir($upgradeDirectory);
    $db->exec('CREATE SCHEMA ' . $upgradeSchema);
    try {
        $db->exec('SET search_path TO ' . $upgradeSchema . ',public');
        foreach (['001_foundation.sql','002_identity.sql'] as $file) {
            copy($root . '/database/' . $file, $upgradeDirectory . '/' . $file);
        }
        (new Migrator($db, $upgradeDirectory))->run();
        sql("INSERT INTO app_users(id,external_subject,display_name) VALUES (?,'legacy:test','Legacy Test Staff')", [id(9001)]);
        sql("INSERT INTO departments(id,code,name) VALUES (?,'LEGACY','Legacy Test Department')", [id(9002)]);
        sql("INSERT INTO categories(id,department_id,code,name) VALUES (?,?,'LEGACY','Legacy Test Category')", [id(9003),id(9002)]);
        sql("INSERT INTO workflow_templates(id,code,name,owner_department_id) VALUES (?,'LEGACY','Legacy Test Workflow',?)", [id(9004),id(9002)]);
        sql("INSERT INTO workflow_versions(id,template_id,version,status,definition,published_at) VALUES (?,?,1,'published','{}',now())", [id(9005),id(9004)]);
        sql("INSERT INTO tickets(id,requester_id,category_id,workflow_version_id,subject,description) VALUES (?,?,?,?,'Legacy ticket','Preserve this ticket')", [id(9006),id(9001),id(9003),id(9005)]);
        expect((new Migrator($db, $root . '/database'))->run() === array_slice($migrationNames,2), 'existing foundation upgrades with only new migrations');
        $legacy = sql('SELECT subject,requester_snapshot,origin_assignment_id FROM tickets WHERE id=?', [id(9006)])->fetch();
        expect($legacy['subject'] === 'Legacy ticket' && $legacy['requester_snapshot'] === '{}' && $legacy['origin_assignment_id'] === null, 'upgrade preserves existing ticket without inventing origin history');
    } finally {
        $db->exec('SET search_path TO ' . $schema . ',public');
        $db->exec('DROP SCHEMA ' . $upgradeSchema . ' CASCADE');
        foreach (glob($upgradeDirectory . '/*.sql') as $file) {
            unlink($file);
        }
        rmdir($upgradeDirectory);
    }
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
        expect((int)sql('SELECT count(*) FROM schema_migrations')->fetchColumn() === $migrationCount, 'failed migration absent from ledger');
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
    $rebound = ['app_users' => [$directory['app_users'][0]]];
    $rebound['app_users'][0]['external_subject'] = 'different:identity';
    try {
        $admin->importDirectory($rebound, false);
        expect(false, 'existing identity cannot be replaced through import');
    } catch (InvalidArgumentException) {
        expect(sql('SELECT external_subject FROM app_users WHERE id=?', [id(101)])->fetchColumn() === 'test:101', 'directory import preserves identity binding');
    }
    $grantLocal = $admin->grantRead(id(101), 'station', id(11), 'Test local scope');
    $grantEvents = (int)sql('SELECT count(*) FROM security_events')->fetchColumn();
    expect($admin->grantRead(id(101), 'station', id(11), 'Test retry') === $grantLocal, 'repeated active grant reuses existing record');
    expect((int)sql('SELECT count(*) FROM security_events')->fetchColumn() === $grantEvents, 'repeated grant adds no audit duplicate');
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
    $tokenCount = (int)sql('SELECT count(*) FROM api_tokens')->fetchColumn();
    sql("CREATE FUNCTION reject_test_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Simulated audit failure'; END \$\$");
    sql('CREATE TRIGGER reject_test_audit BEFORE INSERT ON security_events FOR EACH ROW EXECUTE FUNCTION reject_test_audit()');
    try {
        $admin->issueToken(id(101), 1);
        expect(false, 'audit failure must abort token issuance');
    } catch (PDOException) {
        expect((int)sql('SELECT count(*) FROM api_tokens')->fetchColumn() === $tokenCount, 'audit failure rolls back credential issuance');
    }
    sql('DROP TRIGGER reject_test_audit ON security_events');
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
    sql('UPDATE staff_assignments SET station_id=? WHERE user_id=?', [id(12),id(101)]);
    expect(count(callApi('/tickets', $tokens[101])->payload['data']) === 1, 'changing current posting does not broaden historical ticket scope');
    sql("INSERT INTO scope_grants(user_id,permission_code,scope_kind,grant_reason) VALUES (?,'ticket.read','national','Test grant without role')", [id(106)]);
    expect(callApi('/tickets', $tokens[106])->payload['data'] === [], 'grant without role permission cannot authorize reads');
    $provinceGrant = $admin->grantRead(id(106), 'province', id(2), 'Test province scope');
    sql("UPDATE scope_grants SET revoked_at=now() WHERE user_id=? AND scope_kind='national'", [id(106)]);
    expect(callApi('/tickets/'.id(302), $tokens[106])->status === 200, 'province scope permits matching origin province');
    expect(callApi('/tickets/'.id(301), $tokens[106])->status === 404, 'province scope rejects another province');
    expect(callApi('/tickets/'.id(314), $tokens[102])->status === 404, 'national read does not imply sensitive clearance');
    sql("INSERT INTO role_permissions(role_id,permission_code) SELECT id,'ticket.read_sensitive' FROM roles WHERE code='ticket_reader'");
    sql("INSERT INTO scope_grants(user_id,permission_code,scope_kind,grant_reason) VALUES (?,'ticket.read_sensitive','national','Test separate sensitive clearance')", [id(102)]);
    expect(callApi('/tickets/'.id(314), $tokens[102])->status === 200, 'separate sensitive permission plus scoped grant enables access');
    expect(callApi('/tickets', $tokens[102], ['q'=>'Ticket 13'])->payload['data'][0]['id'] === id(313), 'server search filters authorized rows');
    expect(callApi('/tickets', $tokens[102], ['q'=>'%'])->payload['data'] === [], 'search wildcards treated literally');
    expect(callApi('/tickets', $tokens[101], ['q'=>'Ticket 13'])->payload['data'] === [], 'search never bypasses scope');
    foreach ([['limit'=>'11'],['cursor'=>'bad'],['cursor'=>base64_encode('2026-99-99 99:99:99+00|'.id(301))],['status'=>'invalid'],['q'=>['nested']],['actor'=>id(102)]] as $filter) {
        expect(callApi('/tickets', $tokens[102], $filter)->status === 422, 'invalid query rejected');
    }
    expect(callApi('/tickets', $tokens[101], [], 'HEAD')->body === '', 'HEAD omits body');
    expect(callApi('/tickets', $tokens[101], [], 'POST')->status === 415, 'submission requires JSON content type');
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
    require __DIR__ . '/intake.php';
    require __DIR__ . '/workflows.php';
    require __DIR__ . '/security-operations.php';
    require __DIR__ . '/participants.php';
    require __DIR__ . '/reports.php';
    require __DIR__ . '/local-webhook.php';
    require __DIR__ . '/local-demo.php';
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
