<?php
declare(strict_types=1);
namespace App\Tickets;

use App\Http\ApiException;
use App\Security\Access;
use App\Support\Mutation;
use App\Support\Sql;
use App\Support\Events;
use PDO;

final class Collaboration
{
    public function __construct(private PDO $db) {}

    private function participant(string $actor,string $ticket):bool
    {
        return (bool)Sql::run($this->db,"SELECT 1 FROM tickets WHERE id=? AND (requester_id=CAST(? AS uuid)
            OR EXISTS(SELECT 1 FROM task_instances ti WHERE ti.ticket_id=tickets.id AND ti.claimed_by=CAST(? AS uuid))
            OR EXISTS(SELECT 1 FROM ticket_participants tp WHERE tp.ticket_id=tickets.id AND tp.user_id=CAST(? AS uuid) AND tp.visibility IN ('ticket_details','restricted_details')))",[$ticket,$actor,$actor,$actor])->fetchColumn();
    }

    public function comments(string $actor,string $ticket,array $query):array
    {
        (new TicketReader($this->db))->detail($actor,$ticket);
        if(array_diff(array_keys($query),['after'])||(isset($query['after'])&&(!is_string($query['after'])||!preg_match('/^[0-9]{1,18}$/D',$query['after'])))){throw new ApiException(422,'invalid_cursor','Invalid comment cursor.');}
        $participant=$this->participant($actor,$ticket);$sensitive=false;
        try{Access::ticket($this->db,$actor,'ticket.read_sensitive',$ticket);$sensitive=true;}catch(ApiException){}
        $rows=Sql::run($this->db,"SELECT c.id,c.actor_user_id,u.display_name,c.body,c.sensitivity,c.created_at FROM ticket_comments c JOIN app_users u ON u.id=c.actor_user_id
            WHERE c.ticket_id=? AND c.id>CAST(? AS bigint) AND (c.sensitivity='public_internal'
                OR (c.sensitivity='participants_only' AND CAST(? AS boolean)) OR (c.sensitivity='restricted' AND CAST(? AS boolean))) ORDER BY c.id LIMIT 11",
            [$ticket,$query['after']??'0',$participant?'true':'false',$sensitive?'true':'false'])->fetchAll();
        $more=count($rows)>10;if($more){array_pop($rows);}$last=end($rows);
        return ['data'=>$rows,'meta'=>['next_after'=>$more?(string)$last['id']:null]];
    }

    public function comment(string $actor,string $ticket,int $version,string $key,array $body,string $requestId,bool $reply=false):array
    {
        if(array_diff(array_keys($body),['body','sensitivity'])||!is_string($body['body']??null)||trim($body['body'])===''||IntakeSchema::length($body['body'])>10000||str_contains($body['body'],"\0")
            ||!in_array($body['sensitivity']??'public_internal',['public_internal','participants_only','restricted'],true)){throw new ApiException(422,'invalid_comment','A comment of 1..10000 characters with valid sensitivity is required.');}
        return Mutation::run($this->db,$actor,($reply?'ticket.reply:':'ticket.comment:').$ticket,$key,['version'=>$version,...$body],function()use($actor,$ticket,$version,$body,$requestId,$reply):array{
            (new TicketReader($this->db))->detail($actor,$ticket);
            $row=Sql::run($this->db,'SELECT * FROM tickets WHERE id=? FOR UPDATE',[$ticket])->fetch();Mutation::version((int)$row['version'],$version);
            $sensitivity=$body['sensitivity']??'public_internal';
            if($row['requester_id']!==$actor){Access::ticket($this->db,$actor,'ticket.comment',$ticket);}
            if($sensitivity==='restricted'){Access::ticket($this->db,$actor,'ticket.read_sensitive',$ticket);}
            if($sensitivity==='participants_only'&&!$this->participant($actor,$ticket)){throw new ApiException(403,'participant_required','Participant access required.');}
            if($reply&&($row['requester_id']!==$actor||$row['status']!=='awaiting_input')){throw new ApiException(409,'reply_unavailable','This request is not awaiting your reply.');}
            if(in_array($row['status'],['closed','withdrawn'],true)){throw new ApiException(409,'ticket_closed','Reopen the ticket before commenting.');}
            $comment=Sql::run($this->db,'INSERT INTO ticket_comments(ticket_id,actor_user_id,body,sensitivity) VALUES (?,?,?,?) RETURNING id,created_at',[$ticket,$actor,trim($body['body']),$sensitivity])->fetch();
            Events::record($this->db,$row,null,$actor,$reply?'REQUESTER_REPLIED':'COMMENT_ADDED',null,null,['comment_id'=>$comment['id']],null,$requestId,[],$sensitivity==='public_internal'?'internal':$sensitivity);
            Sql::run($this->db,'UPDATE tickets SET version=version+1,updated_at=now() WHERE id=?',[$ticket]);
            if($reply){
                $executions=Sql::run($this->db,"SELECT * FROM workflow_executions WHERE ticket_id=? AND node_type='request_info' AND status='waiting'",[$ticket])->fetchAll();
                $runtime=new \App\Workflow\Runtime($this->db,$ticket,$actor,$requestId);foreach($executions as$execution){$runtime->finish($execution,'replied');}
            }
            return ['ticket_id'=>$ticket,'comment_id'=>$comment['id'],'version'=>(int)Sql::run($this->db,'SELECT version FROM tickets WHERE id=?',[$ticket])->fetchColumn()];
        },function(array $result)use($actor):void{(new TicketReader($this->db))->detail($actor,$result['ticket_id']);});
    }

    public function progress(string $actor,string $ticket,array$query=[]):array
    {
        $detail=(new TicketReader($this->db))->detail($actor,$ticket);
        if(array_diff(array_keys($query),['run'])||(isset($query['run'])&&(!is_string($query['run'])||!preg_match('/^[1-9][0-9]{0,5}$/D',$query['run'])))){throw new ApiException(422,'invalid_run','Invalid workflow run.');}
        $rows=Sql::run($this->db,'SELECT e.*,ti.id AS task_id,ti.status AS task_status,ti.claimed_by,ti.due_at AS task_due_at,
            d.name AS department_name,p.title AS position_title,u.display_name AS claimant
            FROM workflow_executions e LEFT JOIN task_instances ti ON ti.execution_id=e.id LEFT JOIN departments d ON d.id=ti.assigned_department_id
            LEFT JOIN positions p ON p.id=ti.assigned_position_id LEFT JOIN app_users u ON u.id=ti.claimed_by WHERE e.ticket_id=? AND e.run_number=? ORDER BY e.sequence_no LIMIT 200',[$ticket,$query['run']??$detail['run_number']])->fetchAll();
        foreach($rows as&$row){$row['context']=json_decode($row['context'],false,64,JSON_THROW_ON_ERROR);}
        return $rows;
    }
}
