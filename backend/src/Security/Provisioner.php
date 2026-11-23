<?php
declare(strict_types=1);
namespace App\Security;

use App\Tickets\TicketReader;
use InvalidArgumentException;
use PDO;
use Throwable;

/** Trusted console operations only; never exposed as HTTP endpoints. */
final class Provisioner
{
    public function __construct(private PDO $db) {}

    public function setPassword(string $user,string $username,string $password,string $reason):void
    {
        $this->id($user);$this->reason($reason);$username=strtolower(trim($username));PasswordAuth::validatePassword($password);
        if(!preg_match('/^[a-z0-9][a-z0-9._@-]{2,99}$/D',$username)){throw new InvalidArgumentException('Username must contain 3..100 letters, digits, dots, underscores, @ or hyphens.');}
        $hash=password_hash($password,PASSWORD_BCRYPT,['cost'=>12]);
        $this->transaction(function()use($user,$username,$hash,$reason):void{
            if(!\App\Support\Sql::run($this->db,'SELECT id FROM app_users WHERE id=? AND active FOR UPDATE',[$user])->fetchColumn()){throw new InvalidArgumentException('Active user required.');}
            \App\Support\Sql::run($this->db,'INSERT INTO user_credentials(user_id,username,password_hash,must_change) VALUES (?,?,?,true)
                ON CONFLICT(user_id) DO UPDATE SET username=EXCLUDED.username,password_hash=EXCLUDED.password_hash,must_change=true,password_changed_at=now()',[$user,$username,$hash]);
            \App\Support\Sql::run($this->db,'UPDATE api_tokens SET revoked_at=now() WHERE user_id=? AND revoked_at IS NULL',[$user]);
            \App\Support\Sql::run($this->db,'UPDATE login_challenges SET consumed_at=now() WHERE user_id=? AND consumed_at IS NULL',[$user]);
            $this->audit('PASSWORD_PROVISIONED',$user,$reason);
        });
    }

    public function grantOperations(string $user,string $reason):void
    {
        $this->id($user);$this->reason($reason);
        $this->transaction(function()use($user,$reason):void{
            if(!\App\Support\Sql::run($this->db,'SELECT id FROM app_users WHERE id=? AND active FOR UPDATE',[$user])->fetchColumn()){throw new InvalidArgumentException('Active user required.');}
            \App\Support\Sql::run($this->db,"INSERT INTO user_roles(user_id,role_id) SELECT ?,r.id FROM roles r WHERE r.code='operations_admin'
                AND NOT EXISTS(SELECT 1 FROM user_roles ur WHERE ur.user_id=CAST(? AS uuid) AND ur.role_id=r.id AND ur.valid_from<=now() AND (ur.valid_to IS NULL OR ur.valid_to>now()))",[$user,$user]);
            foreach(['directory.manage','security.manage','workflow.design','workflow.publish']as$permission){
                \App\Support\Sql::run($this->db,"INSERT INTO scope_grants(user_id,permission_code,scope_kind,grant_reason) SELECT CAST(? AS uuid),?,'national',?
                    WHERE NOT EXISTS(SELECT 1 FROM scope_grants WHERE user_id=CAST(? AS uuid) AND permission_code=? AND scope_kind='national' AND revoked_at IS NULL AND valid_from<=now() AND (valid_to IS NULL OR valid_to>now()))",[$user,$permission,$reason,$user,$permission]);
            }
            $this->audit('OPERATIONS_AUTHORITY_GRANTED',$user,$reason);
        });
    }

    public function issueToken(string $userId, int $hours): string
    {
        $this->id($userId);
        if ($hours < 1 || $hours > 24) {
            throw new InvalidArgumentException('Token lifetime must be 1..24 hours.');
        }
        return $this->transaction(function () use ($userId, $hours): string {
            $query = $this->db->prepare('SELECT id FROM app_users WHERE id=? AND active FOR UPDATE');
            $query->execute([$userId]);
            if (!$query->fetchColumn()) {
                throw new InvalidArgumentException('Active user required.');
            }
            $token = bin2hex(random_bytes(32));
            $query = $this->db->prepare("INSERT INTO api_tokens(user_id,token_hash,expires_at)
                VALUES (?,?,statement_timestamp()+CAST(? AS integer)*interval '1 hour') RETURNING id");
            $query->execute([$userId, hash('sha256', $token), $hours]);
            $this->audit('TOKEN_ISSUED', $query->fetchColumn(), 'Console provisioned; expires in ' . $hours . ' hours.');
            return $token;
        });
    }

    public function grantRead(string $userId, string $kind, ?string $ref, string $reason): string
    {
        $this->id($userId);
        $targets = ['station' => 'stations', 'province' => 'provinces', 'department' => 'departments', 'ticket_explicit' => 'tickets'];
        if (!in_array($kind, ['self','national', ...array_keys($targets)], true)) {
            throw new InvalidArgumentException('Invalid scope kind.');
        }
        if (isset($targets[$kind])) {
            $this->id($ref ?? '');
        } elseif ($ref !== null) {
            throw new InvalidArgumentException('Self/national scopes cannot have a reference.');
        }
        $this->reason($reason);
        return $this->transaction(function () use ($userId, $kind, $ref, $reason, $targets): string {
            $query = $this->db->prepare('SELECT id FROM app_users WHERE id=? AND active FOR UPDATE');
            $query->execute([$userId]);
            if (!$query->fetchColumn()) {
                throw new InvalidArgumentException('Active user required.');
            }
            if (isset($targets[$kind])) {
                $query = $this->db->prepare('SELECT id FROM ' . $targets[$kind] . ' WHERE id=?');
                $query->execute([$ref]);
                if (!$query->fetchColumn()) {
                    throw new InvalidArgumentException('Scope target does not exist.');
                }
            }
            $query = $this->db->prepare("INSERT INTO user_roles(user_id,role_id)
                SELECT ?,id FROM roles WHERE code='ticket_reader'
                AND NOT EXISTS (SELECT 1 FROM user_roles WHERE user_id=CAST(? AS uuid)
                  AND role_id=roles.id AND valid_from<=statement_timestamp() AND (valid_to IS NULL OR valid_to>statement_timestamp()))");
            $query->execute([$userId, $userId]);
            if ($query->rowCount() > 0) {
                $this->audit('READER_ROLE_ASSIGNED', $userId, $reason);
            }
            $query = $this->db->prepare("SELECT id FROM scope_grants WHERE user_id=?
                AND permission_code='ticket.read' AND scope_kind=? AND scope_ref_id IS NOT DISTINCT FROM CAST(? AS uuid)
                AND revoked_at IS NULL AND valid_from<=statement_timestamp()
                AND (valid_to IS NULL OR valid_to>statement_timestamp()) ORDER BY valid_from,id LIMIT 1");
            $query->execute([$userId, $kind, $ref]);
            if ($existing = $query->fetchColumn()) {
                return $existing;
            }
            $query = $this->db->prepare("INSERT INTO scope_grants(user_id,permission_code,scope_kind,scope_ref_id,grant_reason)
                VALUES (?,'ticket.read',?,?,?) RETURNING id");
            $query->execute([$userId, $kind, $ref, $reason]);
            $id = $query->fetchColumn();
            $this->audit('READ_SCOPE_GRANTED', $id, $reason);
            return $id;
        });
    }

    public function revoke(string $type, string $id, string $reason): void
    {
        $this->id($id);
        $this->reason($reason);
        $table = match ($type) {
            'token' => 'api_tokens',
            'grant' => 'scope_grants',
            default => throw new InvalidArgumentException('Revoke type must be token or grant.'),
        };
        $this->transaction(function () use ($table, $id, $reason, $type): void {
            $query = $this->db->prepare("UPDATE $table SET revoked_at=clock_timestamp() WHERE id=? AND revoked_at IS NULL RETURNING id");
            $query->execute([$id]);
            if ($query->fetchColumn()) {
                $this->audit(strtoupper($type) . '_REVOKED', $id, $reason);
            }
        });
    }

    public function grantSubmit(string $userId, string $reason): void
    {
        $this->id($userId);
        $this->reason($reason);
        $this->transaction(function () use ($userId, $reason): void {
            $query = $this->db->prepare('SELECT id FROM app_users WHERE id=? AND active FOR UPDATE');
            $query->execute([$userId]);
            if (!$query->fetchColumn()) {
                throw new InvalidArgumentException('Active user required.');
            }
            $changed = false;
            $query = $this->db->prepare("INSERT INTO user_roles(user_id,role_id)
                SELECT ?,r.id FROM roles r WHERE r.code='ticket_requester'
                AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id=CAST(? AS uuid) AND ur.role_id=r.id
                    AND ur.valid_from<=statement_timestamp() AND (ur.valid_to IS NULL OR ur.valid_to>statement_timestamp()))");
            $query->execute([$userId,$userId]);
            $changed = $query->rowCount() > 0;
            foreach (['ticket.create','ticket.read'] as $permission) {
                $query = $this->db->prepare("INSERT INTO scope_grants(user_id,permission_code,scope_kind,grant_reason)
                    SELECT CAST(? AS uuid),?,'self',? WHERE NOT EXISTS (SELECT 1 FROM scope_grants
                        WHERE user_id=CAST(? AS uuid) AND permission_code=? AND scope_kind='self' AND revoked_at IS NULL
                        AND valid_from<=statement_timestamp() AND (valid_to IS NULL OR valid_to>statement_timestamp()))");
                $query->execute([$userId,$permission,$reason,$userId,$permission]);
                $changed = $query->rowCount() > 0 || $changed;
            }
            if ($changed) {
                $this->audit('SUBMISSION_ACCESS_GRANTED', $userId, $reason);
            }
        });
    }

    public function importDirectory(array $data, bool $dryRun): array
    {
        $fields = [
            'provinces' => ['id','code','name','active'],
            'stations' => ['id','code','name','province_id','is_secretariat_station','active'],
            'departments' => ['id','code','name','active'],
            'positions' => ['id','code','title','active'],
            'app_users' => ['id','external_subject','display_name','email','active'],
            'staff_assignments' => ['id','user_id','position_id','department_id','station_id','begins_at','ends_at','is_acting'],
        ];
        if (array_diff(array_keys($data), array_keys($fields))) {
            throw new InvalidArgumentException('Unsupported directory section.');
        }
        $this->db->beginTransaction();
        try {
            \App\Support\Sql::run($this->db,"SELECT set_config('app.actor_system','console',true)");
            $counts = [];
            foreach ($fields as $table => $columns) {
                $rows = $data[$table] ?? [];
                if (!is_array($rows) || !array_is_list($rows) || count($rows) > 10000) {
                    throw new InvalidArgumentException('Each directory section must be a list of at most 10000 rows.');
                }
                $seen = [];
                foreach ($rows as $row) {
                    if (!is_array($row) || array_diff($columns, array_keys($row)) || array_diff(array_keys($row), $columns)) {
                        throw new InvalidArgumentException('Exact columns required for ' . $table . ': ' . implode(',', $columns));
                    }
                    $this->id($row['id']);
                    if (isset($seen[$row['id']])) {
                        throw new InvalidArgumentException('Duplicate directory ID.');
                    }
                    $seen[$row['id']] = true;
                    $values = [];
                    foreach ($columns as $column) {
                        $value = $row[$column];
                        if (in_array($column, ['active','is_secretariat_station','is_acting'], true)) {
                            if (!is_bool($value)) {
                                throw new InvalidArgumentException('Boolean required for ' . $column);
                            }
                            $value = $value ? 'true' : 'false';
                        } elseif ($value !== null && !is_string($value)) {
                            throw new InvalidArgumentException('String or null required for ' . $column);
                        }
                        $values[] = $value;
                    }
                    $updates = array_map(static fn ($column) => "$column=EXCLUDED.$column", array_slice($columns, 1));
                    $updates[]="version=$table.version+1";
                    $current=implode(',',array_map(static fn($column)=>"$table.$column",array_slice($columns,1)));
                    $incoming=implode(',',array_map(static fn($column)=>"EXCLUDED.$column",array_slice($columns,1)));
                    $statement = $this->db->prepare('INSERT INTO ' . $table . '(' . implode(',', $columns) . ') VALUES ('
                        . implode(',', array_fill(0, count($columns), '?')) . ') ON CONFLICT(id) DO UPDATE SET ' . implode(',', $updates)
                        . " WHERE ROW($current) IS DISTINCT FROM ROW($incoming)"
                        . ($table === 'app_users' ? ' AND app_users.external_subject=EXCLUDED.external_subject' : ''));
                    $statement->execute($values);
                    if ($table === 'app_users' && $statement->rowCount() !== 1 && \App\Support\Sql::run($this->db,'SELECT external_subject FROM app_users WHERE id=?',[$row['id']])->fetchColumn()!==$row['external_subject']) {
                        throw new InvalidArgumentException('An existing user UUID cannot be rebound to another external identity.');
                    }
                }
                $counts[$table] = count($rows);
            }
            $this->audit('DIRECTORY_IMPORTED', null, 'Source sha256: ' . hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)));
            if ($dryRun) {
                $this->db->rollBack();
            } else {
                $this->db->commit();
            }
            return $counts;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function transaction(callable $action): mixed
    {
        $this->db->beginTransaction();
        try {
            \App\Support\Sql::run($this->db,"SELECT set_config('app.actor_system','console',true)");
            $result = $action();
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function audit(string $event, ?string $subject, string $reason): void
    {
        $query = $this->db->prepare("INSERT INTO security_events(event_type,actor_system,subject_id,reason) VALUES (?,'console',?,?)");
        $query->execute([$event, $subject, $reason]);
    }

    private function id(string $id): void
    {
        if (!TicketReader::uuid($id)) {
            throw new InvalidArgumentException('Valid UUID required.');
        }
    }

    private function reason(string $reason): void
    {
        if (strlen(trim($reason)) < 3 || strlen($reason) > 500) {
            throw new InvalidArgumentException('Reason must be 3..500 bytes.');
        }
    }
}
