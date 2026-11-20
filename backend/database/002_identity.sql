BEGIN;
CREATE TABLE api_tokens (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 user_id uuid NOT NULL REFERENCES app_users(id),
 token_hash text NOT NULL UNIQUE CHECK(length(token_hash)=64),
 expires_at timestamptz NOT NULL, revoked_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_api_tokens_user ON api_tokens(user_id);

CREATE TABLE security_events (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 event_type text NOT NULL, actor_user_id uuid REFERENCES app_users(id),
 actor_system text, subject_id uuid, reason text NOT NULL,
 occurred_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 CHECK(actor_user_id IS NOT NULL OR actor_system IS NOT NULL)
);
CREATE TRIGGER immutable_security_events BEFORE UPDATE OR DELETE ON security_events
 FOR EACH ROW EXECUTE FUNCTION deny_ticket_event_mutation();

INSERT INTO permissions(code,description) VALUES
 ('ticket.read','Read tickets in an explicit scope'),
 ('ticket.read_sensitive','Read restricted and confidential tickets in an explicit scope')
ON CONFLICT(code) DO NOTHING;
INSERT INTO roles(code,title) VALUES ('ticket_reader','Scoped ticket reader');
INSERT INTO role_permissions(role_id,permission_code)
 SELECT id,'ticket.read' FROM roles WHERE code='ticket_reader';
COMMIT;
