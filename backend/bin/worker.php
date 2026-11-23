<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
$once=in_array('--once',$argv,true);
do{
    try{
        $worker=new App\Workflow\Worker(App\Config\Database::connection());
        $result=['timers'=>$worker->timers(),'escalations'=>$worker->escalate(),'notifications'=>$worker->outbox(),'attachments'=>(new App\Tickets\Attachments(App\Config\Database::connection()))->scanPending()];
        if(array_sum($result)>0||$once){echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;}
        file_put_contents('/tmp/jsc-worker-heartbeat',(string)time());
    }catch(Throwable $error){error_log(json_encode(['event'=>'worker_failed','exception'=>$error::class]));if($once){exit(1);}}
    if(!$once){sleep(2);}
}while(!$once);
