<?php
declare(strict_types=1);
namespace App\Security;

use App\Http\ApiException;
use App\Support\Sql;
use PDO;

final class Access
{
    public static function roleSql(string $permission, string $actor = 'actor'): string
    {
        if (!preg_match('/^[a-z_]+\.[a-z_]+$/D', $permission) || !preg_match('/^[a-z_]+$/D',$actor)) {
            throw new \LogicException('Invalid permission identifier.');
        }
        return "EXISTS (SELECT 1 FROM user_roles ur JOIN role_permissions rp ON rp.role_id=ur.role_id
            WHERE ur.user_id=$actor.id AND rp.permission_code='$permission'
            AND ur.valid_from<=statement_timestamp() AND (ur.valid_to IS NULL OR ur.valid_to>statement_timestamp()))";
    }

    public static function grantSql(string $permission, string $actor = 'actor', string $ticket = 't', ?string $task = null): string
    {
        self::roleSql($permission,$actor);
        $department = $task === null ? "$ticket.responsible_department_id" : "$task.assigned_department_id";
        $nationalOnly = $task === null ? '' : " AND ($task.scope_kind<>'national' OR g.scope_kind='national')";
        return "EXISTS (SELECT 1 FROM scope_grants g WHERE g.user_id=$actor.id AND g.permission_code='$permission'
            AND g.revoked_at IS NULL AND g.valid_from<=statement_timestamp() AND (g.valid_to IS NULL OR g.valid_to>statement_timestamp())
            $nationalOnly AND ((g.scope_kind='self' AND $ticket.requester_id=$actor.id)
                OR (g.scope_kind='station' AND g.scope_ref_id=$ticket.origin_station_id)
                OR (g.scope_kind='province' AND g.scope_ref_id=$ticket.origin_province_id)
                OR (g.scope_kind='department' AND g.scope_ref_id=$department)
                OR g.scope_kind='national' OR (g.scope_kind='ticket_explicit' AND g.scope_ref_id=$ticket.id)))";
    }

    public static function national(PDO $db, string $actor, string $permission): void
    {
        $sql = "SELECT 1 FROM app_users actor WHERE actor.id=? AND actor.active AND " . self::roleSql($permission)
            . " AND EXISTS(SELECT 1 FROM scope_grants g WHERE g.user_id=actor.id AND g.permission_code=? AND g.scope_kind='national'
                AND g.revoked_at IS NULL AND g.valid_from<=statement_timestamp() AND (g.valid_to IS NULL OR g.valid_to>statement_timestamp()))";
        if (!Sql::run($db,$sql,[$actor,$permission])->fetchColumn()) {
            throw new ApiException(403,'permission_denied','An explicit active national permission is required.');
        }
    }

    public static function ticket(PDO $db, string $actor, string $permission, string $ticket): void
    {
        $scope=self::grantSql($permission);
        if($permission==='ticket.comment'){$scope='('.$scope.' OR '.TaskAccess::candidateSql().')';}
        if (!Sql::run($db,'SELECT 1 FROM tickets t JOIN app_users actor ON actor.id=CAST(? AS uuid) WHERE t.id=CAST(? AS uuid) AND actor.active AND '
            .self::roleSql($permission).' AND '.$scope,[$actor,$ticket])->fetchColumn()) {
            throw new ApiException(403,'permission_denied','This action is outside your current authority.');
        }
    }

    public static function department(PDO $db,string $actor,string $permission,string $department):void
    {
        $sql='SELECT 1 FROM app_users actor WHERE actor.id=CAST(? AS uuid) AND actor.active AND '.self::roleSql($permission)
            ." AND EXISTS(SELECT 1 FROM scope_grants g WHERE g.user_id=actor.id AND g.permission_code=?
                AND (g.scope_kind='national' OR (g.scope_kind='department' AND g.scope_ref_id=CAST(? AS uuid)))
                AND g.revoked_at IS NULL AND g.valid_from<=statement_timestamp() AND (g.valid_to IS NULL OR g.valid_to>statement_timestamp()))";
        if(!Sql::run($db,$sql,[$actor,$permission,$department])->fetchColumn()){throw new ApiException(403,'permission_denied','Workflow department authority is required.');}
    }

    public static function task(PDO$db,string$actor,string$permission,string$task):void
    {
        if(!Sql::run($db,'SELECT 1 FROM task_instances ti JOIN tickets t ON t.id=ti.ticket_id JOIN app_users actor ON actor.id=CAST(? AS uuid) WHERE ti.id=CAST(? AS uuid) AND actor.active AND '.self::roleSql($permission).' AND '.self::grantSql($permission,'actor','t','ti'),[$actor,$task])->fetchColumn()){
            throw new ApiException(403,'permission_denied','This task is outside your current management authority.');
        }
    }
}
