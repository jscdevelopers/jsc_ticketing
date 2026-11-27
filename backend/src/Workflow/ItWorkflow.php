<?php
declare(strict_types=1);
namespace App\Workflow;

/** Editable starter definition; UUIDs come from the application's own directory. */
final class ItWorkflow
{
    public static function definition(string$department,array$positions):array
    {
        $specialists=['systems_engineer'=>'Systems Engineer','systems_analyst'=>'Systems Analyst','hardware_specialist'=>'Hardware Specialist','database_specialist'=>'Database Specialist'];
        $leaders=[['position_ids'=>[$positions['principal_it']],'scope'=>'province'],['position_ids'=>[$positions['head_it'],$positions['deputy_it'],$positions['principal_help_desk'],...array_map(fn($key)=>$positions[$key],array_keys($specialists))],'scope'=>'department']];
        $task=static fn(string$id,string$label,string$position,string$scope,array$extra=[])=>['id'=>$id,'type'=>'claim_pool','label'=>$label,'config'=>['department_id'=>$department,'position_id'=>$positions[$position],'scope'=>$scope,'sla_minutes'=>240,'complete_label'=>'Resolve issue',...$extra]];
        $nodes=[['id'=>'start','type'=>'start','label'=>'Computer fault reported'],
            $task('station_it','Station IT officers','it_officer','station',['assignment_mode'=>'automatic','escalate_to'=>'provincial_it','intervention_rules'=>$leaders]),
            $task('provincial_it','Principal IT officer - province','principal_it','province',['escalate_to'=>'help_desk','intervention_rules'=>[$leaders[1]]]),
            $task('help_desk','Principal help desk','principal_help_desk','department',['escalate_to'=>'specialist_router','complete_label'=>'Route to specialist','intervention_rules'=>[$leaders[1]]]),
            ['id'=>'specialist_router','type'=>'decision','label'=>'Match issue category'],
            $task('manual_dispatch','Help desk - choose specialist','principal_help_desk','department',['redirect_only'=>true,'redirect_targets'=>array_keys($specialists),'intervention_rules'=>[$leaders[1]]]),
            ['id'=>'end','type'=>'end','label'=>'Issue resolved']];
        $edges=[['from'=>'start','to'=>'station_it']];
        foreach(['station_it'=>'provincial_it','provincial_it'=>'help_desk']as$from=>$next){$edges[]=['from'=>$from,'to'=>'end','when'=>['eq'=>[['var'=>'step.outcome'],'complete']]];$edges[]=['from'=>$from,'to'=>$next,'otherwise'=>true];}
        $edges[]=['from'=>'help_desk','to'=>'specialist_router'];
        foreach($specialists as$key=>$label){$nodes[]=$task($key,$label,$key,'department',['intervention_rules'=>[['position_ids'=>[$positions['head_it'],$positions['deputy_it']],'scope'=>'department']]]);
            $edges[]=['from'=>'specialist_router','to'=>$key,'when'=>['eq'=>[['var'=>'fields.issue_area'],$key]]];
            $edges[]=['from'=>'manual_dispatch','to'=>$key,'when'=>['eq'=>[['var'=>'step.outcome'],'redirect:'.$key]]];
            $edges[]=['from'=>$key,'to'=>'end'];
        }
        $edges[]=['from'=>'specialist_router','to'=>'manual_dispatch','otherwise'=>true];$edges[]=['from'=>'manual_dispatch','to'=>'end','otherwise'=>true];
        return['engine'=>'workflow-v1','department_id'=>$department,'category_name'=>'Computer faults and IT support','require_second_approver'=>true,
            'form_schema'=>['type'=>'object','additionalProperties'=>false,'required'=>['asset_tag','issue_area'],'properties'=>[
                'asset_tag'=>['type'=>'string','title'=>'Computer / asset reference','minLength'=>2,'maxLength'=>60],
                'issue_area'=>['type'=>'string','title'=>'Issue category','enum'=>[...array_keys($specialists),'other']]]],
            'nodes'=>$nodes,'edges'=>$edges];
    }
}
