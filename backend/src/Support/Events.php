<?php
declare(strict_types=1);
namespace App\Support;

use PDO;

final class Events
{
    public static function record(PDO $db,array $ticket,?string $task,string $actor,string $type,?string $from,?string $to,array $data=[],?string $reason=null,?string $requestId=null,array $recipients=[],string $visibility='internal'): int
    {
        $system=str_starts_with($actor,'system:')?substr($actor,7):null;
        $id = (int)Sql::run($db,'INSERT INTO ticket_events(ticket_id,task_id,event_type,actor_user_id,actor_system,from_state,to_state,event_data,reason,workflow_version_id,request_id,visibility)
            VALUES (?,?,?,?,?,?,?,CAST(? AS jsonb),?,?,?,?) RETURNING id',[$ticket['id'],$task,$type,$system?null:$actor,$system,$from,$to,json_encode((object)$data,JSON_THROW_ON_ERROR),$reason,$ticket['workflow_version_id'],$requestId,$visibility])->fetchColumn();
        $recipients = array_values(array_unique([$ticket['requester_id'],...($system?[]:[$actor]),...$recipients]));
        Sql::run($db,"INSERT INTO notification_outbox(event_id,kind,dedupe_key,payload) VALUES (?,'ticket_event',?,CAST(? AS jsonb))",
            [$id,'event-'.$id,json_encode(['ticket_id'=>$ticket['id'],'recipient_user_ids'=>$recipients],JSON_THROW_ON_ERROR)]);
        return $id;
    }
}
