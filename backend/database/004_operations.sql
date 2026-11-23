BEGIN;
ALTER TABLE tickets ADD COLUMN resolution text CHECK(resolution IN ('completed','rejected','withdrawn'));
ALTER TABLE task_instances ADD COLUMN scope_kind text NOT NULL DEFAULT 'station' CHECK(scope_kind IN ('station','province','department','national'));
ALTER TABLE task_instances ADD COLUMN execution_id uuid;
CREATE TABLE workflow_executions (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),ticket_id uuid NOT NULL REFERENCES tickets(id),
 node_id text NOT NULL, node_type text NOT NULL, label text NOT NULL,
 status text NOT NULL CHECK(status IN ('active','waiting','completed','cancelled')),
 context jsonb NOT NULL DEFAULT '[]', outcome text, due_at timestamptz,
 started_at timestamptz NOT NULL DEFAULT now(),completed_at timestamptz
);
ALTER TABLE task_instances ADD CONSTRAINT fk_task_execution FOREIGN KEY(execution_id) REFERENCES workflow_executions(id);
CREATE INDEX idx_executions_ticket ON workflow_executions(ticket_id,started_at,id);
CREATE INDEX idx_executions_due ON workflow_executions(due_at) WHERE status='waiting' AND due_at IS NOT NULL;
CREATE TABLE workflow_join_runs (
 fork_id uuid PRIMARY KEY REFERENCES workflow_executions(id),join_node text NOT NULL,
 expected integer NOT NULL,threshold integer NOT NULL,status text NOT NULL DEFAULT 'open' CHECK(status IN ('open','completed'))
);
CREATE TABLE workflow_join_arrivals (
 fork_id uuid REFERENCES workflow_join_runs(fork_id),branch_id text NOT NULL,PRIMARY KEY(fork_id,branch_id)
);
CREATE TABLE ticket_comments (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,ticket_id uuid NOT NULL REFERENCES tickets(id),
 actor_user_id uuid NOT NULL REFERENCES app_users(id),body text NOT NULL CHECK(length(body) BETWEEN 1 AND 10000),
 sensitivity text NOT NULL CHECK(sensitivity IN ('public_internal','participants_only','restricted')),
 created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER immutable_comments BEFORE UPDATE OR DELETE ON ticket_comments FOR EACH ROW EXECUTE FUNCTION deny_ticket_event_mutation();
CREATE TABLE user_notifications (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,event_id bigint NOT NULL REFERENCES ticket_events(id),
 user_id uuid NOT NULL REFERENCES app_users(id),ticket_id uuid NOT NULL REFERENCES tickets(id),
 created_at timestamptz NOT NULL DEFAULT now(),read_at timestamptz,UNIQUE(event_id,user_id)
);
CREATE INDEX idx_notifications_user ON user_notifications(user_id,id DESC);
CREATE TABLE workflow_drafts (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),category_id uuid NOT NULL REFERENCES categories(id),
 definition jsonb NOT NULL,version integer NOT NULL DEFAULT 1,created_by uuid NOT NULL REFERENCES app_users(id),
 approved_by uuid REFERENCES app_users(id),status text NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published','archived')),
 created_at timestamptz NOT NULL DEFAULT now(),updated_at timestamptz NOT NULL DEFAULT now()
);
INSERT INTO permissions(code,description) VALUES
 ('task.claim','Claim and action eligible tasks'),('task.manage','Release/reassign/recompute scoped tasks'),
 ('ticket.comment','Comment on visible tickets'),('ticket.manage','Manage ticket lifecycle'),
 ('workflow.design','Draft and simulate workflows'),('workflow.publish','Approve and publish workflow versions'),
 ('directory.manage','Manage organization records'),('security.manage','Manage users and explicit grants'),
 ('report.export','Export authorized ticket data');
INSERT INTO roles(code,title) VALUES ('task_agent','Workflow agent'),('operations_admin','Operations administrator');
INSERT INTO role_permissions(role_id,permission_code) SELECT r.id,p.code FROM roles r CROSS JOIN permissions p
 WHERE (r.code='task_agent' AND p.code IN ('ticket.read','task.claim','ticket.comment'))
 OR (r.code='operations_admin' AND p.code IN ('ticket.read','task.claim','task.manage','ticket.comment','ticket.manage','workflow.design','workflow.publish','directory.manage','security.manage','report.export'));
COMMIT;
