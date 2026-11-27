<?php
declare(strict_types=1);
namespace App\Workflow;

use App\Http\ApiException;
use App\Support\Sql;
use App\Support\Events;
use App\Support\Mutation;
use App\Security\TaskAccess;
use App\Security\Access;
use App\Security\TicketScope;
use App\Tickets\TicketReader;
use PDO;

final class TaskService
{
    public function __construct(private PDO $db) {}

    public function listing(string $actor,array $query,?string $ticketId=null):array
    {
        if(array_diff(array_keys($query),['q','after','status','view','department_id']) || array_filter($query,static fn($v)=>!is_string($v)) || strlen($query['q']??'')>200
            || (isset($query['after'])&&!TicketReader::uuid($query['after'])) || (isset($query['status'])&&!in_array($query['status'],['offered','claimed','in_progress','completed','blocked','cancelled','rejected'],true))) {throw new ApiException(422,'invalid_filter','Invalid task filter.');}
        $view=$query['view']??'mine';
        if(!in_array($view,['mine','authorized','overdue'],true)||(isset($query['department_id'])&&!TicketReader::uuid($query['department_id']))||($ticketId!==null&&$view!=='mine')){throw new ApiException(422,'invalid_view','Select a valid task queue view.');}
        if($view!=='mine'&&!Sql::run($this->db,'SELECT 1 FROM app_users manager WHERE manager.id=? AND manager.active AND '.Access::roleSql('task.manage','manager')." AND EXISTS(SELECT 1 FROM scope_grants g WHERE g.user_id=manager.id AND g.permission_code='task.manage' AND g.revoked_at IS NULL AND g.valid_from<=now() AND (g.valid_to IS NULL OR g.valid_to>now()))",[$actor])->fetchColumn()){throw new ApiException(403,'permission_denied','An explicit active task management scope is required.');}
        if($ticketId!==null){(new TicketReader($this->db))->detail($actor,$ticketId);}
        $sql="SELECT ti.*,(SELECT display_name FROM app_users claimant WHERE claimant.id=ti.claimed_by) AS claimant_name,e.label,e.node_type,t.reference,t.subject,EXISTS(SELECT 1 FROM workflow_versions wv,jsonb_array_elements(wv.definition->'nodes') node WHERE wv.id=ti.workflow_version_id AND node->>'id'=ti.node_id AND node->'config'->>'send_back_to' IS NOT NULL) AS can_send_back FROM task_instances ti JOIN tickets t ON t.id=ti.ticket_id
            LEFT JOIN workflow_executions e ON e.id=ti.execution_id WHERE ".TicketScope::predicate();
        $params=['actor'=>$actor];
        if($ticketId!==null){$sql.=' AND t.id=CAST(:ticket AS uuid)';$params['ticket']=$ticketId;}
        elseif($view==='mine'){$sql.=" AND (EXISTS (SELECT 1 FROM task_candidates own WHERE own.task_id=ti.id AND own.user_id=CAST(:candidate AS uuid) AND own.revoked_at IS NULL) OR EXISTS(SELECT 1 FROM app_users actor WHERE actor.id=CAST(:intervener AS uuid) AND ".TaskAccess::eligibilitySql('intervention').'))';$params['candidate']=$actor;$params['intervener']=$actor;}
        else{$sql.=' AND EXISTS(SELECT 1 FROM app_users manager WHERE manager.id=CAST(:manager AS uuid) AND '.Access::roleSql('task.manage','manager').' AND '.Access::grantSql('task.manage','manager','t','ti').')';$params['manager']=$actor;}
        if($view==='authorized'&&!isset($query['status'])){$sql.=" AND ti.status IN ('offered','claimed','in_progress','blocked')";}
        if($view==='overdue'){$sql.=" AND ti.status IN ('offered','claimed','in_progress','blocked') AND ti.due_at<now()";}
        if(isset($query['department_id'])){$sql.=' AND ti.assigned_department_id=CAST(:department AS uuid)';$params['department']=$query['department_id'];}

        if(isset($query['status'])){$sql.=' AND ti.status=:status';$params['status']=$query['status'];}
        if(isset($query['after'])){$sql.=' AND ti.id>CAST(:after AS uuid)';$params['after']=$query['after'];}
        if(($query['q']??'')!==''){$sql.=" AND (t.subject||' '||t.reference||' '||COALESCE(e.label,ti.node_id)) ILIKE :search ESCAPE '!'";$params['search']='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query['q']).'%';}
        $rows=Sql::run($this->db,$sql.' ORDER BY ti.id LIMIT 11',$params)->fetchAll();$more=count($rows)>10;if($more){array_pop($rows);}$last=end($rows);
        foreach($rows as&$row){$row['routing_policy']=json_decode($row['routing_policy'],false,32,JSON_THROW_ON_ERROR);$row['can_intervene']=(bool)Sql::run($this->db,'SELECT 1 FROM task_instances ti JOIN tickets t ON t.id=ti.ticket_id JOIN app_users actor ON actor.id=CAST(? AS uuid) WHERE ti.id=? AND actor.active AND '.TaskAccess::eligibilitySql('intervention'),[$actor,$row['id']])->fetchColumn();}unset($row);
        return ['data'=>$rows,'meta'=>['next_after'=>$more?$last['id']:null]];
    }

    public function action(string $actor,string $id,string $action,int $version,string $key,array $body,string $requestId):array
    {
        if(!TicketReader::uuid($id)){throw new ApiException(404,'task_unavailable','Task unavailable.');}
        if(!in_array($action,['claim','release','complete','approve','reject','send-back','escalate','redirect','take-over'],true) || array_diff(array_keys($body),['reason','target_node_id'])){throw new ApiException(422,'invalid_action','Unsupported task action or field.');}
        $reason=$action==='claim'?null:Mutation::reason($body['reason']??null);
        return Mutation::run($this->db,$actor,'task.'.$action.':'.$id,$key,['version'=>$version,...$body],function()use($actor,$id,$action,$version,$reason,$requestId,$body):array {
            $ticketId=Sql::run($this->db,'SELECT ticket_id FROM task_instances WHERE id=?',[$id])->fetchColumn();
            if(!$ticketId){throw new ApiException(404,'task_unavailable','Task unavailable.');}
            $ticket=Sql::run($this->db,'SELECT * FROM tickets WHERE id=? FOR UPDATE',[$ticketId])->fetch();
            (new TicketReader($this->db))->detail($actor,$ticketId);
            $task=Sql::run($this->db,'SELECT ti.*,e.node_type,e.context FROM task_instances ti LEFT JOIN workflow_executions e ON e.id=ti.execution_id WHERE ti.id=? FOR UPDATE OF ti',[$id])->fetch();
            $eligible=Sql::run($this->db,'SELECT 1 FROM task_instances ti JOIN tickets t ON t.id=ti.ticket_id JOIN app_users actor ON actor.id=CAST(? AS uuid)
                WHERE ti.id=CAST(? AS uuid) AND actor.active AND (EXISTS(SELECT 1 FROM task_candidates tc WHERE tc.task_id=ti.id AND tc.user_id=actor.id AND tc.revoked_at IS NULL AND (tc.eligible_until IS NULL OR tc.eligible_until>now())) OR ('.TaskAccess::eligibilitySql('intervention').')) AND '.TaskAccess::eligibilitySql(),[$actor,$id])->fetchColumn();
            if(!$eligible){throw new ApiException(403,'candidate_ineligible','You are not currently eligible to action this task.');}
            Mutation::version((int)$task['version'],$version);
            if(in_array($ticket['status'],['closed','withdrawn'],true)){throw new ApiException(409,'ticket_closed','Ticket is no longer actionable.');}
            $old=$task['status'];
            if($action==='take-over'){
                if(!Sql::run($this->db,'SELECT 1 FROM task_instances ti JOIN tickets t ON t.id=ti.ticket_id JOIN app_users actor ON actor.id=CAST(? AS uuid) WHERE ti.id=CAST(? AS uuid) AND '.TaskAccess::eligibilitySql('intervention'),[$actor,$id])->fetchColumn()){throw new ApiException(403,'intervention_denied','The published stage does not permit your intervention.');}
                if(!in_array($old,['offered','claimed','in_progress','blocked'],true)){throw new ApiException(409,'task_inactive','Task is no longer active.');}
                (new Runtime($this->db,$ticketId,$actor,$requestId))->assertSeparation($task);
                Sql::run($this->db,"INSERT INTO task_candidates(task_id,user_id,reason) VALUES (?,?,'Published intervention policy') ON CONFLICT(task_id,user_id) DO UPDATE SET revoked_at=null",[$id,$actor]);
                Sql::run($this->db,"UPDATE task_instances SET status='claimed',claimed_by=?,claimed_at=now(),version=version+1 WHERE id=?",[$actor,$id]);
                if($task['execution_id']){(new Runtime($this->db,$ticketId,$actor,$requestId))->refresh();}
                Events::record($this->db,$ticket,$id,$actor,'TASK_TAKEN_OVER',$old,'claimed',['prior_claimant'=>$task['claimed_by']],$reason,$requestId,$task['claimed_by']?[$task['claimed_by']]:[]);return$this->result($id);
            }
            if($action==='claim') {
                if($task['node_type']==='approval' && $task['execution_id']){(new Runtime($this->db,$ticketId,$actor,$requestId))->assertSeparation($task);}
                if($old!=='offered'){throw new ApiException(409,'task_claimed','Another actor has already claimed or completed this task.');}
                Sql::run($this->db,"UPDATE task_instances SET status='claimed',claimed_by=?,claimed_at=now(),version=version+1 WHERE id=?",[$actor,$id]);
            } else {
                if($task['claimed_by']!==$actor || !in_array($old,['claimed','in_progress'],true)){throw new ApiException(409,'claim_required','Only the current claimant can perform this action.');}
                if($action==='release') {
                    Sql::run($this->db,"UPDATE task_instances SET status='offered',claimed_by=null,claimed_at=null,version=version+1 WHERE id=?",[$id]);
                } else {
                    $policy=json_decode($task['routing_policy'],true,32,JSON_THROW_ON_ERROR);if(($policy['redirect_only']??false)&&$action==='complete')throw new ApiException(422,'redirect_required','Select a specialist destination instead of resolving this dispatch stage.');
                    if(!in_array($action,['send-back','escalate','redirect'],true)&&(($task['node_type']==='approval' && !in_array($action,['approve','reject'],true)) || ($task['node_type']!=='approval' && $action!=='complete'))) {throw new ApiException(422,'invalid_action','Use the action defined by this workflow step.');}
                    if(!$task['execution_id']){throw new ApiException(409,'execution_unavailable','Task has no workflow execution.');}
                    $runtime=new Runtime($this->db,$ticketId,$actor,$requestId);
                    $runtime->assertSeparation($task);
                    Sql::run($this->db,"UPDATE task_instances SET status=?,completed_at=now(),version=version+1 WHERE id=?",[$action==='reject'?'rejected':'completed',$id]);
                    Events::record($this->db,$ticket,$id,$actor,'TASK_'.strtoupper(str_replace('-','_',$action)),$old,$action==='reject'?'rejected':'completed',[],$reason,$requestId);
                    $execution=Sql::run($this->db,'SELECT * FROM workflow_executions WHERE id=?',[$task['execution_id']])->fetch();
                    if($action==='reject'){$runtime->reject($execution,$reason??'Approval rejected');}elseif($action==='escalate'||$action==='redirect'){$runtime->routeAction($execution,$action,$body['target_node_id']??null,$reason);}elseif($action==='send-back'){$runtime->sendBack($execution,$reason);}else{$runtime->finish($execution,$action);}
                    return $this->result($id);
                }
            }
            $result=$this->result($id);
            Events::record($this->db,$ticket,$id,$actor,$action==='claim'?'TASK_CLAIMED':'TASK_RELEASED',$old,$result['status'],['prior_claimant'=>$task['claimed_by']],$reason,$requestId);
            if($task['execution_id']){(new Runtime($this->db,$ticketId,$actor,$requestId))->refresh();}
            return $result;
        },function(array $result)use($actor):void{(new TicketReader($this->db))->detail($actor,$result['ticket_id']);});
    }

    private function result(string $id):array
    {
        $result=Sql::run($this->db,'SELECT id,ticket_id,status,claimed_by,version FROM task_instances WHERE id=?',[$id])->fetch();
        $result['version']=(int)$result['version'];return $result;
    }
}
