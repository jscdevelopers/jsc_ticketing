BEGIN;
ALTER TABLE tickets ADD COLUMN run_number integer NOT NULL DEFAULT 1 CHECK(run_number>0);
ALTER TABLE workflow_executions ADD COLUMN run_number integer NOT NULL DEFAULT 1 CHECK(run_number>0);
ALTER TABLE workflow_executions ADD COLUMN sequence_no bigint GENERATED ALWAYS AS IDENTITY UNIQUE;
ALTER TABLE task_instances ADD COLUMN sla_paused_at timestamptz;
ALTER TABLE task_instances ADD COLUMN sla_remaining_minutes integer;
CREATE TABLE organization_events (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,resource text NOT NULL,record_id uuid NOT NULL,
 actor_user_id uuid REFERENCES app_users(id),actor_system text,before_data jsonb,after_data jsonb,
 occurred_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX idx_organization_events_record ON organization_events(resource,record_id,id);
CREATE TRIGGER immutable_organization_events BEFORE UPDATE OR DELETE ON organization_events FOR EACH ROW EXECUTE FUNCTION deny_ticket_event_mutation();
CREATE FUNCTION audit_organization_change() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE actor uuid := NULLIF(current_setting('app.actor_user_id',true),'')::uuid;
BEGIN
 INSERT INTO organization_events(resource,record_id,actor_user_id,actor_system,before_data,after_data)
 VALUES(TG_TABLE_NAME,NEW.id,actor,CASE WHEN actor IS NULL THEN COALESCE(NULLIF(current_setting('app.actor_system',true),''),'database') END,
 CASE WHEN TG_OP='UPDATE' THEN to_jsonb(OLD) ELSE NULL END,to_jsonb(NEW));
 RETURN NEW;
END $$;
CREATE TRIGGER audit_provinces AFTER INSERT OR UPDATE ON provinces FOR EACH ROW EXECUTE FUNCTION audit_organization_change();
CREATE TRIGGER audit_stations AFTER INSERT OR UPDATE ON stations FOR EACH ROW EXECUTE FUNCTION audit_organization_change();
CREATE TRIGGER audit_departments AFTER INSERT OR UPDATE ON departments FOR EACH ROW EXECUTE FUNCTION audit_organization_change();
CREATE TRIGGER audit_positions AFTER INSERT OR UPDATE ON positions FOR EACH ROW EXECUTE FUNCTION audit_organization_change();
CREATE TRIGGER audit_users AFTER INSERT OR UPDATE ON app_users FOR EACH ROW EXECUTE FUNCTION audit_organization_change();
CREATE TRIGGER audit_assignments AFTER INSERT OR UPDATE ON staff_assignments FOR EACH ROW EXECUTE FUNCTION audit_organization_change();
CREATE TRIGGER audit_categories AFTER INSERT OR UPDATE ON categories FOR EACH ROW EXECUTE FUNCTION audit_organization_change();
CREATE TRIGGER audit_roles AFTER INSERT OR UPDATE ON user_roles FOR EACH ROW EXECUTE FUNCTION audit_organization_change();
CREATE TRIGGER audit_grants AFTER INSERT OR UPDATE ON scope_grants FOR EACH ROW EXECUTE FUNCTION audit_organization_change();
COMMIT;
