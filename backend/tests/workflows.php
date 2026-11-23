<?php
declare(strict_types=1);

use App\Workflow\Graph;
use App\Workflow\WorkflowService;
use App\Workflow\TaskService;
use App\Workflow\BusinessClock;
use App\Tickets\TicketSubmission;
use App\Http\ApiException;

// Disposable schema, no real identities or business data.
foreach([106,101]as$adminNumber){
    sql("INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE code='operations_admin'",[id($adminNumber)]);
    foreach(['workflow.design','workflow.publish']as$permission){sql("INSERT INTO scope_grants(user_id,permission_code,scope_kind,grant_reason) VALUES (?,?,'national','Workflow integration test')",[id($adminNumber),$permission]);}
}
for($i=1;$i<=100;$i++){
    sql('INSERT INTO app_users(id,external_subject,display_name) VALUES (?,?,?)',[id(10000+$i),'race:'.$i,'Race Staff '.$i]);
    sql("INSERT INTO staff_assignments(user_id,position_id,department_id,station_id,begins_at) VALUES (?,?,?,?,'2020-01-01')",[id(10000+$i),id(31),id(21),id(11)]);
    sql("INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE code='task_agent'",[id(10000+$i)]);
    sql("INSERT INTO scope_grants(user_id,permission_code,scope_kind,scope_ref_id,grant_reason) VALUES (?,'task.claim','station',?,'Claim integration test')",[id(10000+$i),id(11)]);
}
sql("INSERT INTO app_users(id,external_subject,display_name) VALUES (?,'workflow:requester','Workflow Requester')",[id(15000)]);
sql("INSERT INTO staff_assignments(id,user_id,position_id,department_id,station_id,begins_at) VALUES (?,?,?,?,?,'2020-01-01')",[id(15001),id(15000),id(31),id(21),id(11)]);
$admin->grantSubmit(id(15000),'Workflow requester test');
$schemaEmpty=['type'=>'object','additionalProperties'=>false,'properties'=>[]];
$workflowCategory=['category_id'=>id(16000),'department_id'=>id(21),'code'=>'WORKFLOW','name'=>'Workflow integration','form_schema'=>$schemaEmpty];
$catalog->configure($workflowCategory,'Workflow integration');
$definition=['engine'=>'workflow-v1','department_id'=>id(21),'form_schema'=>$schemaEmpty,
    'nodes'=>[
        ['id'=>'start','type'=>'start','label'=>'Submitted'],
        ['id'=>'work','type'=>'claim_pool','label'=>'ICT triage','config'=>['department_id'=>id(21),'position_id'=>id(31),'scope'=>'station','sla_minutes'=>60]],
        ['id'=>'end','type'=>'end','label'=>'Closed'],
    ],'edges'=>[['from'=>'start','to'=>'work'],['from'=>'work','to'=>'end']]];
$graph=new Graph($definition);
$bad=$definition;$bad['edges'][]=['from'=>'end','to'=>'start'];
try{new Graph($bad);expect(false,'cycle rejected');}catch(InvalidArgumentException){expect(true,'workflow cycles rejected');}
$bad=$definition;$bad['nodes'][1]['type']='decision';
try{new Graph($bad);expect(false,'fallback required');}catch(InvalidArgumentException){expect(true,'decisions require fallback');}
$workflows=new WorkflowService($db);
$draft=$workflows->save(id(106),null,0,'draft-main',['category_id'=>id(16000),'definition'=>$definition])['data'];
expect($draft['version']===1,'workflow draft saved');
expect($workflows->simulate(id(106),$draft['id'],[])['valid'],'workflow validates and simulates');
try{$workflows->publish(id(106),$draft['id'],1,'self-publish','Self approval must fail');expect(false,'independent publisher required');}
catch(ApiException $error){expect($error->status===403,'author cannot approve own workflow by default');}
$published=$workflows->publish(id(101),$draft['id'],1,'publish-main','Independent approval')['data'];
$submission=new TicketSubmission($db);
$workflowBody=['category_id'=>id(16000),'assignment_id'=>id(15001),'subject'=>'Concurrent claim case','description'=>'Workflow test case','fields'=>[]];
$workflowTicket=$submission->submit(id(15000),$workflowBody,'workflow-main','workflow-test')['ticket'];
$task=sql('SELECT * FROM task_instances WHERE ticket_id=?',[$workflowTicket['id']])->fetch();
expect($workflowTicket['status']==='routed'&&$task['status']==='offered','intake starts offered workflow task');
expect((int)sql('SELECT count(*) FROM task_candidates WHERE task_id=?',[$task['id']])->fetchColumn()===100,'all 100 eligible staff offered one task');
expect($task['due_at']!==null,'business deadline calculated');
expect((new App\Tickets\TicketReader($db))->detail(id(10001),$workflowTicket['id'])['id']===$workflowTicket['id'],'eligible candidate obtains contextual ticket read');
try{(new TaskService($db))->action(id(15000),$task['id'],'claim',1,'forbidden',[],'test');expect(false,'requester cannot claim without task authority');}
catch(ApiException $error){expect($error->status===403,'requester read does not grant claim rights');}

$claimWorkers=[];$winner=null;$workerErrors=[];
try{
    $db->beginTransaction();sql('SELECT id FROM tickets WHERE id=? FOR UPDATE',[$workflowTicket['id']]);
    try{
        $claimEnv=$env;$claimEnv['PGOPTIONS'].=' -c statement_timeout=120000';
        for($i=1;$i<=100;$i++){
            $process=proc_open([PHP_BINARY,__DIR__.'/task-worker.php',id(10000+$i),$task['id']],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$workerPipes,$root,$claimEnv);
            if(!is_resource($process)){throw new RuntimeException('Cannot create claim worker');}
            fclose($workerPipes[0]);stream_set_timeout($workerPipes[1],60);
            $claimWorkers[]=['process'=>$process,'out'=>$workerPipes[1],'err'=>$workerPipes[2],'actor'=>id(10000+$i)];
        }
        foreach($claimWorkers as$worker){expect(trim(fgets($worker['out'])?:'')==='READY','claim contender connected at barrier');}
    }finally{if($db->inTransaction()){$db->commit();}}
    $conflicts=0;$winners=0;
    foreach($claimWorkers as&$worker){
        $output=stream_get_contents($worker['out']);$error=stream_get_contents($worker['err']);fclose($worker['out']);fclose($worker['err']);
        $exit=proc_close($worker['process']);$worker['process']=null;
        expect($exit===0&&$error==='','claim worker returned deterministic result');
        $response=json_decode($output,true,32,JSON_THROW_ON_ERROR);
        if($response['status']===200){$winners++;$winner=$worker['actor'];}elseif($response['status']===409){$conflicts++;}
    }unset($worker);
    expect($winners===1&&$conflicts===99,'100-way race has one winner and 99 conflicts');
    expect((int)sql("SELECT count(*) FROM ticket_events WHERE task_id=? AND event_type='TASK_CLAIMED'",[$task['id']])->fetchColumn()===1,'one immutable CLAIMED event');
}finally{
    foreach($claimWorkers as$worker){if(is_resource($worker['process'])){proc_terminate($worker['process']);foreach(['out','err']as$pipe){if(is_resource($worker[$pipe])){fclose($worker[$pipe]);}}proc_close($worker['process']);}}
}
$tasks=new TaskService($db);
expect($tasks->action($winner,$task['id'],'claim',1,'claim-race',[],'test')['meta']['replayed'],'winner retry is idempotent');
$released=$tasks->action($winner,$task['id'],'release',2,'release',['reason'=>'Return to eligible pool'],'test')['data'];
expect($released['status']==='offered'&&$released['claimed_by']===null,'claim released with actor attribution');
$other=$winner===id(10001)?id(10002):id(10001);
$claimed=$tasks->action($other,$task['id'],'claim',3,'reclaim',[],'test')['data'];
expect($claimed['claimed_by']===$other,'another eligible actor claims released task');
$tasks->action($other,$task['id'],'complete',4,'finish',['reason'=>'Resolved after independent work'],'test');
expect(sql('SELECT status FROM tickets WHERE id=?',[$workflowTicket['id']])->fetchColumn()==='closed','last task closes workflow');
expect(sql("SELECT reason FROM ticket_events WHERE task_id=? AND event_type='TASK_RELEASED'",[$task['id']])->fetchColumn()==='Return to eligible pool','release reason retained');

// Genuine independent tasks for Finance/Legal, joined only after both outcomes.
foreach([10001,10002]as$user){sql("INSERT INTO staff_assignments(user_id,position_id,department_id,station_id,begins_at) VALUES (?,?,?,?,'2020-01-01')",[id($user),id(31),id(22),id(11)]);}
$parallel=$definition;
$parallel['nodes']=[['id'=>'start','type'=>'start','label'=>'Submitted'],['id'=>'fork','type'=>'parallel_fork','label'=>'Independent reviews','config'=>['join_id'=>'join']],
    ['id'=>'finance','type'=>'approval','label'=>'Finance','config'=>['department_id'=>id(21),'position_id'=>id(31),'scope'=>'station']],
    ['id'=>'legal','type'=>'approval','label'=>'Legal','config'=>['department_id'=>id(22),'position_id'=>id(31),'scope'=>'station']],
    ['id'=>'join','type'=>'parallel_join','label'=>'Both reviews','config'=>['mode'=>'all','separation_of_duties'=>true]],['id'=>'end','type'=>'end','label'=>'Closed']];
$parallel['edges']=[['from'=>'start','to'=>'fork'],['from'=>'fork','to'=>'finance'],['from'=>'fork','to'=>'legal'],['from'=>'finance','to'=>'join'],['from'=>'legal','to'=>'join'],['from'=>'join','to'=>'end']];
$draft2=$workflows->save(id(106),null,0,'parallel-draft',['category_id'=>id(16000),'definition'=>$parallel])['data'];
$workflows->publish(id(101),$draft2['id'],1,'parallel-publish','Independent dual review');
$parallelTicket=$submission->submit(id(15000),$workflowBody,'parallel-ticket','test')['ticket'];
$parallelTasks=sql('SELECT * FROM task_instances WHERE ticket_id=? ORDER BY node_id',[$parallelTicket['id']])->fetchAll();
expect(count($parallelTasks)===2,'parallel reviews spawn two separate tasks');
$finance=$parallelTasks[0];$legal=$parallelTasks[1];
$tasks->action(id(10001),$finance['id'],'claim',1,'finance-claim',[],'test');
$tasks->action(id(10001),$finance['id'],'approve',2,'finance-approve',['reason'=>'Finance independently approved'],'test');
expect(sql('SELECT status FROM tickets WHERE id=?',[$parallelTicket['id']])->fetchColumn()==='awaiting_approval','ALL join waits for Legal');
try{$tasks->action(id(10001),$legal['id'],'claim',1,'same-actor',[],'test');expect(false,'same actor must not approve separate branches');}
catch(ApiException $error){expect($error->status===403,'separation of duties enforced before second claim');}
$tasks->action(id(10002),$legal['id'],'claim',1,'legal-claim',[],'test');
$tasks->action(id(10002),$legal['id'],'approve',2,'legal-approve',['reason'=>'Legal independently approved'],'test');
expect(sql('SELECT status FROM tickets WHERE id=?',[$parallelTicket['id']])->fetchColumn()==='closed','ALL join advances after both independent approvals');
expect(sql('SELECT workflow_version_id FROM tickets WHERE id=?',[$workflowTicket['id']])->fetchColumn()===$published['workflow_version_id'],'published version change never rebases prior tickets');

$any=$parallel;$any['nodes'][4]['config']=['mode'=>'any','cancel_remaining'=>true];
$draftAny=$workflows->save(id(106),null,0,'any-draft',['category_id'=>id(16000),'definition'=>$any])['data'];
$workflows->publish(id(101),$draftAny['id'],1,'any-publish','Any authorized review');
$anyTicket=$submission->submit(id(15000),$workflowBody,'any-ticket','test')['ticket'];
$anyTasks=sql('SELECT * FROM task_instances WHERE ticket_id=? ORDER BY node_id',[$anyTicket['id']])->fetchAll();
$tasks->action(id(10001),$anyTasks[0]['id'],'claim',1,'any-claim',[],'test');
$tasks->action(id(10001),$anyTasks[0]['id'],'approve',2,'any-approve',['reason'=>'Any review satisfied'],'test');
expect(sql('SELECT status FROM task_instances WHERE id=?',[$anyTasks[1]['id']])->fetchColumn()==='cancelled','ANY policy cancels remaining independent task');
expect((int)sql("SELECT count(*) FROM ticket_events WHERE ticket_id=? AND event_type='TASK_CANCELLED'",[$anyTicket['id']])->fetchColumn()===1,'ANY cancellation is audited');

$friday=new DateTimeImmutable('2026-11-20T14:00:00Z');
expect(BusinessClock::due($friday,120)->format(DATE_ATOM)==='2026-11-23T07:30:00+00:00','SLA skips weekend in Africa/Harare');
expect(BusinessClock::due($friday,120,['holidays'=>['2026-11-23']])->format('Y-m-d')==='2026-11-24','SLA skips configured holiday');
expect(BusinessClock::due(new DateTimeImmutable('2026-03-06T21:00:00Z'),120,['timezone'=>'America/New_York'])->format(DATE_ATOM)==='2026-03-09T13:30:00+00:00','SLA handles DST boundary in local business calendar');
