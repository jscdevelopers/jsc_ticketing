<?php
declare(strict_types=1);
namespace App\Security;

final class TaskAccess
{
    public static function eligibilitySql(): string
    {
        return Access::roleSql('task.claim') . ' AND ' . Access::grantSql('task.claim','actor','t','ti') . " AND EXISTS (
            SELECT 1 FROM staff_assignments sa JOIN stations st ON st.id=sa.station_id
            JOIN departments dp ON dp.id=sa.department_id JOIN positions pos ON pos.id=sa.position_id
            LEFT JOIN provinces pv ON pv.id=st.province_id
            WHERE sa.user_id=actor.id AND sa.department_id=ti.assigned_department_id
            AND (ti.assigned_position_id IS NULL OR sa.position_id=ti.assigned_position_id)
            AND sa.begins_at<=statement_timestamp() AND (sa.ends_at IS NULL OR sa.ends_at>statement_timestamp())
            AND st.active AND dp.active AND pos.active AND (pv.id IS NULL OR pv.active)
            AND ((ti.scope_kind='station' AND sa.station_id=t.origin_station_id)
              OR (ti.scope_kind='province' AND st.province_id=t.origin_province_id)
              OR ti.scope_kind IN ('department','national')))";
    }

    public static function candidateSql(): string
    {
        return "EXISTS (SELECT 1 FROM task_instances ti JOIN task_candidates tc ON tc.task_id=ti.id
            WHERE ti.ticket_id=t.id AND tc.user_id=actor.id AND tc.revoked_at IS NULL
              AND (tc.eligible_until IS NULL OR tc.eligible_until>statement_timestamp())
              AND (ti.status IN ('offered','claimed','in_progress') OR (ti.status IN ('completed','rejected') AND ti.claimed_by=actor.id))
              AND " . self::eligibilitySql() . ')';
    }
}
