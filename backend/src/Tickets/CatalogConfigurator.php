<?php
declare(strict_types=1);
namespace App\Tickets;

use PDO;
use Throwable;
use InvalidArgumentException;

/** Console-only configuration for versioned department triage, without arbitrary graph execution. */
final class CatalogConfigurator
{
    public function __construct(private PDO $db) {}

    public function configure(array $data, string $reason): array
    {
        $keys = ['category_id','department_id','code','name','form_schema'];
        if (array_diff($keys, array_keys($data)) || array_diff(array_keys($data), $keys)
            || !is_string($data['category_id']) || !TicketReader::uuid($data['category_id'])
            || !is_string($data['department_id']) || !TicketReader::uuid($data['department_id'])
            || !is_string($data['code']) || !preg_match('/^[A-Z][A-Z0-9_]{0,39}$/D', $data['code'])
            || !is_string($data['name']) || trim($data['name']) === '' || IntakeSchema::length($data['name']) > 100
            || !is_array($data['form_schema']) || strlen(trim($reason)) < 3 || strlen($reason) > 500) {
            throw new InvalidArgumentException('Valid category, department, code, name, form_schema and reason are required.');
        }
        IntakeSchema::validateDefinition($data['form_schema']);
        $data['category_id'] = strtolower($data['category_id']);
        $data['department_id'] = strtolower($data['department_id']);
        $definition = ['engine'=>'department-triage-v1','category_code'=>$data['code'],'category_name'=>trim($data['name']),
            'department_id'=>$data['department_id'],'form_schema'=>$data['form_schema']];
        $definition['form_schema']['properties'] = (object)$definition['form_schema']['properties'];
        $encoded = json_encode($definition, JSON_THROW_ON_ERROR);
        $this->db->beginTransaction();
        try {
            $this->db->query('SELECT pg_advisory_xact_lock(741903219)');
            $query = $this->db->prepare('SELECT id FROM departments WHERE id=? AND active FOR SHARE');
            $query->execute([$data['department_id']]);
            if (!$query->fetchColumn()) {
                throw new InvalidArgumentException('Active category department required.');
            }
            $query = $this->db->prepare('INSERT INTO categories(id,department_id,code,name,form_schema)
                VALUES (?,?,?,?,CAST(? AS jsonb)) ON CONFLICT(id) DO UPDATE SET
                department_id=EXCLUDED.department_id,code=EXCLUDED.code,name=EXCLUDED.name,form_schema=EXCLUDED.form_schema');
            $query->execute([$data['category_id'],$data['department_id'],$data['code'],trim($data['name']),json_encode($definition['form_schema'], JSON_THROW_ON_ERROR)]);
            $query = $this->db->prepare('SELECT id,active_version_id FROM workflow_templates WHERE category_id=? FOR UPDATE');
            $query->execute([$data['category_id']]);
            $templates = $query->fetchAll();
            if (count($templates) > 1) {
                throw new InvalidArgumentException('Category has multiple templates; reconcile them before intake configuration.');
            }
            if (!$templates) {
                $query = $this->db->prepare('INSERT INTO workflow_templates(code,name,owner_department_id,category_id) VALUES (?,?,?,?) RETURNING id');
                $query->execute(['intake-'.$data['category_id'],trim($data['name']),$data['department_id'],$data['category_id']]);
                $templateId = $query->fetchColumn();
                $active = null;
            } else {
                $templateId = $templates[0]['id'];
                $active = $templates[0]['active_version_id'];
                $query = $this->db->prepare("SELECT 1 FROM workflow_versions WHERE template_id=? AND definition->>'engine' IS DISTINCT FROM 'department-triage-v1' LIMIT 1");
                $query->execute([$templateId]);
                if ($query->fetchColumn() !== false) {
                    throw new InvalidArgumentException('Existing graph workflow cannot be replaced by a triage configuration.');
                }
            }
            $query = $this->db->prepare("SELECT id,version FROM workflow_versions WHERE id=CAST(? AS uuid) AND status='published' AND definition=CAST(? AS jsonb)");
            $query->execute([$active,$encoded]);
            $version = $query->fetch();
            if (!$version) {
                $query = $this->db->prepare("INSERT INTO workflow_versions(template_id,version,status,definition,published_at)
                    SELECT ?,COALESCE(MAX(version),0)+1,'published',CAST(? AS jsonb),now() FROM workflow_versions WHERE template_id=? RETURNING id,version");
                $query->execute([$templateId,$encoded,$templateId]);
                $version = $query->fetch();
            }
            $query = $this->db->prepare('UPDATE workflow_templates SET active_version_id=?,owner_department_id=?,name=? WHERE id=?');
            $query->execute([$version['id'],$data['department_id'],trim($data['name']),$templateId]);
            if ($active !== $version['id']) {
                $query = $this->db->prepare("INSERT INTO security_events(event_type,actor_system,subject_id,reason) VALUES ('INTAKE_VERSION_ACTIVATED','console',?,?)");
                $query->execute([$version['id'],$reason]);
            }
            $this->db->commit();
            return ['category_id'=>$data['category_id'],'workflow_version_id'=>$version['id'],'version'=>(int)$version['version']];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
