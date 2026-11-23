<?php
declare(strict_types=1);

use App\Tickets\Participants;

foreach([17001,17002]as$number){
    sql('INSERT INTO app_users(id,external_subject,display_name) VALUES (?,?,?)',[id($number),'cc:'.$number,'Summary Observer '.$number]);
    sql("INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE code='ticket_observer'",[id($number)]);
}
sql("INSERT INTO staff_assignments(id,user_id,position_id,department_id,station_id,begins_at) VALUES (?,?,?,?,?,'2020-01-01')",[id(17100),id(17001),id(31),id(22),id(11)]);
foreach(['ticket.share','ticket.privacy','ticket.read_sensitive']as$permission){sql("INSERT INTO scope_grants(user_id,permission_code,scope_kind,scope_ref_id,grant_reason) VALUES (?,?,'station',?,'Approved sharing and privacy fixture')",[id(106),$permission,id(11)]);}
sql("INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE code='sensitive_reader'",[id(106)]);
$ccDefinition=$definition;$ccDefinition['default_cc_department_ids']=[id(22)];
$ccDraft=$workflows->save(id(106),null,0,'cc-draft',['category_id'=>id(16000),'definition'=>$ccDefinition])['data'];
$workflows->publish(id(101),$ccDraft['id'],1,'cc-publish','Approved summary-only department CC');
$sharedTicket=$submission->submit(id(15000),$workflowBody,'cc-ticket','test')['ticket'];$participants=new Participants($db);
$summary=$participants->summary(id(17001),$sharedTicket['id']);
expect($summary['id']===$sharedTicket['id']&&!isset($summary['subject'],$summary['description'],$summary['requester_id'],$summary['custom_fields']),'department CC sees only the allowlisted summary fields');
expect(count($participants->shared(id(17001),[])['data'])===1,'shared summaries are separately paged and scoped');
try{(new App\Tickets\TicketReader($db))->detail(id(17001),$sharedTicket['id']);expect(false,'CC must not grant full read');}catch(App\Http\ApiException$error){expect($error->status===404,'CC does not reveal full ticket detail');}
$sharedTask=sql('SELECT id FROM task_instances WHERE ticket_id=?',[$sharedTicket['id']])->fetchColumn();
try{(new App\Workflow\TaskService($db))->action(id(17001),$sharedTask,'claim',1,'cc-claim',[],'test');expect(false,'CC must not grant claims');}catch(App\Http\ApiException$error){expect(in_array($error->status,[403,404],true),'CC is not a task action grant');}
try{(new App\Tickets\Reporting($db))->export(id(17001),[]);expect(false,'CC must not grant exports');}catch(App\Http\ApiException$error){expect($error->status===403,'summary-only sharing cannot export full ticket reports');}
for($i=0;$i<100;$i++){if($worker->outbox()===0){break;}}
expect(count((new App\Tickets\Reporting($db))->notifications(id(17001),[])['data'])>0,'default CC receives minimal in-app notifications after current ACL checks');
$current=(int)sql('SELECT version FROM tickets WHERE id=?',[$sharedTicket['id']])->fetchColumn();
$body=['targets'=>[['id'=>id(17002),'kind'=>'user','participation'=>'watcher','active'=>true]],'reason'=>'Explicit watcher approval'];
try{$participants->change(id(15000),$sharedTicket['id'],$current,'forbidden-share',$body,'test');expect(false,'requester must not expand audience');}catch(App\Http\ApiException$error){expect($error->status===403,'sharing needs a separate action scope');}
$shared=$participants->change(id(106),$sharedTicket['id'],$current,'watcher-share',$body,'test');
$replay=$participants->change(id(106),$sharedTicket['id'],$current,'watcher-share',$body,'test');
expect($replay['meta']['replayed']&&count($participants->shared(id(17002),[])['data'])===1,'watcher sharing is idempotent and visible only in the summary queue');
$body['targets'][0]['active']=false;$body['reason']='Watcher approval ended';
$revoked=$participants->change(id(106),$sharedTicket['id'],$shared['data']['version'],'watcher-revoke',$body,'test');
expect(count($participants->shared(id(17002),[])['data'])===0,'revoked watcher immediately loses summary visibility');
expect((int)sql("SELECT count(*) FROM ticket_participants WHERE ticket_id=? AND user_id=? AND NOT active AND revoked_at IS NOT NULL",[$sharedTicket['id'],id(17002)])->fetchColumn()===1,'revocation retains the participant record');
sql("UPDATE staff_assignments SET ends_at=now()-interval '1 second',version=version+1 WHERE id=?",[id(17100)]);
expect(count($participants->shared(id(17001),[])['data'])===0&&count((new App\Tickets\Reporting($db))->notifications(id(17001),[])['data'])===0,'posting expiry removes department summaries and prior notifications');
sql('UPDATE staff_assignments SET ends_at=null,version=version+1 WHERE id=?',[id(17100)]);
$privacy=$participants->privacy(id(106),$sharedTicket['id'],$revoked['data']['version'],'privacy-restrict',['classification'=>'restricted','reason'=>'Sensitive case classification'],'test');
expect(count($participants->shared(id(17001),[])['data'])===0,'restricted tickets never appear in CC-only summaries');
try{(new App\Tickets\TicketReader($db))->detail(id(15000),$sharedTicket['id']);expect(false,'requester does not imply sensitive clearance');}catch(App\Http\ApiException$error){expect($error->status===404,'privacy changes apply to the requester on subsequent reads');}
expect((new App\Tickets\TicketReader($db))->detail(id(106),$sharedTicket['id'])['confidentiality']==='restricted','cleared scoped manager retains restricted access');
$participants->privacy(id(106),$sharedTicket['id'],$privacy['data']['version'],'privacy-internal',['classification'=>'internal','reason'=>'Approved classification correction'],'test');
expect(count($participants->shared(id(17001),[])['data'])===1,'approved internal classification restores active summary memberships');

// Management queues require both management scope and independent full read authority.
$queue=new App\Workflow\TaskService($db);
try{$queue->listing(id(10001),['view'=>'authorized']);expect(false,'agent cannot open manager queue');}catch(App\Http\ApiException$error){expect($error->status===403,'authorized work queue requires explicit manager authority');}
$managed=$queue->listing(id(106),['view'=>'authorized','q'=>$workflowBody['subject']]);expect(count($managed['data'])>0&&count($managed['data'])<=10,'management queues are scoped, searched and initially bounded to ten');
$foreignTask=sql("INSERT INTO task_instances(ticket_id,workflow_version_id,node_id,assigned_department_id,assigned_position_id,due_at) VALUES (?,?,?,?,?,now()-interval '1 hour') RETURNING id",[id(302),id(61),'foreign_queue',id(21),id(31)])->fetchColumn();
expect(count($queue->listing(id(106),['view'=>'authorized','q'=>'Ticket 2'])['data'])===0,'management queues cannot bypass the separate ticket read scope');
sql("UPDATE task_instances SET due_at=now()-interval '1 hour' WHERE id=?",[$sharedTask]);
$overdue=$queue->listing(id(106),['view'=>'overdue','q'=>$workflowBody['subject']]);expect(in_array($sharedTask,array_column($overdue['data'],'id'),true),'SLA monitor includes only currently overdue visible managed work');

// An independent publisher can review/simulate without receiving design or data authority.
sql("INSERT INTO app_users(id,external_subject,display_name) VALUES (?,'publisher:independent','Independent Publisher')",[id(18000)]);
sql("INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE code='operations_admin'",[id(18000)]);
sql("INSERT INTO scope_grants(user_id,permission_code,scope_kind,scope_ref_id,grant_reason) VALUES (?,'workflow.publish','department',?,'Independent publisher only')",[id(18000),id(21)]);
$reviewDefinition=$definition;$reviewDefinition['nodes'][1]['label']='Reviewed ICT triage';
$reviewDraft=$workflows->save(id(106),null,0,'review-draft',['category_id'=>id(16000),'definition'=>$reviewDefinition])['data'];
$publisherRows=$workflows->listing(id(18000),[])['data'];expect(count($publisherRows)>0&&!in_array(true,array_column($publisherRows,'can_design'),true),'publisher-only scope can inspect department drafts without design capability');
expect($workflows->simulate(id(18000),$reviewDraft['id'],[])['valid'],'independent publisher can simulate the stored draft without editing');
$diff=$workflows->review(id(18000),$reviewDraft['id']);expect(count($diff['node_changes'])>0&&$diff['base_version']!==null,'review compares draft stages and policies with active publication');
try{$workflows->save(id(18000),$reviewDraft['id'],1,'publisher-edit',['category_id'=>id(16000),'definition'=>$reviewDefinition]);expect(false,'publisher must not edit');}catch(App\Http\ApiException$error){expect($error->status===403,'publication authority does not confer workflow design');}
$workflows->publish(id(18000),$reviewDraft['id'],1,'review-publish','Independent publisher approval');
expect(sql('SELECT approved_by FROM workflow_drafts WHERE id=?',[$reviewDraft['id']])->fetchColumn()===id(18000),'publisher-only authority can approve a different author draft');
