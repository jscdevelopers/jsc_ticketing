<?php
declare(strict_types=1);
namespace App\Support;

use App\Http\ApiException;
use PDO;
use Throwable;

final class Mutation
{
    public static function run(PDO $db,string $actor,string $operation,string $key,array $body,callable $action,callable $replayGuard): array
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D',$key)) {
            throw new ApiException(422,'invalid_idempotency_key','A valid Idempotency-Key is required.');
        }
        $hash = hash('sha256',json_encode(self::canonical($body),JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $db->beginTransaction();
        try {
            Sql::run($db,"SELECT set_config('app.actor_user_id',?,true)",[$actor]);
            if (!Sql::run($db,'SELECT id FROM app_users WHERE id=? AND active FOR UPDATE',[$actor])->fetchColumn()) {
                throw new ApiException(401,'authentication_required','Sign in is required.');
            }
            $previous = Sql::run($db,'SELECT * FROM idempotency_requests WHERE actor_user_id=? AND operation=? AND request_key=? FOR UPDATE',[$actor,$operation,$key])->fetch();
            if ($previous) {
                if (!hash_equals($previous['request_hash'],$hash) || $previous['response']===null) {
                    throw new ApiException(409,'idempotency_conflict','Key already used for different content.');
                }
                $result = json_decode($previous['response'],true,64,JSON_THROW_ON_ERROR);
                $replayGuard($result);
                $db->commit();
                return ['data'=>$result,'meta'=>['replayed'=>true]];
            }
            $id = Sql::run($db,'INSERT INTO idempotency_requests(actor_user_id,operation,request_key,request_hash) VALUES (?,?,?,?) RETURNING id',[$actor,$operation,$key,$hash])->fetchColumn();
            $result = $action();
            Sql::run($db,'UPDATE idempotency_requests SET response=CAST(? AS jsonb) WHERE id=?',[json_encode($result,JSON_THROW_ON_ERROR),$id]);
            $db->commit();
            return ['data'=>$result,'meta'=>['replayed'=>false]];
        } catch (Throwable $error) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $error;
        }
    }

    public static function version(int $actual,int $expected): void
    {
        if ($actual!==$expected) { throw new ApiException(409,'version_conflict','The record changed. Reload before retrying.'); }
    }

    public static function reason(mixed $reason): string
    {
        if (!is_string($reason) || strlen(trim($reason))<3 || strlen($reason)>2000 || str_contains($reason,"\0")) {
            throw new ApiException(422,'reason_required','A reason of 3..2000 bytes is required.');
        }
        return trim($reason);
    }

    public static function canonical(array $value): array
    {
        if (!array_is_list($value)) { ksort($value); }
        foreach ($value as &$item) { if (is_array($item)) { $item=self::canonical($item); } }
        return $value;
    }
}
