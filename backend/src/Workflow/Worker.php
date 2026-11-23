<?php
declare(strict_types=1);
namespace App\Workflow;

use App\Support\Sql;
use App\Support\Events;
use App\Tickets\TicketReader;
use App\Http\ApiException;
use PDO;
use Throwable;

final class Worker
{
    public function __construct(private PDO $db) {}

    public function outbox():int
    {
        $count=0;
        for($i=0;$i<10;$i++){
            $job=null;
            $this->db->beginTransaction();
            try{
                $job=Sql::run($this->db,"SELECT * FROM notification_outbox WHERE status IN ('pending','failed') AND available_at<=now() ORDER BY created_at,id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch();
                if(!$job){$this->db->commit();break;}
                $payload=json_decode($job['payload'],true,64,JSON_THROW_ON_ERROR);
                $recipients=$payload['recipient_user_ids']??(isset($payload['recipient_user_id'])?[$payload['recipient_user_id']]:[]);
                if(Sql::run($this->db,'SELECT visibility FROM ticket_events WHERE id=?',[$job['event_id']])->fetchColumn()!=='internal'){$recipients=[];}
                foreach(array_unique($recipients)as$actor){
                    try{(new TicketReader($this->db))->detail($actor,$payload['ticket_id']);}
                    catch(ApiException $error){if(!in_array($error->status,[403,404],true)){throw$error;}try{(new \App\Tickets\Participants($this->db))->summary($actor,$payload['ticket_id']);}catch(ApiException){continue;}}
                    Sql::run($this->db,'INSERT INTO user_notifications(event_id,user_id,ticket_id) VALUES (?,?,?) ON CONFLICT(event_id,user_id) DO NOTHING',[$job['event_id'],$actor,$payload['ticket_id']]);
                }
                Sql::run($this->db,"UPDATE notification_outbox SET status='delivered',attempts=attempts+1,delivered_at=now(),last_error=null WHERE id=?",[$job['id']]);
                $this->db->commit();$count++;
            }catch(Throwable $error){
                if($this->db->inTransaction()){$this->db->rollBack();}
                if(isset($job['id'])){Sql::run($this->db,"UPDATE notification_outbox SET attempts=attempts+1,status=CASE WHEN attempts>=4 THEN 'dead_letter' ELSE 'failed' END,available_at=now()+interval '1 minute',last_error=? WHERE id=?",[$error::class,$job['id']]);}
                else{throw$error;}
            }
        }
        return $count;
    }

    public function timers():int
    {
        $rows=Sql::run($this->db,"SELECT e.id,e.ticket_id,t.requester_id FROM workflow_executions e JOIN tickets t ON t.id=e.ticket_id WHERE e.status='waiting' AND e.node_type='timer' AND e.due_at<=now() ORDER BY e.due_at LIMIT 10")->fetchAll();
        $count=0;
        foreach($rows as$row){
            $this->db->beginTransaction();
            try{
                Sql::run($this->db,'SELECT id FROM tickets WHERE id=? FOR UPDATE',[$row['ticket_id']]);
                $execution=Sql::run($this->db,"SELECT * FROM workflow_executions WHERE id=? AND status='waiting' FOR UPDATE",[$row['id']])->fetch();
                if($execution){(new Runtime($this->db,$row['ticket_id'],'system:timer','timer-worker'))->finish($execution,'elapsed');$count++;}
                $this->db->commit();
            }catch(Throwable $error){if($this->db->inTransaction()){$this->db->rollBack();}throw$error;}
        }
        return $count;
    }

    public function escalate():int
    {
        $rows=Sql::run($this->db,"SELECT ti.id,ti.ticket_id,t.requester_id FROM task_instances ti JOIN tickets t ON t.id=ti.ticket_id
            WHERE ti.status IN ('offered','claimed','in_progress','blocked') AND ti.due_at<now()
            AND NOT EXISTS(SELECT 1 FROM ticket_events e WHERE e.task_id=ti.id AND e.event_type='SLA_BREACHED') ORDER BY ti.due_at LIMIT 10")->fetchAll();$count=0;
        foreach($rows as$row){$this->db->beginTransaction();try{
            $ticket=Sql::run($this->db,'SELECT * FROM tickets WHERE id=? FOR UPDATE',[$row['ticket_id']])->fetch();
            $task=Sql::run($this->db,'SELECT * FROM task_instances WHERE id=? FOR UPDATE',[$row['id']])->fetch();
            if(in_array($task['status'],['offered','claimed','in_progress','blocked'],true)&&!Sql::run($this->db,"SELECT 1 FROM ticket_events WHERE task_id=? AND event_type='SLA_BREACHED'",[$row['id']])->fetchColumn()){
                Events::record($this->db,$ticket,$row['id'],'system:sla','SLA_BREACHED',null,null,[],'Business deadline exceeded','sla-worker',$task['claimed_by']?[$task['claimed_by']]:[]);$count++;
            }$this->db->commit();
        }catch(Throwable $error){if($this->db->inTransaction()){$this->db->rollBack();}throw$error;}}
        return$count;
    }
}
