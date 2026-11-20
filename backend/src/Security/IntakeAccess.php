<?php
declare(strict_types=1);
namespace App\Security;

use App\Http\ApiException;
use PDO;

final class IntakeAccess
{
    public static function requirePermission(PDO $db, string $actor): void
    {
        $query = $db->prepare("SELECT p.code FROM permissions p WHERE p.code IN ('ticket.create','ticket.read')
            AND EXISTS (SELECT 1 FROM app_users u WHERE u.id=CAST(:actor AS uuid) AND u.active
              AND EXISTS (SELECT 1 FROM user_roles ur JOIN role_permissions rp ON rp.role_id=ur.role_id
                WHERE ur.user_id=u.id AND rp.permission_code=p.code AND ur.valid_from<=statement_timestamp()
                  AND (ur.valid_to IS NULL OR ur.valid_to>statement_timestamp()))
              AND EXISTS (SELECT 1 FROM scope_grants g WHERE g.user_id=u.id AND g.permission_code=p.code
                AND g.scope_kind='self' AND g.revoked_at IS NULL AND g.valid_from<=statement_timestamp()
                AND (g.valid_to IS NULL OR g.valid_to>statement_timestamp())))");
        $query->execute(['actor'=>$actor]);
        if (count($query->fetchAll()) !== 2) {
            throw new ApiException(403, 'submission_forbidden', 'Permission to submit and read your own tickets is required.');
        }
    }

    public static function assignmentSql(): string
    {
        return "SELECT a.id,a.position_id,a.department_id,a.station_id,s.province_id,
            p.title AS position_title,d.name AS department_name,s.name AS station_name,v.name AS province_name
            FROM staff_assignments a JOIN positions p ON p.id=a.position_id
            JOIN departments d ON d.id=a.department_id JOIN stations s ON s.id=a.station_id
            LEFT JOIN provinces v ON v.id=s.province_id
            WHERE a.user_id=CAST(:actor AS uuid) AND a.begins_at<=statement_timestamp()
              AND (a.ends_at IS NULL OR a.ends_at>statement_timestamp())
              AND p.active AND d.active AND s.active AND (v.id IS NULL OR v.active)";
    }
}
