BEGIN;
ALTER TABLE task_instances ADD COLUMN routing_policy jsonb NOT NULL DEFAULT '{}'::jsonb;
INSERT INTO permissions(code,description) VALUES ('task.intervene','Take responsibility for lower-stage work under a published intervention policy'),('report.analytics','View scoped operational analytics'),('ticket.comment_closed','Comment on completed operational work');
INSERT INTO role_permissions(role_id,permission_code) SELECT r.id,p.code FROM roles r CROSS JOIN permissions p WHERE r.code='operations_admin' AND p.code IN ('task.intervene','report.analytics','ticket.comment_closed');
ALTER TABLE ticket_comments ADD COLUMN recipient_user_ids uuid[] NOT NULL DEFAULT '{}';
COMMIT;
