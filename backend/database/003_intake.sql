BEGIN;
ALTER TABLE tickets ADD COLUMN origin_assignment_id uuid REFERENCES staff_assignments(id);
ALTER TABLE tickets ADD COLUMN origin_position_id uuid REFERENCES positions(id);
ALTER TABLE tickets ADD COLUMN requester_snapshot jsonb NOT NULL DEFAULT '{}'::jsonb
 CHECK(jsonb_typeof(requester_snapshot)='object');
CREATE INDEX idx_workflow_templates_category ON workflow_templates(category_id);
INSERT INTO permissions(code,description) VALUES ('ticket.create','Submit own tickets from a valid posting');
INSERT INTO roles(code,title) VALUES ('ticket_requester','Ticket requester');
INSERT INTO role_permissions(role_id,permission_code)
 SELECT id,p.code FROM roles CROSS JOIN permissions p
 WHERE roles.code='ticket_requester' AND p.code IN ('ticket.create','ticket.read');
COMMIT;
