BEGIN;
CREATE TABLE court_levels(id uuid PRIMARY KEY DEFAULT gen_random_uuid(),code text NOT NULL UNIQUE,name text NOT NULL,active boolean NOT NULL DEFAULT true,version integer NOT NULL DEFAULT 1);
ALTER TABLE stations ADD COLUMN court_level_id uuid REFERENCES court_levels(id);
ALTER TABLE departments ADD COLUMN parent_id uuid REFERENCES departments(id);
ALTER TABLE positions ADD COLUMN reports_to_position_id uuid REFERENCES positions(id);
CREATE TABLE sample_installations(code text PRIMARY KEY,installed_at timestamptz NOT NULL DEFAULT now(),summary jsonb NOT NULL);
CREATE TRIGGER audit_court_levels AFTER INSERT OR UPDATE ON court_levels FOR EACH ROW EXECUTE FUNCTION audit_organization_change();
COMMIT;
