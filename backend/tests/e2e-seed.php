<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
$db=App\Config\Database::connection();
if($db->query('SELECT current_database()')->fetchColumn()!=='ticketing_test'||getenv('APP_ENV')!=='test'){fwrite(STDERR,"Seed requires an isolated test database.\n");exit(1);}
(new App\Config\Migrator($db,dirname(__DIR__).'/database'))->run();
if((int)$db->query('SELECT count(*) FROM app_users')->fetchColumn()!==0){fwrite(STDERR,"Seed refuses a populated database.\n");exit(1);}
$password=file_get_contents(getenv('E2E_PASSWORD_FILE')?:'');App\Security\PasswordAuth::validatePassword($password);
$hash=password_hash($password,PASSWORD_BCRYPT,['cost'=>12]);
function uid(int$i):string{return'00000000-0000-4000-8000-'.str_pad(dechex($i),12,'0',STR_PAD_LEFT);}
function seedSql(string$sql,array$args=[]):PDOStatement{global$db;return App\Support\Sql::run($db,$sql,$args);}
seedSql("INSERT INTO provinces(id,code,name) VALUES (?,'QA','QA Province')",[uid(1)]);
seedSql("INSERT INTO stations(id,code,name,province_id) VALUES (?,'QA','QA Station',?)",[uid(2),uid(1)]);
seedSql("INSERT INTO departments(id,code,name) VALUES (?,'QA_ICT','QA ICT')",[uid(3)]);
seedSql("INSERT INTO positions(id,code,title) VALUES (?,'QA_OFFICER','QA Officer')",[uid(4)]);
foreach(['admin.qa'=>10,'requester.qa'=>11,'agent.qa'=>12,'manager.qa'=>13,'observer.qa'=>14]as$name=>$number){
    seedSql('INSERT INTO app_users(id,external_subject,display_name) VALUES (?,?,?)',[uid($number),'qa:'.$name,ucfirst(str_replace('.',' ',$name))]);
    seedSql('INSERT INTO user_credentials(user_id,username,password_hash,must_change) VALUES (?,?,?,false)',[uid($number),$name,$hash]);
    seedSql("INSERT INTO staff_assignments(id,user_id,position_id,department_id,station_id,begins_at) VALUES (?,?,?,?,?,'2020-01-01')",[uid($number+100),uid($number),uid(4),uid(3),uid(2)]);
}
$provisioner=new App\Security\Provisioner($db);$provisioner->grantOperations(uid(10),'QA configuration authority');$provisioner->grantSubmit(uid(11),'QA requester authority');
seedSql("INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE code='task_agent'",[uid(12)]);
seedSql("INSERT INTO scope_grants(user_id,permission_code,scope_kind,scope_ref_id,grant_reason) VALUES (?,'task.claim','station',?,'QA agent authority')",[uid(12),uid(2)]);
$provisioner->grantRead(uid(13),'station',uid(2),'QA scoped manager');
seedSql("INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE code='operations_admin'",[uid(13)]);
seedSql("INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE code='sensitive_reader'",[uid(13)]);
foreach(['task.manage','ticket.manage','ticket.share','ticket.privacy','ticket.read_sensitive','report.export','report.analytics']as$permission){seedSql("INSERT INTO scope_grants(user_id,permission_code,scope_kind,scope_ref_id,grant_reason) VALUES (?,?,'station',?,'QA scoped manager')",[uid(13),$permission,uid(2)]);}
seedSql("INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE code='ticket_observer'",[uid(14)]);
$schema=['type'=>'object','additionalProperties'=>false,'required'=>['asset_tag'],'properties'=>['asset_tag'=>['type'=>'string','title'=>'Asset tag','minLength'=>2,'maxLength'=>30]]];
(new App\Tickets\CatalogConfigurator($db))->configure(['category_id'=>uid(20),'department_id'=>uid(3),'code'=>'EQUIPMENT','name'=>'QA Equipment','form_schema'=>$schema],'QA initial category');
$definition=['engine'=>'workflow-v1','department_id'=>uid(3),'category_code'=>'EQUIPMENT','form_schema'=>$schema,'require_second_approver'=>false,
    'nodes'=>[['id'=>'start','type'=>'start','label'=>'Submitted'],['id'=>'work','type'=>'claim_pool','label'=>'ICT work','config'=>['department_id'=>uid(3),'position_id'=>uid(4),'scope'=>'station','sla_minutes'=>60]],['id'=>'end','type'=>'end','label'=>'Closed']],
    'edges'=>[['from'=>'start','to'=>'work'],['from'=>'work','to'=>'end']]];
$service=new App\Workflow\WorkflowService($db);$draft=$service->save(uid(10),null,0,'qa-draft',['category_id'=>uid(20),'definition'=>$definition])['data'];$service->publish(uid(10),$draft['id'],1,'qa-publish','QA approved isolated fixture');
echo "Isolated UI fixtures created.\n";

$itPositions=['it_officer'=>uid(4)];$itNames=['principal_it'=>'qa.principal','principal_help_desk'=>'qa.helpdesk','head_it'=>'qa.ithead','deputy_it'=>'qa.itdeputy','systems_engineer'=>'qa.systems_engineer','systems_analyst'=>'qa.systems_analyst','hardware_specialist'=>'qa.hardware','database_specialist'=>'qa.database'];
$itNumber=300;
foreach($itNames as$key=>$username){$itNumber++;$itPositions[$key]=uid($itNumber);seedSql('INSERT INTO positions(id,code,title) VALUES (?,?,?)',[uid($itNumber),'QA_'.strtoupper($key),ucwords(str_replace('_',' ',$key))]);$user=uid($itNumber+100);seedSql('INSERT INTO app_users(id,external_subject,display_name) VALUES (?,?,?)',[$user,'qa:'.$username,$username]);seedSql('INSERT INTO user_credentials(user_id,username,password_hash,must_change) VALUES (?,?,?,false)',[$user,$username,$hash]);seedSql('INSERT INTO staff_assignments(user_id,position_id,department_id,station_id,begins_at) VALUES (?,?,?,?,now())',[$user,$itPositions[$key],uid(3),uid(2)]);foreach(['operations_admin','ticket_reader']as$role)seedSql('INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE code=?',[$user,$role]);foreach(['task.claim','task.intervene','ticket.read','ticket.comment','ticket.comment_closed','report.analytics']as$permission)seedSql("INSERT INTO scope_grants(user_id,permission_code,scope_kind,scope_ref_id,grant_reason) VALUES (?,?,'department',?,'QA IT routing authority')",[$user,$permission,uid(3)]);}
seedSql("INSERT INTO categories(id,department_id,code,name) VALUES (?,?,'QA_IT','QA Computer faults')",[uid(500),uid(3)]);
$itDefinition=App\Workflow\ItWorkflow::definition(uid(3),$itPositions);$itDefinition['require_second_approver']=false;
$itDraft=$service->save(uid(10),null,0,'qa-it-draft',['category_id'=>uid(500),'definition'=>$itDefinition])['data'];$service->publish(uid(10),$itDraft['id'],1,'qa-it-publish','QA approved IT escalation fixture');
