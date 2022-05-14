-- # migrate_up

ALTER TABLE "state"
    ADD "suids" text NULL;

CREATE INDEX "state_suids" ON "state" ("suids");

-- # migrate_down

--ALTER TABLE "state" DROP "suids";
