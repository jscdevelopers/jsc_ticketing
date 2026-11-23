<?php
declare(strict_types=1);

$demoSecrets=tempnam(sys_get_temp_dir(),'jsc-demo-');
$demoPasswords=[];
foreach(['requester.demo','agent.demo','manager.demo','designer.demo','publisher.demo']as$username){$demoPasswords[$username]=bin2hex(random_bytes(16));}
file_put_contents($demoSecrets,json_encode($demoPasswords,JSON_THROW_ON_ERROR));chmod($demoSecrets,0600);
$runDemo=static function(string$environment)use($env,$root,$demoSecrets):array{
    $settings=$env;$settings['APP_ENV']=$environment;$settings['ALLOW_DEMO_INTEGRATIONS']='true';$settings['DEMO_PASSWORDS_FILE']=$demoSecrets;
    $process=proc_open([PHP_BINARY,$root.'/bin/demo.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,$settings);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return[proc_close($process),$out,$error];
};
try{
    [$code,$out,$error]=$runDemo('test');expect($code===0&&$error===''&&str_contains($out,'Created fictional'),'local demo setup creates a complete fictional workflow and staff');
    expect((int)sql("SELECT count(*) FROM user_credentials c JOIN app_users u ON u.id=c.user_id WHERE u.external_subject LIKE 'local-demo:%' AND c.must_change")->fetchColumn()===5,'every demo password requires an initial change');
    expect((int)sql("SELECT count(*) FROM scope_grants g JOIN app_users u ON u.id=g.user_id WHERE u.external_subject LIKE 'local-demo:%' AND g.permission_code='ticket.read' AND g.scope_kind='national'")->fetchColumn()===0,'local demonstration never creates national ticket authority');
    $passwordHashes=sql("SELECT c.password_hash FROM user_credentials c JOIN app_users u ON u.id=c.user_id WHERE u.external_subject LIKE 'local-demo:%' ORDER BY c.username")->fetchAll(PDO::FETCH_COLUMN);
    [$code,$out]=$runDemo('offline');expect($code===0&&str_contains($out,'already exists'),'local demo re-run is a no-op');
    expect($passwordHashes===sql("SELECT c.password_hash FROM user_credentials c JOIN app_users u ON u.id=c.user_id WHERE u.external_subject LIKE 'local-demo:%' ORDER BY c.username")->fetchAll(PDO::FETCH_COLUMN),'demo re-run does not reset credentials');
    [$code]=$runDemo('production');expect($code!==0,'production refuses fictional organization provisioning');
}finally{unlink($demoSecrets);}
