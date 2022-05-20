-- # migrate_up

CREATE TABLE "state"
(
    "id"       integer NOT NULL PRIMARY KEY AUTOINCREMENT,
    "type"     text    NOT NULL,
    "updated"  integer NOT NULL,
    "watched"  integer NOT NULL DEFAULT '0',
    "via"      text    NOT NULL,
    "title"    text    NOT NULL,
    "year"     integer NULL,
    "season"   integer NULL,
    "episode"  integer NULL,
    "parent"   text    NULL,
    "guids"    text    NULL,
    "metadata" text    NULL,
    "extra"    text    NULL
);

CREATE INDEX "state_type" ON "state" ("type");
CREATE INDEX "state_updated" ON "state" ("updated");
CREATE INDEX "state_watched" ON "state" ("watched");
CREATE INDEX "state_via" ON "state" ("via");
CREATE INDEX "state_title" ON "state" ("title");
CREATE INDEX "state_year" ON "state" ("year");
CREATE INDEX "state_season" ON "state" ("season");
CREATE INDEX "state_episode" ON "state" ("episode");
CREATE INDEX "state_parent" ON "state" ("parent");
CREATE INDEX "state_guids" ON "state" ("guids");
CREATE INDEX "state_metadata" ON "state" ("metadata");
CREATE INDEX "state_extra" ON "state" ("extra");

-- # migrate_down

DROP TABLE IF EXISTS "state";
