<?php
declare(strict_types=1);
namespace App\Tickets;
use App\Http\ApiException;
use App\Support\Sql;
use App\Support\Mutation;
use PDO;

final class SavedReports
{
    public function __construct(private PDO$db){}
    public function listing(string$actor,array$query):array
    {
        if(array_diff(array_keys($query),['q','after'])||array_filter($query,static fn($v)=>!is_string($v))||strlen($query['q']??'')>200||(isset($query['after'])&&!TicketReader::uuid($query['after']))){throw new ApiException(422,'invalid_filter','Invalid saved report search.');}
        $sql='SELECT * FROM saved_reports WHERE owner_user_id=? AND active';$params=[$actor];
        if(isset($query['after'])){$sql.=' AND id>CAST(? AS uuid)';$params[]=$query['after'];}
        if(($query['q']??'')!==''){$sql.=" AND name ILIKE ? ESCAPE '!'";$params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query['q']).'%';}
        $rows=Sql::run($this->db,$sql.' ORDER BY id LIMIT 11',$params)->fetchAll();$more=count($rows)>10;if($more){array_pop($rows);}$last=end($rows);
        foreach($rows as&$row){$row['filters']=json_decode($row['filters'],false,16,JSON_THROW_ON_ERROR);}return['data'=>$rows,'meta'=>['next_after'=>$more?$last['id']:null]];
    }
    public function save(string$actor,?string$id,int$version,string$key,array$body):array
    {
        if(array_diff(array_keys($body),['name','filters','active','reason'])||!is_string($body['name']??null)||IntakeSchema::length(trim($body['name']))<3||IntakeSchema::length($body['name'])>120||str_contains($body['name'],"\0")||!is_array($body['filters']??null)||!is_bool($body['active']??null)||($id!==null&&!TicketReader::uuid($id))){throw new ApiException(422,'invalid_report','Report name, filters, active state and reason required.');}
        Filters::build($body['filters'],$actor);$reason=Mutation::reason($body['reason']??null);
        return Mutation::run($this->db,$actor,'report.save:'.($id??'new'),$key,['version'=>$version,...$body],function()use($actor,$id,$version,$body,$reason):array{
            if($id!==null){$old=Sql::run($this->db,'SELECT * FROM saved_reports WHERE id=? AND owner_user_id=? FOR UPDATE',[$id,$actor])->fetch();if(!$old){throw new ApiException(404,'report_unavailable','Report unavailable.');}Mutation::version((int)$old['version'],$version);}
            $filters=json_encode((object)$body['filters'],JSON_THROW_ON_ERROR);
            $row=$id===null?Sql::run($this->db,'INSERT INTO saved_reports(owner_user_id,name,filters,active) VALUES (?,?,CAST(? AS jsonb),CAST(? AS boolean)) RETURNING *',[$actor,trim($body['name']),$filters,$body['active']?'true':'false'])->fetch():Sql::run($this->db,'UPDATE saved_reports SET name=?,filters=CAST(? AS jsonb),active=CAST(? AS boolean),version=version+1,updated_at=now() WHERE id=? RETURNING *',[trim($body['name']),$filters,$body['active']?'true':'false',$id])->fetch();
            Sql::run($this->db,'INSERT INTO security_events(event_type,actor_user_id,subject_id,reason) VALUES (?,?,?,?)',[$body['active']?'REPORT_SAVED':'REPORT_ARCHIVED',$actor,$row['id'],$reason]);$row['filters']=json_decode($row['filters'],false,16,JSON_THROW_ON_ERROR);return$row;
        },function(array$result)use($actor):void{if(!Sql::run($this->db,'SELECT 1 FROM saved_reports WHERE id=? AND owner_user_id=?',[$result['id'],$actor])->fetchColumn()){throw new ApiException(404,'report_unavailable','Report unavailable.');}});
    }
}
