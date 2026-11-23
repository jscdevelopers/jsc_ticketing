<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
try{
    $db=App\Config\Database::connection();
    $db->query('SELECT purpose FROM api_tokens LIMIT 0');$db->query('SELECT id FROM workflow_executions LIMIT 0');$db->query('SELECT user_id FROM user_credentials LIMIT 0');
    if(in_array('--worker',$argv,true)&&(!is_file('/tmp/jsc-worker-heartbeat')||filemtime('/tmp/jsc-worker-heartbeat')<time()-30)){exit(1);}
    exit(0);
}catch(Throwable){exit(1);}
