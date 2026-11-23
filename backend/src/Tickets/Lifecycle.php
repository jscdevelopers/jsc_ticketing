<?php
declare(strict_types=1);
namespace App\Tickets;

use App\Http\ApiException;
use App\Security\Access;
use App\Support\Mutation;
use App\Support\Sql;
use App\Support\Events;
use PDO;

final class Lifecycle
{
    public function __construct(private PDO$db){}
    public function action(string$actor,string$id,string$action,int$version,string$key,array$body,string$requestId):array
    {
        if(!in_array($action,['withdraw','reopen'],true)||array_diff(array_keys($body),['reason'])){throw new ApiException(422,'invalid_action','Invalid lifecycle action.');}
        $reason=Mutation::reason($body['reason']??null);
        return Mutation::run($this->db,$actor,'ticket.'.$action.':'.$id,$key,['version'=>$version,...$body],function()use($actor,$id,$action,$version,$reason,$requestId):array{
            (new TicketReader($this->db))->detail($actor,$id);$ticket=Sql::run($this->db,'SELECT * FROM tickets WHERE id=? FOR UPDATE',[$id])->fetch();Mutation::version((int)$ticket['version'],$version);
            if($action==='withdraw'){
                if(in_array($ticket['status'],['closed','withdrawn'],true)){throw new ApiException(409,'ticket_inactive','Ticket is already closed or withdrawn.');}
                if($ticket['requester_id']===$actor&&in_array($ticket['status'],['submitted','routed'],true)){
                    Access::ticket($this->db,$actor,'ticket.create',$id);
                    if(Sql::run($this->db,"SELECT 1 FROM task_instances WHERE ticket_id=? AND status IN ('claimed','in_progress','completed','rejected')",[$id])->fetchColumn()){Access::ticket($this->db,$actor,'ticket.manage',$id);}
                }else{Access::ticket($this->db,$actor,'ticket.manage',$id);}
                $tasks=Sql::run($this->db,"UPDATE task_instances SET status='cancelled',completed_at=now(),version=version+1 WHERE ticket_id=? AND status IN ('offered','claimed','in_progress','blocked') RETURNING id",[$id])->fetchAll(PDO::FETCH_COLUMN);
                foreach($tasks as$task){Events::record($this->db,$ticket,$task,$actor,'TASK_CANCELLED',null,'cancelled',[],$reason,$requestId);}
                Sql::run($this->db,"UPDATE workflow_executions SET status='cancelled',completed_at=now() WHERE ticket_id=? AND status IN ('active','waiting')",[$id]);
                Sql::run($this->db,"UPDATE workflow_join_runs SET status='completed' WHERE fork_id IN(SELECT id FROM workflow_executions WHERE ticket_id=?)",[$id]);
                Sql::run($this->db,"UPDATE tickets SET status='withdrawn',resolution='withdrawn',closed_at=now(),version=version+1,updated_at=now() WHERE id=?",[$id]);
            }else{
                Access::ticket($this->db,$actor,'ticket.manage',$id);
                if(!in_array($ticket['status'],['closed','withdrawn'],true)){throw new ApiException(409,'ticket_active','Only closed or withdrawn tickets can be reopened.');}
                Sql::run($this->db,"UPDATE tickets SET status='submitted',resolution=null,closed_at=null,version=version+1,run_number=run_number+1,updated_at=now() WHERE id=?",[$id]);
                $definition=json_decode(Sql::run($this->db,'SELECT definition FROM workflow_versions WHERE id=?',[$ticket['workflow_version_id']])->fetchColumn(),true,64,JSON_THROW_ON_ERROR);
                if(($definition['engine']??'')==='workflow-v1'){(new \App\Workflow\Runtime($this->db,$id,$actor,$requestId))->start();}
                elseif(($definition['engine']??'')!=='department-triage-v1'){throw new ApiException(409,'workflow_unavailable','Historical workflow requires an explicit reviewed migration before reopening.');}
            }
            $result=Sql::run($this->db,'SELECT id,status,resolution,version,workflow_version_id,run_number FROM tickets WHERE id=?',[$id])->fetch();
            Events::record($this->db,$ticket,null,$actor,$action==='withdraw'?'TICKET_WITHDRAWN':'TICKET_REOPENED',$ticket['status'],$result['status'],['run_number'=>$result['run_number']],$reason,$requestId);
            return$result+['ticket_id'=>$id];
        },function(array$result)use($actor):void{(new TicketReader($this->db))->detail($actor,$result['id']);});
    }
}
