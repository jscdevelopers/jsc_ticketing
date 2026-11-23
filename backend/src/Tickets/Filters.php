<?php
declare(strict_types=1);
namespace App\Tickets;
use App\Http\ApiException;

final class Filters
{
    public const KEYS=['q','status','priority','station_id','province_id','department_id','requester'];
    public static function build(array$query,string$actor):array
    {
        if(array_diff(array_keys($query),self::KEYS)||array_filter($query,static fn($v)=>!is_string($v))||strlen($query['q']??'')>200){throw new ApiException(422,'invalid_filter','Invalid ticket/report filter.');}
        $clauses=[];$params=[];
        foreach(['status'=>['draft','submitted','routed','in_progress','awaiting_input','awaiting_approval','resolved','closed','withdrawn'],'priority'=>['low','normal','high','critical']]as$field=>$allowed){
            if(isset($query[$field])){if(!in_array($query[$field],$allowed,true)){throw new ApiException(422,'invalid_filter','Invalid '.$field.' filter.');}$clauses[]='t.'.$field.'=:filter_'.$field;$params['filter_'.$field]=$query[$field];}
        }
        foreach(['station_id'=>'origin_station_id','province_id'=>'origin_province_id','department_id'=>'responsible_department_id']as$key=>$column){if(isset($query[$key])){if(!TicketReader::uuid($query[$key])){throw new ApiException(422,'invalid_filter','Invalid scope filter.');}$clauses[]='t.'.$column.'=CAST(:filter_'.$key.' AS uuid)';$params['filter_'.$key]=$query[$key];}}
        if(isset($query['requester'])){if($query['requester']!=='me'){throw new ApiException(422,'invalid_filter','Requester filter supports only me.');}$clauses[]='t.requester_id=CAST(:filter_requester AS uuid)';$params['filter_requester']=$actor;}
        if(($query['q']??'')!==''){$clauses[]="(t.subject||' '||t.reference) ILIKE :filter_search ESCAPE '!'";$params['filter_search']='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query['q']).'%';}
        return[$clauses?' AND '.implode(' AND ',$clauses):'',$params];
    }
}
