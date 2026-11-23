<?php
declare(strict_types=1);
namespace App\Workflow;

use App\Support\Sql;
use App\Support\Mutation;
use App\Security\Access;
use App\Http\ApiException;
use App\Tickets\TicketReader;
use PDO;

final class WorkflowService
{
    public function __construct(private PDO $db) {}

    private function category(string $actor,string $id,string $permission):array
    {
        if(!TicketReader::uuid($id)){throw new ApiException(404,'category_unavailable','Category unavailable.');}
        $row=Sql::run($this->db,'SELECT * FROM categories WHERE id=? AND active',[$id])->fetch();
        if(!$row){throw new ApiException(404,'category_unavailable','Category unavailable.');}
        Access::department($this->db,$actor,$permission,$row['department_id']);return $row;
    }

    public function listing(string $actor,array $query):array
    {
        if(array_diff(array_keys($query),['q','after']) || array_filter($query,static fn($v)=>!is_string($v)) || strlen($query['q']??'')>200 || (isset($query['after'])&&!TicketReader::uuid($query['after']))) {throw new ApiException(422,'invalid_filter','Invalid workflow filter.');}
        $sql='SELECT w.*,c.name AS category_name,c.department_id AS category_department_id FROM workflow_drafts w JOIN categories c ON c.id=w.category_id JOIN app_users actor ON actor.id=CAST(:actor AS uuid)
            WHERE actor.active AND '.self::viewSql('c.department_id');
        $params=['actor'=>$actor];
        if(isset($query['after'])){$sql.=' AND w.id>CAST(:after AS uuid)';$params['after']=$query['after'];}
        if(($query['q']??'')!==''){$sql.=" AND c.name ILIKE :search ESCAPE '!'";$params['search']='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query['q']).'%';}
        $rows=Sql::run($this->db,$sql.' ORDER BY w.id LIMIT 11',$params)->fetchAll();$more=count($rows)>10;if($more){array_pop($rows);}$last=end($rows);
        foreach($rows as &$row){$row['can_design']=true;try{Access::department($this->db,$actor,'workflow.design',$row['category_department_id']);}catch(ApiException){$row['can_design']=false;}$row['definition']=json_decode($row['definition'],false,64,JSON_THROW_ON_ERROR);}
        return ['data'=>$rows,'meta'=>['next_after'=>$more?$last['id']:null]];
    }

    public function choices(string $actor,string $resource,array $query):array
    {
        if(!in_array($resource,['categories','departments','positions','stations','provinces'],true)||array_diff(array_keys($query),['q','after','id'])||array_filter($query,static fn($v)=>!is_string($v))||(isset($query['id'])&&!TicketReader::uuid($query['id']))||strlen($query['q']??'')>200||(isset($query['after'])&&!TicketReader::uuid($query['after']))){throw new ApiException(422,'invalid_filter','Invalid workflow choice filter.');}
        $label=$resource==='positions'?'title':'name';
        $sql="SELECT c.* FROM $resource c JOIN app_users actor ON actor.id=CAST(:actor AS uuid) WHERE actor.active AND c.active AND ".self::viewSql($resource==='categories'?'c.department_id':null);
        $params=['actor'=>$actor];if(isset($query['id'])){$sql.=' AND c.id=CAST(:choice_id AS uuid)';$params['choice_id']=$query['id'];}if(isset($query['after'])){$sql.=' AND c.id>CAST(:after AS uuid)';$params['after']=$query['after'];}
        if(($query['q']??'')!==''){$sql.=" AND c.$label ILIKE :q ESCAPE '!'";$params['q']='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query['q']).'%';}
        $rows=Sql::run($this->db,$sql.' ORDER BY c.id LIMIT 11',$params)->fetchAll();$more=count($rows)>10;if($more){array_pop($rows);}$last=end($rows);return['data'=>$rows,'meta'=>['next_after'=>$more?$last['id']:null]];
    }

    private static function viewSql(?string$department):string
    {
        return "EXISTS(SELECT 1 FROM user_roles vr JOIN role_permissions vp ON vp.role_id=vr.role_id JOIN scope_grants vg ON vg.user_id=vr.user_id AND vg.permission_code=vp.permission_code
            WHERE vr.user_id=actor.id AND vp.permission_code IN ('workflow.design','workflow.publish') AND vr.valid_from<=now() AND (vr.valid_to IS NULL OR vr.valid_to>now())
            AND vg.revoked_at IS NULL AND vg.valid_from<=now() AND (vg.valid_to IS NULL OR vg.valid_to>now()) AND ".($department===null?"vg.scope_kind IN ('national','department')":"(vg.scope_kind='national' OR (vg.scope_kind='department' AND vg.scope_ref_id=$department))").')';
    }
    private function reviewDraft(string$actor,string$id):array
    {
        try{return$this->draft($actor,$id,'workflow.design');}catch(ApiException$error){if($error->status!==403)throw$error;return$this->draft($actor,$id,'workflow.publish');}
    }
    public function review(string$actor,string$id):array
    {
        $row=$this->reviewDraft($actor,$id);$definition=json_decode($row['definition'],true,64,JSON_THROW_ON_ERROR);
        $published=Sql::run($this->db,"SELECT v.version,v.definition FROM workflow_templates t JOIN workflow_versions v ON v.id=t.active_version_id WHERE t.category_id=?",[$row['category_id']])->fetch();
        $before=$published?json_decode($published['definition'],true,64,JSON_THROW_ON_ERROR):[];
        $old=array_column($before['nodes']??[],null,'id');$new=array_column($definition['nodes']??[],null,'id');$changes=[];
        foreach($new as$key=>$node){if(!isset($old[$key])||Mutation::canonical($old[$key])!==Mutation::canonical($node))$changes[]=['id'=>$key,'label'=>$node['label'],'change'=>isset($old[$key])?'changed':'added'];}
        foreach($old as$key=>$node){if(!isset($new[$key]))$changes[]=['id'=>$key,'label'=>$node['label'],'change'=>'removed'];}
        return['base_version'=>$published?(int)$published['version']:null,'node_changes'=>$changes,'form_changed'=>($before['form_schema']??null)!==($definition['form_schema']??null),'routing_changed'=>($before['edges']??[])!==($definition['edges']??[]),'policies_changed'=>array_diff_key($before,array_flip(['nodes','edges','form_schema']))!==array_diff_key($definition,array_flip(['nodes','edges','form_schema']))];
    }

    public function save(string $actor,?string $id,int $version,string $key,array $body):array
    {
        if(array_diff(array_keys($body),['category_id','definition']) || !is_string($body['category_id']??null) || !is_array($body['definition']??null) || strlen(json_encode($body,JSON_THROW_ON_ERROR))>60000){throw new ApiException(422,'invalid_workflow','Category and bounded definition required.');}
        $category=$this->category($actor,$body['category_id'],'workflow.design');
        if($id!==null&&!TicketReader::uuid($id)){throw new ApiException(404,'draft_unavailable','Draft unavailable.');}
        return Mutation::run($this->db,$actor,'workflow.save:'.($id??'new'),$key,['version'=>$version,...$body],function()use($actor,$category,$id,$version,$body):array {
            $definition=json_encode($body['definition'],JSON_THROW_ON_ERROR);
            if($id===null){$row=Sql::run($this->db,'INSERT INTO workflow_drafts(category_id,definition,created_by) VALUES (?,CAST(? AS jsonb),?) RETURNING *',[$category['id'],$definition,$actor])->fetch();}
            else{
                $old=Sql::run($this->db,'SELECT * FROM workflow_drafts WHERE id=? FOR UPDATE',[$id])->fetch();
                if(!$old||$old['category_id']!==$category['id']){throw new ApiException(404,'draft_unavailable','Draft unavailable.');}
                if($old['status']!=='draft'){throw new ApiException(409,'immutable_workflow','Create a new draft from the published version.');}
                Mutation::version((int)$old['version'],$version);
                $row=Sql::run($this->db,'UPDATE workflow_drafts SET definition=CAST(? AS jsonb),version=version+1,updated_at=now(),approved_by=null WHERE id=? RETURNING *',[$definition,$id])->fetch();
            }
            Sql::run($this->db,"INSERT INTO security_events(event_type,actor_user_id,subject_id,reason) VALUES ('WORKFLOW_DRAFT_SAVED',?,?,'Workflow draft saved')",[$actor,$row['id']]);
            $row['definition']=json_decode($row['definition'],false,64,JSON_THROW_ON_ERROR);return $row;
        },function(array $result)use($actor):void{$this->category($actor,$result['category_id'],'workflow.design');});
    }

    public function simulate(string $actor,string $id,array $values):array
    {
        $row=$this->reviewDraft($actor,$id);$definition=json_decode($row['definition'],true,64,JSON_THROW_ON_ERROR);
        try{$graph=new Graph($definition);$this->validateDirectory($graph);}
        catch(\InvalidArgumentException $error){throw new ApiException(422,'invalid_workflow',$error->getMessage());}
        if(count($values)>40||array_filter($values,static fn($v)=>!is_scalar($v)&&$v!==null)){throw new ApiException(422,'invalid_context','Simulation requires scalar captured values.');}
        $trace=[];$walk=function(string $node,int $depth=0)use(&$walk,&$trace,$graph,$values):void{
            if($depth>100||count($trace)>200){throw new ApiException(422,'simulation_limit','Simulation exceeded graph bound.');}
            $trace[]=['id'=>$node,'type'=>$graph->nodes[$node]['type'],'label'=>$graph->nodes[$node]['label']];
            foreach($graph->next($node,$values)as$next){$walk($next,$depth+1);}
        };
        $walk($graph->start);return ['valid'=>true,'trace'=>$trace];
    }

    public function publish(string $actor,string $id,int $version,string $key,string $reason):array
    {
        $reason=Mutation::reason($reason);$this->draft($actor,$id,'workflow.publish');
        return Mutation::run($this->db,$actor,'workflow.publish:'.$id,$key,['version'=>$version,'reason'=>$reason],function()use($actor,$id,$version,$reason):array{
            $draft=Sql::run($this->db,'SELECT * FROM workflow_drafts WHERE id=? FOR UPDATE',[$id])->fetch();Mutation::version((int)$draft['version'],$version);
            if($draft['status']!=='draft'){throw new ApiException(409,'immutable_workflow','Draft has already been published.');}
            $category=$this->category($actor,$draft['category_id'],'workflow.publish');$definition=json_decode($draft['definition'],true,64,JSON_THROW_ON_ERROR);
            try{$graph=new Graph($definition);$this->validateDirectory($graph);BusinessClock::due(new \DateTimeImmutable(),1,$definition['calendar']??[]);}
            catch(\InvalidArgumentException $error){throw new ApiException(422,'invalid_workflow',$error->getMessage());}
            if($definition['department_id']!==$category['department_id']){throw new ApiException(422,'invalid_workflow','Workflow owner must match category owner.');}
            $national=(bool)array_filter($graph->nodes,static fn($node)=>($node['config']['scope']??'')==='national');
            if((($definition['require_second_approver']??true)!==false||$national)&&$draft['created_by']===$actor){throw new ApiException(403,'second_approver_required','A different authorized publisher must approve this draft.');}
            Sql::run($this->db,'SELECT id FROM categories WHERE id=? FOR UPDATE',[$category['id']]);
            $templates=Sql::run($this->db,'SELECT * FROM workflow_templates WHERE category_id=? FOR UPDATE',[$category['id']])->fetchAll();
            if(count($templates)>1){throw new ApiException(422,'invalid_workflow','Category requires exactly one configured template.');}
            $definition['category_code']=$category['code'];
            $template=$templates?$templates[0]['id']:Sql::run($this->db,'INSERT INTO workflow_templates(code,name,owner_department_id,category_id) VALUES (?,?,?,?) RETURNING id',['workflow-'.$category['id'],$category['name'],$category['department_id'],$category['id']])->fetchColumn();
            $new=Sql::run($this->db,"INSERT INTO workflow_versions(template_id,version,status,definition,created_by,published_by,published_at)
                SELECT ?,COALESCE(MAX(version),0)+1,'published',CAST(? AS jsonb),?,?,now() FROM workflow_versions WHERE template_id=? RETURNING id,version",
                [$template,json_encode($definition,JSON_THROW_ON_ERROR),$draft['created_by'],$actor,$template])->fetch();
            Sql::run($this->db,'UPDATE workflow_templates SET active_version_id=? WHERE id=?',[$new['id'],$template]);
            Sql::run($this->db,"UPDATE workflow_drafts SET status='published',approved_by=?,version=version+1,updated_at=now() WHERE id=?",[$actor,$id]);
            Sql::run($this->db,"INSERT INTO security_events(event_type,actor_user_id,subject_id,reason) VALUES ('WORKFLOW_PUBLISHED',?,?,?)",[$actor,$new['id'],$reason]);
            return ['id'=>$id,'category_id'=>$category['id'],'workflow_version_id'=>$new['id'],'version'=>(int)$new['version']];
        },function(array $result)use($actor):void{$this->category($actor,$result['category_id'],'workflow.publish');});
    }

    private function draft(string $actor,string $id,string $permission):array
    {
        if(!TicketReader::uuid($id)){throw new ApiException(404,'draft_unavailable','Draft unavailable.');}
        $row=Sql::run($this->db,'SELECT * FROM workflow_drafts WHERE id=?',[$id])->fetch();
        if(!$row){throw new ApiException(404,'draft_unavailable','Draft unavailable.');}
        $this->category($actor,$row['category_id'],$permission);return $row;
    }

    private function validateDirectory(Graph $graph):void
    {
        BusinessClock::due(new \DateTimeImmutable(),1,$graph->definition['calendar']??[]);
        foreach($graph->definition['default_cc_department_ids']??[]as$department){if(!Sql::run($this->db,'SELECT 1 FROM departments WHERE id=? AND active',[$department])->fetchColumn()){throw new \InvalidArgumentException('Default CC department is inactive or unavailable.');}}
        foreach($graph->nodes as $node){$config=$node['config']??[];
            if($node['type']==='webhook'){LocalWebhook::requireEnabled();}
            if(isset($config['sla_minutes'])){BusinessClock::due(new \DateTimeImmutable(),$config['sla_minutes'],$graph->definition['calendar']??[]);}
            if(isset($config['department_id'])&&!Sql::run($this->db,'SELECT 1 FROM departments WHERE id=? AND active',[$config['department_id']])->fetchColumn()){throw new \InvalidArgumentException('Node department is inactive or unavailable.');}
            if(isset($config['position_id'])&&!Sql::run($this->db,'SELECT 1 FROM positions WHERE id=? AND active',[$config['position_id']])->fetchColumn()){throw new \InvalidArgumentException('Node position is inactive or unavailable.');}
            if(in_array($node['type'],['task','claim_pool','approval'],true)){
                $national=($config['scope']??'station')==='national';
                $eligible=Sql::run($this->db,'SELECT 1 FROM app_users actor JOIN staff_assignments sa ON sa.user_id=actor.id JOIN stations st ON st.id=sa.station_id
                    WHERE actor.active AND st.active AND sa.department_id=CAST(? AS uuid) AND sa.position_id=CAST(? AS uuid)
                    AND sa.begins_at<=now() AND (sa.ends_at IS NULL OR sa.ends_at>now()) AND '.Access::roleSql('task.claim').
                    " AND EXISTS(SELECT 1 FROM scope_grants g WHERE g.user_id=actor.id AND g.permission_code='task.claim' AND g.revoked_at IS NULL
                        AND g.valid_from<=now() AND (g.valid_to IS NULL OR g.valid_to>now()) AND "
                        .($national?"g.scope_kind='national'":"(g.scope_kind='national' OR (g.scope_kind='department' AND g.scope_ref_id=sa.department_id) OR (g.scope_kind='station' AND g.scope_ref_id=sa.station_id) OR (g.scope_kind='province' AND g.scope_ref_id=st.province_id))").') LIMIT 1',[$config['department_id'],$config['position_id']])->fetchColumn();
                if(!$eligible){throw new \InvalidArgumentException('Task node '.$node['label'].' has no currently authorized position candidates.');}
            }
        }
    }
}
