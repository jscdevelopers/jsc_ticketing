-- JSC Ticketing foundational PostgreSQL schema. Requires PostgreSQL >= 16.
-- NOT a production deployment migration until the actual Nite conventions are inspected.
-- Run in a fresh, dedicated database with an approved migration role.
BEGIN;
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE SEQUENCE IF NOT EXISTS jsc_ticket_number_seq;

CREATE TABLE provinces (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), code text NOT NULL UNIQUE, name text NOT NULL, active boolean NOT NULL DEFAULT true
);
CREATE TABLE stations (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), code text NOT NULL UNIQUE, name text NOT NULL,
 province_id uuid REFERENCES provinces(id), is_secretariat_station boolean NOT NULL DEFAULT false,
 active boolean NOT NULL DEFAULT true
);
COMMENT ON COLUMN stations.is_secretariat_station IS 'Display/property only; does not grant any national privileges.';
CREATE TABLE departments (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), code text NOT NULL UNIQUE, name text NOT NULL, active boolean NOT NULL DEFAULT true
);
CREATE TABLE positions (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), code text NOT NULL UNIQUE, title text NOT NULL, active boolean NOT NULL DEFAULT true
);
CREATE TABLE app_users (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), external_subject text NOT NULL UNIQUE,
 display_name text NOT NULL, email text, active boolean NOT NULL DEFAULT true,
 created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE staff_assignments (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), user_id uuid NOT NULL REFERENCES app_users(id),
 position_id uuid NOT NULL REFERENCES positions(id), department_id uuid NOT NULL REFERENCES departments(id),
 station_id uuid NOT NULL REFERENCES stations(id), begins_at timestamptz NOT NULL,
 ends_at timestamptz, is_acting boolean NOT NULL DEFAULT false,
 CHECK (ends_at IS NULL OR ends_at > begins_at)
);
CREATE INDEX idx_staff_assignments_user_active ON staff_assignments(user_id,begins_at,ends_at);
CREATE INDEX idx_staff_assignments_target ON staff_assignments(department_id,position_id,station_id);
CREATE TABLE roles (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), code text NOT NULL UNIQUE, title text NOT NULL);
CREATE TABLE permissions (code text PRIMARY KEY, description text NOT NULL);
CREATE TABLE role_permissions (role_id uuid REFERENCES roles(id) ON DELETE CASCADE, permission_code text REFERENCES permissions(code) ON DELETE CASCADE, PRIMARY KEY(role_id,permission_code));
CREATE TABLE user_roles (
 user_id uuid REFERENCES app_users(id), role_id uuid REFERENCES roles(id),
 valid_from timestamptz NOT NULL DEFAULT now(), valid_to timestamptz,
 PRIMARY KEY(user_id,role_id,valid_from), CHECK (valid_to IS NULL OR valid_to > valid_from)
);
CREATE TABLE scope_grants (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), user_id uuid NOT NULL REFERENCES app_users(id),
 permission_code text NOT NULL REFERENCES permissions(code),
 scope_kind text NOT NULL CHECK (scope_kind IN ('self','station','province','department','national','ticket_explicit')),
 scope_ref_id uuid,
 valid_from timestamptz NOT NULL DEFAULT now(), valid_to timestamptz,
 granted_by uuid REFERENCES app_users(id), grant_reason text NOT NULL, revoked_at timestamptz,
 CHECK (valid_to IS NULL OR valid_to > valid_from),
 CHECK ((scope_kind IN ('national','self') AND scope_ref_id IS NULL) OR
        (scope_kind IN ('station','province','department','ticket_explicit') AND scope_ref_id IS NOT NULL))
);
CREATE INDEX idx_scope_grants_user ON scope_grants(user_id, permission_code,scope_kind,valid_from,valid_to) WHERE revoked_at IS NULL;

CREATE TABLE categories (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), department_id uuid NOT NULL REFERENCES departments(id),
 code text NOT NULL, name text NOT NULL, parent_id uuid REFERENCES categories(id),
 form_schema jsonb NOT NULL DEFAULT '{}'::jsonb, active boolean NOT NULL DEFAULT true,
 UNIQUE(department_id,code), CHECK (jsonb_typeof(form_schema) = 'object')
);
CREATE TABLE workflow_templates (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), code text NOT NULL UNIQUE, name text NOT NULL,
 owner_department_id uuid NOT NULL REFERENCES departments(id), category_id uuid REFERENCES categories(id),
 active_version_id uuid, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workflow_versions (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), template_id uuid NOT NULL REFERENCES workflow_templates(id),
 version integer NOT NULL CHECK(version >= 1), status text NOT NULL DEFAULT 'draft'
 CHECK(status IN ('draft','published')), definition jsonb NOT NULL,
 created_by uuid REFERENCES app_users(id), created_at timestamptz NOT NULL DEFAULT now(),
 published_by uuid REFERENCES app_users(id), published_at timestamptz,
 UNIQUE(template_id,version), UNIQUE(id,template_id),
 CHECK(jsonb_typeof(definition)='object'),
 CHECK((status='draft' AND published_at IS NULL) OR (status='published' AND published_at IS NOT NULL))
);
ALTER TABLE workflow_templates ADD CONSTRAINT fk_template_active_version FOREIGN KEY (active_version_id,id) REFERENCES workflow_versions(id,template_id) DEFERRABLE INITIALLY DEFERRED;
CREATE OR REPLACE FUNCTION prevent_published_workflow_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' AND OLD.status = 'published' THEN RAISE EXCEPTION 'published workflow version cannot be deleted'; END IF;
 IF TG_OP = 'UPDATE' AND OLD.status = 'published' THEN RAISE EXCEPTION 'published workflow version is immutable'; END IF;
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER immutable_published_workflows BEFORE UPDATE OR DELETE ON workflow_versions
 FOR EACH ROW EXECUTE FUNCTION prevent_published_workflow_mutation();

CREATE TABLE tickets (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 reference text NOT NULL UNIQUE DEFAULT ('JSC-' || to_char(now(),'YYYY') || '-' || lpad(nextval('jsc_ticket_number_seq')::text,7,'0')),
 requester_id uuid NOT NULL REFERENCES app_users(id),
 category_id uuid NOT NULL REFERENCES categories(id),
 workflow_version_id uuid NOT NULL REFERENCES workflow_versions(id),
 origin_station_id uuid REFERENCES stations(id), origin_province_id uuid REFERENCES provinces(id),
 origin_department_id uuid REFERENCES departments(id),
 responsible_department_id uuid REFERENCES departments(id),
 subject text NOT NULL, description text NOT NULL, custom_fields jsonb NOT NULL DEFAULT '{}'::jsonb,
 priority text NOT NULL DEFAULT 'normal' CHECK(priority IN ('low','normal','high','critical')),
 confidentiality text NOT NULL DEFAULT 'internal' CHECK(confidentiality IN ('internal','restricted','confidential')),
 status text NOT NULL DEFAULT 'submitted' CHECK(status IN ('draft','submitted','routed','in_progress','awaiting_input','awaiting_approval','resolved','closed','withdrawn')),
 version integer NOT NULL DEFAULT 1 CHECK(version >= 1), created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(), closed_at timestamptz,
 CHECK(length(subject) BETWEEN 3 AND 255), CHECK(jsonb_typeof(custom_fields)='object')
);
CREATE INDEX idx_tickets_requester ON tickets(requester_id,created_at DESC);
CREATE INDEX idx_tickets_origin ON tickets(origin_province_id,origin_station_id,created_at DESC);
CREATE INDEX idx_tickets_owner_status ON tickets(responsible_department_id,status,updated_at DESC);
CREATE INDEX idx_tickets_open ON tickets(status,created_at) WHERE status NOT IN ('closed','withdrawn');
CREATE TABLE ticket_participants (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), ticket_id uuid NOT NULL REFERENCES tickets(id),
 user_id uuid REFERENCES app_users(id), department_id uuid REFERENCES departments(id),
 participation text NOT NULL CHECK(participation IN ('requester','collaborator','cc','watcher','owner','auditor')),
 visibility text NOT NULL DEFAULT 'internal_summary_only'
 CHECK(visibility IN ('internal_summary_only','ticket_details','restricted_details')),
 created_by uuid REFERENCES app_users(id), created_at timestamptz NOT NULL DEFAULT now(),
 CHECK ((user_id IS NOT NULL)::integer + (department_id IS NOT NULL)::integer = 1)
);
CREATE UNIQUE INDEX uq_ticket_participant_user ON ticket_participants(ticket_id,user_id,participation) WHERE user_id IS NOT NULL;
CREATE UNIQUE INDEX uq_ticket_participant_department ON ticket_participants(ticket_id,department_id,participation) WHERE department_id IS NOT NULL;
CREATE TABLE task_instances (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), ticket_id uuid NOT NULL REFERENCES tickets(id),
 workflow_version_id uuid NOT NULL REFERENCES workflow_versions(id), node_id text NOT NULL,
 branch_id text, status text NOT NULL DEFAULT 'offered'
 CHECK(status IN ('offered','claimed','in_progress','completed','rejected','cancelled','blocked')),
 assigned_department_id uuid REFERENCES departments(id),
 assigned_position_id uuid REFERENCES positions(id),
 claimed_by uuid REFERENCES app_users(id), claimed_at timestamptz,
 started_at timestamptz NOT NULL DEFAULT now(), completed_at timestamptz,
 due_at timestamptz, version integer NOT NULL DEFAULT 1 CHECK(version >= 1),
 CHECK ((status NOT IN ('claimed','in_progress')) OR (claimed_by IS NOT NULL)),
 CHECK ((claimed_by IS NULL) = (claimed_at IS NULL))
);
CREATE INDEX idx_task_ticket ON task_instances(ticket_id,started_at);
CREATE INDEX idx_task_offered ON task_instances(status,assigned_department_id,due_at) WHERE status='offered';
CREATE TABLE task_candidates (
 task_id uuid NOT NULL REFERENCES task_instances(id) ON DELETE CASCADE,
 user_id uuid NOT NULL REFERENCES app_users(id),
 reason text NOT NULL, eligible_until timestamptz, revoked_at timestamptz,
 offered_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(task_id,user_id)
);
CREATE INDEX idx_task_candidates_user ON task_candidates(user_id,task_id) WHERE revoked_at IS NULL;

CREATE TABLE ticket_events (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 ticket_id uuid NOT NULL REFERENCES tickets(id), task_id uuid REFERENCES task_instances(id),
 event_type text NOT NULL, actor_user_id uuid REFERENCES app_users(id),
 actor_system text, from_state text, to_state text, event_data jsonb NOT NULL DEFAULT '{}'::jsonb,
 reason text, workflow_version_id uuid REFERENCES workflow_versions(id), request_id text,
 visibility text NOT NULL DEFAULT 'internal',
 occurred_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 CHECK ((actor_user_id IS NOT NULL) OR (actor_system IS NOT NULL)),
 CHECK(jsonb_typeof(event_data)='object')
);
CREATE INDEX idx_ticket_events_timeline ON ticket_events(ticket_id,occurred_at,id);
CREATE OR REPLACE FUNCTION deny_ticket_event_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'ticket_events is append only'; END $$;
CREATE TRIGGER immutable_ticket_events BEFORE UPDATE OR DELETE ON ticket_events
 FOR EACH ROW EXECUTE FUNCTION deny_ticket_event_mutation();
CREATE TABLE notification_outbox (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), event_id bigint NOT NULL REFERENCES ticket_events(id),
 kind text NOT NULL, dedupe_key text NOT NULL UNIQUE, payload jsonb NOT NULL DEFAULT '{}'::jsonb,
 status text NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','delivered','failed','dead_letter')),
 attempts integer NOT NULL DEFAULT 0, available_at timestamptz NOT NULL DEFAULT now(),
 last_error text, created_at timestamptz NOT NULL DEFAULT now(), delivered_at timestamptz
);
CREATE INDEX idx_outbox_pending ON notification_outbox(status,available_at) WHERE status IN ('pending','failed');
CREATE TABLE idempotency_requests (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), actor_user_id uuid NOT NULL REFERENCES app_users(id),
 operation text NOT NULL, request_key text NOT NULL, request_hash text NOT NULL,
 response jsonb, created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(actor_user_id,operation,request_key)
);
CREATE TABLE ticket_attachments (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), ticket_id uuid NOT NULL REFERENCES tickets(id),
 uploaded_by uuid NOT NULL REFERENCES app_users(id), file_name text NOT NULL, storage_key text NOT NULL UNIQUE,
 content_type text NOT NULL, byte_size bigint NOT NULL CHECK (byte_size > 0),
 classification text NOT NULL CHECK (classification IN ('internal','restricted','confidential')),
 scan_state text NOT NULL DEFAULT 'quarantined' CHECK(scan_state IN ('quarantined','clean','rejected')),
 uploaded_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_attachment_ticket ON ticket_attachments(ticket_id);
COMMIT;
