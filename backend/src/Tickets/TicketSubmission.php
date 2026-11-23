<?php
declare(strict_types=1);
namespace App\Tickets;

use App\Http\ApiException;
use App\Security\IntakeAccess;
use PDO;
use Throwable;

final class TicketSubmission
{
    public function __construct(private PDO $db) {}

    public function submit(string $actor, array $body, string $key, string $requestId): array
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key)) {
            throw new ApiException(422, 'invalid_idempotency_key', 'Supply an Idempotency-Key of 1..128 letters, digits, dots, colons, underscores or hyphens.');
        }
        $body = $this->validateBody($body);
        $hash = hash('sha256', json_encode($this->canonical($body), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $this->db->beginTransaction();
        try {
            // Serializes submissions for this actor, including the per-hour limit and duplicate keys.
            $query = $this->db->prepare('SELECT id,display_name FROM app_users WHERE id=? AND active FOR UPDATE');
            $query->execute([$actor]);
            $user = $query->fetch();
            if (!$user) {
                throw new ApiException(401, 'authentication_required', 'Sign in is required.');
            }
            IntakeAccess::requirePermission($this->db, $actor);
            $query = $this->db->prepare("SELECT request_hash,response FROM idempotency_requests WHERE actor_user_id=? AND operation='ticket.submit' AND request_key=? FOR UPDATE");
            $query->execute([$actor,$key]);
            if ($prior = $query->fetch()) {
                if (!hash_equals($prior['request_hash'], $hash) || $prior['response'] === null) {
                    throw new ApiException(409, 'idempotency_conflict', 'Idempotency-Key was already used for a different submission.');
                }
                $result = json_decode($prior['response'], true, 32, JSON_THROW_ON_ERROR);
                (new TicketReader($this->db))->detail($actor, $result['id']);
                $this->db->commit();
                return ['ticket'=>$result,'replayed'=>true];
            }
            $query = $this->db->prepare("SELECT count(*) FROM tickets WHERE requester_id=? AND created_at>statement_timestamp()-interval '1 hour'");
            $query->execute([$actor]);
            if ((int)$query->fetchColumn() >= 10) {
                throw new ApiException(429, 'submission_rate_limited', 'At most ten tickets can be submitted per hour. Retry later.');
            }
            $query = $this->db->prepare(IntakeAccess::assignmentSql() . ' AND a.id=CAST(:assignment AS uuid) FOR SHARE OF a,p,d,s');
            $query->execute(['actor'=>$actor,'assignment'=>$body['assignment_id']]);
            $assignment = $query->fetch();
            if (!$assignment) {
                throw new ApiException(403, 'posting_unavailable', 'Select one of your active postings.');
            }
            $query = $this->db->prepare("SELECT c.id,c.department_id,w.id AS template_id,v.id AS workflow_version_id,v.definition
                FROM categories c JOIN departments d ON d.id=c.department_id
                JOIN workflow_templates w ON w.category_id=c.id
                JOIN workflow_versions v ON v.id=w.active_version_id AND v.template_id=w.id AND v.status='published'
                WHERE c.id=? AND c.active AND d.active FOR SHARE OF c,d,w");
            $query->execute([$body['category_id']]);
            $routes = $query->fetchAll();
            if (!$routes) {
                throw new ApiException(422, 'category_unavailable', 'Select an active category with a published intake configuration.');
            }
            $definition = json_decode($routes[0]['definition'], true, 32, JSON_THROW_ON_ERROR);
            if (count($routes) !== 1 || !in_array($definition['engine'] ?? '', ['department-triage-v1','workflow-v1'],true)
                || ($definition['department_id'] ?? '') !== $routes[0]['department_id'] || !is_array($definition['form_schema'] ?? null)) {
                throw new ApiException(503, 'intake_configuration_invalid', 'This category requires administrator attention before submission.');
            }
            try {
                if(($definition['engine']??'')==='workflow-v1'){new \App\Workflow\Graph($definition);}
                IntakeSchema::validateFields($definition['form_schema'], $body['fields']);
            } catch (\InvalidArgumentException) {
                throw new ApiException(503, 'intake_configuration_invalid', 'This category requires administrator attention before submission.');
            }
            $query = $this->db->prepare("INSERT INTO idempotency_requests(actor_user_id,operation,request_key,request_hash) VALUES (?,'ticket.submit',?,?) RETURNING id");
            $query->execute([$actor,$key,$hash]);
            $reservation = $query->fetchColumn();
            $snapshot = ['display_name'=>$user['display_name'],'position'=>$assignment['position_title'],
                'station'=>$assignment['station_name'],'province'=>$assignment['province_name'],'department'=>$assignment['department_name']];
            $query = $this->db->prepare("INSERT INTO tickets(requester_id,category_id,workflow_version_id,
                origin_assignment_id,origin_position_id,origin_station_id,origin_province_id,origin_department_id,
                responsible_department_id,subject,description,custom_fields,priority,requester_snapshot)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,CAST(? AS jsonb),?,CAST(? AS jsonb))
                RETURNING id,reference,status,version,responsible_department_id,workflow_version_id");
            $query->execute([$actor,$body['category_id'],$routes[0]['workflow_version_id'],$assignment['id'],$assignment['position_id'],
                $assignment['station_id'],$assignment['province_id'],$assignment['department_id'],$routes[0]['department_id'],
                $body['subject'],$body['description'],json_encode((object)$body['fields'], JSON_THROW_ON_ERROR),$body['priority'],json_encode($snapshot, JSON_THROW_ON_ERROR)]);
            $ticket = $query->fetch();
            $ticket['version'] = (int)$ticket['version'];
            $query = $this->db->prepare("INSERT INTO ticket_events(ticket_id,event_type,actor_user_id,to_state,workflow_version_id,request_id,event_data)
                VALUES (?,'TICKET_SUBMITTED',?,'submitted',?,?,CAST(? AS jsonb)) RETURNING id");
            $query->execute([$ticket['id'],$actor,$ticket['workflow_version_id'],$requestId,
                json_encode(['responsible_department_id'=>$ticket['responsible_department_id'],'origin_assignment_id'=>$assignment['id'],'engine'=>$definition['engine']], JSON_THROW_ON_ERROR)]);
            $eventId = $query->fetchColumn();
            $query = $this->db->prepare("INSERT INTO notification_outbox(event_id,kind,dedupe_key,payload) VALUES (?,'ticket_submitted',?,CAST(? AS jsonb))");
            $query->execute([$eventId,'ticket-submitted-'.$ticket['id'],json_encode(['ticket_id'=>$ticket['id'],'event_id'=>$eventId,'recipient_user_id'=>$actor], JSON_THROW_ON_ERROR)]);
            if(($definition['engine']??'')==='workflow-v1') {
                (new \App\Workflow\Runtime($this->db,$ticket['id'],$actor,$requestId))->start();
                $ticket=\App\Support\Sql::run($this->db,'SELECT id,reference,status,version,responsible_department_id,workflow_version_id FROM tickets WHERE id=?',[$ticket['id']])->fetch();
                $ticket['version']=(int)$ticket['version'];
            }
            $query = $this->db->prepare('UPDATE idempotency_requests SET response=CAST(? AS jsonb) WHERE id=?');
            $query->execute([json_encode($ticket, JSON_THROW_ON_ERROR),$reservation]);
            $this->db->commit();
            return ['ticket'=>$ticket,'replayed'=>false];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($error instanceof ApiException && $error->errorCode === 'intake_configuration_invalid') {
                $query = $this->db->prepare("INSERT INTO security_events(event_type,actor_user_id,subject_id,reason)
                    VALUES ('INTAKE_CONFIGURATION_INVALID',?,?,'Submission refused: ambiguous, unsupported or invalid intake version.')");
                $query->execute([$actor,$body['category_id']]);
            }
            throw $error;
        }
    }

    private function validateBody(array $body): array
    {
        if (array_diff(array_keys($body), ['category_id','assignment_id','subject','description','priority','fields'])) {
            throw new ApiException(422, 'invalid_submission', 'Unsupported submission field.');
        }
        foreach (['category_id','assignment_id'] as $name) {
            if (!is_string($body[$name] ?? null) || !TicketReader::uuid($body[$name])) {
                throw new ApiException(422, 'invalid_submission', 'Select a valid category and posting.');
            }
            $body[$name] = strtolower($body[$name]);
        }
        foreach (['subject'=>[3,255],'description'=>[1,10000]] as $name => [$min,$max]) {
            if (!is_string($body[$name] ?? null)) {
                throw new ApiException(422, 'invalid_submission', 'Subject and description are required.');
            }
            $body[$name] = trim($body[$name]);
            $length = IntakeSchema::length($body[$name]);
            if ($length < $min || $length > $max || str_contains($body[$name], "\0")) {
                throw new ApiException(422, 'invalid_submission', ucfirst($name) . " must contain $min..$max characters and no null bytes.");
            }
        }
        if (!array_key_exists('priority', $body)) {
            $body['priority'] = 'normal';
        }
        if (!is_string($body['priority']) || !in_array($body['priority'], ['low','normal','high','critical'], true)) {
            throw new ApiException(422, 'invalid_submission', 'Select a valid priority.');
        }
        if (!array_key_exists('fields', $body)) {
            $body['fields'] = [];
        }
        if (!is_array($body['fields']) || ($body['fields'] && array_is_list($body['fields']))) {
            throw new ApiException(422, 'invalid_fields', 'Fields must be an object of at most 32000 encoded bytes.');
        }
        try {
            $encodedFields = json_encode($body['fields'], JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(422, 'invalid_fields', 'Fields must contain valid UTF-8 and finite JSON values.');
        }
        if (strlen($encodedFields) > 32000) {
            throw new ApiException(422, 'invalid_fields', 'Fields must be an object of at most 32000 encoded bytes.');
        }
        return $body;
    }

    private function canonical(array $value): array
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }
        return $value;
    }
}
