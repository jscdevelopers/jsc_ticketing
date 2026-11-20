<?php
declare(strict_types=1);

/**
 * Illustrative dependency-light PDO service. NOT a drop-in Nite controller.
 * Provide an authorization callback from the ACTUAL inspected framework.
 * PostgreSQL >= 16 schema in database/001_foundation.sql.
 */
final class TicketAlreadyClaimed extends RuntimeException {}
final class TicketClaimForbidden extends RuntimeException {}
final class TicketClaimConflict extends RuntimeException {}

final class TicketClaimService
{
    private Closure $authorize;

    /** @param Closure(string, array<string,mixed>):bool $authorize */
    public function __construct(private PDO $db, Closure $authorize)
    {
        $this->authorize = $authorize;
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** @return array<string, mixed> */
    public function claim(string $taskId, string $actorId, string $idempotencyKey): array
    {
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            throw new InvalidArgumentException('Idempotency-Key must be 1..128 chars');
        }
        $operation = 'task.claim:' . $taskId;
        $hash = hash('sha256', $actorId . ':' . $taskId);

        $this->db->beginTransaction();
        try {
            // Unique reservation serializes duplicate requests even across independent workers.
            $reserve = $this->db->prepare(
                'INSERT INTO idempotency_requests (actor_user_id,operation,request_key,request_hash) '
              . 'VALUES (CAST(:actor AS uuid),:op,:idem,:hash) ON CONFLICT DO NOTHING RETURNING id'
            );
            $reserve->execute(['actor'=>$actorId, 'op'=>$operation, 'idem'=>$idempotencyKey, 'hash'=>$hash]);
            $created = $reserve->fetchColumn();
            if ($created === false) {
                $prior = $this->db->prepare(
                    'SELECT request_hash,response FROM idempotency_requests '
                  . 'WHERE actor_user_id=CAST(:actor AS uuid) AND operation=:op AND request_key=:idem FOR UPDATE'
                );
                $prior->execute(['actor'=>$actorId,'op'=>$operation,'idem'=>$idempotencyKey]);
                $existing = $prior->fetch(PDO::FETCH_ASSOC);
                if (!$existing || !hash_equals($existing['request_hash'], $hash)) {
                    throw new TicketClaimConflict('Idempotency key reused with different request');
                }
                if ($existing['response'] === null) {
                    throw new TicketClaimConflict('Previous request not completed');
                }
                $response = json_decode($existing['response'], true, 512, JSON_THROW_ON_ERROR);
                $this->db->commit();
                return $response;
            }

            // DO NOT use SKIP LOCKED for interactive claim: wait and recheck state.
            $lock = $this->db->prepare(
                'SELECT t.id,t.ticket_id,t.workflow_version_id,t.status,t.claimed_by,t.version, '
              . 'k.confidentiality,k.responsible_department_id '
              . 'FROM task_instances t JOIN tickets k ON k.id=t.ticket_id '
              . 'WHERE t.id=CAST(:task AS uuid) FOR UPDATE OF t,k'
            );
            $lock->execute(['task'=>$taskId]);
            $task = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$task) {
                throw new TicketClaimConflict('Task unavailable'); // avoid record enumeration in API
            }
            // `authorize` MUST check current grants, active posting, sensitivity and task context.
            if (!(($this->authorize)($actorId, $task))) {
                throw new TicketClaimForbidden('Not permitted to claim this task');
            }
            $candidate = $this->db->prepare(
                'SELECT 1 FROM task_candidates '
              . 'WHERE task_id=CAST(:task AS uuid) AND user_id=CAST(:actor AS uuid) '
              . 'AND revoked_at IS NULL AND (eligible_until IS NULL OR eligible_until>now())'
            );
            $candidate->execute(['task'=>$taskId,'actor'=>$actorId]);
            if ($candidate->fetchColumn() === false) {
                throw new TicketClaimForbidden('Not an eligible task candidate');
            }
            if ($task['status'] !== 'offered') {
                throw new TicketAlreadyClaimed('Task no longer available for claim');
            }
            $update = $this->db->prepare(
                "UPDATE task_instances SET status='claimed',claimed_by=CAST(:actor AS uuid),"
              . 'claimed_at=now(),version=version+1 WHERE id=CAST(:task AS uuid) AND status=\'offered\' RETURNING version'
            );
            $update->execute(['actor'=>$actorId,'task'=>$taskId]);
            $version = $update->fetchColumn();
            if ($version === false) {
                throw new TicketClaimConflict('Lost claim race');
            }
            $event = $this->db->prepare(
                'INSERT INTO ticket_events (ticket_id,task_id,event_type,actor_user_id,from_state,to_state,workflow_version_id,event_data) '
              . "VALUES (CAST(:ticket AS uuid),CAST(:task AS uuid),'TASK_CLAIMED',CAST(:actor AS uuid),'offered','claimed',CAST(:wf AS uuid),CAST(:data AS jsonb)) RETURNING id"
            );
            $event->execute([
                'ticket'=>$task['ticket_id'],'task'=>$taskId,'actor'=>$actorId,
                'wf'=>$task['workflow_version_id'],
                'data'=>json_encode(['prior_version'=>(int)$task['version']], JSON_THROW_ON_ERROR)
            ]);
            $eventId = (int)$event->fetchColumn();
            $outbox = $this->db->prepare(
                "INSERT INTO notification_outbox(event_id,kind,dedupe_key,payload) VALUES "
              . "(:event,'task_claimed',:dedupe,CAST(:payload AS jsonb)) ON CONFLICT(dedupe_key) DO NOTHING"
            );
            $outbox->execute([
                'event'=>$eventId,
                'dedupe'=>'task-claimed-'.$taskId.'-v'.$version,
                'payload'=>json_encode(['task_id'=>$taskId,'ticket_id'=>$task['ticket_id']], JSON_THROW_ON_ERROR)
            ]);
            $response = [
                'task_id'=>$taskId,'ticket_id'=>$task['ticket_id'],
                'claimed_by'=>$actorId,'status'=>'claimed','version'=>(int)$version,
            ];
            $save = $this->db->prepare(
                'UPDATE idempotency_requests SET response=CAST(:response AS jsonb) WHERE id=CAST(:id AS uuid)'
            );
            $save->execute(['response'=>json_encode($response,JSON_THROW_ON_ERROR),'id'=>$created]);
            $this->db->commit();
            return $response;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
