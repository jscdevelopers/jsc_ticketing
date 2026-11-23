<?php
declare(strict_types=1);
$sampleEnv=getenv('APP_ENV');$sampleFlag=getenv('ALLOW_DEMO_INTEGRATIONS');putenv('APP_ENV=test');putenv('ALLOW_DEMO_INTEGRATIONS=true');
try{
    $passwords=[];foreach(App\Support\JscSamples::usernames()as$name)$passwords[$name]=bin2hex(random_bytes(16));
    $beforeHashes=sql("SELECT user_id,password_hash FROM user_credentials WHERE username LIKE '%.demo' ORDER BY user_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $installer=new App\Support\JscSamples($db);$result=$installer->install($passwords);
    expect($result['provinces']===10&&$result['court_levels']===7&&$result['stations']===19,'JSC samples cover national geography and distinct court levels/stations');
    expect($result['categories']===9&&$result['departments']===14&&$result['positions']===18,'sample structure includes service departments, reporting positions and runnable workflows');
    foreach($beforeHashes as$user=>$hash){expect(sql('SELECT password_hash FROM user_credentials WHERE user_id=?',[$user])->fetchColumn()===$hash,'renaming legacy sample logins preserves password hashes');}
    expect((int)sql("SELECT count(*) FROM user_credentials WHERE username LIKE '%.demo'")->fetchColumn()===0,'sample usernames no longer use demo naming');
    expect((int)sql("SELECT count(*) FROM stations s JOIN court_levels c ON c.id=s.court_level_id WHERE s.is_secretariat_station AND c.code='SECRETARIAT'")->fetchColumn()===1,'Secretariat is an explicitly classified office, not a national data grant');
    $counts=sql("SELECT t.status,count(*) FROM tickets t JOIN app_users u ON u.id=t.requester_id WHERE u.external_subject LIKE 'local-jsc:registry.%' GROUP BY t.status")->fetchAll(PDO::FETCH_KEY_PAIR);
    expect(isset($counts['routed'],$counts['in_progress'],$counts['closed'])&&array_sum($counts)===37,'full operational samples run through actual intake, claims and completion');
    $manager=sql("SELECT user_id FROM user_credentials WHERE username='station.manager'")->fetchColumn();
    try{(new App\Tickets\Reporting($db))->dashboard($manager,['scope'=>'national']);expect(false,'station manager must not inherit national read');}catch(App\Http\ApiException$error){expect($error->status===403,'sample station manager remains geographically scoped');}
    $national=sql("SELECT user_id FROM user_credentials WHERE username='national.oversight'")->fetchColumn();expect((new App\Tickets\Reporting($db))->dashboard($national,['scope'=>'national'])['total']>=37,'separate explicitly granted oversight account can inspect national samples');
    $ticketCount=(int)sql('SELECT count(*) FROM tickets')->fetchColumn();$events=(int)sql('SELECT count(*) FROM ticket_events')->fetchColumn();$again=$installer->install($passwords);
    expect($again['already_installed']&&(int)sql('SELECT count(*) FROM tickets')->fetchColumn()===$ticketCount&&(int)sql('SELECT count(*) FROM ticket_events')->fetchColumn()===$events,'re-running samples duplicates neither tickets nor events');
    $configUser=sql("SELECT user_id FROM user_credentials WHERE username='configuration.admin'")->fetchColumn();$organization=new App\Security\Administration($db);
    expect(count($organization->listing($configUser,'court-levels',['q'=>'High Court'])['data'])===1,'court classifications are searchable through the secured administration interface');
    $rootDepartment=sql("SELECT * FROM departments WHERE code='SECRETARIAT'")->fetch();$child=sql("SELECT id FROM departments WHERE code='ICT'")->fetchColumn();
    try{$organization->save($configUser,'departments',$rootDepartment['id'],(int)$rootDepartment['version'],'jsc-dept-cycle',['code'=>$rootDepartment['code'],'name'=>$rootDepartment['name'],'parent_id'=>$child,'active'=>true,'reason'=>'Cycle must fail']);expect(false,'department reporting cycle must fail');}catch(App\Http\ApiException$error){expect($error->status===422,'department hierarchy rejects reporting cycles');}
    putenv('APP_ENV=production');try{$installer->install($passwords);expect(false,'production must refuse samples');}catch(InvalidArgumentException){expect(true,'sample import is blocked in production');}
}finally{putenv($sampleEnv===false?'APP_ENV':'APP_ENV='.$sampleEnv);putenv($sampleFlag===false?'ALLOW_DEMO_INTEGRATIONS':'ALLOW_DEMO_INTEGRATIONS='.$sampleFlag);}
