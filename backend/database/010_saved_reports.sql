BEGIN;
CREATE TABLE saved_reports (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),owner_user_id uuid NOT NULL REFERENCES app_users(id),
 name text NOT NULL CHECK(length(name) BETWEEN 3 AND 120),filters jsonb NOT NULL CHECK(jsonb_typeof(filters)='object'),
 active boolean NOT NULL DEFAULT true,version integer NOT NULL DEFAULT 1,
 created_at timestamptz NOT NULL DEFAULT now(),updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX idx_saved_reports_owner ON saved_reports(owner_user_id,id) WHERE active;
COMMIT;
