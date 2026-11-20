<?php
declare(strict_types=1);
namespace App\Security;

use App\Http\ApiException;
use App\Http\Request;
use PDO;

final class Authenticator
{
    public function __construct(private PDO $db) {}

    public function actor(Request $request): array
    {
        $header = $request->header('Authorization') ?? '';
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/iD', $header, $matches)) {
            throw new ApiException(401, 'authentication_required', 'Sign in is required.');
        }
        $query = $this->db->prepare('SELECT u.id,u.display_name,u.external_subject FROM api_tokens a
            JOIN app_users u ON u.id=a.user_id
            WHERE a.token_hash=:hash AND a.revoked_at IS NULL AND a.expires_at>statement_timestamp() AND u.active');
        $query->execute(['hash' => hash('sha256', $matches[1])]);
        $actor = $query->fetch();
        if (!$actor) {
            throw new ApiException(401, 'authentication_required', 'Sign in is required.');
        }
        return $actor;
    }
}
