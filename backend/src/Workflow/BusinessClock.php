<?php
declare(strict_types=1);
namespace App\Workflow;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class BusinessClock
{
    public static function due(DateTimeImmutable $start,int $minutes,array $calendar=[]):DateTimeImmutable
    {
        try{$zone=new DateTimeZone($calendar['timezone']??'Africa/Harare');}catch(\Exception){throw new InvalidArgumentException('Invalid business calendar time zone.');}
        $days=$calendar['days']??[1,2,3,4,5];$begin=$calendar['start']??'08:00';$end=$calendar['end']??'16:30';$holidays=$calendar['holidays']??[];
        if($minutes<1 || $minutes>525600 || !is_array($days) || !$days || array_filter($days,static fn($d)=>!is_int($d)||$d<1||$d>7)
            || !is_string($begin)||!is_string($end)||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$begin)||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$end)||$end<=$begin||!is_array($holidays)||count($holidays)>3660) {throw new InvalidArgumentException('Invalid business calendar.');}
        foreach($holidays as $day){if(!is_string($day)||!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$day,$parts)||!checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1])){throw new InvalidArgumentException('Invalid calendar holiday.');}}
        $time=$start->setTimezone($zone);$seconds=$minutes*60;
        for($i=0;$i<3660;$i++) {
            $date=$time->format('Y-m-d');$open=new DateTimeImmutable($date.' '.$begin,$zone);$close=new DateTimeImmutable($date.' '.$end,$zone);
            if(in_array((int)$time->format('N'),$days,true) && !in_array($date,$holidays,true)) {
                if($time<$open){$time=$open;}
                $available=max(0,$close->getTimestamp()-$time->getTimestamp());
                if($seconds<=$available){return (new DateTimeImmutable('@'.($time->getTimestamp()+$seconds)))->setTimezone(new DateTimeZone('UTC'));}
                $seconds-=$available;
            }
            $time=$time->modify('+1 day')->setTime(0,0);
        }
        throw new InvalidArgumentException('Calendar has no available work time within ten years.');
    }

    public static function remaining(DateTimeImmutable$from,DateTimeImmutable$until,array$calendar=[]):int
    {
        self::due($from,1,$calendar);if($until<=$from)return 0;
        $zone=new DateTimeZone($calendar['timezone']??'Africa/Harare');$time=$from->setTimezone($zone);$end=$until->setTimezone($zone);
        $days=$calendar['days']??[1,2,3,4,5];$holidays=$calendar['holidays']??[];$seconds=0;
        for($i=0;$i<3660&&$time<$end;$i++){
            $date=$time->format('Y-m-d');
            if(in_array((int)$time->format('N'),$days,true)&&!in_array($date,$holidays,true)){
                $open=new DateTimeImmutable($date.' '.($calendar['start']??'08:00'),$zone);$close=new DateTimeImmutable($date.' '.($calendar['end']??'16:30'),$zone);
                $seconds+=max(0,min($end->getTimestamp(),$close->getTimestamp())-max($time->getTimestamp(),$open->getTimestamp()));
            }
            $time=$time->modify('+1 day')->setTime(0,0);
        }
        if($time<$end)throw new InvalidArgumentException('Deadline exceeds calendar bound.');return(int)ceil($seconds/60);
    }
}
