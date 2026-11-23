<?php
declare(strict_types=1);
namespace App\Tickets;

use App\Http\ApiException;
use App\Security\Access;
use App\Security\TicketScope;
use App\Support\Sql;
use App\Support\Events;
use App\Support\Mutation;
use PDO;

final class Participants
{
    public function __construct(private PDO$db){}
    public static function membershipSql(string$actor='actor'):string
    {
        return "EXISTS(SELECT 1 FROM ticket_participants tp WHERE tp.ticket_id=t.id AND tp.active AND tp.participation IN ('cc','watcher')
            AND (tp.user_id=$actor.id OR EXISTS(SELECT 1 FROM staff_assignments sa JOIN departments d ON d.id=sa.department_id JOIN stations st ON st.id=sa.station_id
                WHERE sa.user_id=$actor.id AND sa.department_id=tp.department_id AND d.active AND st.active AND sa.begins_at<=now() AND (sa.ends_at IS NULL OR sa.ends_at>now()))))";
    }
    public static function summarySql():string
    {
        return "t.confidentiality='internal' AND EXISTS(SELECT 1 FROM app_users actor WHERE actor.id=CAST(:actor AS uuid) AND actor.active AND ".Access::roleSql('ticket.summary').' AND '.self::membershipSql().')';
    }
    public function summary(string$actor,string$id):array
    {
        if(!TicketReader::uuid($id)){throw new ApiException(404,'ticket_unavailable','Ticket unavailable.');}
        $row=Sql::run($this->db,'SELECT t.id,t.reference,t.status,t.priority,t.created_at,c.name AS category_name,d.name AS department_name FROM tickets t JOIN categories c ON c.id=t.category_id JOIN departments d ON d.id=t.responsible_department_id WHERE t.id=CAST(:id AS uuid) AND ('.self::summarySql().' OR '.TicketScope::predicate().')',['id'=>$id,'actor'=>$actor])->fetch();
        if(!$row){throw new ApiException(404,'ticket_unavailable','Ticket unavailable.');}return$row;
    }
    public function shared(string$actor,array$query):array
    {
        $this->filter($query);$params=['actor'=>$actor];$sql='SELECT t.id,t.reference,t.status,t.priority,t.created_at,c.name AS category_name,d.name AS department_name FROM tickets t JOIN categories c ON c.id=t.category_id JOIN departments d ON d.id=t.responsible_department_id WHERE '.self::summarySql();
        if(isset($query['after'])){$sql.=' AND t.id>CAST(:after AS uuid)';$params['after']=$query['after'];}
        if(($query['q']??'')!==''){$sql.=" AND (t.reference||' '||c.name||' '||d.name) ILIKE :q ESCAPE '!'";$params['q']=$this->search($query['q']);}
        return$this->page($sql.' ORDER BY t.id LIMIT 11',$params);
    }
    public function listing(string$actor,string$ticket,array$query):array
    {
        (new TicketReader($this->db))->detail($actor,$ticket);$this->filter($query);$params=[$ticket];
        $sql='SELECT tp.*,COALESCE(u.display_name,d.name) AS name FROM ticket_participants tp LEFT JOIN app_users u ON u.id=tp.user_id LEFT JOIN departments d ON d.id=tp.department_id WHERE tp.ticket_id=?';
        if(isset($query['after'])){$sql.=' AND tp.id>CAST(? AS uuid)';$params[]=$query['after'];}
        if(($query['q']??'')!==''){$sql.=" AND COALESCE(u.display_name,d.name) ILIKE ? ESCAPE '!'";$params[]=$this->search($query['q']);}
        return$this->page($sql.' ORDER BY tp.id LIMIT 11',$params);
    }
    public function choices(string$actor,string$ticket,string$kind,array$query):array
    {
        (new TicketReader($this->db))->detail($actor,$ticket);Access::ticket($this->db,$actor,'ticket.share',$ticket);$this->filter($query);
        if(!in_array($kind,['users','departments'],true)){throw new ApiException(422,'invalid_target','Choose staff or departments.');}
        $table=$kind==='users'?'app_users':'departments';$label=$kind==='users'?'display_name':'name';$sql="SELECT id,$label AS name FROM $table WHERE active";$params=[];
        if(isset($query['after'])){$sql.=' AND id>CAST(? AS uuid)';$params[]=$query['after'];}
        if(($query['q']??'')!==''){$sql.=" AND $label ILIKE ? ESCAPE '!'";$params[]=$this->search($query['q']);}
        return$this->page($sql.' ORDER BY id LIMIT 11',$params);
    }
    public function change(string$actor,string$ticket,int$version,string$key,array$body,string$requestId):array
    {
        if(array_diff(array_keys($body),['targets','reason'])||!is_array($body['targets']??null)||!array_is_list($body['targets'])||count($body['targets'])<1||count($body['targets'])>10){throw new ApiException(422,'invalid_participants','Choose one to ten CC or watcher records.');}
        $reason=Mutation::reason($body['reason']??null);
        foreach($body['targets']as$target){if(!is_array($target)||array_diff(array_keys($target),['id','kind','participation','active'])||!is_string($target['id']??null)||!TicketReader::uuid($target['id'])||!in_array($target['kind']??'', ['user','department'],true)||!in_array($target['participation']??'', ['cc','watcher'],true)||!is_bool($target['active']??null)){throw new ApiException(422,'invalid_participants','Invalid participant target.');}}
        return Mutation::run($this->db,$actor,'participants.change:'.$ticket,$key,['version'=>$version,...$body],function()use($actor,$ticket,$version,$body,$requestId,$reason):array{
            (new TicketReader($this->db))->detail($actor,$ticket);Access::ticket($this->db,$actor,'ticket.share',$ticket);
            $row=Sql::run($this->db,'SELECT * FROM tickets WHERE id=? FOR UPDATE',[$ticket])->fetch();Mutation::version((int)$row['version'],$version);
            foreach($body['targets']as$target){
                $table=$target['kind']==='user'?'app_users':'departments';if(!Sql::run($this->db,"SELECT 1 FROM $table WHERE id=? AND active",[$target['id']])->fetchColumn()){throw new ApiException(422,'invalid_target','A selected recipient is unavailable.');}
                $column=$target['kind']==='user'?'user_id':'department_id';
                $old=Sql::run($this->db,"SELECT * FROM ticket_participants WHERE ticket_id=? AND $column=? AND participation=? FOR UPDATE",[$ticket,$target['id'],$target['participation']])->fetch();
                if($old){Sql::run($this->db,'UPDATE ticket_participants SET active=CAST(? AS boolean),revoked_at=CASE WHEN CAST(? AS boolean) THEN null ELSE now() END,version=version+1 WHERE id=?',[$target['active']?'true':'false',$target['active']?'true':'false',$old['id']]);}
                elseif($target['active']){Sql::run($this->db,"INSERT INTO ticket_participants(ticket_id,$column,participation,visibility,created_by) VALUES (?,?,?,'internal_summary_only',?)",[$ticket,$target['id'],$target['participation'],$actor]);}
                Events::record($this->db,$row,null,$actor,$target['active']?'PARTICIPANT_SHARED':'PARTICIPANT_REVOKED',null,null,['target'=>$target,'previous_active'=>$old?$old['active']:null],$reason,$requestId);
            }
            Sql::run($this->db,'UPDATE tickets SET version=version+1,updated_at=now() WHERE id=?',[$ticket]);return['ticket_id'=>$ticket,'version'=>$version+1];
        },function(array$result)use($actor):void{(new TicketReader($this->db))->detail($actor,$result['ticket_id']);Access::ticket($this->db,$actor,'ticket.share',$result['ticket_id']);});
    }
    public function privacy(string$actor,string$ticket,int$version,string$key,array$body,string$requestId):array
    {
        if(array_diff(array_keys($body),['classification','reason'])||!in_array($body['classification']??'', ['internal','restricted','confidential'],true)){throw new ApiException(422,'invalid_privacy','Choose a valid privacy classification.');}$reason=Mutation::reason($body['reason']??null);
        return Mutation::run($this->db,$actor,'ticket.privacy:'.$ticket,$key,['version'=>$version,...$body],function()use($actor,$ticket,$version,$body,$requestId,$reason):array{
            (new TicketReader($this->db))->detail($actor,$ticket);Access::ticket($this->db,$actor,'ticket.privacy',$ticket);Access::ticket($this->db,$actor,'ticket.read_sensitive',$ticket);
            $row=Sql::run($this->db,'SELECT * FROM tickets WHERE id=? FOR UPDATE',[$ticket])->fetch();Mutation::version((int)$row['version'],$version);
            Sql::run($this->db,'UPDATE tickets SET confidentiality=?,version=version+1,updated_at=now() WHERE id=?',[$body['classification'],$ticket]);
            Events::record($this->db,$row,null,$actor,'PRIVACY_CHANGED',$row['confidentiality'],$body['classification'],[],$reason,$requestId,[],'restricted');return['ticket_id'=>$ticket,'version'=>$version+1];
        },function(array$result)use($actor):void{(new TicketReader($this->db))->detail($actor,$result['ticket_id']);Access::ticket($this->db,$actor,'ticket.privacy',$result['ticket_id']);});
    }
    public static function defaults(PDO$db,array$definition,array$ticket,string$actor,string$requestId):void
    {
        foreach($definition['default_cc_department_ids']??[]as$department){Sql::run($db,"INSERT INTO ticket_participants(ticket_id,department_id,participation,visibility,created_by) VALUES (?,?,'cc','internal_summary_only',?) ON CONFLICT DO NOTHING",[$ticket['id'],$department,$actor]);
            Events::record($db,$ticket,null,$actor,'DEFAULT_CC_APPLIED',null,null,['department_id'=>$department],'Published workflow CC policy',$requestId);
        }
    }
    public static function recipients(PDO$db,string$ticket):array
    {
        $rows=Sql::run($db,'SELECT actor.id FROM app_users actor JOIN tickets t ON t.id=? WHERE actor.active AND '.Access::roleSql('ticket.summary').' AND '.self::membershipSql().' ORDER BY actor.id LIMIT 1001',[$ticket])->fetchAll(PDO::FETCH_COLUMN);
        if(count($rows)>1000){throw new ApiException(422,'participant_limit','Narrow the shared audience to at most 1000 current staff.');}return$rows;
    }
    private function filter(array$query):void{if(array_diff(array_keys($query),['q','after'])||array_filter($query,static fn($v)=>!is_string($v))||strlen($query['q']??'')>200||(isset($query['after'])&&!TicketReader::uuid($query['after']))){throw new ApiException(422,'invalid_filter','Invalid participant search or cursor.');}}
    private function search(string$value):string{return'%'.str_replace(['!','%','_'],['!!','!%','!_'],$value).'%';}
    private function page(string$sql,array$params):array{$rows=Sql::run($this->db,$sql,$params)->fetchAll();$more=count($rows)>10;if($more){array_pop($rows);}$last=end($rows);return['data'=>$rows,'meta'=>['next_after'=>$more?$last['id']:null]];}
}
