<?php
declare(strict_types=1);
namespace App\Security;

use App\Http\ApiException;
use App\Support\Sql;
use App\Support\Mutation;
use App\Tickets\TicketReader;
use PDO;

final class Administration
{
    public function __construct(private PDO $db) {}
    public const FIELDS=[
        'provinces'=>['code'=>'string','name'=>'string','active'=>'boolean'],
        'stations'=>['code'=>'string','name'=>'string','province_id'=>'nullable_uuid','is_secretariat_station'=>'boolean','active'=>'boolean'],
        'departments'=>['code'=>'string','name'=>'string','active'=>'boolean'],
        'positions'=>['code'=>'string','title'=>'string','active'=>'boolean'],
        'categories'=>['department_id'=>'uuid','code'=>'string','name'=>'string','parent_id'=>'nullable_uuid','active'=>'boolean'],
        'users'=>['external_subject'=>'string','display_name'=>'string','email'=>'nullable_email','active'=>'boolean'],
        'assignments'=>['user_id'=>'uuid','position_id'=>'uuid','department_id'=>'uuid','station_id'=>'uuid','begins_at'=>'date','ends_at'=>'nullable_date','is_acting'=>'boolean'],
        'user-roles'=>['user_id'=>'uuid','role_id'=>'uuid','valid_from'=>'date','valid_to'=>'nullable_date'],
        'scope-grants'=>['user_id'=>'uuid','permission_code'=>'string','scope_kind'=>'scope','scope_ref_id'=>'nullable_uuid','valid_from'=>'date','valid_to'=>'nullable_date','grant_reason'=>'string'],
    ];
    private const TABLES=['users'=>'app_users','assignments'=>'staff_assignments','user-roles'=>'user_roles','scope-grants'=>'scope_grants'];

    public function schema(string $actor):array
    {
        try{Access::national($this->db,$actor,'directory.manage');}catch(ApiException){Access::national($this->db,$actor,'security.manage');}return self::FIELDS;
    }
    private function permission(string $resource):string{return in_array($resource,['users','user-roles','scope-grants','roles','permissions','audit'],true)?'security.manage':'directory.manage';}
    private function table(string $resource):string
    {
        if(!isset(self::FIELDS[$resource])&&!in_array($resource,['roles','permissions','audit'],true)){throw new ApiException(404,'resource_unavailable','Resource unavailable.');}
        return self::TABLES[$resource]??($resource==='audit'?'security_events':$resource);
    }

    public function listing(string $actor,string $resource,array $query):array
    {
        $table=$this->table($resource);Access::national($this->db,$actor,$this->permission($resource));
        if(array_diff(array_keys($query),['q','after','id'])||array_filter($query,static fn($v)=>!is_string($v))||strlen($query['q']??'')>200){throw new ApiException(422,'invalid_filter','Invalid directory filter.');}
        $key=$resource==='permissions'?'code':'id';$sql="SELECT * FROM $table WHERE true";$params=[];
        if(isset($query['id'])){if($key==='id'&&!TicketReader::uuid($query['id'])&&$resource!=='audit'){throw new ApiException(422,'invalid_filter','Invalid record identifier.');}$sql.=" AND $key=?";$params[]=$query['id'];}
        if(isset($query['after'])){
            if($resource==='audit'){if(!preg_match('/^[0-9]{1,18}$/D',$query['after'])){throw new ApiException(422,'invalid_cursor','Invalid audit cursor.');}}
            elseif($resource!=='permissions'&&!TicketReader::uuid($query['after'])){throw new ApiException(422,'invalid_cursor','Invalid cursor.');}
            $sql.=" AND $key>?";$params[]=$query['after'];
        }
        if(($query['q']??'')!==''){
            $fields=match($resource){'users'=>['display_name','email'],'provinces','stations','departments','categories'=>['code','name'],'positions','roles'=>['code','title'],'permissions'=>['code','description'],'audit'=>['event_type','reason'],default=>['id::text']};
            $sql.=' AND ('.implode("||' '||",array_map(static fn($f)=>"COALESCE($f,'')",$fields)).") ILIKE ? ESCAPE '!'";
            $params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query['q']).'%';
        }
        $rows=Sql::run($this->db,$sql." ORDER BY $key LIMIT 11",$params)->fetchAll();$more=count($rows)>10;if($more){array_pop($rows);}$last=end($rows);
        return['data'=>$rows,'meta'=>['next_after'=>$more?(string)$last[$key]:null]];
    }

    public function save(string $actor,string $resource,?string $id,int $version,string $key,array $body):array
    {
        $table=$this->table($resource);Access::national($this->db,$actor,$this->permission($resource));
        if(!isset(self::FIELDS[$resource])||$resource==='scope-grants'&&$id!==null){throw new ApiException(422,'immutable_resource','This resource is read-only; revoke scope grants rather than editing them.');}
        if($id!==null&&!TicketReader::uuid($id)){throw new ApiException(404,'resource_unavailable','Record unavailable.');}
        $fields=self::FIELDS[$resource];
        if(array_diff(array_keys($body),[...array_keys($fields),'reason'])||array_diff(array_keys($fields),array_keys($body))){throw new ApiException(422,'invalid_record','Supply every field defined by the resource schema.');}
        $reason=Mutation::reason($body['reason']??null);$values=[];
        foreach($fields as$name=>$type){$value=$body[$name];
            if(str_starts_with($type,'nullable_')&&$value===null){$values[$name]=null;continue;}
            $base=str_replace('nullable_','',$type);
            $valid=match($base){
                'boolean'=>is_bool($value),'uuid'=>is_string($value)&&TicketReader::uuid($value),
                'email'=>is_string($value)&&filter_var($value,FILTER_VALIDATE_EMAIL)!==false,
                'date'=>is_string($value)&&preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/D',$value)&&strtotime($value)!==false,
                'scope'=>in_array($value,['self','station','province','department','national','ticket_explicit'],true),
                default=>is_string($value)&&trim($value)!==''&&strlen($value)<=255&&!str_contains($value,"\0"),
            };
            if(!$valid){throw new ApiException(422,'invalid_record','Invalid field: '.$name);}
            if($base==='date'){$parsed=date_parse($value);if($parsed['warning_count']||$parsed['error_count']){throw new ApiException(422,'invalid_record','Invalid date: '.$name);}}
            $values[$name]=is_bool($value)?($value?'true':'false'):$value;
        }
        if($resource==='scope-grants'){
            $kind=$body['scope_kind'];$ref=$body['scope_ref_id'];
            if(in_array($kind,['self','national'],true)?$ref!==null:$ref===null){throw new ApiException(422,'invalid_scope','Scope reference does not match the scope kind.');}
            $targets=['station'=>'stations','province'=>'provinces','department'=>'departments','ticket_explicit'=>'tickets'];
            if(isset($targets[$kind])&&!Sql::run($this->db,'SELECT 1 FROM '.$targets[$kind].' WHERE id=?',[$ref])->fetchColumn()){throw new ApiException(422,'invalid_scope','Scope target unavailable.');}
        }
        if($resource==='categories'&&$body['parent_id']!==null){
            $parent=Sql::run($this->db,'SELECT department_id FROM categories WHERE id=?',[$body['parent_id']])->fetchColumn();
            if($parent!==$body['department_id']){throw new ApiException(422,'invalid_parent','Parent category must belong to the same department.');}
            if($id!==null&&Sql::run($this->db,'WITH RECURSIVE ancestors AS (SELECT id,parent_id FROM categories WHERE id=? UNION SELECT c.id,c.parent_id FROM categories c JOIN ancestors a ON c.id=a.parent_id) SELECT 1 FROM ancestors WHERE id=?',[$body['parent_id'],$id])->fetchColumn()){throw new ApiException(422,'category_cycle','A category cannot become its own ancestor.');}
        }
        return Mutation::run($this->db,$actor,'admin.'.$resource.':'.($id??'new'),$key,['version'=>$version,...$body],function()use($actor,$resource,$table,$id,$version,$values,$reason):array{
            if($id!==null){
                $old=Sql::run($this->db,"SELECT * FROM $table WHERE id=? FOR UPDATE",[$id])->fetch();if(!$old){throw new ApiException(404,'record_unavailable','Record unavailable.');}
                Mutation::version((int)$old['version'],$version);
                if($resource==='users'&&$old['external_subject']!==$values['external_subject']){throw new ApiException(422,'immutable_identity','An existing identity cannot be rebound.');}
                $set=implode(',',array_map(static fn($column)=>"$column=?",array_keys($values)));
                $row=Sql::run($this->db,"UPDATE $table SET $set,version=version+1 WHERE id=? RETURNING *",[...array_values($values),$id])->fetch();
            }else{
                $insert=$values;if($resource==='scope-grants'){$insert['granted_by']=$actor;}
                $row=Sql::run($this->db,"INSERT INTO $table (".implode(',',array_keys($insert)).') VALUES ('.implode(',',array_fill(0,count($insert),'?')).') RETURNING *',array_values($insert))->fetch();
            }
            Sql::run($this->db,'INSERT INTO security_events(event_type,actor_user_id,subject_id,reason) VALUES (?,?,?,?)',[$id===null?'RECORD_CREATED':'RECORD_UPDATED',$actor,$row['id'],$resource.': '.$reason]);
            if($resource==='users'&&$values['active']==='false'){Sql::run($this->db,'UPDATE api_tokens SET revoked_at=now() WHERE user_id=? AND revoked_at IS NULL',[$row['id']]);}
            return$row;
        },function(array $result)use($actor,$resource):void{Access::national($this->db,$actor,$this->permission($resource));});
    }

    public function revoke(string $actor,string $id,int $version,string $key,string $reason):array
    {
        Access::national($this->db,$actor,'security.manage');$reason=Mutation::reason($reason);
        if(!TicketReader::uuid($id)){throw new ApiException(404,'grant_unavailable','Grant unavailable.');}
        return Mutation::run($this->db,$actor,'admin.revoke:'.$id,$key,['version'=>$version,'reason'=>$reason],function()use($id,$actor,$version,$reason):array{
            $row=Sql::run($this->db,'SELECT * FROM scope_grants WHERE id=? FOR UPDATE',[$id])->fetch();if(!$row){throw new ApiException(404,'grant_unavailable','Grant unavailable.');}Mutation::version((int)$row['version'],$version);
            if($row['revoked_at']===null){$row=Sql::run($this->db,'UPDATE scope_grants SET revoked_at=now(),version=version+1 WHERE id=? RETURNING *',[$id])->fetch();Sql::run($this->db,"INSERT INTO security_events(event_type,actor_user_id,subject_id,reason) VALUES ('GRANT_REVOKED',?,?,?)",[$actor,$id,$reason]);}
            return$row;
        },function(array $row)use($actor):void{Access::national($this->db,$actor,'security.manage');});
    }

    public function password(string $actor,string $id,int $version,string $key,array $body):array
    {
        Access::national($this->db,$actor,'security.manage');
        if(!TicketReader::uuid($id)||array_diff(array_keys($body),['username','temporary_password','reason'])||!is_string($body['username']??null)||!is_string($body['temporary_password']??null)){throw new ApiException(422,'invalid_credential','Username, temporary password and reason required.');}
        $username=strtolower(trim($body['username']));if(!preg_match('/^[a-z0-9][a-z0-9._@-]{2,99}$/D',$username)){throw new ApiException(422,'invalid_username','Enter a username of 3..100 allowed characters.');}
        PasswordAuth::validatePassword($body['temporary_password']);$reason=Mutation::reason($body['reason']??null);$hash=password_hash($body['temporary_password'],PASSWORD_BCRYPT,['cost'=>12]);
        return Mutation::run($this->db,$actor,'admin.password:'.$id,$key,['version'=>$version,...$body],function()use($id,$version,$username,$hash,$actor,$reason):array{
            $user=Sql::run($this->db,'SELECT * FROM app_users WHERE id=? AND active FOR UPDATE',[$id])->fetch();if(!$user){throw new ApiException(404,'user_unavailable','Active user unavailable.');}Mutation::version((int)$user['version'],$version);
            Sql::run($this->db,'INSERT INTO user_credentials(user_id,username,password_hash,must_change) VALUES (?,?,?,true) ON CONFLICT(user_id) DO UPDATE SET username=EXCLUDED.username,password_hash=EXCLUDED.password_hash,must_change=true,password_changed_at=now()',[$id,$username,$hash]);
            Sql::run($this->db,'UPDATE api_tokens SET revoked_at=now() WHERE user_id=? AND revoked_at IS NULL',[$id]);
            Sql::run($this->db,'UPDATE login_challenges SET consumed_at=now() WHERE user_id=? AND consumed_at IS NULL',[$id]);
            Sql::run($this->db,'UPDATE app_users SET version=version+1 WHERE id=?',[$id]);
            Sql::run($this->db,"INSERT INTO security_events(event_type,actor_user_id,subject_id,reason) VALUES ('PASSWORD_RESET',?,?,?)",[$actor,$id,$reason]);
            return['id'=>$id,'must_change_password'=>true,'version'=>(int)$user['version']+1];
        },function(array$row)use($actor):void{Access::national($this->db,$actor,'security.manage');});
    }
}
