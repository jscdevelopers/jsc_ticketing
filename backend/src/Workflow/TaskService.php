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
        if(array_diff(array_keys($query),['q','after','status']) || array_filter($query,static fn($v)=>!is_string($v)) || strlen($query['q']??'')>200
            || (isset($query['after'])&&!TicketReader::uuid($query['after'])) || (isset($query['status'])&&!in_array($query['status'],['offered','claimed','in_progress','completed','blocked','cancelled','rejected'],true))) {throw new ApiException(422,'invalid_filter','Invalid task filter.');}
        if($ticketId!==null){(new TicketReader($this->db))->detail($actor,$ticketId);}
        $sql='SELECT ti.*,e.label,e.node_type,t.reference,t.subject FROM task_instances ti JOIN tickets t ON t.id=ti.ticket_id
            LEFT JOIN workflow_executions e ON e.id=ti.execution_id WHERE '.TicketScope::predicate();
        $params=['actor'=>$actor];
        if($ticketId!==null){$sql.=' AND t.id=CAST(:ticket AS uuid)';$params['ticket']=$ticketId;}
        else{$sql.=" AND EXISTS (SELECT 1 FROM task_candidates own WHERE own.task_id=ti.id AND own.user_id=CAST(:candidate AS uuid) AND own.revoked_at IS NULL)";$params['candidate']=$actor;}
        if(isset($query['status'])){$sql.=' AND ti.status=:status';$params['status']=$query['status'];}
        if(isset($query['after'])){$sql.=' AND ti.id>CAST(:after AS uuid)';$params['after']=$query['after'];}
        if(($query['q']??'')!==''){$sql.=" AND (t.subject||' '||t.reference||' '||COALESCE(e.label,ti.node_id)) ILIKE :search ESCAPE '!'";$params['search']='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query['q']).'%';}
        $rows=Sql::run($this->db,$sql.' ORDER BY ti.id LIMIT 11',$params)->fetchAll();$more=count($rows)>10;if($more){array_pop($rows);}$last=end($rows);
        return ['data'=>$rows,'meta'=>['next_after'=>$more?$last['id']:null]];
    }

    public function action(string $actor,string $id,string $action,int $version,string $key,array $body,string $requestId):array
    {
        if(!TicketReader::uuid($id)){throw new ApiException(404,'task_unavailable','Task unavailable.');}
        if(!in_array($action,['claim','release','complete','approve','reject'],true) || array_diff(array_keys($body),['reason'])){throw new ApiException(422,'invalid_action','Unsupported task action or field.');}
        $reason=$action==='claim'?null:Mutation::reason($body['reason']??null);
        return Mutation::run($this->db,$actor,'task.'.$action.':'.$id,$key,['version'=>$version,...$body],function()use($actor,$id,$action,$version,$reason,$requestId):array {
            $ticketId=Sql::run($this->db,'SELECT ticket_id FROM task_instances WHERE id=?',[$id])->fetchColumn();
            if(!$ticketId){throw new ApiException(404,'task_unavailable','Task unavailable.');}
            $ticket=Sql::run($this->db,'SELECT * FROM tickets WHERE id=? FOR UPDATE',[$ticketId])->fetch();
            (new TicketReader($this->db))->detail($actor,$ticketId);
            $task=Sql::run($this->db,'SELECT ti.*,e.node_type,e.context FROM task_instances ti LEFT JOIN workflow_executions e ON e.id=ti.execution_id WHERE ti.id=? FOR UPDATE OF ti',[$id])->fetch();
            $eligible=Sql::run($this->db,'SELECT 1 FROM task_instances ti JOIN tickets t ON t.id=ti.ticket_id JOIN app_users actor ON actor.id=CAST(? AS uuid)
                JOIN task_candidates tc ON tc.task_id=ti.id AND tc.user_id=actor.id WHERE ti.id=CAST(? AS uuid) AND actor.active AND tc.revoked_at IS NULL
                AND (tc.eligible_until IS NULL OR tc.eligible_until>statement_timestamp()) AND '.TaskAccess::eligibilitySql(),[$actor,$id])->fetchColumn();
            if(!$eligible){throw new ApiException(403,'candidate_ineligible','You are not currently eligible to action this task.');}
            Mutation::version((int)$task['version'],$version);
            if(in_array($ticket['status'],['closed','withdrawn'],true)){throw new ApiException(409,'ticket_closed','Ticket is no longer actionable.');}
            $old=$task['status'];
            if($action==='claim') {
                if($task['node_type']==='approval' && $task['execution_id']){(new Runtime($this->db,$ticketId,$actor,$requestId))->assertSeparation($task);}
                if($old!=='offered'){throw new ApiException(409,'task_claimed','Another actor has already claimed or completed this task.');}
                Sql::run($this->db,"UPDATE task_instances SET status='claimed',claimed_by=?,claimed_at=now(),version=version+1 WHERE id=?",[$actor,$id]);
            } else {
                if($task['claimed_by']!==$actor || !in_array($old,['claimed','in_progress'],true)){throw new ApiException(409,'claim_required','Only the current claimant can perform this action.');}
                if($action==='release') {
                    Sql::run($this->db,"UPDATE task_instances SET status='offered',claimed_by=null,claimed_at=null,version=version+1 WHERE id=?",[$id]);
                } else {
                    if(($task['node_type']==='approval' && !in_array($action,['approve','reject'],true)) || ($task['node_type']!=='approval' && $action!=='complete')) {throw new ApiException(422,'invalid_action','Use the action defined by this workflow step.');}
                    if(!$task['execution_id']){throw new ApiException(409,'execution_unavailable','Task has no workflow execution.');}
                    $runtime=new Runtime($this->db,$ticketId,$actor,$requestId);
                    $runtime->assertSeparation($task);
                    Sql::run($this->db,"UPDATE task_instances SET status=?,completed_at=now(),version=version+1 WHERE id=?",[$action==='reject'?'rejected':'completed',$id]);
                    Events::record($this->db,$ticket,$id,$actor,'TASK_'.strtoupper($action),$old,$action==='reject'?'rejected':'completed',[],$reason,$requestId);
                    $execution=Sql::run($this->db,'SELECT * FROM workflow_executions WHERE id=?',[$task['execution_id']])->fetch();
                    if($action==='reject'){$runtime->reject($execution,$reason??'Approval rejected');}else{$runtime->finish($execution,$action);}
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
