<?php
declare(strict_types=1);
namespace App\Tickets;

use App\Http\ApiException;
use App\Security\IntakeAccess;
use PDO;

final class IntakeCatalog
{
    public function __construct(private PDO $db) {}

    public function listing(string $actor, array $query, bool $assignments): array
    {
        IntakeAccess::requirePermission($this->db, $actor);
        if (array_diff(array_keys($query), ['q','after','id']) || count(array_filter($query, 'is_string')) !== count($query)
            || (isset($query['id']) && !TicketReader::uuid($query['id'])) || strlen($query['q'] ?? '') > 200 || (isset($query['after']) && !TicketReader::uuid($query['after']))) {
            throw new ApiException(422, 'invalid_filter', 'Use a search of at most 200 bytes and a valid after UUID.');
        }
        $params = [];
        if ($assignments) {
            $sql = IntakeAccess::assignmentSql();
            $params['actor'] = $actor;
            $id = 'a.id';
            $search = "s.name || ' ' || d.name || ' ' || p.title";
        } else {
            $sql = "SELECT c.id,c.code,c.name,c.department_id,v.id AS workflow_version_id,v.definition->'form_schema' AS form_schema
                FROM categories c JOIN departments d ON d.id=c.department_id
                JOIN workflow_templates w ON w.category_id=c.id
                JOIN workflow_versions v ON v.id=w.active_version_id AND v.template_id=w.id AND v.status='published'
                WHERE c.active AND d.active AND v.definition->>'engine' IN ('department-triage-v1','workflow-v1')
                  AND v.definition->>'department_id'=c.department_id::text
                  AND (SELECT count(*) FROM workflow_templates other WHERE other.category_id=c.id AND other.active_version_id IS NOT NULL)=1";
            $id = 'c.id';
            $search = "c.name || ' ' || c.code";
        }
        if(isset($query['id'])){$sql.=" AND $id=CAST(:choice_id AS uuid)";$params['choice_id']=$query['id'];}
        if (isset($query['after'])) {
            $sql .= " AND $id>CAST(:after AS uuid)";
            $params['after'] = $query['after'];
        }
        if (($query['q'] ?? '') !== '') {
            $sql .= " AND ($search) ILIKE :search ESCAPE '!'";
            $params['search'] = '%' . str_replace(['!','%','_'], ['!!','!%','!_'], $query['q']) . '%';
        }
        $statement = $this->db->prepare($sql . " ORDER BY $id LIMIT 11");
        $statement->execute($params);
        $rows = $statement->fetchAll();
        $more = count($rows) > 10;
        if ($more) {
            array_pop($rows);
        }
        $last = end($rows);
        foreach ($rows as &$row) {
            if (isset($row['form_schema'])) {
                $row['form_schema'] = json_decode($row['form_schema'], false, 32, JSON_THROW_ON_ERROR);
            }
        }
        return ['data'=>$rows,'meta'=>['next_after'=>$more ? $last['id'] : null,'limit'=>10]];
    }
}
