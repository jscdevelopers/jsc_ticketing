<?php
declare(strict_types=1);

use App\Support\Sql;
use App\Security\PasswordAuth;

require dirname(__DIR__).'/bootstrap/autoload.php';
$username=strtolower(trim($argv[1]??'administrator'));
if(!preg_match('/^[a-z0-9][a-z0-9._@-]{2,99}$/D',$username)){fwrite(STDERR,"Valid administrator username required.\n");exit(1);}
$password=rtrim((string)fgets(STDIN),"\r\n");PasswordAuth::validatePassword($password);
$db=App\Config\Database::connection();$db->beginTransaction();
try{
    $db->query('SELECT pg_advisory_xact_lock(741903220)');
    if((int)$db->query('SELECT count(*) FROM user_credentials')->fetchColumn()>0){$db->rollBack();echo "Administrator setup already completed; no account was changed.\n";exit(0);}
    $user=Sql::run($db,"INSERT INTO app_users(external_subject,display_name) VALUES (?,'System Administrator') RETURNING id",['local:administrator:'.$username])->fetchColumn();
    Sql::run($db,'INSERT INTO user_credentials(user_id,username,password_hash,must_change) VALUES (?,?,?,true)',[$user,$username,password_hash($password,PASSWORD_BCRYPT,['cost'=>12])]);
    Sql::run($db,"INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE code='operations_admin'",[$user]);
    foreach(['directory.manage','security.manage','workflow.design','workflow.publish']as$permission){Sql::run($db,"INSERT INTO scope_grants(user_id,permission_code,scope_kind,grant_reason) VALUES (?,?,'national','Initial operator configuration authority')",[$user,$permission]);}
    Sql::run($db,"INSERT INTO security_events(event_type,actor_system,subject_id,reason) VALUES ('INITIAL_ADMINISTRATOR_CREATED','setup',?,'Configuration authority only; no national ticket visibility granted')",[$user]);
    $db->commit();echo json_encode(['user_id'=>$user,'username'=>$username,'password_change_required'=>true],JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable$error){if($db->inTransaction()){$db->rollBack();}fwrite(STDERR,'Setup failed ('.$error::class.').'.PHP_EOL);exit(1);}
