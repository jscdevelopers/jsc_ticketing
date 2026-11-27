<?php
declare(strict_types=1);

// Full local IT path against the disposable PostgreSQL schema.
$itEnv=getenv('APP_ENV');$itFlag=getenv('ALLOW_DEMO_INTEGRATIONS');putenv('APP_ENV=test');putenv('ALLOW_DEMO_INTEGRATIONS=true');
function itUser(string$name):string{return sql('SELECT user_id FROM user_credentials WHERE username=?',[$name])->fetchColumn();}
function itTask(string$ticket):array{return sql("SELECT * FROM task_instances WHERE ticket_id=? AND status IN ('offered','claimed','in_progress','blocked') ORDER BY started_at DESC,id DESC LIMIT 1",[$ticket])->fetch();}
function itAction(string$actor,array$task,string$action,array$extra=[]):array{global$db;return(new App\Workflow\TaskService($db))->action($actor,$task['id'],$action,(int)$task['version'],bin2hex(random_bytes(12)),['reason'=>'Approved IT routing integration check',...$extra],'it-routing')['data'];}
function itSubmit(string$area,string$suffix):array{global$db,$itCategory;$actor=itUser('it.officer.mag_harare');$posting=sql('SELECT id FROM staff_assignments WHERE user_id=? AND ends_at IS NULL',[$actor])->fetchColumn();return(new App\Tickets\TicketSubmission($db))->submit($actor,['category_id'=>$itCategory,'assignment_id'=>$posting,'subject'=>'Computer malfunction '.$suffix,'description'=>'Station computer requires IT investigation.','fields'=>['asset_tag'=>'JSC-IT-42','issue_area'=>$area]],'it-submit-'.$suffix,'it-routing')['ticket'];}
try{
    $itPasswords=[];foreach(App\Support\ItSetup::accounts()as$name)$itPasswords[$name]=bin2hex(random_bytes(16));
    $itInstaller=new App\Support\ItSetup($db);$installed=$itInstaller->install($itPasswords);$itCategory=$installed['category_id'];
    expect($installed['tickets']===9,'IT setup creates working examples at each stage and one resolved operation');
    expect($installed['accounts']===38,'IT structure provisions 19 station officers, 10 principals, 7 department staff and 2 executives');
    App\Security\IntakeAccess::requirePermission($db,itUser('it.hardware_specialist'));expect(true,'IT personnel can also report and read their own service requests');
    $itHashes=sql("SELECT username,password_hash FROM user_credentials WHERE username LIKE 'it.%' OR username IN ('secretary','deputy.secretary')")->fetchAll(PDO::FETCH_KEY_PAIR);
    expect($itInstaller->install($itPasswords)['already_installed']&&$itHashes===sql("SELECT username,password_hash FROM user_credentials WHERE username LIKE 'it.%' OR username IN ('secretary','deputy.secretary')")->fetchAll(PDO::FETCH_KEY_PAIR),'IT setup re-run preserves all existing credentials');
    $itReader=new App\Tickets\TicketReader($db);$itTasks=new App\Workflow\TaskService($db);$itComments=new App\Tickets\Collaboration($db);
    $principal=itUser('it.principal.harare');$foreignPrincipal=itUser('it.principal.bulawayo');$head=itUser('it.head');$secretary=itUser('secretary');$helpDesk=itUser('it.helpdesk');$hardware=itUser('it.hardware_specialist');
    $itTicket=itSubmit('hardware_specialist','auto');$task=itTask($itTicket['id']);
    expect($task['status']==='claimed'&&$task['node_id']==='station_it','station IT work is automatically claimed without a manual queue step');
    expect((bool)sql('SELECT 1 FROM staff_assignments sa JOIN tickets t ON t.id=? WHERE sa.user_id=? AND sa.station_id=t.origin_station_id AND sa.department_id=t.responsible_department_id AND sa.ends_at IS NULL',[$itTicket['id'],$task['claimed_by']])->fetchColumn(),'automatic assignment chooses a primary officer at the origin station');
    expect($itTicket['status']==='in_progress','automatic assignment immediately updates the ticket lifecycle');
    expect($itReader->detail($principal,$itTicket['id'])['id']===$itTicket['id'],'provincial principal can inspect lower-stage work in the same province');
    try{$itReader->detail($foreignPrincipal,$itTicket['id']);expect(false,'foreign principal must not see lower-stage tickets');}catch(App\Http\ApiException$error){expect($error->status===404,'principal intervention cannot cross provinces');}
    expect($itTasks->listing($hardware,['q'=>'Computer malfunction auto'])['data'][0]['can_intervene'],'specialist has an explicit current intervention audience');
    $takeoverBody=['reason'=>'Hardware specialist takes responsibility'];$takeoverKey='it-takeover-retry';
    $taken=$itTasks->action($hardware,$task['id'],'take-over',(int)$task['version'],$takeoverKey,$takeoverBody,'it-routing');
    expect($taken['data']['claimed_by']===$hardware,'specialist can take over a claimed station operation');
    expect($itTasks->action($hardware,$task['id'],'take-over',(int)$task['version'],$takeoverKey,$takeoverBody,'it-routing')['meta']['replayed'],'takeover retry is idempotent');
    expect((int)sql("SELECT count(*) FROM ticket_events WHERE task_id=? AND event_type='TASK_TAKEN_OVER'",[$task['id']])->fetchColumn()===1,'takeover produces exactly one audited event');
    try{itAction($principal,$task,'take-over');expect(false,'stale takeover must fail');}catch(App\Http\ApiException$error){expect($error->status===409,'stale concurrent takeover is rejected');}
    try{itAction($task['claimed_by'],itTask($itTicket['id']),'complete');expect(false,'prior claimant cannot complete');}catch(App\Http\ApiException$error){expect($error->status===409,'takeover removes prior claimant action rights');}
    itAction($hardware,itTask($itTicket['id']),'complete');expect(sql('SELECT status FROM tickets WHERE id=?',[$itTicket['id']])->fetchColumn()==='closed','intervening specialist can resolve lower-stage work directly');
    foreach(['systems_engineer','systems_analyst','hardware_specialist','database_specialist','other']as$area){
        $ticket=itSubmit($area,$area);$task=itTask($ticket['id']);itAction($task['claimed_by'],$task,'escalate');
        $task=itTask($ticket['id']);expect($task['node_id']==='provincial_it'&&$task['status']==='offered','unresolved station work escalates to provincial principal');
        itAction($principal,$task,'claim');itAction($principal,itTask($ticket['id']),'escalate');$task=itTask($ticket['id']);expect($task['node_id']==='help_desk','principal escalates to principal help desk');
        itAction($helpDesk,$task,'claim');itAction($helpDesk,itTask($ticket['id']),'complete');$task=itTask($ticket['id']);
        expect($task['node_id']===($area==='other'?'manual_dispatch':$area),'issue category selects the matching specialist or manual dispatch');
        if($area==='other'){
            itAction($helpDesk,$task,'claim');$task=itTask($ticket['id']);$eventCount=(int)sql('SELECT count(*) FROM ticket_events WHERE ticket_id=?',[$ticket['id']])->fetchColumn();
            try{itAction($helpDesk,$task,'complete');expect(false,'dispatch cannot be resolved without destination');}catch(App\Http\ApiException$error){expect($error->status===422,'manual dispatch requires an explicit destination');}
            try{itAction($helpDesk,$task,'redirect',['target_node_id'=>'end']);expect(false,'unapproved destination rejected');}catch(App\Http\ApiException$error){expect($error->status===422,'manual redirection cannot skip to closure');}
            expect(itTask($ticket['id'])['version']===$task['version']&&(int)sql('SELECT count(*) FROM ticket_events WHERE ticket_id=?',[$ticket['id']])->fetchColumn()===$eventCount,'failed redirects roll back task and audit changes');
            itAction($helpDesk,$task,'redirect',['target_node_id'=>'hardware_specialist']);$task=itTask($ticket['id']);expect($task['node_id']==='hardware_specialist','help desk redirects an unmatched category to an approved specialist');
        }
        $specialist=itUser('it.'.($area==='other'?'hardware_specialist':$area));itAction($specialist,$task,'claim');itAction($specialist,itTask($ticket['id']),'complete');
        expect(sql('SELECT status FROM tickets WHERE id=?',[$ticket['id']])->fetchColumn()==='closed','specialist resolution closes the complete escalation path');
        expect((int)sql("SELECT count(*) FROM ticket_events WHERE ticket_id=? AND event_type='WORKFLOW_ESCALATED'",[$ticket['id']])->fetchColumn()===2,'station and province escalations retain their reasons and event trail');
    }
    $version=(int)sql('SELECT version FROM tickets WHERE id=?',[$itTicket['id']])->fetchColumn();
    $directed=$itComments->comment($secretary,$itTicket['id'],$version,'secretary-closed-update',['body'=>'Please review this resolved operation.','recipient_user_ids'=>[$hardware]],'it-routing');
    expect($directed['data']['comment_id']!==null,'Secretary can comment to authorized personnel after closure');
    expect(count($itComments->recipients($secretary,$itTicket['id'],['q'=>'Hardware'])['data'])>=1,'directed recipient search includes current authorized specialist');
    try{$itComments->comment($secretary,$itTicket['id'],$version+1,'secretary-wrong-recipient',['body'=>'Invalid recipient must fail.','recipient_user_ids'=>[$foreignPrincipal]],'it-routing');expect(false,'foreign recipient has no detail authority');}catch(App\Http\ApiException$error){expect($error->status===404,'directed comments never grant ticket visibility');}
    $flow=$itComments->flow($secretary,$itTicket['id']);expect(count($flow['nodes'])===11&&count($flow['edges'])>10,'ticket diagram includes future stages from its own published version');
    expect(!array_filter($flow['nodes'],static fn($node)=>isset($node['config']['user_ids'])||isset($node['config']['intervention_rules'])),'ticket diagram omits personnel policy details');
    $itAnalytics=new App\Tickets\Analytics($db);$headAnalytics=$itAnalytics->dashboard($head,[]);expect(count($headAnalytics['charts'])===18&&$headAnalytics['total']>=6,'department head has 18 scoped operational charts');
    $ictDepartment=sql("SELECT id FROM departments WHERE code='ICT'")->fetchColumn();
    expect($headAnalytics['total']===(int)sql('SELECT count(*) FROM tickets WHERE responsible_department_id=? AND confidentiality=\'internal\'',[$ictDepartment])->fetchColumn(),'department analytics exclude other departments and uncleared data');
    expect($itAnalytics->dashboard($secretary,[])['total']===(int)sql('SELECT count(*) FROM tickets')->fetchColumn(),'Secretary analytics cover all explicitly authorized national records');
    try{$itAnalytics->dashboard(itUser('registry.harare'),[]);expect(false,'requester analytics must fail');}catch(App\Http\ApiException$error){expect($error->status===403,'advanced analytics require a separate permission and scope');}
    $revokeTicket=itSubmit('hardware_specialist','revocation');$revokeTask=itTask($revokeTicket['id']);sql("UPDATE scope_grants SET revoked_at=now() WHERE user_id=? AND permission_code='task.intervene'",[$hardware]);
    try{$itReader->detail($hardware,$revokeTicket['id']);expect(false,'revoked intervention cannot read contextual ticket');}catch(App\Http\ApiException$error){expect($error->status===404,'revoked intervention immediately removes contextual read');}
    try{itAction($hardware,$revokeTask,'take-over');expect(false,'revoked intervention cannot act');}catch(App\Http\ApiException$error){expect(in_array($error->status,[403,404],true),'revoked intervention immediately blocks takeover');}
    $activeDefinition=json_decode(sql('SELECT definition FROM workflow_versions WHERE id=?',[$revokeTicket['workflow_version_id']])->fetchColumn(),true);
    foreach([['redirect_targets'=>'bad'],['escalate_to'=>[]],['redirect_only'=>true,'redirect_targets'=>[]],['intervention_rules'=>[['scope'=>'department','user_ids'=>[]]]]]as$badConfig){$bad=$activeDefinition;$bad['nodes'][1]['config']=array_replace($bad['nodes'][1]['config'],$badConfig);try{new App\Workflow\Graph($bad);expect(false,'malformed routing policy must fail');}catch(InvalidArgumentException){expect(true,'malformed routing policy fails validation before runtime');}}
    $people=$activeDefinition;$people['nodes'][1]['config']['audience_mode']='people';$people['nodes'][1]['config']['user_ids']=[itUser('it.officer.mag_harare')];$people['require_second_approver']=false;$people['nodes'][1]['config']['intervention_rules'][0]['scope']='national';
    $designer=itUser('workflow.designer');$saved=(new App\Workflow\WorkflowService($db))->save($designer,null,0,'national-intervention-draft',['category_id'=>$itCategory,'definition'=>$people])['data'];
    sql("INSERT INTO scope_grants(user_id,permission_code,scope_kind,grant_reason) VALUES (?,'workflow.publish','national','Independent publication test')",[$designer]);
    try{(new App\Workflow\WorkflowService($db))->publish($designer,$saved['id'],1,'national-intervention-publish','Author cannot approve national intervention');expect(false,'national intervention requires second publisher');}catch(App\Http\ApiException$error){expect($error->status===403,'national intervention rules cannot bypass independent publication');}
    expect(count((new App\Workflow\WorkflowService($db))->choices($designer,'users',['q'=>'IT Officer'])['data'])===10,'workflow personnel choices are search-first and bounded to ten');
    putenv('APP_ENV=production');try{$itInstaller->install($itPasswords);expect(false,'production installer must refuse local examples');}catch(InvalidArgumentException){expect(true,'IT sample provisioning is blocked in production');}
}finally{putenv($itEnv===false?'APP_ENV':'APP_ENV='.$itEnv);putenv($itFlag===false?'ALLOW_DEMO_INTEGRATIONS':'ALLOW_DEMO_INTEGRATIONS='.$itFlag);}
