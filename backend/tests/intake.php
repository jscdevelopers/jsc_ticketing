<?php
declare(strict_types=1);

use App\Http\Application;
use App\Http\Request;
use App\Tickets\CatalogConfigurator;

// Included by integration.php, using its disposable schema and fixtures.
function submitApi(array $body, string $key = 'test-submission', ?string $token = null): App\Http\Response
{
    global $db, $tokens;
    return (new Application($db))->handle(new Request('POST','/api/v1/tickets',[],
        ['Content-Type'=>'application/json','Authorization'=>'Bearer '.($token ?? $tokens[106]),'Idempotency-Key'=>$key],$body,[]));
}
$catalog = new CatalogConfigurator($db);
$schemaA = ['type'=>'object','additionalProperties'=>false,'required'=>['asset_tag'], 'properties'=>[
    'asset_tag'=>['type'=>'string','title'=>'Asset tag','minLength'=>2,'maxLength'=>30],
    'seats'=>['type'=>'integer','minimum'=>1,'maximum'=>20],
    'urgent'=>['type'=>'boolean'],
    'service'=>['type'=>'string','enum'=>['repair','replace']],
]];
$configA = ['category_id'=>id(401),'department_id'=>id(22),'code'=>'A','name'=>'Equipment request','form_schema'=>$schemaA];
$versionA = $catalog->configure($configA, 'Approved test category A');
expect($versionA === $catalog->configure($configA, 'Same configuration retry'), 'catalog retry reuses immutable version');
$configB = ['category_id'=>id(402),'department_id'=>id(21),'code'=>'B','name'=>'General request',
    'form_schema'=>['type'=>'object','additionalProperties'=>false,'properties'=>[]]];
$versionB = $catalog->configure($configB, 'Approved test category B');
$invalidConfig = $configA;
$invalidConfig['form_schema']['properties']['asset_tag']['pattern'] = 'arbitrary-regex';
try {
    $catalog->configure($invalidConfig, 'Invalid configuration');
    expect(false, 'unsupported schema keyword must reject configuration');
} catch (InvalidArgumentException) {
    expect(true, 'unsupported schema keyword rejected');
}
$graphConfig = $configA;
$graphConfig['category_id'] = id(41);
$graphConfig['code'] = 'TEST';
try {
    $catalog->configure($graphConfig, 'Must not overwrite graph');
    expect(false, 'existing graph workflow cannot be replaced');
} catch (InvalidArgumentException) {
    expect(sql('SELECT active_version_id FROM workflow_templates WHERE id=?', [id(51)])->fetchColumn() === null, 'graph workflow preserved on configuration rejection');
}
$body = ['category_id'=>id(401),'assignment_id'=>id(206),'subject'=>'Replace printer cartridge',
    'description'=>'Station printer requires a replacement cartridge.','fields'=>['asset_tag'=>'PR-42','seats'=>2,'urgent'=>false,'service'=>'replace']];
expect(submitApi($body, 'forbidden', $tokens[101])->status === 403, 'read or identity access does not imply submit permission');
$admin->grantSubmit(id(106), 'Approved test intake access');
$count = (int)sql('SELECT count(*) FROM security_events')->fetchColumn();
$admin->grantSubmit(id(106), 'Repeated test access');
expect((int)sql('SELECT count(*) FROM security_events')->fetchColumn() === $count, 'submit provisioning is idempotent');
expect(count(callApi('/ticket-categories', $tokens[106])->payload['data']) === 2, 'catalog exposes configured triage categories only');
expect(callApi('/ticket-categories', $tokens[101])->status === 403, 'catalog requires submit and self-read permissions');
expect(count(callApi('/me/assignments', $tokens[106])->payload['data']) === 1, 'posting selector exposes only current actor assignments');
expect(callApi('/me/assignments', $tokens[106], ['actor'=>id(101)])->status === 422, 'posting selector rejects actor override');
expect(callApi('/ticket-categories', $tokens[106], ['q'=>'Equipment'])->payload['data'][0]['id'] === id(401), 'catalog search runs on server');
expect(submitApi($body, '')->status === 422, 'idempotency header required');
$invalidBodies = [
    array_replace($body, ['requester_id'=>id(101)]),
    array_replace($body, ['origin_station_id'=>id(11)]),
    array_replace($body, ['responsible_department_id'=>id(21)]),
    array_replace($body, ['workflow_version_id'=>id(61)]),
    array_replace($body, ['confidentiality'=>'internal']),
    array_replace($body, ['subject'=>' ']),
    array_replace($body, ['subject'=>str_repeat('x',256)]),
    array_replace($body, ['priority'=>'arbitrary']),
    array_replace($body, ['priority'=>null]),
    array_replace($body, ['fields'=>null]),
    array_replace($body, ['fields'=>['asset_tag'=>'x']]),
    array_replace($body, ['fields'=>['asset_tag'=>"PR\0-42"]]),
    array_replace($body, ['fields'=>['asset_tag'=>'PR-42','seats'=>'2']]),
    array_replace($body, ['fields'=>['asset_tag'=>'PR-42','seats'=>0]]),
    array_replace($body, ['fields'=>['asset_tag'=>'PR-42','seats'=>INF]]),
    array_replace($body, ['fields'=>['asset_tag'=>'PR-42','urgent'=>'false']]),
    array_replace($body, ['fields'=>['asset_tag'=>'PR-42','service'=>'unknown']]),
    array_replace($body, ['fields'=>['asset_tag'=>'PR-42','extra'=>'not allowed']]),
    array_replace($body, ['fields'=>[]]),
    array_replace($body, ['category_id'=>id(999)]),
];
foreach ($invalidBodies as $invalid) {
    expect(submitApi($invalid, 'invalid')->status === 422, 'invalid or client-controlled submission field rejected');
}
expect(submitApi(array_replace($body, ['assignment_id'=>id(201)]), 'spoof-posting')->status === 403, 'another actor posting rejected');
sql("UPDATE staff_assignments SET ends_at=now()-interval '1 minute' WHERE id=?", [id(206)]);
expect(submitApi($body, 'expired-posting')->status === 403, 'expired posting rejected at submit time');
sql("UPDATE staff_assignments SET ends_at=null,begins_at=now()+interval '1 day' WHERE id=?", [id(206)]);
expect(submitApi($body, 'future-posting')->status === 403, 'future posting rejected at submit time');
sql("UPDATE staff_assignments SET begins_at='2020-01-01' WHERE id=?", [id(206)]);
expect((int)sql("SELECT count(*) FROM idempotency_requests WHERE actor_user_id=?", [id(106)])->fetchColumn() === 0, 'validation failures do not reserve keys');
$first = submitApi($body, 'intake-a');
expect($first->status === 201 && !$first->payload['meta']->replayed, 'fresh submission returns created');
$ticketA = $first->payload['data'];
expect($ticketA['responsible_department_id'] === id(22), 'category A routes to configured Finance department');
expect($first->headers['Location'] === '/api/v1/tickets/'.$ticketA['id'], 'created response supplies detail location');
$retryBody = array_reverse($body, true);
$retryBody['fields'] = array_reverse($body['fields'], true);
$retry = submitApi($retryBody, 'intake-a');
expect($retry->payload['data']['id'] === $ticketA['id'] && $retry->payload['meta']->replayed, 'reordered object keys replay original ticket');
expect(submitApi(array_replace($body, ['subject'=>'Changed subject']), 'intake-a')->status === 409, 'same key with changed body conflicts');
$detail = callApi('/tickets/'.$ticketA['id'], $tokens[106])->payload['data'];
expect($detail['origin_station_id'] === id(12) && $detail['origin_assignment_id'] === id(206) && $detail['origin_position_id'] === id(31), 'origin IDs captured from authorized posting');
expect($detail['requester_snapshot']->station === 'Remote Test Station', 'human-readable requester posting snapshot captured');
sql('UPDATE staff_assignments SET station_id=? WHERE id=?', [id(11),id(206)]);
expect(callApi('/tickets/'.$ticketA['id'], $tokens[106])->payload['data']['origin_station_id'] === id(12), 'posting transfer preserves ticket origin');
$events = callApi('/tickets/'.$ticketA['id'].'/timeline', $tokens[106]);
expect(count($events->payload['data']) === 1 && $events->payload['data'][0]['event_type'] === 'TICKET_SUBMITTED', 'authorized timeline shows submission');
expect(callApi('/tickets/'.$ticketA['id'].'/timeline', $tokens[101])->status === 404, 'timeline obeys same ticket ACL');
expect(callApi('/tickets/'.$ticketA['id'].'/timeline', $tokens[106], ['after'=>'invalid'])->status === 422, 'timeline rejects malformed cursor');
for ($i=0; $i<11; $i++) {
    sql("INSERT INTO ticket_events(ticket_id,event_type,actor_user_id) VALUES (?,'TEST_HISTORY',?)", [$ticketA['id'],id(106)]);
}
sql("INSERT INTO ticket_events(ticket_id,event_type,actor_user_id,visibility) VALUES (?,'RESTRICTED_HISTORY',?,'restricted')", [$ticketA['id'],id(106)]);
$history = callApi('/tickets/'.$ticketA['id'].'/timeline', $tokens[106])->payload;
expect(count($history['data']) === 10 && $history['meta']->next_after !== null, 'timeline initially returns at most ten events');
$historyNext = callApi('/tickets/'.$ticketA['id'].'/timeline', $tokens[106], ['after'=>$history['meta']->next_after])->payload;
expect(count($historyNext['data']) === 2 && !in_array('RESTRICTED_HISTORY', array_column($historyNext['data'], 'event_type'), true), 'timeline next page excludes restricted history');
expect(sql("SELECT request_id FROM ticket_events WHERE ticket_id=? AND event_type='TICKET_SUBMITTED'", [$ticketA['id']])->fetchColumn() === $first->headers['X-Request-ID'], 'event carries HTTP correlation ID');
expect((int)sql('SELECT count(*) FROM notification_outbox o JOIN ticket_events e ON e.id=o.event_id WHERE e.ticket_id=?', [$ticketA['id']])->fetchColumn() === 1, 'duplicate submission creates one outbox job');
$bodyB = array_replace($body, ['category_id'=>id(402),'fields'=>[]]);
expect(submitApi($bodyB, 'intake-b')->payload['data']['responsible_department_id'] === id(21), 'category B routes independently to ICT');
$configA['form_schema']['required'][] = 'service';
$newVersion = $catalog->configure($configA, 'Approved updated form');
expect($newVersion['version'] === 2 && $newVersion['workflow_version_id'] !== $versionA['workflow_version_id'], 'configuration change publishes a new immutable version');
expect(sql('SELECT workflow_version_id FROM tickets WHERE id=?', [$ticketA['id']])->fetchColumn() === $versionA['workflow_version_id'], 'running ticket retains original workflow version');
expect(submitApi($body, 'intake-a')->payload['data']['id'] === $ticketA['id'], 'replay survives later configuration changes');
$newTicket = submitApi($body, 'intake-v2')->payload['data'];
expect($newTicket['workflow_version_id'] === $newVersion['workflow_version_id'], 'new submission binds current published version');

// Failure after ticket + event writes must roll back the entire submission and key.
$before = [];
foreach (['tickets','ticket_events','notification_outbox','idempotency_requests'] as $table) {
    $before[$table] = (int)sql("SELECT count(*) FROM $table")->fetchColumn();
}
sql("CREATE FUNCTION reject_intake_outbox() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Simulated outbox failure'; END \$\$");
sql('CREATE TRIGGER reject_intake_outbox BEFORE INSERT ON notification_outbox FOR EACH ROW EXECUTE FUNCTION reject_intake_outbox()');
expect(submitApi($body, 'outbox-failure')->status === 503, 'outbox failure returns safe service error');
foreach ($before as $table=>$total) {
    expect((int)sql("SELECT count(*) FROM $table")->fetchColumn() === $total, 'outbox failure rolls back '.$table);
}
sql('DROP TRIGGER reject_intake_outbox ON notification_outbox');
expect(submitApi($body, 'outbox-failure')->status === 201, 'rolled-back key can be retried safely');

// Six independent connections wait behind the same actor row lock before racing one key.
$payloadFile = tempnam(sys_get_temp_dir(), 'ticketing-intake-');
file_put_contents($payloadFile, json_encode($body, JSON_THROW_ON_ERROR));
$workers = [];
try {
    $db->beginTransaction();
    sql('SELECT id FROM app_users WHERE id=? FOR UPDATE', [id(106)]);
    try {
        $workerEnv = $env;
        $workerEnv['PGOPTIONS'] .= ' -c statement_timeout=15000';
        for ($i=0; $i<6; $i++) {
            $process = proc_open([PHP_BINARY,__DIR__.'/submit-worker.php',id(106),'concurrent-intake',$payloadFile],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $workerPipes, $root, $workerEnv);
            if (!is_resource($process)) {
                throw new RuntimeException('Submission worker could not start.');
            }
            fclose($workerPipes[0]);
            stream_set_timeout($workerPipes[1], 10);
            $workers[] = ['process'=>$process,'out'=>$workerPipes[1],'err'=>$workerPipes[2]];
        }
        foreach ($workers as $worker) {
            expect(trim(fgets($worker['out']) ?: '') === 'READY', 'independent submission worker reaches race barrier');
        }
    } finally {
        if ($db->inTransaction()) {
            $db->commit();
        }
    }
    $winnerIds = [];
    $created = 0;
    foreach ($workers as &$worker) {
        $output = stream_get_contents($worker['out']);
        $errors = stream_get_contents($worker['err']);
        fclose($worker['out']);
        fclose($worker['err']);
        $exitCode = proc_close($worker['process']);
        $worker['process'] = null;
        expect($exitCode === 0 && $errors === '', 'concurrent worker succeeds');
        $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        $winnerIds[] = $result['ticket']['id'];
        $created += (int)!$result['replayed'];
    }
    unset($worker);
    expect(count(array_unique($winnerIds)) === 1 && $created === 1, 'concurrent duplicate submissions create exactly one ticket');
    expect((int)sql('SELECT count(*) FROM ticket_events WHERE ticket_id=?', [$winnerIds[0]])->fetchColumn() === 1, 'concurrent retries write one event');
    expect((int)sql('SELECT count(*) FROM notification_outbox o JOIN ticket_events e ON e.id=o.event_id WHERE e.ticket_id=?', [$winnerIds[0]])->fetchColumn() === 1, 'concurrent retries enqueue one outbox job');
} finally {
    foreach ($workers as $worker) {
        if (is_resource($worker['process'])) {
            proc_terminate($worker['process']);
            foreach (['out','err'] as $pipe) {
                if (is_resource($worker[$pipe])) {
                    fclose($worker[$pipe]);
                }
            }
            proc_close($worker['process']);
        }
    }
    unlink($payloadFile);
}

// Broken published route fails closed and records an administrator-facing audit signal.
sql('UPDATE workflow_templates SET active_version_id=null WHERE category_id=?', [id(401)]);
expect(submitApi($body, 'missing-route')->status === 422, 'missing published route never silently chooses a department');
sql('UPDATE workflow_templates SET active_version_id=? WHERE category_id=?', [$newVersion['workflow_version_id'],id(401)]);
sql('UPDATE categories SET department_id=? WHERE id=?', [id(21),id(401)]);
expect(submitApi($body, 'mismatched-route')->status === 503, 'mismatched route fails closed');
expect((int)sql("SELECT count(*) FROM security_events WHERE event_type='INTAKE_CONFIGURATION_INVALID'")->fetchColumn() === 1, 'broken route records administrator-facing signal');
sql('UPDATE categories SET department_id=? WHERE id=?', [id(22),id(401)]);

// Real HTTP JSON boundary, successful creation and replay.
function httpSubmit(string $url, string $token, string $json, string $key): array
{
    $result = file_get_contents($url, false, stream_context_create(['http'=>[
        'method'=>'POST','ignore_errors'=>true,
        'header'=>"Content-Type: application/json\r\nAuthorization: Bearer $token\r\nIdempotency-Key: $key",
        'content'=>$json,
    ]]));
    return json_decode($result, true, 32, JSON_THROW_ON_ERROR);
}
$httpBody = $body;
$httpBody['fields'] = (object)$body['fields'];
$httpCreated = httpSubmit($base.'/tickets', $tokens[106], json_encode($httpBody, JSON_THROW_ON_ERROR), 'http-intake');
expect($httpCreated['code'] === 201, 'real HTTP JSON intake succeeds');
expect(httpSubmit($base.'/tickets', $tokens[106], json_encode($httpBody, JSON_THROW_ON_ERROR), 'http-intake')['meta']['replayed'], 'real HTTP retry replays');
expect(httpSubmit($base.'/tickets', $tokens[106], '[]', 'bad-json')['code'] === 422, 'HTTP rejects JSON array body');
expect(httpSubmit($base.'/tickets', $tokens[106], '{"fields":[]}', 'bad-fields')['code'] === 422, 'HTTP rejects array fields');
expect(httpSubmit($base.'/tickets', $tokens[106], '{invalid', 'bad-json')['code'] === 422, 'HTTP rejects malformed JSON');
expect(httpSubmit($base.'/tickets', $tokens[106], str_replace('"seats":2', '"seats":1e999', json_encode($httpBody, JSON_THROW_ON_ERROR)), 'overflow-number')['code'] === 422, 'HTTP rejects numeric overflow as validation error');
expect(httpSubmit($base.'/tickets', $tokens[106], str_repeat(' ',65537), 'large-body')['code'] === 422, 'HTTP bounds request body');

// Fill remaining allowance, then verify successful replays bypass the create limit.
$recent = (int)sql("SELECT count(*) FROM tickets WHERE requester_id=? AND created_at>now()-interval '1 hour'", [id(106)])->fetchColumn();
for ($i=$recent; $i<10; $i++) {
    expect(submitApi($body, 'rate-fill-'.$i)->status === 201, 'submission within hourly allowance succeeds');
}
$limited = submitApi($body, 'rate-exceeded');
expect($limited->status === 429 && isset($limited->headers['Retry-After']), 'eleventh ticket rate limited with retry hint');
expect(submitApi($body, 'intake-a')->status === 201, 'committed replay succeeds after rate limit');
