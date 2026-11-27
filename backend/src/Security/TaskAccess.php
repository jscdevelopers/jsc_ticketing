<?php
declare(strict_types=1);
namespace App\Security;

final class TaskAccess
{
    public static function eligibilitySql(string$mode='all'): string
    {
        $primary="((COALESCE(ti.routing_policy->>'audience_mode','both')<>'people' AND (ti.assigned_position_id IS NULL OR sa.position_id=ti.assigned_position_id OR jsonb_exists(ti.routing_policy->'position_ids',sa.position_id::text))) OR (COALESCE(ti.routing_policy->>'audience_mode','both')<>'positions' AND jsonb_exists(ti.routing_policy->'user_ids',actor.id::text))) AND ".self::geography('ti.scope_kind');
        $intervention="EXISTS(SELECT 1 FROM jsonb_array_elements(COALESCE(ti.routing_policy->'intervention_rules','[]'::jsonb)) rule WHERE (jsonb_exists(rule->'position_ids',sa.position_id::text) OR jsonb_exists(rule->'user_ids',actor.id::text)) AND ".self::geography("rule->>'scope'")." AND (rule->>'scope'<>'national' OR (EXISTS(SELECT 1 FROM scope_grants ng WHERE ng.user_id=actor.id AND ng.permission_code='task.intervene' AND ng.scope_kind='national' AND ng.revoked_at IS NULL AND ng.valid_from<=now() AND (ng.valid_to IS NULL OR ng.valid_to>now())) AND EXISTS(SELECT 1 FROM scope_grants ng WHERE ng.user_id=actor.id AND ng.permission_code='task.claim' AND ng.scope_kind='national' AND ng.revoked_at IS NULL AND ng.valid_from<=now() AND (ng.valid_to IS NULL OR ng.valid_to>now()))))) AND ".Access::roleSql('task.intervene').' AND '.Access::grantSql('task.intervene','actor','t','ti');
        $audience=$mode==='primary'?$primary:($mode==='intervention'?$intervention:'(('.$primary.') OR ('.$intervention.'))');
        return Access::roleSql('task.claim').' AND '.Access::grantSql('task.claim','actor','t','ti')." AND EXISTS (
            SELECT 1 FROM staff_assignments sa JOIN stations st ON st.id=sa.station_id JOIN departments dp ON dp.id=sa.department_id
            JOIN positions pos ON pos.id=sa.position_id LEFT JOIN provinces pv ON pv.id=st.province_id
            WHERE sa.user_id=actor.id AND sa.department_id=ti.assigned_department_id AND sa.begins_at<=now() AND (sa.ends_at IS NULL OR sa.ends_at>now())
            AND st.active AND dp.active AND pos.active AND (pv.id IS NULL OR pv.active) AND (".$audience.'))';
    }
    private static function geography(string$scope):string
    {
        return "(($scope='station' AND sa.station_id=t.origin_station_id) OR ($scope='province' AND st.province_id=t.origin_province_id) OR $scope IN ('department','national'))";
    }

    public static function candidateSql(): string
    {
        return "EXISTS (SELECT 1 FROM task_instances ti
            WHERE ti.ticket_id=t.id AND (EXISTS(SELECT 1 FROM task_candidates tc WHERE tc.task_id=ti.id AND tc.user_id=actor.id AND tc.revoked_at IS NULL AND (tc.eligible_until IS NULL OR tc.eligible_until>now())) OR (".self::eligibilitySql('intervention')."))
              AND (ti.status IN ('offered','claimed','in_progress','blocked') OR (ti.status IN ('completed','rejected') AND ti.claimed_by=actor.id))
              AND " . self::eligibilitySql() . ')';
    }
}
