<?php
declare(strict_types=1);

use App\Support\Sql;
use App\Security\PasswordAuth;

require dirname(__DIR__).'/bootstrap/autoload.php';
if(!in_array(getenv('APP_ENV'),['development','offline','test'],true)||getenv('ALLOW_DEMO_INTEGRATIONS')!=='true'){
    fwrite(STDERR,"Demo setup requires an explicitly enabled local environment.\n");exit(1);
}
try{
    $passwords=json_decode((string)file_get_contents(getenv('DEMO_PASSWORDS_FILE')?:''),true,8,JSON_THROW_ON_ERROR);
    $accounts=['requester.demo'=>'Demo Requester','agent.demo'=>'Demo ICT Officer','manager.demo'=>'Demo Station Manager','designer.demo'=>'Demo Workflow Designer','publisher.demo'=>'Demo Workflow Publisher'];
    foreach($accounts as$username=>$label){PasswordAuth::validatePassword($passwords[$username]??'');}
    $db=App\Config\Database::connection();$db->beginTransaction();
    $db->query('SELECT pg_advisory_xact_lock(741903221)');
    if(Sql::run($db,"SELECT 1 FROM app_users WHERE external_subject='local-demo:requester.demo'")->fetchColumn()){
        $manager=Sql::run($db,"SELECT u.id,a.station_id FROM app_users u JOIN staff_assignments a ON a.user_id=u.id JOIN stations st ON st.id=a.station_id WHERE u.external_subject='local-demo:manager.demo' AND st.code='LOCAL_DEMO' AND u.active AND a.begins_at<=now() AND (a.ends_at IS NULL OR a.ends_at>now()) ORDER BY a.id LIMIT 1")->fetch();
        if($manager){
            Sql::run($db,"SELECT set_config('app.actor_system','local-demo-upgrade',true)");
            Sql::run($db,"INSERT INTO user_roles(user_id,role_id) SELECT ?,r.id FROM roles r WHERE r.code='sensitive_reader' AND NOT EXISTS(SELECT 1 FROM user_roles ur WHERE ur.user_id=CAST(? AS uuid) AND ur.role_id=r.id AND ur.valid_from<=now() AND (ur.valid_to IS NULL OR ur.valid_to>now()))",[$manager['id'],$manager['id']]);
            foreach(['ticket.share','ticket.privacy','ticket.read_sensitive']as$permission){Sql::run($db,"INSERT INTO scope_grants(user_id,permission_code,scope_kind,scope_ref_id,grant_reason) SELECT CAST(? AS uuid),?,'station',CAST(? AS uuid),'Fictional local demo scope upgrade' WHERE NOT EXISTS(SELECT 1 FROM scope_grants WHERE user_id=CAST(? AS uuid) AND permission_code=? AND scope_kind='station' AND scope_ref_id=CAST(? AS uuid) AND revoked_at IS NULL AND valid_from<=now() AND (valid_to IS NULL OR valid_to>now()))",[$manager['id'],$permission,$manager['station_id'],$manager['id'],$permission,$manager['station_id']]);}
        }
        $db->commit();echo "Local demonstration already exists; compatible demo scopes reconciled, no records removed or passwords changed.\n";exit(0);
    }
    $db->query("SELECT set_config('app.actor_system','local-demo-setup',true)");
    $province=Sql::run($db,"INSERT INTO provinces(code,name) VALUES ('LOCAL_DEMO','Demo Province') RETURNING id")->fetchColumn();
    $station=Sql::run($db,"INSERT INTO stations(code,name,province_id) VALUES ('LOCAL_DEMO','Demo Station',?) RETURNING id",[$province])->fetchColumn();
    $department=Sql::run($db,"INSERT INTO departments(code,name) VALUES ('LOCAL_DEMO_ICT','Demo ICT') RETURNING id")->fetchColumn();
    $position=Sql::run($db,"INSERT INTO positions(code,title) VALUES ('LOCAL_DEMO_OFFICER','Demo Officer') RETURNING id")->fetchColumn();
    $users=[];
    foreach($accounts as$username=>$label){
        $user=Sql::run($db,'INSERT INTO app_users(external_subject,display_name) VALUES (?,?) RETURNING id',['local-demo:'.$username,$label])->fetchColumn();$users[$username]=$user;
        Sql::run($db,'INSERT INTO user_credentials(user_id,username,password_hash,must_change) VALUES (?,?,?,true)',[$user,$username,password_hash($passwords[$username],PASSWORD_BCRYPT,['cost'=>12])]);
        Sql::run($db,"INSERT INTO staff_assignments(user_id,position_id,department_id,station_id,begins_at) VALUES (?,?,?,?,now()-interval '1 day')",[$user,$position,$department,$station]);
        $roles=match($username){'requester.demo'=>['ticket_requester'],'agent.demo'=>['task_agent'],'manager.demo'=>['operations_admin','ticket_reader','sensitive_reader'],default=>['operations_admin','ticket_reader']};
        foreach($roles as$role){Sql::run($db,'INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE code=?',[$user,$role]);}
        $permissions=match($username){
            'requester.demo'=>['ticket.create'=>'self','ticket.read'=>'self'],
            'agent.demo'=>['task.claim'=>'station'],
            'manager.demo'=>['ticket.share'=>'station','ticket.privacy'=>'station','ticket.read_sensitive'=>'station','ticket.read'=>'station','task.manage'=>'station','ticket.manage'=>'station','ticket.comment'=>'station','report.export'=>'station'],
            'designer.demo'=>['workflow.design'=>'department'],
            'publisher.demo'=>['workflow.publish'=>'department','workflow.design'=>'department'],
        };
        foreach($permissions as$permission=>$scope){Sql::run($db,'INSERT INTO scope_grants(user_id,permission_code,scope_kind,scope_ref_id,grant_reason) VALUES (?,?,?,?,?)',[$user,$permission,$scope,match($scope){'station'=>$station,'department'=>$department,default=>null},'Fictional local demonstration only']);}
    }
    $schema=['type'=>'object','additionalProperties'=>false,'required'=>['asset_tag'],'properties'=>['asset_tag'=>['type'=>'string','title'=>'Asset tag','minLength'=>2,'maxLength'=>40]]];
    $category=Sql::run($db,"INSERT INTO categories(department_id,code,name,form_schema) VALUES (?,'LOCAL_DEMO_EQUIPMENT','Demo equipment support',CAST(? AS jsonb)) RETURNING id",[$department,json_encode($schema,JSON_THROW_ON_ERROR)])->fetchColumn();
    $definition=['engine'=>'workflow-v1','department_id'=>$department,'category_name'=>'Demo equipment support','form_schema'=>$schema,'require_second_approver'=>true,
        'nodes'=>[['id'=>'start','type'=>'start','label'=>'Submitted'],['id'=>'work','type'=>'claim_pool','label'=>'ICT support','config'=>['department_id'=>$department,'position_id'=>$position,'scope'=>'station','sla_minutes'=>240]],['id'=>'end','type'=>'end','label'=>'Completed']],
        'edges'=>[['from'=>'start','to'=>'work'],['from'=>'work','to'=>'end']]];
    new App\Workflow\Graph($definition);
    $template=Sql::run($db,"INSERT INTO workflow_templates(code,name,owner_department_id,category_id) VALUES ('LOCAL_DEMO','Demo equipment support',?,?) RETURNING id",[$department,$category])->fetchColumn();
    $version=Sql::run($db,"INSERT INTO workflow_versions(template_id,version,status,definition,created_by,published_by,published_at) VALUES (?,1,'published',CAST(? AS jsonb),?,?,now()) RETURNING id",[$template,json_encode($definition,JSON_THROW_ON_ERROR),$users['designer.demo'],$users['publisher.demo']])->fetchColumn();
    Sql::run($db,'UPDATE workflow_templates SET active_version_id=? WHERE id=?',[$version,$template]);
    Sql::run($db,"INSERT INTO security_events(event_type,actor_system,reason) VALUES ('LOCAL_DEMO_CREATED','local-demo-setup','Fictional organization, scoped staff and independent workflow publication; all passwords must change')");
    $db->commit();echo "Created fictional local organization, five scoped demo accounts and equipment workflow. Password change required.\n";
}catch(Throwable$error){if(isset($db)&&$db->inTransaction()){$db->rollBack();}fwrite(STDERR,'Demo setup failed ('.$error::class.').'.PHP_EOL);exit(1);}
