BEGIN;
ALTER TABLE api_tokens ADD COLUMN purpose text NOT NULL DEFAULT 'access' CHECK(purpose IN ('access','password_change'));
CREATE TABLE user_credentials (
 user_id uuid PRIMARY KEY REFERENCES app_users(id),username text NOT NULL UNIQUE CHECK(username=lower(username)),
 password_hash text NOT NULL,must_change boolean NOT NULL DEFAULT true,password_changed_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE login_rate_limits (
 bucket text PRIMARY KEY,attempts integer NOT NULL,expires_at timestamptz NOT NULL
);
CREATE TABLE login_challenges (
 challenge_hash text PRIMARY KEY,user_id uuid NOT NULL REFERENCES app_users(id),mode text NOT NULL CHECK(mode IN ('code','biometric')),
 remote_challenge text,credential_changed_at timestamptz NOT NULL,expires_at timestamptz NOT NULL,attempts integer NOT NULL DEFAULT 0,consumed_at timestamptz
);
INSERT INTO role_permissions(role_id,permission_code) SELECT id,'ticket.comment' FROM roles WHERE code='ticket_requester';
COMMIT;
