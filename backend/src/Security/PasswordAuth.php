<?php
declare(strict_types=1);
namespace App\Security;

use App\Support\Sql;
use App\Http\ApiException;
use App\Http\Request;
use PDO;
use Throwable;

final class PasswordAuth
{
    public function __construct(private PDO $db,private ?\Closure $mfaClient=null) {}

    private function client():object
    {
        if($this->mfaClient){return($this->mfaClient)();}
        require_once dirname(__DIR__,2).'/integrations/nauth.php';
        $file=getenv('NAUTH_CONFIG_FILE')?:'';
        if($file===''||!is_readable($file)){throw new ApiException(503,'identity_unavailable','Identity verification is not configured.');}
        return new \NAuthClient($file);
    }

    private function throttle(string $bucket,int $maximum):void
    {
        $count=Sql::run($this->db,"INSERT INTO login_rate_limits(bucket,attempts,expires_at) VALUES (?,1,now()+interval '15 minutes')
            ON CONFLICT(bucket) DO UPDATE SET attempts=CASE WHEN login_rate_limits.expires_at<=now() THEN 1 ELSE login_rate_limits.attempts+1 END,
            expires_at=CASE WHEN login_rate_limits.expires_at<=now() THEN now()+interval '15 minutes' ELSE login_rate_limits.expires_at END RETURNING attempts",[$bucket])->fetchColumn();
        if((int)$count>$maximum){throw new ApiException(429,'login_rate_limited','Too many sign-in attempts. Retry after fifteen minutes.');}
    }

    public function login(array $body,string $ip):array
    {
        if(array_diff(array_keys($body),['username','password'])||!is_string($body['username']??null)||!is_string($body['password']??null)||strlen($body['username'])>100||strlen($body['password'])>72){throw new ApiException(422,'invalid_login','Enter your username and password.');}
        $username=strtolower(trim($body['username']));
        $this->throttle('ip:'.hash('sha256',$ip),30);$this->throttle('user:'.hash('sha256',$username),8);
        $credential=Sql::run($this->db,'SELECT c.*,u.active,u.external_subject FROM user_credentials c JOIN app_users u ON u.id=c.user_id WHERE c.username=?',[$username])->fetch();
        // Constant-cost password check even when the username is unknown.
        $hash=$credential['password_hash']??'$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $verified=password_verify($body['password'],$hash);
        if(!$credential||!$credential['active']||!$verified){throw new ApiException(401,'invalid_credentials','Username or password is incorrect.');}
        // Reserve before expensive verification, then refund valid primary credentials.
        // Successful staff sign-ins must not exhaust a shared workstation/proxy's failure budget.
        foreach(['ip:'.hash('sha256',$ip),'user:'.hash('sha256',$username)]as$bucket){Sql::run($this->db,'UPDATE login_rate_limits SET attempts=GREATEST(0,attempts-1) WHERE bucket=?',[$bucket]);}
        $mode=getenv('AUTH_MODE')?:'password';
        if((getenv('APP_ENV')?:'')==='production'&&$mode!=='nauth'){throw new ApiException(503,'identity_unavailable','Production requires the approved identity verification adapter.');}
        if(!in_array($mode,['password','nauth'],true)){throw new ApiException(503,'identity_unavailable','Unsupported identity mode.');}
        if($mode==='password'){return$this->issue($credential['user_id'],$credential['password_changed_at']);}
        $this->throttle('mfa-start-ip:'.hash('sha256',$ip),60);$this->throttle('mfa-start-user:'.hash('sha256',$username),8);
        $client=$this->client();$status=$client->checkUser($credential['external_subject'],$username);
        if(($status['ok']??false)!==true||($status['data']['authentication_allowed']??false)!==true){throw new ApiException(403,'mfa_unavailable','Central identity verification denied sign-in. Contact your administrator.');}
        $biometric=($status['data']['biometric_available']??false)===true||($status['data']['biometric_required']??false)===true;
        if(!$biometric&&($status['data']['code_allowed']??false)!==true){throw new ApiException(403,'mfa_unavailable','No approved verification factor is available.');}
        $remote=null;
        if($biometric){$challenge=$client->startBiometric($credential['external_subject'],$username);if(($challenge['ok']??false)!==true||!is_string($challenge['data']['challenge_id']??null)){throw new ApiException(503,'identity_unavailable','Phone verification could not be started.');}$remote=$challenge['data']['challenge_id'];}
        $token=bin2hex(random_bytes(32));
        Sql::run($this->db,"INSERT INTO login_challenges(challenge_hash,user_id,mode,remote_challenge,credential_changed_at,expires_at) VALUES (?,?,?,?,?,now()+interval '3 minutes')",[hash('sha256',$token),$credential['user_id'],$biometric?'biometric':'code',$remote,$credential['password_changed_at']]);
        return ['challenge'=>$token,'mode'=>$biometric?'biometric':'code','expires_in'=>180];
    }

    public function verify(array $body,string $ip):array
    {
        if(array_diff(array_keys($body),['challenge','code'])||!is_string($body['challenge']??null)||!preg_match('/^[a-f0-9]{64}$/D',$body['challenge'])||isset($body['code'])&&!is_string($body['code'])){throw new ApiException(422,'invalid_challenge','Invalid identity challenge.');}
        $this->throttle('mfa-ip:'.hash('sha256',$ip),60);
        $this->db->beginTransaction();
        try{
            $row=Sql::run($this->db,'SELECT l.*,u.external_subject,u.active,c.username FROM login_challenges l JOIN app_users u ON u.id=l.user_id JOIN user_credentials c ON c.user_id=u.id
                WHERE l.challenge_hash=? AND c.password_changed_at=l.credential_changed_at FOR UPDATE OF l',[hash('sha256',$body['challenge'])])->fetch();
            if(!$row||!$row['active']||$row['consumed_at']!==null||strtotime($row['expires_at'])<=time()||(int)$row['attempts']>=5){throw new ApiException(401,'invalid_challenge','Identity challenge is invalid or expired.');}
            Sql::run($this->db,'UPDATE login_challenges SET attempts=attempts+1 WHERE challenge_hash=?',[$row['challenge_hash']]);
            // Never automatically retry verification/consumption against the central provider.
            $client=$this->client();$result=$row['mode']==='biometric'?$client->biometricStatus($row['remote_challenge']):$client->verify($row['external_subject'],$row['username'],$body['code']??'');
            if(($result['allowed']??false)!==true){$this->db->commit();throw new ApiException(401,'verification_pending','Verification was not approved.');}
            Sql::run($this->db,'UPDATE login_challenges SET consumed_at=now() WHERE challenge_hash=?',[$row['challenge_hash']]);
            $this->db->commit();return$this->issue($row['user_id'],$row['credential_changed_at']);
        }catch(Throwable$error){if($this->db->inTransaction()){$this->db->rollBack();}throw$error;}
    }

    private function issue(string $user,string $credentialVersion):array
    {
        $this->db->beginTransaction();try{
            $active=Sql::run($this->db,'SELECT active FROM app_users WHERE id=? FOR UPDATE',[$user])->fetchColumn();
            $credential=Sql::run($this->db,'SELECT * FROM user_credentials WHERE user_id=? FOR UPDATE',[$user])->fetch();
            if(!$credential||!$active||$credential['password_changed_at']!==$credentialVersion){throw new ApiException(401,'invalid_credentials','Sign-in unavailable.');}
            $token=bin2hex(random_bytes(32));$purpose=$credential['must_change']?'password_change':'access';
            Sql::run($this->db,"INSERT INTO api_tokens(user_id,token_hash,expires_at,purpose) VALUES (?,?,now()+interval '8 hours',?)",[$user,hash('sha256',$token),$purpose]);
            Sql::run($this->db,"INSERT INTO security_events(event_type,actor_user_id,reason) VALUES ('SIGNED_IN',?,'Primary and configured identity factors verified')",[$user]);
            $this->db->commit();return['token'=>$token,'must_change_password'=>(bool)$credential['must_change'],'expires_in'=>28800];
        }catch(Throwable$error){if($this->db->inTransaction()){$this->db->rollBack();}throw$error;}
    }

    public function change(string $actor,array $body):array
    {
        if(array_diff(array_keys($body),['current_password','new_password'])||!is_string($body['current_password']??null)||!is_string($body['new_password']??null)){throw new ApiException(422,'invalid_password','Current and new passwords are required.');}
        self::validatePassword($body['new_password']);
        $this->db->beginTransaction();try{
            Sql::run($this->db,'SELECT id FROM app_users WHERE id=? FOR UPDATE',[$actor]);
            $row=Sql::run($this->db,'SELECT * FROM user_credentials WHERE user_id=? FOR UPDATE',[$actor])->fetch();
            if(!$row||!password_verify($body['current_password'],$row['password_hash'])){throw new ApiException(403,'password_mismatch','Current password is incorrect.');}
            if(password_verify($body['new_password'],$row['password_hash'])){throw new ApiException(422,'password_reused','Choose a different password.');}
            Sql::run($this->db,'UPDATE user_credentials SET password_hash=?,must_change=false,password_changed_at=now() WHERE user_id=?',[password_hash($body['new_password'],PASSWORD_BCRYPT,['cost'=>12]),$actor]);
            Sql::run($this->db,'UPDATE api_tokens SET revoked_at=now() WHERE user_id=? AND revoked_at IS NULL',[$actor]);
            Sql::run($this->db,'UPDATE login_challenges SET consumed_at=now() WHERE user_id=? AND consumed_at IS NULL',[$actor]);
            Sql::run($this->db,"INSERT INTO security_events(event_type,actor_user_id,reason) VALUES ('PASSWORD_CHANGED',?,'User changed password; all sessions revoked')",[$actor]);
            $this->db->commit();return['changed'=>true,'sign_in_required'=>true];
        }catch(Throwable$error){if($this->db->inTransaction()){$this->db->rollBack();}throw$error;}
    }

    public static function validatePassword(string $password):void
    {
        if(strlen($password)<12||strlen($password)>72||str_contains($password,"\0")){throw new ApiException(422,'weak_password','Use a password of 12..72 bytes without null characters.');}
    }
}
