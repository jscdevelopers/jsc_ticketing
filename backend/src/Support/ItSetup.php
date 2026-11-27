<?php
declare(strict_types=1);
namespace App\Support;
use PDO;
use App\Security\PasswordAuth;
use App\Workflow\ItWorkflow;
use App\Workflow\WorkflowService;

final class ItSetup
{
    public function __construct(private PDO$db){}
    public static function accounts():array
    {
        $accounts=['it.head','it.deputy','it.helpdesk','it.systems_engineer','it.systems_analyst','it.hardware_specialist','it.database_specialist','secretary','deputy.secretary'];
        foreach(JscSamples::dataset()['provinces']as$name)$accounts[]='it.principal.'.strtolower(str_replace(' ','_',$name));
        foreach(JscSamples::dataset()['stations']as[$code])$accounts[]='it.officer.'.strtolower($code);
        return$accounts;
    }
    public function install(array$passwords):array
    {
        if(!in_array(getenv('APP_ENV'),['development','offline','test'],true)||getenv('ALLOW_DEMO_INTEGRATIONS')!=='true')throw new \InvalidArgumentException('IT examples require an enabled local environment.');
        foreach(self::accounts()as$name)PasswordAuth::validatePassword($passwords[$name]??'');
        $this->db->query('SELECT pg_advisory_lock(741903223)');
        try{return$this->configure($passwords);}finally{$this->db->query('SELECT pg_advisory_unlock(741903223)');}
    }
    private function configure(array$passwords):array
    {
        if(Sql::run($this->db,"SELECT 1 FROM sample_installations WHERE code='it-routing-v1'")->fetchColumn())return['already_installed'=>true];
        $department=Sql::run($this->db,"SELECT id FROM departments WHERE code='ICT' AND active")->fetchColumn();if(!$department)throw new \InvalidArgumentException('Install the JSC structure first.');
        $positions=[];$codes=['it_officer'=>'ICT_OFFICER','principal_it'=>'PRINCIPAL_IT','principal_help_desk'=>'PRINCIPAL_HELP_DESK','head_it'=>'HEAD_IT','deputy_it'=>'DEPUTY_IT','systems_engineer'=>'SYSTEMS_ENGINEER','systems_analyst'=>'SYSTEMS_ANALYST','hardware_specialist'=>'HARDWARE_SPECIALIST','database_specialist'=>'DATABASE_SPECIALIST'];
        $this->db->beginTransaction();try{
            $this->db->query("SELECT set_config('app.actor_system','it-workflow-setup',true)");
            foreach($codes as$key=>$code){$positions[$key]=Sql::run($this->db,'INSERT INTO positions(code,title) VALUES (?,?) ON CONFLICT(code) DO UPDATE SET title=EXCLUDED.title RETURNING id',[$code,ucwords(str_replace('_',' ',$key==='it_officer'?'IT Officer':($key==='principal_it'?'Principal IT Officer':($key==='head_it'?'Head of IT':($key==='deputy_it'?'Deputy Head of IT':($key==='principal_help_desk'?'Principal Help Desk Officer':$key))))))])->fetchColumn();}
            foreach(['deputy_it'=>'head_it','principal_it'=>'deputy_it','principal_help_desk'=>'deputy_it','it_officer'=>'principal_it','systems_engineer'=>'deputy_it','systems_analyst'=>'deputy_it','hardware_specialist'=>'deputy_it','database_specialist'=>'deputy_it']as$key=>$parent)Sql::run($this->db,'UPDATE positions SET reports_to_position_id=?,version=version+1 WHERE id=?',[$positions[$parent],$positions[$key]]);
            $stations=Sql::run($this->db,'SELECT s.*,p.name AS province_name FROM stations s JOIN provinces p ON p.id=s.province_id WHERE s.active ORDER BY s.id')->fetchAll();
            $secretariat=null;foreach($stations as$station)if($station['is_secretariat_station'])$secretariat=$station;if(!$secretariat)throw new \InvalidArgumentException('JSC Secretariat station required.');
            foreach(self::accounts()as$username){$station=$secretariat;$key='principal_help_desk';$scope='department';$leader=false;$executive=in_array($username,['secretary','deputy.secretary'],true);
                if(str_starts_with($username,'it.principal.')){$key='principal_it';$scope='province';$province=substr($username,13);foreach($stations as$choice)if(strtolower(str_replace(' ','_',$choice['province_name']))===$province){$station=$choice;break;}}
                elseif(str_starts_with($username,'it.officer.')){$key='it_officer';$scope='station';$code=strtoupper(substr($username,11));foreach($stations as$choice)if($choice['code']===$code){$station=$choice;break;}}
                elseif($username==='it.head'){$key='head_it';$leader=true;}elseif($username==='it.deputy'){$key='deputy_it';$leader=true;}
                elseif(in_array(substr($username,3),array_keys($positions),true))$key=substr($username,3);
                $label=$executive?($username==='secretary'?'Secretary':'Deputy Secretary'):($key==='it_officer'?$station['name'].' - IT Officer':($key==='principal_it'?$station['province_name'].' - Principal IT Officer':Sql::run($this->db,'SELECT title FROM positions WHERE id=?',[$positions[$key]])->fetchColumn()));
                if(Sql::run($this->db,'SELECT 1 FROM app_users WHERE external_subject=?',['local-it:'.$username])->fetchColumn())continue;
                $user=Sql::run($this->db,'INSERT INTO app_users(external_subject,display_name) VALUES (?,?) RETURNING id',['local-it:'.$username,$label])->fetchColumn();
                Sql::run($this->db,'INSERT INTO user_credentials(user_id,username,password_hash,must_change) VALUES (?,?,?,true)',[$user,$username,password_hash($passwords[$username],PASSWORD_BCRYPT,['cost'=>12])]);
                $position=$positions[$key];if($executive)$position=Sql::run($this->db,'SELECT id FROM positions WHERE code=?',[$username==='secretary'?'SECRETARY':'DEPUTY_SECRETARY'])->fetchColumn();
                $dept=$executive?Sql::run($this->db,"SELECT id FROM departments WHERE code='SECRETARIAT'")->fetchColumn():$department;
                Sql::run($this->db,'INSERT INTO staff_assignments(user_id,position_id,department_id,station_id,begins_at) VALUES (?,?,?,?,now())',[$user,$position,$dept,$station['id']]);
                foreach($executive?['operations_admin','ticket_reader','sensitive_reader','ticket_requester']:['operations_admin','ticket_reader','ticket_requester']as$role)Sql::run($this->db,'INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE code=?',[$user,$role]);
                foreach(['ticket.create','ticket.read']as$permission)Sql::run($this->db,"INSERT INTO scope_grants(user_id,permission_code,scope_kind,grant_reason) VALUES (?,?,'self','Staff may report and read their own service requests')",[$user,$permission]);
                $permissions=$executive?['ticket.read','ticket.read_sensitive','ticket.comment','ticket.comment_closed','report.analytics','report.export']:['task.claim','task.intervene',...($leader?['ticket.read','ticket.comment','task.manage','report.analytics','report.export']:[])];
                foreach($permissions as$permission){$kind=$executive?'national':$scope;$ref=match($kind){'department'=>$department,'province'=>$station['province_id'],'station'=>$station['id'],default=>null};Sql::run($this->db,'INSERT INTO scope_grants(user_id,permission_code,scope_kind,scope_ref_id,grant_reason) VALUES (?,?,?,?,?)',[$user,$permission,$kind,$ref,'Owner requested IT routing and senior oversight example']);}
            }
            $category=Sql::run($this->db,"INSERT INTO categories(department_id,code,name) VALUES (?,'COMPUTER_FAULT','Computer faults and IT support') ON CONFLICT(department_id,code) DO UPDATE SET name=EXCLUDED.name RETURNING id",[$department])->fetchColumn();
            Sql::run($this->db,"INSERT INTO security_events(event_type,actor_system,reason) VALUES ('IT_ROUTING_CONFIGURED','it-workflow-setup','Station assignment, provincial escalation, help desk category dispatch and explicit executive oversight')");
            $this->db->commit();
        }catch(\Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
        $designer=Sql::run($this->db,"SELECT user_id FROM user_credentials WHERE username='workflow.designer'")->fetchColumn();$publisher=Sql::run($this->db,"SELECT user_id FROM user_credentials WHERE username='workflow.publisher'")->fetchColumn();
        $service=new WorkflowService($this->db);$draft=$service->save($designer,null,0,'it-routing-draft',['category_id'=>$category,'definition'=>ItWorkflow::definition($department,$positions)])['data'];$published=$service->publish($publisher,$draft['id'],1,'it-routing-publish','Owner specified IT service escalation policy')['data'];
        $this->examples($category);
        Sql::run($this->db,"INSERT INTO sample_installations(code,summary) VALUES ('it-routing-v1',CAST(? AS jsonb))",[json_encode(['accounts'=>count(self::accounts()),'tickets'=>9,'category_id'=>$category,'workflow_version_id'=>$published['workflow_version_id']])]);
        return['already_installed'=>false,'accounts'=>count(self::accounts()),'tickets'=>9,'category_id'=>$category];
    }

    private function examples(string$category):void
    {
        $requester=Sql::run($this->db,"SELECT user_id FROM user_credentials WHERE username='registry.harare'")->fetchColumn();
        $posting=Sql::run($this->db,'SELECT id FROM staff_assignments WHERE user_id=? AND begins_at<=now() AND (ends_at IS NULL OR ends_at>now()) ORDER BY begins_at DESC LIMIT 1',[$requester])->fetchColumn();
        $user=fn(string$name)=>Sql::run($this->db,'SELECT user_id FROM user_credentials WHERE username=?',[$name])->fetchColumn();
        $examples=[
            ['station_it','hardware_specialist','Registry workstation fails to start'],
            ['provincial_it','hardware_specialist','Recurring courtroom computer shutdowns'],
            ['help_desk','systems_engineer','Court network service unavailable'],
            ['systems_engineer','systems_engineer','Operating system service failure'],
            ['systems_analyst','systems_analyst','Staff application sign-in failure'],
            ['hardware_specialist','hardware_specialist','Workstation screen remains blank'],
            ['database_specialist','database_specialist','Court application database connection failure'],
            ['manual_dispatch','other','Computer fault requires specialist assessment'],
            ['end','hardware_specialist','Workstation restored after diagnostics'],
        ];
        foreach($examples as$i=>[$destination,$area,$subject]){
            $ticket=(new \App\Tickets\TicketSubmission($this->db))->submit($requester,['assignment_id'=>$posting,'category_id'=>$category,'subject'=>$subject,'description'=>'Local operational sample; contains no real court case or personal record. Record investigation and escalation decisions in the ticket history.','priority'=>['normal','high','critical'][$i%3],'fields'=>['asset_tag'=>'JSC-IT-'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT),'issue_area'=>$area]],'it-example-'.$i,'it-setup')['ticket'];
            // Stable mutation keys and current stage checks make interrupted setup resumable.
            for($step=0;$step<6;$step++){
                if($destination!=='end'&&Sql::run($this->db,'SELECT 1 FROM workflow_executions WHERE ticket_id=? AND node_id=?',[$ticket['id'],$destination])->fetchColumn())break;
                $task=Sql::run($this->db,"SELECT * FROM task_instances WHERE ticket_id=? AND status IN ('offered','claimed','in_progress','blocked') ORDER BY started_at,id LIMIT 1",[$ticket['id']])->fetch();
                if(!$task)break;
                $actor=$task['claimed_by']?:$user(match($task['node_id']){'provincial_it'=>'it.principal.harare','help_desk','manual_dispatch'=>'it.helpdesk',default=>'it.'.$task['node_id']});
                $service=new \App\Workflow\TaskService($this->db);
                if(!$task['claimed_by']){$service->action($actor,$task['id'],'claim',(int)$task['version'],'it-example-'.$i.'-'.$task['node_id'].'-claim',[],'it-setup');$task=Sql::run($this->db,'SELECT * FROM task_instances WHERE id=?',[$task['id']])->fetch();}
                $action=in_array($task['node_id'],['station_it','provincial_it'],true)&&$destination!=='end'?'escalate':'complete';
                $service->action($actor,$task['id'],$action,(int)$task['version'],'it-example-'.$i.'-'.$task['node_id'].'-'.$action,['reason'=>$action==='escalate'?'Local investigation requires the next support tier.':($task['node_id']==='help_desk'?'Issue category identifies the specialist team.':'Diagnostics and restoration completed; requester informed.')],'it-setup');
            }
        }
    }
}
