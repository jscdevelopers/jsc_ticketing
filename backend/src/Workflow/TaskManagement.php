<?php
declare(strict_types=1);
namespace App\Workflow;

use App\Http\ApiException;
use App\Support\Sql;
use App\Support\Mutation;
use App\Support\Events;
use App\Security\Access;
use App\Security\TaskAccess;
use App\Tickets\TicketReader;
use PDO;

final class TaskManagement
{
    public function __construct(private PDO$db){}
    public function candidates(string$actor,string$id,array$query):array
    {
        if(!TicketReader::uuid($id)||array_diff(array_keys($query),['q','after','eligible'])||array_filter($query,static fn($v)=>!is_string($v))||strlen($query['q']??'')>200||(isset($query['after'])&&!TicketReader::uuid($query['after']))){throw new ApiException(422,'invalid_filter','Invalid candidate filter.');}
        $ticket=Sql::run($this->db,'SELECT ticket_id FROM task_instances WHERE id=?',[$id])->fetchColumn();if(!$ticket){throw new ApiException(404,'task_unavailable','Task unavailable.');}(new TicketReader($this->db))->detail($actor,$ticket);
        $eligible=($query['eligible']??'0')==='1';if($eligible){Access::task($this->db,$actor,'task.manage',$id);}
        $sql='SELECT actor.id,actor.display_name FROM app_users actor JOIN task_instances ti ON ti.id=CAST(:task AS uuid) JOIN tickets t ON t.id=ti.ticket_id
            WHERE actor.active AND '.TaskAccess::eligibilitySql();$params=['task'=>$id];
        if(!$eligible){$sql.=' AND EXISTS(SELECT 1 FROM task_candidates tc WHERE tc.task_id=ti.id AND tc.user_id=actor.id AND tc.revoked_at IS NULL AND (tc.eligible_until IS NULL OR tc.eligible_until>now()))';}
        if(isset($query['after'])){$sql.=' AND actor.id>CAST(:after AS uuid)';$params['after']=$query['after'];}
        if(($query['q']??'')!==''){$sql.=" AND actor.display_name ILIKE :q ESCAPE '!'";$params['q']='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query['q']).'%';}
        $rows=Sql::run($this->db,$sql.' ORDER BY actor.id LIMIT 11',$params)->fetchAll();$more=count($rows)>10;if($more){array_pop($rows);}$last=end($rows);return['data'=>$rows,'meta'=>['next_after'=>$more?$last['id']:null]];
    }
    public function action(string$actor,string$id,string$action,int$version,string$key,array$body,string$requestId):array
    {
        if(!TicketReader::uuid($id)||!in_array($action,['recompute','reassign','manage-release','pause-sla','resume-sla'],true)||array_diff(array_keys($body),['reason','target_user_id'])){throw new ApiException(422,'invalid_action','Invalid management action.');}
        $reason=Mutation::reason($body['reason']??null);
        if($action==='reassign'&&(!is_string($body['target_user_id']??null)||!TicketReader::uuid($body['target_user_id']))){throw new ApiException(422,'invalid_target','Choose a valid eligible staff member.');}
        return Mutation::run($this->db,$actor,'task.'.$action.':'.$id,$key,['version'=>$version,...$body],function()use($actor,$id,$action,$version,$body,$reason,$requestId):array{
            $ticketId=Sql::run($this->db,'SELECT ticket_id FROM task_instances WHERE id=?',[$id])->fetchColumn();if(!$ticketId){throw new ApiException(404,'task_unavailable','Task unavailable.');}
            $ticket=Sql::run($this->db,'SELECT * FROM tickets WHERE id=? FOR UPDATE',[$ticketId])->fetch();(new TicketReader($this->db))->detail($actor,$ticketId);Access::task($this->db,$actor,'task.manage',$id);
            $task=Sql::run($this->db,'SELECT * FROM task_instances WHERE id=? FOR UPDATE',[$id])->fetch();Mutation::version((int)$task['version'],$version);
            if(!in_array($task['status'],['offered','claimed','in_progress','blocked'],true)||in_array($ticket['status'],['closed','withdrawn'],true)){throw new ApiException(409,'task_inactive','Task is no longer active.');}
            $data=['previous_claimant'=>$task['claimed_by']];
            if($action==='reassign'){
                $target=$body['target_user_id'];$eligible=Sql::run($this->db,'SELECT 1 FROM app_users actor JOIN task_instances ti ON ti.id=CAST(? AS uuid) JOIN tickets t ON t.id=ti.ticket_id WHERE actor.id=CAST(? AS uuid) AND actor.active AND '.TaskAccess::eligibilitySql(),[$id,$target])->fetchColumn();
                if(!$eligible){throw new ApiException(422,'ineligible_target','Target no longer meets position, location and explicit authority requirements.');}
                if($task['execution_id']){$execution=Sql::run($this->db,'SELECT context FROM workflow_executions WHERE id=?',[$task['execution_id']])->fetch();(new Runtime($this->db,$ticketId,$target,$requestId))->assertSeparation($execution);}
                Sql::run($this->db,'INSERT INTO task_candidates(task_id,user_id,reason) VALUES (?,?,?) ON CONFLICT(task_id,user_id) DO UPDATE SET revoked_at=null,eligible_until=null',[$id,$target,$reason]);
                Sql::run($this->db,"UPDATE task_instances SET status='claimed',claimed_by=?,claimed_at=now(),version=version+1 WHERE id=?",[$target,$id]);$data['target_user_id']=$target;
            }elseif($action==='manage-release'){
                Sql::run($this->db,"UPDATE task_instances SET status='offered',claimed_by=null,claimed_at=null,version=version+1 WHERE id=?",[$id]);
            }elseif($action==='recompute'){
                if($task['claimed_by']!==null){throw new ApiException(409,'release_required','Release the existing claim before recomputing offers.');}
                $eligible=Sql::run($this->db,'SELECT actor.id FROM app_users actor JOIN task_instances ti ON ti.id=CAST(? AS uuid) JOIN tickets t ON t.id=ti.ticket_id WHERE actor.active AND '.TaskAccess::eligibilitySql().' ORDER BY actor.id LIMIT 1001',[$id])->fetchAll(PDO::FETCH_COLUMN);
                if(count($eligible)>1000){throw new ApiException(422,'candidate_limit','Narrow the configured candidate pool to at most 1000 staff.');}
                $data['previous_candidates']=Sql::run($this->db,'SELECT user_id FROM task_candidates WHERE task_id=? AND revoked_at IS NULL',[$id])->fetchAll(PDO::FETCH_COLUMN);$data['offered_candidates']=$eligible;
                Sql::run($this->db,'UPDATE task_candidates SET revoked_at=now() WHERE task_id=? AND revoked_at IS NULL',[$id]);
                foreach($eligible as$user){Sql::run($this->db,'INSERT INTO task_candidates(task_id,user_id,reason) VALUES (?,?,?) ON CONFLICT(task_id,user_id) DO UPDATE SET revoked_at=null,eligible_until=null',[$id,$user,$reason]);}
                Sql::run($this->db,'UPDATE task_instances SET status=?,version=version+1 WHERE id=?',[$eligible?'offered':'blocked',$id]);
            }elseif($action==='pause-sla'){
                if($task['sla_paused_at']!==null||$task['due_at']===null){throw new ApiException(409,'sla_unavailable','There is no running SLA to pause.');}
                $definition=json_decode(Sql::run($this->db,'SELECT definition FROM workflow_versions WHERE id=?',[$task['workflow_version_id']])->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
                $remaining=BusinessClock::remaining(new \DateTimeImmutable(),new \DateTimeImmutable($task['due_at']),$definition['calendar']??[]);
                Sql::run($this->db,'UPDATE task_instances SET sla_paused_at=now(),sla_remaining_minutes=?,due_at=null,version=version+1 WHERE id=?',[$remaining,$id]);
            }else{
                if($task['sla_paused_at']===null){throw new ApiException(409,'sla_unavailable','SLA is not paused.');}
                $definition=json_decode(Sql::run($this->db,'SELECT definition FROM workflow_versions WHERE id=?',[$task['workflow_version_id']])->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
                $due=(int)$task['sla_remaining_minutes']>0?BusinessClock::due(new \DateTimeImmutable(),min(525600,(int)$task['sla_remaining_minutes']),$definition['calendar']??[]):new \DateTimeImmutable();
                Sql::run($this->db,'UPDATE task_instances SET sla_paused_at=null,sla_remaining_minutes=null,due_at=?,version=version+1 WHERE id=?',[$due->format(DATE_ATOM),$id]);
            }
            $result=Sql::run($this->db,'SELECT id,ticket_id,status,claimed_by,version FROM task_instances WHERE id=?',[$id])->fetch();
            Events::record($this->db,$ticket,$id,$actor,'TASK_'.strtoupper(str_replace('-','_',$action)),$task['status'],$result['status'],$data,$reason,$requestId);
            if($task['execution_id']){(new Runtime($this->db,$ticketId,$actor,$requestId))->refresh();}return$result;
        },function(array$result)use($actor):void{(new TicketReader($this->db))->detail($actor,$result['ticket_id']);});
    }
}
