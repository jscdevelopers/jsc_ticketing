<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
$db=App\Config\Database::connection();
if(getenv('APP_ENV')!=='test'||$db->query('SELECT current_database()')->fetchColumn()!=='ticketing_test'){fwrite(STDERR,"Recovery probe requires a disposable test database.\n");exit(1);}
try{
    (new App\Config\Migrator($db,dirname(__DIR__).'/database'))->run();
    $requester=App\Support\Sql::run($db,"SELECT u.id,a.id AS assignment_id FROM app_users u JOIN staff_assignments a ON a.user_id=u.id WHERE u.external_subject='local-demo:requester.demo' AND u.active AND a.begins_at<=now() AND (a.ends_at IS NULL OR a.ends_at>now()) LIMIT 1")->fetch();
    if(!$requester){throw new RuntimeException('Seed a local demo before exercising operational recovery.');}
    $catalog=new App\Tickets\IntakeCatalog($db);$categories=$catalog->listing($requester['id'],['q'=>'Equipment and network support'],false)['data'];$jsc=(bool)$categories;if(!$categories)$categories=$catalog->listing($requester['id'],['q'=>'Demo equipment support'],false)['data'];
    if(count($categories)!==1){throw new RuntimeException('A published demo workflow is required.');}
    $ticket=(new App\Tickets\TicketSubmission($db))->submit($requester['id'],['assignment_id'=>$requester['assignment_id'],'category_id'=>$categories[0]['id'],'subject'=>'Disposable recovery probe','description'=>'Created only in the restored test database.','fields'=>[$jsc?'service_reference':'asset_tag'=>'RECOVERY-TEST']],bin2hex(random_bytes(12)),'recovery-probe')['ticket'];
    (new App\Tickets\TicketReader($db))->detail($requester['id'],$ticket['id']);
    $agent=App\Support\Sql::run($db,"SELECT id FROM app_users WHERE external_subject='local-demo:agent.demo'")->fetchColumn();
    $task=App\Support\Sql::run($db,'SELECT id,version FROM task_instances WHERE ticket_id=?',[$ticket['id']])->fetch();
    $service=new App\Workflow\TaskService($db);$claimed=$service->action($agent,$task['id'],'claim',(int)$task['version'],'recovery-claim',[],'recovery-probe')['data'];
    $service->action($agent,$task['id'],'complete',$claimed['version'],'recovery-complete',['reason'=>'Restored workflow executed successfully'],'recovery-probe');
    if((new App\Tickets\TicketReader($db))->detail($requester['id'],$ticket['id'])['status']!=='closed'){throw new RuntimeException('Recovered workflow did not complete.');}
    echo "PASS: restored schema, requester authorization, intake, eligibility, claim and completion.\n";
}catch(Throwable$error){fwrite(STDERR,'Recovery probe failed ('.$error::class.').'.PHP_EOL);exit(1);}
