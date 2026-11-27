<?php
declare(strict_types=1);
namespace App\Tickets;
use App\Security\Access;
use App\Security\TicketScope;
use App\Support\Sql;
use App\Http\ApiException;
use PDO;

final class Analytics
{
    public function __construct(private PDO$db){}
    public function dashboard(string$actor,array$query):array
    {
        if(!Sql::run($this->db,'SELECT 1 FROM app_users actor WHERE actor.id=? AND actor.active AND '.Access::roleSql('report.analytics')." AND EXISTS(SELECT 1 FROM scope_grants g WHERE g.user_id=actor.id AND g.permission_code='report.analytics' AND g.revoked_at IS NULL AND g.valid_from<=now() AND (g.valid_to IS NULL OR g.valid_to>now()))",[$actor])->fetchColumn())throw new ApiException(403,'analytics_denied','An explicit analytics role and scope are required.');
        [$filter,$params]=Filters::build($query,$actor);$params=['actor'=>$actor,'analytics_actor'=>$actor,...$params];
        $base=' FROM tickets t JOIN app_users analyst ON analyst.id=CAST(:analytics_actor AS uuid) WHERE '.TicketScope::predicate().' AND '.Access::roleSql('report.analytics','analyst').' AND '.Access::grantSql('report.analytics','analyst').$filter;
        $charts=[];
        $groups=['status'=>['t.status','Ticket status'],'priority'=>['t.priority','Priority'],
            'departments'=>["COALESCE((SELECT name FROM departments d WHERE d.id=t.responsible_department_id),'Unassigned')",'Responsible departments'],
            'stations'=>["COALESCE((SELECT name FROM stations s WHERE s.id=t.origin_station_id),'Unspecified')",'Origin stations'],
            'provinces'=>["COALESCE((SELECT name FROM provinces p WHERE p.id=t.origin_province_id),'Unspecified')",'Origin provinces'],
            'categories'=>["(SELECT name FROM categories c WHERE c.id=t.category_id)",'Service categories'],
            'ageing'=>["CASE WHEN t.status IN ('closed','withdrawn') THEN 'Finished' WHEN t.created_at>now()-interval '1 day' THEN 'Under 1 day' WHEN t.created_at>now()-interval '3 days' THEN '1-3 days' WHEN t.created_at>now()-interval '7 days' THEN '3-7 days' ELSE 'Over 7 days' END",'Backlog age'],
            'resolution'=>["COALESCE(t.resolution,'Open')",'Resolution outcomes'],
            'sla'=>["CASE WHEN EXISTS(SELECT 1 FROM task_instances ti WHERE ti.ticket_id=t.id AND ti.status IN ('offered','claimed','in_progress','blocked') AND ti.due_at<now()) THEN 'Overdue' ELSE 'No current breach' END",'Current SLA exposure'],
            'escalations'=>["CASE WHEN EXISTS(SELECT 1 FROM ticket_events e WHERE e.ticket_id=t.id AND e.event_type='WORKFLOW_ESCALATED') THEN 'Escalated' ELSE 'Not escalated' END",'Escalation incidence'],
            'interventions'=>["CASE WHEN EXISTS(SELECT 1 FROM ticket_events e WHERE e.ticket_id=t.id AND e.event_type='TASK_TAKEN_OVER') THEN 'Senior intervention' ELSE 'Normal handling' END",'Senior interventions'],
            'reopened'=>["CASE WHEN t.run_number>1 THEN 'Reopened' ELSE 'First run' END",'Reopened work']];
        foreach($groups as$key=>[$expression,$title]){$charts[]=['key'=>$key,'title'=>$title,'unit'=>'tickets','rows'=>Sql::run($this->db,'SELECT '.$expression.' AS label,count(*)::integer AS value'.$base.' GROUP BY 1 ORDER BY value DESC,label LIMIT 10',$params)->fetchAll(),'note'=>'Top 10 groups; counts use current read and analytics scopes.'];}
        $charts[]=['key'=>'monthly','title'=>'Requests in the last 12 months','unit'=>'tickets','rows'=>Sql::run($this->db,"SELECT to_char(t.created_at,'YYYY-MM') AS label,count(*)::integer AS value".$base." AND t.created_at>=date_trunc('month',now())-interval '11 months' GROUP BY 1 ORDER BY 1",$params)->fetchAll()];
        $charts[]=['key'=>'resolution_time','title'=>'Average completion time by category','unit'=>'hours','rows'=>Sql::run($this->db,"SELECT (SELECT name FROM categories c WHERE c.id=t.category_id) AS label,round(avg(extract(epoch FROM (t.closed_at-t.created_at))/3600)::numeric,1) AS value".$base." AND t.status='closed' AND t.closed_at IS NOT NULL GROUP BY 1 ORDER BY value DESC LIMIT 10",$params)->fetchAll(),'note'=>'Calendar hours; current completed runs in the selected scope.'];
        foreach(['owners'=>["COALESCE((SELECT u.display_name FROM task_instances ti LEFT JOIN app_users u ON u.id=ti.claimed_by WHERE ti.ticket_id=t.id AND ti.status IN ('offered','claimed','in_progress','blocked') ORDER BY ti.started_at,ti.id LIMIT 1),'Unassigned')",'Current primary owner'], 'stages'=>["COALESCE((SELECT e.label FROM task_instances ti JOIN workflow_executions e ON e.id=ti.execution_id WHERE ti.ticket_id=t.id AND ti.status IN ('offered','claimed','in_progress','blocked') ORDER BY ti.started_at,ti.id LIMIT 1),'Manual triage')",'Current primary stage']]as$key=>[$expression,$title]){$charts[]=['key'=>$key,'title'=>$title,'unit'=>'active tickets','rows'=>Sql::run($this->db,'SELECT '.$expression.' AS label,count(*)::integer AS value'.$base." AND t.status NOT IN ('closed','withdrawn') GROUP BY 1 ORDER BY value DESC,label LIMIT 10",$params)->fetchAll(),'note'=>'Top 10 groups; parallel work is counted once using the earliest active task.'];}
        $charts[]=['key'=>'monthly_closed','title'=>'Closures in the last 12 months','unit'=>'tickets','rows'=>Sql::run($this->db,"SELECT to_char(t.closed_at,'YYYY-MM') AS label,count(*)::integer AS value".$base." AND t.status='closed' AND t.closed_at>=date_trunc('month',now())-interval '11 months' GROUP BY 1 ORDER BY 1",$params)->fetchAll()];
        $charts[]=['key'=>'weekly','title'=>'Requests in the last four weeks','unit'=>'tickets','rows'=>Sql::run($this->db,"SELECT to_char(date_trunc('week',t.created_at),'YYYY-MM-DD') AS label,count(*)::integer AS value".$base." AND t.created_at>=date_trunc('week',now())-interval '3 weeks' GROUP BY 1 ORDER BY 1",$params)->fetchAll()];
        return['total'=>(int)Sql::run($this->db,'SELECT count(*)'.$base,$params)->fetchColumn(),'charts'=>$charts,'generated_at'=>gmdate('c')];
    }
}
