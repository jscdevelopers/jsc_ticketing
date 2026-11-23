<?php
declare(strict_types=1);
namespace App\Support;
use PDO;
use App\Security\PasswordAuth;
use App\Tickets\TicketSubmission;
use App\Tickets\Collaboration;
use App\Workflow\TaskService;

final class JscSamples
{
    public const ALIASES=['requester.demo'=>'registry.harare','agent.demo'=>'ict.harare','manager.demo'=>'station.manager','designer.demo'=>'workflow.designer','publisher.demo'=>'workflow.publisher'];
    public function __construct(private PDO$db){}
    public static function dataset():array{return json_decode(file_get_contents(dirname(__DIR__,2).'/resources/jsc-structure.json'),true,32,JSON_THROW_ON_ERROR);}
    public static function usernames():array
    {
        $names=[...array_values(self::ALIASES),'national.oversight','configuration.admin'];
        foreach(self::dataset()['stations']as[$code]){$names[]='registry.'.strtolower($code);}
        foreach(self::dataset()['categories']as[$code,,$dept]){$names[]='officer.'.strtolower($dept);}
        return array_values(array_unique($names));
    }
    private function run(string$sql,array$args=[]):\PDOStatement{return Sql::run($this->db,$sql,$args);}
    public function install(array$passwords):array
    {
        if(!in_array(getenv('APP_ENV'),['development','offline','test'],true)||getenv('ALLOW_DEMO_INTEGRATIONS')!=='true'){throw new \InvalidArgumentException('Sample installation is restricted to explicitly enabled local environments.');}
        $data=self::dataset();foreach(self::usernames()as$name){PasswordAuth::validatePassword($passwords[$name]??'');}
        $this->db->beginTransaction();try{
            $this->db->query('SELECT pg_advisory_xact_lock(741903222)');
            $existing=$this->run("SELECT summary FROM sample_installations WHERE code='jsc-zimbabwe-v1'")->fetchColumn();
            if($existing){$this->db->commit();$summary=json_decode($existing,true);if(!($summary['tickets_seeded']??false))$this->seedTickets($data);return['already_installed'=>true,...$summary];}
            $this->db->query("SELECT set_config('app.actor_system','jsc-sample-install',true)");
            $provinces=[];$levels=[];$stations=[];$departments=[];$positions=[];$users=[];$assignments=[];
            foreach($data['provinces']as$name){$code=strtoupper(str_replace(' ','_',$name));
                if($name==='Harare'){$this->run("UPDATE provinces SET code='HARARE',name='Harare',version=version+1 WHERE code='LOCAL_DEMO' AND NOT EXISTS(SELECT 1 FROM provinces WHERE code='HARARE')");$this->run("UPDATE provinces SET name='Harare (legacy)',active=false WHERE code='LOCAL_DEMO'");}
                $provinces[$name]=$this->run('INSERT INTO provinces(code,name) VALUES (?,?) ON CONFLICT(code) DO UPDATE SET name=EXCLUDED.name RETURNING id',[$code,$name])->fetchColumn();}
            foreach($data['court_levels']as$name){$code=strtoupper(str_replace(' ','_',$name));$levels[$name]=$this->run('INSERT INTO court_levels(code,name) VALUES (?,?) ON CONFLICT(code) DO UPDATE SET name=EXCLUDED.name RETURNING id',[$code,$name])->fetchColumn();}
            foreach($data['stations']as[$code,$name,$province,$level]){
                if($code==='MAG_HARARE'){$this->run("UPDATE stations SET code=?,name=?,province_id=?,court_level_id=?,version=version+1 WHERE code='LOCAL_DEMO' AND NOT EXISTS(SELECT 1 FROM stations WHERE code='MAG_HARARE')",[$code,$name,$provinces[$province],$levels[$level]]);$this->run("UPDATE stations SET name='Harare Magistrates Court (legacy)',active=false WHERE code='LOCAL_DEMO'");}
                $stations[$code]=$this->run('INSERT INTO stations(code,name,province_id,court_level_id,is_secretariat_station) VALUES (?,?,?,?,CAST(? AS boolean)) ON CONFLICT(code) DO UPDATE SET name=EXCLUDED.name,province_id=EXCLUDED.province_id,court_level_id=EXCLUDED.court_level_id,is_secretariat_station=EXCLUDED.is_secretariat_station RETURNING id',[$code,$name,$provinces[$province],$levels[$level],$level==='Secretariat'?'true':'false'])->fetchColumn();}
            foreach($data['departments']as[$code,$name,$parent]){
                if($code==='ICT'){$this->run("UPDATE departments SET code='ICT',name=?,version=version+1 WHERE code='LOCAL_DEMO_ICT' AND NOT EXISTS(SELECT 1 FROM departments WHERE code='ICT')",[$name]);$this->run("UPDATE departments SET name='ICT (legacy)',active=false WHERE code='LOCAL_DEMO_ICT'");}
                $departments[$code]=$this->run('INSERT INTO departments(code,name,parent_id) VALUES (?,?,?) ON CONFLICT(code) DO UPDATE SET name=EXCLUDED.name,parent_id=EXCLUDED.parent_id RETURNING id',[$code,$name,$parent?$departments[$parent]:null])->fetchColumn();}
            foreach($data['positions']as[$code,$name,$parent]){
                if($code==='ICT_OFFICER'){$this->run("UPDATE positions SET code='ICT_OFFICER',title=?,version=version+1 WHERE code='LOCAL_DEMO_OFFICER' AND NOT EXISTS(SELECT 1 FROM positions WHERE code='ICT_OFFICER')",[$name]);$this->run("UPDATE positions SET title='ICT Officer (legacy)',active=false WHERE code='LOCAL_DEMO_OFFICER'");}
                $positions[$code]=$this->run('INSERT INTO positions(code,title,reports_to_position_id) VALUES (?,?,?) ON CONFLICT(code) DO UPDATE SET title=EXCLUDED.title,reports_to_position_id=EXCLUDED.reports_to_position_id RETURNING id',[$code,$name,$parent?$positions[$parent]:null])->fetchColumn();}
            $account=function(string$name,string$label,string$station,string$dept,string$position,array$roles,array$grants)use($passwords,$stations,$departments,$positions,&$users,&$assignments):string{
                $old=array_search($name,self::ALIASES,true);
                $user=$old?$this->run('SELECT user_id FROM user_credentials WHERE username=?',[$old])->fetchColumn():false;
                if($user){$this->run('UPDATE user_credentials SET username=? WHERE user_id=?',[$name,$user]);$this->run('UPDATE app_users SET display_name=?,version=version+1 WHERE id=?',[$label,$user]);}
                else{$user=$this->run('INSERT INTO app_users(external_subject,display_name) VALUES (?,?) RETURNING id',['local-jsc:'.$name,$label])->fetchColumn();$this->run('INSERT INTO user_credentials(user_id,username,password_hash,must_change) VALUES (?,?,?,true)',[$user,$name,password_hash($passwords[$name],PASSWORD_BCRYPT,['cost'=>12])]);}
                $users[$name]=$user;
                $this->run('UPDATE staff_assignments SET ends_at=now(),version=version+1 WHERE user_id=? AND (ends_at IS NULL OR ends_at>now()) AND begins_at<now()',[$user]);
                $assignments[$name]=$this->run("INSERT INTO staff_assignments(user_id,position_id,department_id,station_id,begins_at) VALUES (?,?,?,?,now()) RETURNING id",[$user,$positions[$position],$departments[$dept],$stations[$station]])->fetchColumn();
                foreach($roles as$role){$this->run('INSERT INTO user_roles(user_id,role_id) SELECT ?,r.id FROM roles r WHERE code=? AND NOT EXISTS(SELECT 1 FROM user_roles ur WHERE ur.user_id=CAST(? AS uuid) AND ur.role_id=r.id AND ur.valid_from<=now() AND (ur.valid_to IS NULL OR ur.valid_to>now()))',[$user,$role,$user]);}
                foreach($grants as$permission=>$scope){$ref=match($scope){'station'=>$stations[$station],'department'=>$departments[$dept],default=>null};$this->run('INSERT INTO scope_grants(user_id,permission_code,scope_kind,scope_ref_id,grant_reason) SELECT ?,?,?,?,? WHERE NOT EXISTS(SELECT 1 FROM scope_grants WHERE user_id=CAST(? AS uuid) AND permission_code=? AND scope_kind=? AND scope_ref_id IS NOT DISTINCT FROM CAST(? AS uuid) AND revoked_at IS NULL)',[$user,$permission,$scope,$ref,'Local sample operating authority',$user,$permission,$scope,$ref]);}
                return$user;
            };
            $account('registry.harare','Harare Registry Officer','MAG_HARARE','MAGISTRATES','CLERK',['ticket_requester'],['ticket.create'=>'self','ticket.read'=>'self']);
            $account('ict.harare','Harare ICT Officer','MAG_HARARE','ICT','ICT_OFFICER',['task_agent'],['task.claim'=>'department']);
            $account('station.manager','Harare Station Manager','MAG_HARARE','MAGISTRATES','RESIDENT_MAGISTRATE',['operations_admin','ticket_reader','sensitive_reader'],['ticket.read'=>'station','task.manage'=>'station','ticket.manage'=>'station','ticket.share'=>'station','ticket.privacy'=>'station','ticket.read_sensitive'=>'station','report.export'=>'station']);
            $account('workflow.designer','Workflow Design Officer','JSC_SECRETARIAT','ICT','ICT_OFFICER',['operations_admin'],['workflow.design'=>'national']);
            $account('workflow.publisher','Workflow Review Officer','JSC_SECRETARIAT','ICT','ICT_OFFICER',['operations_admin'],['workflow.publish'=>'national']);
            $account('national.oversight','National Service Oversight','JSC_SECRETARIAT','SECRETARIAT','DEPUTY_SECRETARY',['operations_admin','ticket_reader'],['ticket.read'=>'national','task.manage'=>'national','ticket.manage'=>'national','report.export'=>'national']);
            $account('configuration.admin','System Configuration Administrator','JSC_SECRETARIAT','ICT','ICT_OFFICER',['operations_admin'],['directory.manage'=>'national','security.manage'=>'national','workflow.design'=>'national','workflow.publish'=>'national']);
            foreach($data['stations']as[$code,$name,,$level]){$account('registry.'.strtolower($code),$name.' Registry Officer',$code,$level==='Magistrates Court'?'MAGISTRATES':'SUPERIOR_REGISTRY',$level==='Magistrates Court'?'CLERK':'REGISTRAR',['ticket_requester'],['ticket.create'=>'self','ticket.read'=>'self']);}
            foreach($data['categories']as[,,$dept,$position]){$account('officer.'.strtolower($dept),$dept==='ICT'?'ICT Service Officer':$data['positions'][array_search($position,array_column($data['positions'],0))][1],'JSC_SECRETARIAT',$dept,$position,['task_agent'],['task.claim'=>'department']);}
            $categories=[];
            foreach($data['categories']as[$code,$name,$dept,$position]){
                $schema=['type'=>'object','additionalProperties'=>false,'required'=>['service_reference'],'properties'=>['service_reference'=>['type'=>'string','title'=>'Asset or service reference','minLength'=>2,'maxLength'=>60]]];
                $category=$this->run('INSERT INTO categories(department_id,code,name,form_schema) VALUES (?,?,?,CAST(? AS jsonb)) RETURNING id',[$departments[$dept],$code,$name,json_encode($schema)])->fetchColumn();$categories[$code]=$category;
                $definition=['engine'=>'workflow-v1','department_id'=>$departments[$dept],'category_name'=>$name,'form_schema'=>$schema,'require_second_approver'=>true,'nodes'=>[['id'=>'start','type'=>'start','label'=>'Request submitted'],['id'=>'work','type'=>'claim_pool','label'=>$name,'config'=>['department_id'=>$departments[$dept],'position_id'=>$positions[$position],'scope'=>'department','sla_minutes'=>240]],['id'=>'end','type'=>'end','label'=>'Service completed']],'edges'=>[['from'=>'start','to'=>'work'],['from'=>'work','to'=>'end']]];
                $service=new \App\Workflow\Graph($definition);
                $template=$this->run('INSERT INTO workflow_templates(code,name,owner_department_id,category_id) VALUES (?,?,?,?) RETURNING id',['JSC_'.$code,$name,$departments[$dept],$category])->fetchColumn();
                $version=$this->run("INSERT INTO workflow_versions(template_id,version,status,definition,created_by,published_by,published_at) VALUES (?,1,'published',CAST(? AS jsonb),?,?,now()) RETURNING id",[$template,json_encode($definition),$users['workflow.designer'],$users['workflow.publisher']])->fetchColumn();$this->run('UPDATE workflow_templates SET active_version_id=? WHERE id=?',[$version,$template]);$this->run("INSERT INTO workflow_drafts(category_id,definition,created_by,approved_by,status) VALUES (?,CAST(? AS jsonb),?,?,'published')",[$category,json_encode($definition),$users['workflow.designer'],$users['workflow.publisher']]);
            }
            $this->run("UPDATE categories SET name='Equipment support (legacy)',active=false,version=version+1 WHERE code='LOCAL_DEMO_EQUIPMENT'");
            $summary=['provinces'=>count($provinces),'court_levels'=>count($levels),'stations'=>count($stations),'departments'=>count($departments),'positions'=>count($positions),'accounts'=>count($users),'categories'=>count($categories)];
            $this->run("INSERT INTO sample_installations(code,summary) VALUES ('jsc-zimbabwe-v1',CAST(? AS jsonb))",[json_encode($summary)]);
            $this->run("INSERT INTO security_events(event_type,actor_system,reason) VALUES ('JSC_SAMPLE_STRUCTURE_INSTALLED','jsc-sample-install','Source-backed court levels and geography; configurable support reporting lines; fictional staff; no real judicial case data')");
            $this->db->commit();
        }catch(\Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
        $this->seedTickets($data);return['already_installed'=>false,...$summary];
    }
    private function seedTickets(array$data):void
    {
        $users=[];$assignments=[];$categories=[];
        foreach(self::usernames()as$name){$users[$name]=$this->run('SELECT user_id FROM user_credentials WHERE username=?',[$name])->fetchColumn();$assignments[$name]=$this->run('SELECT id FROM staff_assignments WHERE user_id=? AND begins_at<=now() AND (ends_at IS NULL OR ends_at>now()) ORDER BY begins_at DESC LIMIT 1',[$users[$name]])->fetchColumn();}
        foreach($data['categories']as[$code]){$categories[$code]=$this->run('SELECT id FROM categories WHERE code=?',[$code])->fetchColumn();}
        foreach($data['stations']as$i=>[$station,$label]){foreach(array_map(fn($offset)=>$data['categories'][($i+$offset)%count($data['categories'])],range(0,$i%3))as$j=>[$code,$name,$dept]){
            $requester='registry.'.strtolower($station);$ticket=(new TicketSubmission($this->db))->submit($users[$requester],['assignment_id'=>$assignments[$requester],'category_id'=>$categories[$code],'subject'=>$name.' - '.$label,'description'=>'Sample operational request for local training. No real court case or personal information.','priority'=>['normal','high','low'][($i+$j)%3],'fields'=>['service_reference'=>'JSC-'.($i+1).'-'.($j+1)]],'jsc-sample-'.$i.'-'.$j,'jsc-samples')['ticket'];
            if(!$this->run('SELECT 1 FROM ticket_comments WHERE ticket_id=? AND actor_user_id=?',[$ticket['id'],$users[$requester]])->fetchColumn()){
                $version=(int)$this->run('SELECT version FROM tickets WHERE id=?',[$ticket['id']])->fetchColumn();
                (new Collaboration($this->db))->comment($users[$requester],$ticket['id'],$version,'jsc-note-'.$i.'-'.$j,['body'=>'Station registry has supplied the service reference and contact point.','sensitivity'=>'public_internal'],'jsc-samples');
            }
            if($i%3!==0){$task=$this->run('SELECT id,version,status FROM task_instances WHERE ticket_id=?',[$ticket['id']])->fetch();$actor=$users['officer.'.strtolower($dept)];$tasks=new TaskService($this->db);
                if($task['status']==='offered')$tasks->action($actor,$task['id'],'claim',(int)$task['version'],'jsc-claim-'.$i.'-'.$j,[],'jsc-samples');
                $task=$this->run('SELECT id,version,status,claimed_by FROM task_instances WHERE id=?',[$task['id']])->fetch();
                if($i%3===2&&$task['status']==='claimed'&&$task['claimed_by']===$actor)$tasks->action($actor,$task['id'],'complete',(int)$task['version'],'jsc-complete-'.$i.'-'.$j,['reason'=>'Service restored and registry confirmation recorded.'],'jsc-samples');
            }
        }}
        $this->run("UPDATE sample_installations SET summary=summary || '{\"tickets_seeded\":true}'::jsonb WHERE code='jsc-zimbabwe-v1'");
    }
}
