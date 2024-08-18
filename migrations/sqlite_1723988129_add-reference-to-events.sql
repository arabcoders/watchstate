-- # migrate_up

CREATE TABLE "_tmp_events"
(
    "id"         text    NOT NULL,
    "status"     integer NOT NULL DEFAULT '0',
    "reference"  text    NULL,
    "event"      text    NOT NULL,
    "event_data" text    NOT NULL DEFAULT '{}',
    "options"    text    NOT NULL DEFAULT '{}',
    "attempts"   integer NOT NULL DEFAULT '0',
    "logs"       text    NOT NULL DEFAULT '{}',
    "created_at" numeric NOT NULL,
    "updated_at" numeric NULL,
    PRIMARY KEY ("id")
);

INSERT INTO "_tmp_events" ("id", "status", "event", "event_data", "options", "attempts", "logs", "created_at",
                           "updated_at")
SELECT "id",
       "status",
       "event",
       "event_data",
       "options",
       "attempts",
       "logs",
       "created_at",
       "updated_at"
FROM "events";

DROP TABLE "events";
ALTER TABLE "_tmp_events"
    RENAME TO "events";
CREATE INDEX "events_event" ON "events" ("event");
CREATE INDEX "events_status" ON "events" ("status");
CREATE INDEX "events_reference" ON "events" ("reference");

-- # migrate_down

CREATE TABLE "_tmp_events"
(
    "id"         text    NOT NULL,
    "status"     integer NOT NULL DEFAULT '0',
    "event"      text    NOT NULL,
    "event_data" text    NOT NULL DEFAULT '{}',
    "options"    text    NOT NULL DEFAULT '{}',
    "attempts"   integer NOT NULL DEFAULT '0',
    "logs"       text    NOT NULL DEFAULT '{}',
    "created_at" numeric NOT NULL,
    "updated_at" numeric NULL,
    PRIMARY KEY ("id")
);

INSERT INTO "_tmp_events" ("id", "status", "event", "event_data", "options", "attempts", "logs", "created_at",
                           "updated_at")
SELECT "id",
       "status",
       "event",
       "event_data",
       "options",
       "attempts",
       "logs",
       "created_at",
       "updated_at"
FROM "events";

DROP TABLE "events";
ALTER TABLE "_tmp_events"
    RENAME TO "events";
CREATE INDEX "events_event" ON "events" ("event");
CREATE INDEX "events_status" ON "events" ("status");

-- put your downgrade database commands here.
