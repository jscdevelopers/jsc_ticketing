<?php
declare(strict_types=1);

use App\Http\ApiException;
use App\Security\PasswordAuth;
use App\Security\Administration;
use App\Tickets\Collaboration;
use App\Tickets\Reporting;
use App\Workflow\Worker;

$admin->grantOperations(id(106),'Operational administrator test');
$administration=new Administration($db);
$createdProvince=$administration->save(id(106),'provinces',null,0,'admin-province',['code'=>'TEST_ADMIN','name'=>'Administrator Province','active'=>true,'reason'=>'Approved test organization'])['data'];
expect($createdProvince['version']===1,'audited organization create returns initial version');
$edited=$administration->save(id(106),'provinces',$createdProvince['id'],1,'admin-edit',['code'=>'TEST_ADMIN','name'=>'Updated Administrator Province','active'=>false,'reason'=>'Approved reversible archive'])['data'];
expect(!$edited['active']&&$edited['version']===2,'directory archive is reversible and version guarded');
try{$administration->save(id(106),'provinces',$createdProvince['id'],1,'admin-stale',['code'=>'TEST_ADMIN','name'=>'Stale overwrite','active'=>true,'reason'=>'Stale write must fail']);expect(false,'stale directory update must reject');}
catch(App\Http\ApiException$error){expect($error->status===409,'stale directory update rejected');}
try{$administration->listing(id(15000),'users',[]);expect(false,'requester cannot inspect user directory');}
catch(App\Http\ApiException$error){expect($error->status===403,'national directory authorization enforced');}

$oldAuthMode=getenv('AUTH_MODE');$oldAppEnv=getenv('APP_ENV');putenv('AUTH_MODE=password');putenv('APP_ENV=offline');
try{
    $temporaryPassword=bin2hex(random_bytes(12));$newPassword=bin2hex(random_bytes(12));
    $admin->setPassword(id(15000),'workflow.requester',$temporaryPassword,'Provision test primary credential');
    $passwordAuth=new PasswordAuth($db);
    try{$passwordAuth->login(['username'=>'workflow.requester','password'=>'incorrect-password'],'test-ip');expect(false,'bad password must reject');}
    catch(App\Http\ApiException$error){expect($error->status===401,'bad password rejected without identity details');}
    $session=$passwordAuth->login(['username'=>'WORKFLOW.REQUESTER','password'=>$temporaryPassword],'test-ip');
    expect($session['must_change_password'],'temporary password requires mandatory change');
    expect(callApi('/me',$session['token'])->status===403,'password-change token cannot read application data');
    $passwordAuth->change(id(15000),['current_password'=>$temporaryPassword,'new_password'=>$newPassword]);
    expect(callApi('/me',$session['token'])->status===401,'password change revokes all old sessions');
    $session=$passwordAuth->login(['username'=>'workflow.requester','password'=>$newPassword],'test-ip');
    expect(!$session['must_change_password']&&callApi('/me',$session['token'])->status===200,'changed primary credential signs in offline');
    for($i=0;$i<8;$i++){try{$passwordAuth->login(['username'=>'unknown.test','password'=>'incorrect-password'],'rate-test-ip');}catch(App\Http\ApiException){}}
    try{$passwordAuth->login(['username'=>'unknown.test','password'=>'incorrect-password'],'rate-test-ip');expect(false,'login rate limit must trigger');}
    catch(App\Http\ApiException$error){expect($error->status===429,'per-account login throttling enforced');}

    // Inject only the provider boundary. Primary password and challenge storage remain real.
    putenv('AUTH_MODE=nauth');$factor=new class {
        public bool $allowed=false;
        public function checkUser(string$id,string$name):array{return['ok'=>true,'data'=>['authentication_allowed'=>true,'code_allowed'=>true]];}
        public function verify(string$id,string$name,string$code):array{return['allowed'=>$this->allowed];}
    };
    $mfa=new PasswordAuth($db,static fn()=>$factor);
    $challenge=$mfa->login(['username'=>'workflow.requester','password'=>$newPassword],'mfa-test-ip');
    expect(isset($challenge['challenge'])&&!isset($challenge['token']),'primary password alone cannot bypass configured MFA');
    try{$mfa->verify(['challenge'=>$challenge['challenge'],'code'=>'000000'],'mfa-test-ip');expect(false,'provider denial must reject');}
    catch(App\Http\ApiException$error){expect($error->status===401,'MFA requires strict allowed true');}
    $factor->allowed=true;$mfaSession=$mfa->verify(['challenge'=>$challenge['challenge'],'code'=>'000000'],'mfa-test-ip');
    expect(callApi('/me',$mfaSession['token'])->status===200,'approved provider factor completes sign-in');
    try{$mfa->verify(['challenge'=>$challenge['challenge'],'code'=>'000000'],'mfa-test-ip');expect(false,'consumed MFA challenge cannot replay');}
    catch(App\Http\ApiException$error){expect($error->status===401,'MFA challenge consumption is one-use');}
}finally{if($oldAuthMode===false){putenv('AUTH_MODE');}else{putenv('AUTH_MODE='.$oldAuthMode);}if($oldAppEnv===false){putenv('APP_ENV');}else{putenv('APP_ENV='.$oldAppEnv);}}

// Request-information -> reply -> timer -> claim task, driven by the same runtime.
$info=$definition;
$info['nodes']=[['id'=>'start','type'=>'start','label'=>'Submitted'],['id'=>'question','type'=>'request_info','label'=>'Requester details'],['id'=>'wait','type'=>'timer','label'=>'Wait','config'=>['delay_minutes'=>1]],$definition['nodes'][1],$definition['nodes'][2]];
$info['edges']=[['from'=>'start','to'=>'question'],['from'=>'question','to'=>'wait'],['from'=>'wait','to'=>'work'],['from'=>'work','to'=>'end']];
$infoDraft=$workflows->save(id(106),null,0,'info-draft',['category_id'=>id(16000),'definition'=>$info])['data'];$workflows->publish(id(101),$infoDraft['id'],1,'info-publish','Requester information flow');
$infoTicket=$submission->submit(id(15000),$workflowBody,'info-ticket','test')['ticket'];
expect($infoTicket['status']==='awaiting_input','request-information node waits for requester');
$collaboration=new Collaboration($db);
$reply=$collaboration->comment(id(15000),$infoTicket['id'],$infoTicket['version'],'requester-reply',['body'=>'Requested details supplied','sensitivity'=>'participants_only'],'test',true)['data'];
expect((int)sql("SELECT count(*) FROM workflow_executions WHERE ticket_id=? AND node_type='timer' AND status='waiting'",[$infoTicket['id']])->fetchColumn()===1,'reply advances to waiting timer');
sql("UPDATE workflow_executions SET due_at=now()-interval '1 second' WHERE ticket_id=? AND node_type='timer'",[$infoTicket['id']]);
$worker=new Worker($db);expect($worker->timers()===1,'due timer advances exactly once');expect($worker->timers()===0,'timer retries cannot duplicate activation');
$infoTask=sql('SELECT * FROM task_instances WHERE ticket_id=?',[$infoTicket['id']])->fetch();
expect($infoTask['status']==='offered','timer activates eligible claim pool');
sql("UPDATE task_instances SET due_at=now()-interval '1 second' WHERE id=?",[$infoTask['id']]);
expect($worker->escalate()===1&&$worker->escalate()===0,'SLA breach escalates once under retry');
$currentVersion=(int)sql('SELECT version FROM tickets WHERE id=?',[$infoTicket['id']])->fetchColumn();
$collaboration->comment(id(15000),$infoTicket['id'],$currentVersion,'requester-comment',['body'=>'Visible requester note','sensitivity'=>'public_internal'],'test');
expect(count($collaboration->comments(id(10001),$infoTicket['id'],[])['data'])===1,'unclaimed candidate cannot read participants-only reply');
expect(count($collaboration->comments(id(15000),$infoTicket['id'],[])['data'])===2,'requester sees own participant reply and internal note');

for($i=0;$i<100;$i++){if($worker->outbox()===0){break;}}
$delivered=(int)sql('SELECT count(*) FROM user_notifications')->fetchColumn();$worker->outbox();
expect($delivered>0&&(int)sql('SELECT count(*) FROM user_notifications')->fetchColumn()===$delivered,'in-app outbox retries do not duplicate user notices');
expect((new Reporting($db))->dashboard(id(15000),[])['total']===4,'personal dashboard contains only requester tickets');
try{(new Reporting($db))->dashboard(id(10001),['scope'=>'national']);expect(false,'station task authority is not national reporting');}
catch(App\Http\ApiException$error){expect($error->status===403,'national dashboard requires explicit national data scope');}

// Management does not confer data visibility: grant both explicitly for this fixture.
$admin->grantRead(id(106),'station',id(11),'Scoped lifecycle test');
foreach(['task.manage','ticket.manage']as$permission){sql("INSERT INTO scope_grants(user_id,permission_code,scope_kind,scope_ref_id,grant_reason) VALUES (?,?,'station',?,'Management test')",[id(106),$permission,id(11)]);}
$management=new App\Workflow\TaskManagement($db);
$taskVersion=(int)sql('SELECT version FROM task_instances WHERE id=?',[$infoTask['id']])->fetchColumn();
$management->action(id(106),$infoTask['id'],'pause-sla',$taskVersion,'pause-sla',['reason'=>'Approved pause'],'test');
$paused=sql('SELECT * FROM task_instances WHERE id=?',[$infoTask['id']])->fetch();
expect($paused['due_at']===null&&$paused['sla_paused_at']!==null,'management pauses the business SLA');
$management->action(id(106),$infoTask['id'],'resume-sla',(int)$paused['version'],'resume-sla',['reason'=>'Approved resume'],'test');
$resumed=sql('SELECT * FROM task_instances WHERE id=?',[$infoTask['id']])->fetch();
expect($resumed['due_at']!==null&&$resumed['sla_paused_at']===null,'management resumes remaining business time');
$management->action(id(106),$infoTask['id'],'reassign',(int)$resumed['version'],'reassign',['reason'=>'Approved coverage','target_user_id'=>id(10002)],'test');
$assigned=sql('SELECT * FROM task_instances WHERE id=?',[$infoTask['id']])->fetch();
expect($assigned['claimed_by']===id(10002),'reassignment selects an eligible current staff member');
$management->action(id(106),$infoTask['id'],'manage-release',(int)$assigned['version'],'manage-release',['reason'=>'Return to eligible pool'],'test');
$released=sql('SELECT * FROM task_instances WHERE id=?',[$infoTask['id']])->fetch();
$management->action(id(106),$infoTask['id'],'recompute',(int)$released['version'],'recompute',['reason'=>'Refresh active posting offers'],'test');
expect(count($management->candidates(id(106),$infoTask['id'],[])['data'])===10,'candidate listing requests at most ten rows');
$lifecycle=new App\Tickets\Lifecycle($db);
$current=(int)sql('SELECT version FROM tickets WHERE id=?',[$infoTicket['id']])->fetchColumn();
$withdrawn=$lifecycle->action(id(106),$infoTicket['id'],'withdraw',$current,'withdraw',['reason'=>'Approved withdrawal'],'test')['data'];
expect($withdrawn['status']==='withdrawn','withdrawal cancels active work');
expect((int)sql("SELECT count(*) FROM workflow_executions WHERE ticket_id=? AND status IN ('active','waiting')",[$infoTicket['id']])->fetchColumn()===0,'withdrawn work has no live executions');
$reopened=$lifecycle->action(id(106),$infoTicket['id'],'reopen',(int)$withdrawn['version'],'reopen',['reason'=>'Approved new run'],'test')['data'];
expect((int)$reopened['run_number']===2&&$reopened['workflow_version_id']===$infoTicket['workflow_version_id'],'reopen retains immutable workflow and starts a separate run');
expect((int)sql('SELECT count(DISTINCT run_number) FROM workflow_executions WHERE ticket_id=?',[$infoTicket['id']])->fetchColumn()===2,'historical workflow executions remain intact');
$audit=sql("SELECT * FROM organization_events WHERE resource='provinces' AND record_id=? ORDER BY id",[$createdProvince['id']])->fetchAll();
expect(count($audit)===2&&$audit[1]['actor_user_id']===id(106)&&json_decode($audit[1]['before_data'],true)['name']==='Administrator Province','organization audit retains actor and before/after values');
$rejection=$definition;$rejection['nodes'][1]['type']='approval';
$rejectionDraft=$workflows->save(id(106),null,0,'reject-draft',['category_id'=>id(16000),'definition'=>$rejection])['data'];
$workflows->publish(id(101),$rejectionDraft['id'],1,'reject-publish','Rejection test flow');
$rejectTicket=$submission->submit(id(15000),$workflowBody,'reject-ticket','test')['ticket'];
$rejectTask=sql('SELECT * FROM task_instances WHERE ticket_id=?',[$rejectTicket['id']])->fetch();
$tasks=new App\Workflow\TaskService($db);$claimed=$tasks->action(id(10001),$rejectTask['id'],'claim',1,'reject-claim',[],'test')['data'];
$tasks->action(id(10001),$rejectTask['id'],'reject',$claimed['version'],'reject-action',['reason'=>'Approval denied'],'test');
expect(sql('SELECT resolution FROM tickets WHERE id=?',[$rejectTicket['id']])->fetchColumn()==='rejected','rejection terminates as rejected rather than an approval');
expect((int)sql("SELECT count(*) FROM workflow_executions WHERE ticket_id=? AND node_id='end'",[$rejectTicket['id']])->fetchColumn()===0,'rejected approval cannot activate success path');

// Files never have a public path; ACL and integrity are checked again on download.
$storage=sys_get_temp_dir().'/ticketing-files-'.bin2hex(random_bytes(8));mkdir($storage,0700);
$previousFileEnv=[];foreach(['PRIVATE_STORAGE_PATH','MALWARE_SCANNER_MODE','ALLOW_DEMO_INTEGRATIONS','APP_ENV']as$name){$previousFileEnv[$name]=getenv($name);}
putenv('PRIVATE_STORAGE_PATH='.$storage);putenv('MALWARE_SCANNER_MODE=demo');putenv('ALLOW_DEMO_INTEGRATIONS=true');putenv('APP_ENV=test');
try{
    $fixtureFiles=[];
    foreach(['safe'=>'Internal attachment fixture','blocked'=>'EICAR-STANDARD-ANTIVIRUS-TEST-FILE','restricted'=>'Restricted fixture']as$name=>$bytes){
        $storageKey=bin2hex(random_bytes(24));file_put_contents($storage.'/'.$storageKey,$bytes);
        $fixtureFiles[$name]=sql('INSERT INTO ticket_attachments(ticket_id,uploaded_by,file_name,storage_key,content_type,byte_size,classification,sha256) VALUES (?,?,?,?,?,?,?,?) RETURNING id',[$rejectTicket['id'],id(15000),$name.'.txt',$storageKey,'text/plain',strlen($bytes),$name==='restricted'?'restricted':'internal',hash('sha256',$bytes)])->fetchColumn();
    }
    $files=new App\Tickets\Attachments($db);
    expect(count($files->listing(id(15000),$rejectTicket['id'],[])['data'])===2,'attachment list hides restricted metadata from requester');
    try{$files->download(id(15000),$fixtureFiles['safe']);expect(false,'quarantined download must fail');}catch(ApiException$error){expect($error->status===409,'quarantined files cannot be downloaded');}
    try{$files->download(id(15000),$fixtureFiles['restricted']);expect(false,'restricted download must fail');}catch(ApiException$error){expect($error->status===403,'restricted attachment requires separate sensitive grant');}
    expect($files->scanPending()===3&&$files->scanPending()===0,'scanner consumes each quarantined file once');
    expect($files->download(id(15000),$fixtureFiles['safe'])->body==='Internal attachment fixture','clean private file downloads after ACL and integrity checks');
    try{$files->download(id(15000),$fixtureFiles['blocked']);expect(false,'rejected download must fail');}catch(ApiException$error){expect($error->status===409,'demo test marker is rejected');}
    putenv('APP_ENV=production');
    try{$files->download(id(15000),$fixtureFiles['safe']);expect(false,'demo provider must not work in production');}catch(ApiException$error){expect($error->status===409,'production never trusts the local dummy scanner');}
    try{(new App\Security\MalwareScanner())->scan($storage.'/'.sql('SELECT storage_key FROM ticket_attachments WHERE id=?',[$fixtureFiles['safe']])->fetchColumn());expect(false,'production dummy scanner must reject');}catch(RuntimeException){expect(true,'demo scanner is restricted to explicitly enabled local environments');}
    putenv('APP_ENV=test');$safePath=$storage.'/'.sql('SELECT storage_key FROM ticket_attachments WHERE id=?',[$fixtureFiles['safe']])->fetchColumn();file_put_contents($safePath,'tampered');
    try{$files->download(id(15000),$fixtureFiles['safe']);expect(false,'tampered file must fail');}catch(ApiException$error){expect($error->status===409,'SHA-256 mismatch prevents download');}
}finally{
    foreach(glob($storage.'/*')as$file){unlink($file);}rmdir($storage);
    foreach($previousFileEnv as$name=>$value){putenv($value===false?$name:$name.'='.$value);}
}
