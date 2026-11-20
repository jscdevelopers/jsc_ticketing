<?php
declare(strict_types=1);
namespace App\Tickets;

use App\Http\ApiException;
use App\Security\TicketScope;
use PDO;

final class TicketReader
{
    private const STATUSES = ['draft','submitted','routed','in_progress','awaiting_input','awaiting_approval','resolved','closed','withdrawn'];

    public function __construct(private PDO $db) {}

    public function listing(string $actor, array $query): array
    {
        foreach ($query as $key => $value) {
            if (!in_array($key, ['q','status','cursor','limit'], true) || !is_string($value)) {
                throw new ApiException(422, 'invalid_filter', 'Unsupported or invalid ticket filter.');
            }
        }
        $limit = $query['limit'] ?? '10';
        if (!preg_match('/^(?:[1-9]|10)$/D', $limit)) {
            throw new ApiException(422, 'invalid_limit', 'Limit must be between 1 and 10.');
        }
        $where = [TicketScope::predicate()];
        $params = ['actor' => $actor];
        if (isset($query['status'])) {
            if (!in_array($query['status'], self::STATUSES, true)) {
                throw new ApiException(422, 'invalid_status', 'Select a valid ticket status.');
            }
            $where[] = 't.status=:status';
            $params['status'] = $query['status'];
        }
        if (isset($query['q']) && $query['q'] !== '') {
            if (strlen($query['q']) > 200) {
                throw new ApiException(422, 'invalid_search', 'Search must be at most 200 bytes.');
            }
            $where[] = "(t.subject ILIKE :search_subject ESCAPE '!' OR t.reference ILIKE :search_reference ESCAPE '!')";
            $params['search_subject'] = $params['search_reference'] = '%' . str_replace(['!','%','_'], ['!!','!%','!_'], $query['q']) . '%';
        }
        if (isset($query['cursor'])) {
            $cursor = strlen($query['cursor']) <= 128 ? base64_decode($query['cursor'], true) : false;
            $parts = $cursor === false ? [] : explode('|', $cursor);
            if (count($parts) !== 2 || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\+00$/D', $parts[0]) || !self::uuid($parts[1])) {
                throw new ApiException(422, 'invalid_cursor', 'Cursor is invalid.');
            }
            $parsed = date_parse($parts[0]);
            if ($parsed['error_count'] || $parsed['warning_count']) {
                throw new ApiException(422, 'invalid_cursor', 'Cursor is invalid.');
            }
            $where[] = '(t.created_at,t.id)<(CAST(:created AS timestamptz),CAST(:cursor_id AS uuid))';
            $params['created'] = $parts[0];
            $params['cursor_id'] = $parts[1];
        }
        $sql = 'SELECT t.id,t.reference,t.subject,t.status,t.priority,t.created_at,t.version FROM tickets t WHERE '
            . implode(' AND ', $where) . ' ORDER BY t.created_at DESC,t.id DESC LIMIT ' . ((int)$limit + 1);
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();
        $more = count($rows) > (int)$limit;
        if ($more) {
            array_pop($rows);
        }
        $last = end($rows);
        return ['data' => $rows, 'meta' => ['next_cursor' => $more ? base64_encode($last['created_at'] . '|' . $last['id']) : null, 'limit' => (int)$limit]];
    }

    public function detail(string $actor, string $id): array
    {
        if (!self::uuid($id)) {
            throw new ApiException(404, 'ticket_unavailable', 'Ticket unavailable.');
        }
        $statement = $this->db->prepare('SELECT t.id,t.reference,t.subject,t.description,t.custom_fields,
            t.status,t.priority,t.confidentiality,t.origin_station_id,t.origin_province_id,t.origin_department_id,
            t.responsible_department_id,t.workflow_version_id,t.origin_assignment_id,t.origin_position_id,t.requester_snapshot,t.created_at,t.updated_at,t.version
            FROM tickets t WHERE t.id=CAST(:id AS uuid) AND ' . TicketScope::predicate());
        $statement->execute(['id' => $id, 'actor' => $actor]);
        $ticket = $statement->fetch();
        if (!$ticket) {
            throw new ApiException(404, 'ticket_unavailable', 'Ticket unavailable.');
        }
        $ticket['custom_fields'] = json_decode($ticket['custom_fields'], false, 512, JSON_THROW_ON_ERROR);
        $ticket['requester_snapshot'] = json_decode($ticket['requester_snapshot'], false, 32, JSON_THROW_ON_ERROR);
        return $ticket;
    }

    public static function uuid(string $value): bool
    {
        return (bool)preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD', $value);
    }

    public function timeline(string $actor, string $id, array $query): array
    {
        if (array_diff(array_keys($query), ['after']) || (isset($query['after'])
            && (!is_string($query['after']) || !preg_match('/^[0-9]{1,18}$/D', $query['after'])))) {
            throw new ApiException(422, 'invalid_cursor', 'Timeline after must be a non-negative event ID.');
        }
        $this->detail($actor, $id);
        $statement = $this->db->prepare("SELECT e.id,e.event_type,e.actor_user_id,e.from_state,e.to_state,e.event_data,e.occurred_at
            FROM ticket_events e JOIN tickets t ON t.id=e.ticket_id
            WHERE t.id=CAST(:id AS uuid) AND e.id>CAST(:after AS bigint) AND e.visibility='internal'
            AND " . TicketScope::predicate() . ' ORDER BY e.id LIMIT 11');
        $statement->execute(['id'=>$id,'actor'=>$actor,'after'=>$query['after'] ?? '0']);
        $rows = $statement->fetchAll();
        $more = count($rows) > 10;
        if ($more) {
            array_pop($rows);
        }
        $last = end($rows);
        foreach ($rows as &$row) {
            $row['event_data'] = json_decode($row['event_data'], false, 32, JSON_THROW_ON_ERROR);
        }
        return ['data'=>$rows,'meta'=>['next_after'=>$more ? (string)$last['id'] : null,'limit'=>10]];
    }
}
