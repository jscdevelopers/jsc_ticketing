<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
$db=App\Config\Database::connection();
if($db->query('SELECT current_database()')->fetchColumn()!=='ticketing_test'){exit(1);}
echo "READY\n";fflush(STDOUT);
try{
    $result=(new App\Workflow\TaskService($db))->action($argv[1],$argv[2],'claim',1,'claim-race',[],'race-test');
    echo json_encode(['status'=>200,'result'=>$result],JSON_THROW_ON_ERROR).PHP_EOL;
}catch(App\Http\ApiException $error){echo json_encode(['status'=>$error->status,'error'=>$error->errorCode],JSON_THROW_ON_ERROR).PHP_EOL;}
catch(Throwable $error){fwrite(STDERR,$error::class.': '.$error->getMessage().PHP_EOL);exit(1);}
