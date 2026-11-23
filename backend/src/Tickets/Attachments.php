<?php
declare(strict_types=1);
namespace App\Tickets;

use App\Http\ApiException;
use App\Http\Response;
use App\Security\Access;
use App\Security\MalwareScanner;
use App\Support\Sql;
use App\Support\Mutation;
use App\Support\Events;
use PDO;

final class Attachments
{
    public function __construct(private PDO$db){}
    private function path(string$key):string
    {
        if(!preg_match('/^[a-f0-9]{48}$/D',$key)){throw new \RuntimeException('Invalid storage key.');}
        $root=getenv('PRIVATE_STORAGE_PATH')?:'/var/lib/ticketing/private';
        if(!is_dir($root)||!is_writable($root)){throw new ApiException(503,'storage_unavailable','Private attachment storage is unavailable.');}
        return rtrim($root,'/\\').DIRECTORY_SEPARATOR.$key;
    }
    private function readable(string$actor,array$file):void
    {
        (new TicketReader($this->db))->detail($actor,$file['ticket_id']);
        if($file['classification']!=='internal'){Access::ticket($this->db,$actor,'ticket.read_sensitive',$file['ticket_id']);}
    }
    public function listing(string$actor,string$ticket,array$query):array
    {
        (new TicketReader($this->db))->detail($actor,$ticket);
        if(array_diff(array_keys($query),['after'])||(isset($query['after'])&&!TicketReader::uuid($query['after']))){throw new ApiException(422,'invalid_cursor','Invalid attachment cursor.');}
        $sensitive=false;try{Access::ticket($this->db,$actor,'ticket.read_sensitive',$ticket);$sensitive=true;}catch(ApiException){}
        $rows=Sql::run($this->db,'SELECT id,ticket_id,file_name,content_type,byte_size,classification,scan_state,scan_provider,last_scan_error,uploaded_at,uploaded_by,version FROM ticket_attachments
            WHERE ticket_id=? AND active AND (classification=\'internal\' OR CAST(? AS boolean)) AND id>CAST(? AS uuid) ORDER BY id LIMIT 11',[$ticket,$sensitive?'true':'false',$query['after']??'00000000-0000-0000-0000-000000000000'])->fetchAll();
        $more=count($rows)>10;if($more){array_pop($rows);}$last=end($rows);return['data'=>$rows,'meta'=>['next_after'=>$more?$last['id']:null]];
    }
    public function upload(string$actor,string$ticket,int$version,string$key,array$file,array$body,string$requestId):array
    {
        if(array_diff(array_keys($body),['classification'])||!in_array($body['classification']??'internal',['internal','restricted','confidential'],true)){throw new ApiException(422,'invalid_upload','Invalid attachment classification.');}
        if(in_array($file['error']??UPLOAD_ERR_NO_FILE,[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true)){throw new ApiException(413,'upload_too_large','Maximum attachment size is 5 MiB.');}
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name'])){throw new ApiException(422,'invalid_upload','A valid uploaded file is required.');}
        $tmp=$file['tmp_name'];$size=filesize($tmp);if($size===false||$size<1||$size>5*1024*1024){throw new ApiException(413,'upload_too_large','Use a nonempty file of at most 5 MiB.');}
        $name=basename(str_replace('\\','/',(string)$file['name']));
        if(!preg_match('//u',$name)||preg_match('/[\x00-\x1f\x7f]/u',$name)||IntakeSchema::length($name)>180){throw new ApiException(422,'invalid_filename','Use a short filename without control characters.');}
        $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $types=['pdf'=>['application/pdf'],'png'=>['image/png'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'txt'=>['text/plain'],'csv'=>['text/plain','text/csv']];
        if(!isset($types[$extension])||!in_array($mime,$types[$extension],true)){throw new ApiException(422,'file_type_rejected','Supported files: PDF, PNG, JPEG, UTF-8 text and CSV.');}
        if(in_array($extension,['png','jpg','jpeg'],true)){$image=@getimagesize($tmp);if(!$image||$image[0]*$image[1]>40000000){throw new ApiException(422,'invalid_image','Image structure or dimensions are invalid.');}}
        if(in_array($extension,['txt','csv'],true)){$bytes=file_get_contents($tmp);if($bytes===false||str_contains($bytes,"\0")||!preg_match('//u',$bytes)){throw new ApiException(422,'invalid_text','Text files must contain valid UTF-8 without null bytes.');}}
        if($extension==='pdf'){$stream=fopen($tmp,'rb');$header=fread($stream,16);fseek($stream,max(0,$size-2048));$tail=stream_get_contents($stream);fclose($stream);if(!str_starts_with($header,'%PDF-')||!str_contains($tail,'%%EOF')){throw new ApiException(422,'invalid_pdf','PDF structure is invalid.');}}
        $sha=hash_file('sha256',$tmp);$stored=null;
        try{return Mutation::run($this->db,$actor,'attachment.upload:'.$ticket,$key,['version'=>$version,'name'=>$name,'sha256'=>$sha,...$body],function()use($actor,$ticket,$version,$file,$body,$requestId,$tmp,$name,$mime,$size,$sha,&$stored):array{
            (new TicketReader($this->db))->detail($actor,$ticket);$record=Sql::run($this->db,'SELECT * FROM tickets WHERE id=? FOR UPDATE',[$ticket])->fetch();Mutation::version((int)$record['version'],$version);
            if(in_array($record['status'],['closed','withdrawn'],true)){throw new ApiException(409,'ticket_closed','Reopen the ticket before uploading.');}
            if($record['requester_id']!==$actor){Access::ticket($this->db,$actor,'ticket.comment',$ticket);}
            $classification=$body['classification']??'internal';if($classification!=='internal'){Access::ticket($this->db,$actor,'ticket.read_sensitive',$ticket);}
            $storage=bin2hex(random_bytes(24));$stored=$this->path($storage);
            if(!move_uploaded_file($tmp,$stored)){throw new ApiException(503,'storage_unavailable','The file could not be stored privately.');}chmod($stored,0600);
            $attachment=Sql::run($this->db,'INSERT INTO ticket_attachments(ticket_id,uploaded_by,file_name,storage_key,content_type,byte_size,classification,sha256) VALUES (?,?,?,?,?,?,?,?) RETURNING id,ticket_id,scan_state,version',[$ticket,$actor,$name,$storage,$mime,$size,$classification,$sha])->fetch();
            Sql::run($this->db,'UPDATE tickets SET version=version+1,updated_at=now() WHERE id=?',[$ticket]);
            Events::record($this->db,$record,null,$actor,'ATTACHMENT_QUARANTINED',null,null,['attachment_id'=>$attachment['id']],null,$requestId,[],$classification==='internal'?'internal':'restricted');return$attachment;
        },function(array$result)use($actor):void{$file=Sql::run($this->db,'SELECT * FROM ticket_attachments WHERE id=?',[$result['id']])->fetch();$this->readable($actor,$file);});}
        catch(\Throwable$error){if($stored!==null&&is_file($stored)){unlink($stored);}throw$error;}
    }
    public function download(string$actor,string$id):Response
    {
        if(!TicketReader::uuid($id)){throw new ApiException(404,'file_unavailable','Attachment unavailable.');}
        $file=Sql::run($this->db,'SELECT * FROM ticket_attachments WHERE id=? AND active',[$id])->fetch();if(!$file){throw new ApiException(404,'file_unavailable','Attachment unavailable.');}$this->readable($actor,$file);
        if($file['scan_state']!=='clean'||((getenv('APP_ENV')?:'')==='production'&&$file['scan_provider']!=='clamav')){throw new ApiException(409,'file_quarantined','Attachment is quarantined, rejected or awaiting an approved scan.');}
        $path=$this->path($file['storage_key']);if(!is_file($path)||!hash_equals($file['sha256']??'',hash_file('sha256',$path))){throw new ApiException(409,'file_integrity','Attachment integrity check failed.');}
        $bytes=file_get_contents($path);if($bytes===false){throw new ApiException(503,'file_unavailable','Attachment unavailable.');}
        return new Response($bytes,200,['Content-Type'=>'application/octet-stream','Content-Disposition'=>"attachment; filename=\"attachment\"; filename*=UTF-8''".rawurlencode($file['file_name']),'Content-Length'=>(string)strlen($bytes)]);
    }
    public function scanPending():int
    {
        $files=Sql::run($this->db,"SELECT * FROM ticket_attachments WHERE active AND scan_state='quarantined' AND scan_next_at<=now() ORDER BY scan_next_at LIMIT 10")->fetchAll();$count=0;
        foreach($files as$file){
            try{$path=$this->path($file['storage_key']);if(!is_file($path)||!hash_equals($file['sha256']??'',hash_file('sha256',$path))){$verdict=['state'=>'rejected','provider'=>'integrity'];}else{$verdict=(new MalwareScanner())->scan($path);}}
            catch(\Throwable){Sql::run($this->db,"UPDATE ticket_attachments SET scan_attempts=scan_attempts+1,scan_next_at=now()+interval '1 minute',last_scan_error='scanner_unavailable' WHERE id=? AND scan_state='quarantined'",[$file['id']]);continue;}
            $this->db->beginTransaction();try{
                $ticket=Sql::run($this->db,'SELECT * FROM tickets WHERE id=? FOR UPDATE',[$file['ticket_id']])->fetch();
                $current=Sql::run($this->db,'SELECT * FROM ticket_attachments WHERE id=? AND active FOR UPDATE',[$file['id']])->fetch();
                if($current&&$current['scan_state']==='quarantined'){
                    Sql::run($this->db,'UPDATE ticket_attachments SET scan_state=?,scan_provider=?,scan_attempts=scan_attempts+1,last_scan_error=null,version=version+1 WHERE id=?',[$verdict['state'],$verdict['provider'],$file['id']]);
                    Events::record($this->db,$ticket,null,'system:scanner',$verdict['state']==='clean'?'ATTACHMENT_CHECK_PASSED':'ATTACHMENT_REJECTED',null,null,['attachment_id'=>$file['id'],'provider'=>$verdict['provider']],null,'scanner-worker',[],$file['classification']==='internal'?'internal':'restricted');$count++;
                }$this->db->commit();
            }catch(\Throwable$error){if($this->db->inTransaction()){$this->db->rollBack();}throw$error;}
        }return$count;
    }
}
