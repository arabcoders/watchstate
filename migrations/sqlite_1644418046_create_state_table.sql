-- # migrate_up

CREATE TABLE IF NOT EXISTS "state"
(
    "id"          integer NOT NULL PRIMARY KEY AUTOINCREMENT,
    "type"        text    NOT NULL,
    "updated"     integer NOT NULL,
    "watched"     integer NOT NULL DEFAULT 0,
    "meta"        text    NULL,
    "guid_plex"   text    NULL,
    "guid_imdb"   text    NULL,
    "guid_tvdb"   text    NULL,
    "guid_tmdb"   text    NULL,
    "guid_tvmaze" text    NULL,
    "guid_tvrage" text    NULL,
    "guid_anidb"  text    NULL
);

CREATE INDEX IF NOT EXISTS "state_type" ON "state" ("type");
CREATE INDEX IF NOT EXISTS "state_watched" ON "state" ("watched");
CREATE INDEX IF NOT EXISTS "state_updated" ON "state" ("updated");
CREATE INDEX IF NOT EXISTS "state_meta" ON "state" ("meta");
CREATE INDEX IF NOT EXISTS "state_guid_plex" ON "state" ("guid_plex");
CREATE INDEX IF NOT EXISTS "state_guid_imdb" ON "state" ("guid_imdb");
CREATE INDEX IF NOT EXISTS "state_guid_tvdb" ON "state" ("guid_tvdb");
CREATE INDEX IF NOT EXISTS "state_guid_tvmaze" ON "state" ("guid_tvmaze");
CREATE INDEX IF NOT EXISTS "state_guid_tvrage" ON "state" ("guid_tvrage");
CREATE INDEX IF NOT EXISTS "state_guid_anidb" ON "state" ("guid_anidb");

-- # migrate_down

DROP TABLE IF EXISTS "state";
