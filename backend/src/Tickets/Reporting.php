<?php
declare(strict_types=1);
namespace App\Tickets;

use App\Http\ApiException;
use App\Http\Response;
use App\Security\Access;
use App\Security\TicketScope;
use App\Support\Sql;
use PDO;

final class Reporting
{
    public function __construct(private PDO $db) {}

    public function dashboard(string $actor,array $query):array
    {
        if(array_diff(array_keys($query),['scope','id'])||array_filter($query,static fn($v)=>!is_string($v))||!in_array($query['scope']??'personal',['personal','authorized','station','province','department','national'],true)) {throw new ApiException(422,'invalid_scope','Select a valid dashboard scope.');}
        $scope=$query['scope']??'personal';$filter='';$params=['actor'=>$actor];
        if($scope==='personal'){$filter=' AND t.requester_id=CAST(:requester AS uuid)';$params['requester']=$actor;}
        if($scope==='national'){Access::national($this->db,$actor,'ticket.read');}
        if(in_array($scope,['station','province','department'],true)){
            if(!TicketReader::uuid($query['id']??'')){throw new ApiException(422,'invalid_scope','Scope UUID required.');}
            $column=match($scope){'station'=>'origin_station_id','province'=>'origin_province_id','department'=>'responsible_department_id'};
            $filter=" AND t.$column=CAST(:scope_id AS uuid)";$params['scope_id']=$query['id'];
        }
        $rows=Sql::run($this->db,'SELECT t.status,count(*)::integer AS count FROM tickets t WHERE '.TicketScope::predicate().$filter.' GROUP BY t.status',$params)->fetchAll();
        $sla=Sql::run($this->db,"SELECT count(DISTINCT t.id)::integer FROM tickets t JOIN task_instances ti ON ti.ticket_id=t.id WHERE ti.status IN ('offered','claimed','in_progress','blocked') AND ti.due_at<now() AND ".TicketScope::predicate().$filter,$params)->fetchColumn();
        return ['scope'=>$scope,'counts'=>$rows,'overdue'=>(int)$sla,'total'=>array_sum(array_column($rows,'count'))];
    }

    public function notifications(string $actor,array $query):array
    {
        if(array_diff(array_keys($query),['before'])||(isset($query['before'])&&(!is_string($query['before'])||!preg_match('/^[0-9]{1,18}$/D',$query['before'])))){throw new ApiException(422,'invalid_cursor','Invalid notification cursor.');}
        $rows=Sql::run($this->db,'SELECT n.id,n.ticket_id,n.created_at,n.read_at,e.event_type FROM user_notifications n JOIN ticket_events e ON e.id=n.event_id JOIN tickets t ON t.id=n.ticket_id
            WHERE n.user_id=CAST(:viewer AS uuid) AND n.id<CAST(:before AS bigint) AND '.TicketScope::predicate().' ORDER BY n.id DESC LIMIT 11',
            ['viewer'=>$actor,'actor'=>$actor,'before'=>$query['before']??'9223372036854775807'])->fetchAll();
        $more=count($rows)>10;if($more){array_pop($rows);}$last=end($rows);return['data'=>$rows,'meta'=>['next_before'=>$more?(string)$last['id']:null]];
    }

    public function export(string $actor,array $query):Response
    {
        if(array_diff(array_keys($query),['status'])||(isset($query['status'])&&(!is_string($query['status'])||strlen($query['status'])>30))){throw new ApiException(422,'invalid_filter','Export supports a status filter.');}
        if(!Sql::run($this->db,"SELECT 1 FROM app_users actor WHERE actor.id=? AND actor.active AND ".Access::roleSql('report.export')." AND EXISTS(SELECT 1 FROM scope_grants g WHERE g.user_id=actor.id AND g.permission_code='report.export' AND g.revoked_at IS NULL AND g.valid_from<=now() AND (g.valid_to IS NULL OR g.valid_to>now()))",[$actor])->fetchColumn()){throw new ApiException(403,'permission_denied','An explicit active export permission is required.');}
        $sql='SELECT t.reference,t.subject,t.status,t.priority,t.created_at FROM tickets t JOIN app_users actor ON actor.id=CAST(:export_actor AS uuid) WHERE '
            .TicketScope::predicate().' AND '.Access::roleSql('report.export').' AND '.Access::grantSql('report.export');
        $params=['export_actor'=>$actor,'actor'=>$actor];if(isset($query['status'])){$sql.=' AND t.status=:status';$params['status']=$query['status'];}
        $rows=Sql::run($this->db,$sql.' ORDER BY t.created_at DESC,t.id LIMIT 1001',$params)->fetchAll();
        if(count($rows)>1000){throw new ApiException(422,'export_limit','Refine the filter to at most 1000 tickets.');}
        $stream=fopen('php://temp','w+');fputcsv($stream,['Reference','Subject','Status','Priority','Created UTC'],',','"','');
        foreach($rows as$row){foreach($row as&$cell){if(is_string($cell)&&preg_match('/^[\s]*[=+@-]/u',$cell)){$cell="'".$cell;}}unset($cell);fputcsv($stream,array_values($row),',','"','');}
        rewind($stream);$csv=stream_get_contents($stream);fclose($stream);
        Sql::run($this->db,"INSERT INTO security_events(event_type,actor_user_id,reason) VALUES ('REPORT_EXPORTED',?,?)",[$actor,'Authorized export rows: '.count($rows)]);
        return new Response($csv,200,['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="jsc-tickets.csv"']);
    }
}
