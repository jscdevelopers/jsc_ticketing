<?php
declare(strict_types=1);
namespace App\Security;

/** One SQL predicate for list/detail. Scope references are independent alternatives, never cross-products. */
final class TicketScope
{
    public static function predicate(): string
    {
        return "EXISTS (SELECT 1 FROM app_users actor WHERE actor.id=CAST(:actor AS uuid) AND actor.active
            AND EXISTS (SELECT 1 FROM user_roles ur
              JOIN role_permissions rp ON rp.role_id=ur.role_id
              WHERE ur.user_id=actor.id AND rp.permission_code='ticket.read'
                AND ur.valid_from<=statement_timestamp() AND (ur.valid_to IS NULL OR ur.valid_to>statement_timestamp()))
            AND (" . self::grant('ticket.read') . ' OR ' . TaskAccess::candidateSql() . ")
            AND (t.confidentiality='internal' OR (
              EXISTS (SELECT 1 FROM user_roles sur JOIN role_permissions srp ON srp.role_id=sur.role_id
                WHERE sur.user_id=actor.id AND srp.permission_code='ticket.read_sensitive'
                AND sur.valid_from<=statement_timestamp() AND (sur.valid_to IS NULL OR sur.valid_to>statement_timestamp()))
              AND " . self::grant('ticket.read_sensitive') . ')))';
    }

    private static function grant(string $permission): string
    {
        return "EXISTS (SELECT 1 FROM scope_grants g WHERE g.user_id=actor.id
            AND g.permission_code='$permission' AND g.revoked_at IS NULL
            AND g.valid_from<=statement_timestamp() AND (g.valid_to IS NULL OR g.valid_to>statement_timestamp())
            AND ((g.scope_kind='self' AND t.requester_id=actor.id)
              OR (g.scope_kind='station' AND g.scope_ref_id=t.origin_station_id)
              OR (g.scope_kind='province' AND g.scope_ref_id=t.origin_province_id)
              OR (g.scope_kind='department' AND g.scope_ref_id=t.responsible_department_id)
              OR g.scope_kind='national'
              OR (g.scope_kind='ticket_explicit' AND g.scope_ref_id=t.id)))";
    }
}
