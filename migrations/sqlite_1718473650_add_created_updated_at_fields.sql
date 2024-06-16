-- # migrate_up

ALTER TABLE "state"
    ADD COLUMN "created_at" integer NOT NULL DEFAULT '0';
ALTER TABLE "state"
    ADD COLUMN "updated_at" integer NOT NULL DEFAULT '0';
UPDATE "state"
SET created_at = updated,
    updated_at = updated
WHERE created_at = 0;
CREATE INDEX IF NOT EXISTS "state_created_at" ON "state" (created_at);
CREATE INDEX IF NOT EXISTS "state_updated_at" ON "state" (updated_at);

-- # migrate_down

ALTER TABLE "state"
    DROP COLUMN "created_at";
ALTER TABLE "state"
    DROP COLUMN "updated_at";
DROP INDEX IF EXISTS "state_created_at";
DROP INDEX IF EXISTS "state_updated_at";
