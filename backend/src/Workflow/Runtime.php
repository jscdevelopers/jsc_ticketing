<?php
declare(strict_types=1);
namespace App\Workflow;

use App\Support\Sql;
use App\Support\Events;
use App\Security\TaskAccess;
use PDO;
use DateTimeImmutable;

/** All calls are made while the caller owns the ticket row lock and transaction. */
final class Runtime
{
    private Graph $graph;
    private array $ticket;
    public function __construct(private PDO $db,string $ticketId,private string $actor,private string $requestId)
    {
        $this->ticket=Sql::run($db,'SELECT t.*,v.definition FROM tickets t JOIN workflow_versions v ON v.id=t.workflow_version_id WHERE t.id=? FOR UPDATE OF t',[$ticketId])->fetch();
        $this->graph=new Graph(json_decode($this->ticket['definition'],true,64,JSON_THROW_ON_ERROR));
    }

    public function start():void { $this->enter($this->graph->start,[]);$this->refresh(); }

    public function routeAction(array$execution,string$action,mixed$target,string$reason):void
    {
        $config=$this->graph->nodes[$execution['node_id']]['config']??[];
        if($action==='escalate'){$target=$config['escalate_to']??null;}
        elseif(!is_string($target)||!in_array($target,$config['redirect_targets']??[],true)){throw new \App\Http\ApiException(422,'invalid_redirect','Choose an approved destination stage.');}
        if(!is_string($target)||!isset($this->graph->nodes[$target])||!in_array($target,array_column($this->graph->edges[$execution['node_id']]??[],'to'),true)){throw new \App\Http\ApiException(422,'route_unavailable','This stage has no approved route for that action.');}
        Sql::run($this->db,"UPDATE workflow_executions SET status='completed',outcome=?,completed_at=now() WHERE id=?",[$action,$execution['id']]);
        Events::record($this->db,$this->ticket,null,$this->actor,$action==='escalate'?'WORKFLOW_ESCALATED':'WORKFLOW_REDIRECTED',null,null,['from_node'=>$execution['node_id'],'to_node'=>$target],$reason,$this->requestId);
        $this->enter($target,json_decode($execution['context'],true,64,JSON_THROW_ON_ERROR));$this->refresh();
    }

    public function sendBack(array$execution,string$reason):void
    {
        $config=$this->graph->nodes[$execution['node_id']]['config']??[];
        if(!isset($config['send_back_to'],$config['max_returns'])||json_decode($execution['context'],true,64,JSON_THROW_ON_ERROR)!==[]){throw new \App\Http\ApiException(422,'return_unavailable','This stage has no approved linear send-back policy.');}
        $returns=(int)Sql::run($this->db,"SELECT count(*) FROM workflow_executions WHERE ticket_id=? AND run_number=? AND node_id=? AND outcome='sent_back'",[$this->ticket['id'],$this->ticket['run_number'],$execution['node_id']])->fetchColumn();
        if($returns>=$config['max_returns']){throw new \App\Http\ApiException(409,'return_limit','The approved send-back limit is reached. Resolve or reject the task.');}
        Sql::run($this->db,"UPDATE workflow_executions SET status='completed',outcome='sent_back',completed_at=now() WHERE id=?",[$execution['id']]);
        Events::record($this->db,$this->ticket,null,$this->actor,'WORKFLOW_SENT_BACK',null,null,['from_node'=>$execution['node_id'],'target_node'=>$config['send_back_to'],'return_number'=>$returns+1],$reason,$this->requestId);
        $this->enter($config['send_back_to'],[]);$this->refresh();
    }

    public function finish(array $execution,string $outcome):void
    {
        Sql::run($this->db,"UPDATE workflow_executions SET status='completed',outcome=?,completed_at=now() WHERE id=?",[$outcome,$execution['id']]);
        $context=is_string($execution['context'])?json_decode($execution['context'],true,64,JSON_THROW_ON_ERROR):$execution['context'];
        foreach($this->graph->next($execution['node_id'],$this->values($outcome)) as $next){$this->enter($next,$context);}
        $this->refresh();
    }

    private function values(string $outcome=''):array
    {
        $category=Sql::run($this->db,'SELECT code FROM categories WHERE id=?',[$this->ticket['category_id']])->fetchColumn();
        $values=['ticket.category'=>$this->graph->definition['category_code']??$category,'ticket.priority'=>$this->ticket['priority'],
            'requester.station_id'=>$this->ticket['origin_station_id'],'requester.province_id'=>$this->ticket['origin_province_id'],
            'requester.department_id'=>$this->ticket['origin_department_id'],'step.outcome'=>$outcome];
        foreach(json_decode($this->ticket['custom_fields'],true,32,JSON_THROW_ON_ERROR) as $key=>$value){$values['fields.'.$key]=$value;}
        return $values;
    }

    private function enter(string $nodeId,array $context):void
    {
        $node=$this->graph->nodes[$nodeId];$type=$node['type'];$config=$node['config']??[];
        $execution=Sql::run($this->db,"INSERT INTO workflow_executions(ticket_id,node_id,node_type,label,status,context,run_number)
            VALUES (?,?,?,?,'active',CAST(? AS jsonb),?) RETURNING *",[$this->ticket['id'],$nodeId,$type,$node['label'],json_encode($context,JSON_THROW_ON_ERROR),$this->ticket['run_number']])->fetch();
        if(in_array($type,['task','claim_pool','approval'],true)) {
            $due=isset($config['sla_minutes'])?BusinessClock::due(new DateTimeImmutable(),$config['sla_minutes'],$this->graph->definition['calendar']??[])->format(DATE_ATOM):null;
            $task=Sql::run($this->db,"INSERT INTO task_instances(ticket_id,workflow_version_id,node_id,branch_id,assigned_department_id,assigned_position_id,scope_kind,execution_id,due_at,routing_policy)
                VALUES (?,?,?,?,?,?,?,?,?,CAST(? AS jsonb)) RETURNING *",[$this->ticket['id'],$this->ticket['workflow_version_id'],$nodeId,$context?end($context)['branch_id']:null,
                $config['department_id'],$config['position_id'],$config['scope']??'station',$execution['id'],$due,json_encode($config,JSON_THROW_ON_ERROR)])->fetch();
            $candidates=Sql::run($this->db,'SELECT actor.id FROM app_users actor JOIN task_instances ti ON ti.id=CAST(? AS uuid) JOIN tickets t ON t.id=ti.ticket_id
                WHERE actor.active AND '.TaskAccess::eligibilitySql().' ORDER BY actor.id LIMIT 1001',[$task['id']])->fetchAll(PDO::FETCH_COLUMN);
            $overflow=count($candidates)>1000;if($overflow){$candidates=[];}
            foreach($candidates as $candidate){Sql::run($this->db,'INSERT INTO task_candidates(task_id,user_id,reason) VALUES (?,?,?)',[$task['id'],$candidate,'Published position, location and current task grant']);}
            if(!$candidates){Sql::run($this->db,"UPDATE task_instances SET status='blocked' WHERE id=?",[$task['id']]);
                Sql::run($this->db,"INSERT INTO security_events(event_type,actor_system,subject_id,reason) VALUES ('WORKFLOW_EXCEPTION','workflow',?,?)",[$this->ticket['workflow_version_id'],($overflow?'Candidate pool exceeds 1000':'No eligible candidates').' at node '.$nodeId]);
            }
            Events::record($this->db,$this->ticket,$task['id'],$this->actor,$candidates?'TASK_OFFERED':'NO_ELIGIBLE_CANDIDATES',null,$candidates?'offered':'blocked',
                ['node_id'=>$nodeId,'candidate_count'=>count($candidates)],null,$this->requestId,$candidates);
            if(($config['assignment_mode']??'pool')==='automatic'&&!$overflow){
                $primaryCandidates=Sql::run($this->db,'SELECT actor.id FROM app_users actor JOIN task_instances ti ON ti.id=CAST(? AS uuid) JOIN tickets t ON t.id=ti.ticket_id WHERE actor.active AND '.TaskAccess::eligibilitySql('primary')." ORDER BY (SELECT count(*) FROM task_instances load WHERE load.claimed_by=actor.id AND load.status IN ('claimed','in_progress')),actor.id LIMIT 1000",[$task['id']])->fetchAll(PDO::FETCH_COLUMN);
                $primary=null;foreach($primaryCandidates as$candidate){try{(new self($this->db,$this->ticket['id'],$candidate,$this->requestId))->assertSeparation($execution);$primary=$candidate;break;}catch(\App\Http\ApiException$error){if($error->status!==403)throw$error;}}
                if($primary){Sql::run($this->db,"UPDATE task_instances SET status='claimed',claimed_by=?,claimed_at=now(),version=version+1 WHERE id=?",[$primary,$task['id']]);Events::record($this->db,$this->ticket,$task['id'],$this->actor,'TASK_AUTO_ASSIGNED','offered','claimed',['assigned_user_id'=>$primary],null,$this->requestId,[$primary]);}
            }
            return;
        }
        if($type==='request_info' || $type==='timer') {
            $due=$type==='timer'?(new DateTimeImmutable())->modify('+'.$config['delay_minutes'].' minutes')->format(DATE_ATOM):null;
            Sql::run($this->db,"UPDATE workflow_executions SET status='waiting',due_at=? WHERE id=?",[$due,$execution['id']]);
            Events::record($this->db,$this->ticket,null,$this->actor,$type==='timer'?'TIMER_STARTED':'INFORMATION_REQUESTED',null,'waiting',['node_id'=>$nodeId],null,$this->requestId);
            return;
        }
        if($type==='parallel_fork') {
            $join=$this->graph->nodes[$config['join_id']];$policy=$join['config']['mode']??'all';$branches=$this->graph->next($nodeId,$this->values());
            $threshold=match($policy){'any'=>1,'quorum'=>$join['config']['quorum'],default=>count($branches)};
            Sql::run($this->db,'INSERT INTO workflow_join_runs(fork_id,join_node,expected,threshold) VALUES (?,?,?,?)',[$execution['id'],$join['id'],count($branches),$threshold]);
            Sql::run($this->db,"UPDATE workflow_executions SET status='completed',completed_at=now() WHERE id=?",[$execution['id']]);
            foreach($branches as $branch){
                if(Sql::run($this->db,'SELECT status FROM workflow_join_runs WHERE fork_id=?',[$execution['id']])->fetchColumn()==='completed'){break;}
                $this->enter($branch,[...$context,['fork_id'=>$execution['id'],'join_id'=>$join['id'],'branch_id'=>$branch,'separation_of_duties'=>($join['config']['separation_of_duties']??false)===true]]);
            }
            return;
        }
        if($type==='parallel_join') {
            $fork=array_pop($context);
            if(!$fork || $fork['join_id']!==$nodeId){throw new \RuntimeException('Join context mismatch.');}
            $run=Sql::run($this->db,'SELECT * FROM workflow_join_runs WHERE fork_id=? FOR UPDATE',[$fork['fork_id']])->fetch();
            Sql::run($this->db,"UPDATE workflow_executions SET status='completed',completed_at=now() WHERE id=?",[$execution['id']]);
            if($run['status']==='completed'){return;}
            Sql::run($this->db,'INSERT INTO workflow_join_arrivals(fork_id,branch_id) VALUES (?,?) ON CONFLICT DO NOTHING',[$fork['fork_id'],$fork['branch_id']]);
            $arrived=(int)Sql::run($this->db,'SELECT count(*) FROM workflow_join_arrivals WHERE fork_id=?',[$fork['fork_id']])->fetchColumn();
            if($arrived<(int)$run['threshold']){return;}
            Sql::run($this->db,"UPDATE workflow_join_runs SET status='completed' WHERE fork_id=?",[$fork['fork_id']]);
            $pattern=json_encode([['fork_id'=>$fork['fork_id']]],JSON_THROW_ON_ERROR);
            $cancelled=Sql::run($this->db,"UPDATE task_instances ti SET status='cancelled',completed_at=now(),version=version+1
                FROM workflow_executions e WHERE ti.execution_id=e.id AND e.ticket_id=? AND e.context @> CAST(? AS jsonb)
                AND ti.status IN ('offered','claimed','in_progress','blocked') RETURNING ti.id,ti.claimed_by",[$this->ticket['id'],$pattern])->fetchAll();
            foreach($cancelled as $task){Events::record($this->db,$this->ticket,$task['id'],$this->actor,'TASK_CANCELLED',null,'cancelled',[],'Join threshold satisfied',$this->requestId,$task['claimed_by']?[$task['claimed_by']]:[]);}
            Sql::run($this->db,"UPDATE workflow_executions SET status='cancelled',completed_at=now() WHERE ticket_id=? AND context @> CAST(? AS jsonb) AND status IN ('active','waiting')",[$this->ticket['id'],$pattern]);
            foreach($this->graph->next($nodeId,$this->values()) as $next){$this->enter($next,$context);}
            return;
        }
        if($type==='route') {
            $previous=$this->ticket['responsible_department_id'];
            Sql::run($this->db,'UPDATE tickets SET responsible_department_id=? WHERE id=?',[$config['department_id'],$this->ticket['id']]);
            $this->ticket['responsible_department_id']=$config['department_id'];
            Events::record($this->db,$this->ticket,null,$this->actor,'DEPARTMENT_ROUTED',null,null,['previous_department_id'=>$previous,'department_id'=>$config['department_id']],null,$this->requestId);
        }
        if($type==='webhook'){LocalWebhook::validate($config);LocalWebhook::requireEnabled();Events::record($this->db,$this->ticket,null,$this->actor,'WEBHOOK_DEMO_RECORDED',null,null,['node_id'=>$nodeId,'endpoint_id'=>'local-audit','external_delivery'=>false],'Local integration dummy; no external request sent',$this->requestId);}
        if(in_array($type,['notification','escalation'],true)){Events::record($this->db,$this->ticket,null,$this->actor,strtoupper($type),null,null,['node_id'=>$nodeId],null,$this->requestId);}
        Sql::run($this->db,"UPDATE workflow_executions SET status='completed',completed_at=now() WHERE id=?",[$execution['id']]);
        if(!in_array($type,['end','close'],true)){foreach($this->graph->next($nodeId,$this->values()) as $next){$this->enter($next,$context);}}
    }

    public function assertSeparation(array $execution):void
    {
        $context=json_decode($execution['context'],true,64,JSON_THROW_ON_ERROR);
        foreach($context as $fork){
            if(!($fork['separation_of_duties']??false)){continue;}
            if(Sql::run($this->db,"SELECT 1 FROM task_instances ti JOIN workflow_executions e ON e.id=ti.execution_id
                WHERE ti.ticket_id=? AND ti.claimed_by=? AND ti.status IN ('completed','rejected')
                  AND EXISTS(SELECT 1 FROM jsonb_array_elements(e.context) c WHERE c->>'fork_id'=? AND c->>'branch_id'<>?) LIMIT 1",
                [$this->ticket['id'],$this->actor,$fork['fork_id'],$fork['branch_id']])->fetchColumn()){
                throw new \App\Http\ApiException(403,'separation_of_duties','A different authorized actor must complete this independent branch.');
            }
        }
    }

    public function refresh():void
    {
        $active=(int)Sql::run($this->db,"SELECT count(*) FROM workflow_executions WHERE ticket_id=? AND status IN ('active','waiting')",[$this->ticket['id']])->fetchColumn();
        $pendingJoin=(int)Sql::run($this->db,"SELECT count(*) FROM workflow_join_runs j JOIN workflow_executions e ON e.id=j.fork_id WHERE e.ticket_id=? AND j.status='open'",[$this->ticket['id']])->fetchColumn();
        $status='routed';
        if(!$active && !$pendingJoin){$status='closed';}
        elseif(Sql::run($this->db,"SELECT 1 FROM workflow_executions WHERE ticket_id=? AND node_type='request_info' AND status='waiting' LIMIT 1",[$this->ticket['id']])->fetchColumn()){$status='awaiting_input';}
        elseif(Sql::run($this->db,"SELECT 1 FROM task_instances ti JOIN workflow_executions e ON e.id=ti.execution_id WHERE ti.ticket_id=? AND e.node_type='approval' AND ti.status IN ('offered','claimed','in_progress') LIMIT 1",[$this->ticket['id']])->fetchColumn()){$status='awaiting_approval';}
        elseif(Sql::run($this->db,"SELECT 1 FROM task_instances WHERE ticket_id=? AND status IN ('claimed','in_progress') LIMIT 1",[$this->ticket['id']])->fetchColumn()){$status='in_progress';}
        $prior=Sql::run($this->db,'SELECT status FROM tickets WHERE id=?',[$this->ticket['id']])->fetchColumn();
        Sql::run($this->db,"UPDATE tickets SET status=?,version=version+1,updated_at=now(),resolution=CASE WHEN ?='closed' THEN 'completed' ELSE null END,closed_at=CASE WHEN ?='closed' THEN now() ELSE null END WHERE id=?",[$status,$status,$status,$this->ticket['id']]);
        if($prior!==$status){Events::record($this->db,$this->ticket,null,$this->actor,'TICKET_STATE_CHANGED',$prior,$status,[],null,$this->requestId);}
    }

    public function reject(array $execution,string $reason):void
    {
        $config=$this->graph->nodes[$execution['node_id']]['config']??[];
        if(($config['on_reject']??'terminate')==='route'){$this->finish($execution,'reject');return;}
        Sql::run($this->db,"UPDATE workflow_executions SET status='completed',outcome='reject',completed_at=now() WHERE id=?",[$execution['id']]);
        $cancelled=Sql::run($this->db,"UPDATE task_instances SET status='cancelled',version=version+1,completed_at=now()
            WHERE ticket_id=? AND status IN ('offered','claimed','in_progress','blocked') RETURNING id",[$this->ticket['id']])->fetchAll(PDO::FETCH_COLUMN);
        foreach($cancelled as$task){Events::record($this->db,$this->ticket,$task,$this->actor,'TASK_CANCELLED',null,'cancelled',[],'Workflow rejected',$this->requestId);}
        Sql::run($this->db,"UPDATE workflow_executions SET status='cancelled',completed_at=now() WHERE ticket_id=? AND status IN ('active','waiting')",[$this->ticket['id']]);
        Sql::run($this->db,"UPDATE workflow_join_runs SET status='completed' WHERE fork_id IN (SELECT id FROM workflow_executions WHERE ticket_id=?)",[$this->ticket['id']]);
        Sql::run($this->db,"UPDATE tickets SET status='closed',resolution='rejected',closed_at=now(),updated_at=now(),version=version+1 WHERE id=?",[$this->ticket['id']]);
        Events::record($this->db,$this->ticket,null,$this->actor,'TICKET_REJECTED',$this->ticket['status'],'closed',[],$reason,$this->requestId);
    }
}
