-- # migrate_up
ALTER TABLE "state"
    RENAME TO "old_state";

CREATE TABLE "state"
(
    "id"          integer NOT NULL PRIMARY KEY AUTOINCREMENT,
    "type"        text    NOT NULL,
    "updated"     integer NOT NULL,
    "watched"     integer NOT NULL DEFAULT '0',
    "via"         text    NULL,
    "title"       text    NULL,
    "year"        integer NULL,
    "season"      integer NULL,
    "episode"     integer NULL,
    "parent"      text    NULL,
    "guids"       text    NULL,
    "meta"        text    NULL,
    "guid_plex"   text    NULL,
    "guid_imdb"   text    NULL,
    "guid_tvdb"   text    NULL,
    "guid_tmdb"   text    NULL,
    "guid_tvmaze" text    NULL,
    "guid_tvrage" text    NULL,
    "guid_anidb"  text    NULL
);

INSERT INTO "state" ("id", "type", "updated", "watched", "meta", "guid_plex", "guid_imdb", "guid_tvdb",
                     "guid_tmdb", "guid_tvmaze", "guid_tvrage", "guid_anidb")
SELECT "id",
       "type",
       "updated",
       "watched",
       "meta",
       "guid_plex",
       "guid_imdb",
       "guid_tvdb",
       "guid_tmdb",
       "guid_tvmaze",
       "guid_tvrage",
       "guid_anidb"
FROM "old_state";

UPDATE sqlite_sequence
SET "seq" = (SELECT MAX("id") FROM "state")
WHERE "name" = 'state';

DROP TABLE "old_state";

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
CREATE INDEX "state_meta" ON "state" ("meta");
CREATE INDEX "state_guid_plex" ON "state" ("guid_plex");
CREATE INDEX "state_guid_imdb" ON "state" ("guid_imdb");
CREATE INDEX "state_guid_tvdb" ON "state" ("guid_tvdb");
CREATE INDEX "state_guid_tvmaze" ON "state" ("guid_tvmaze");
CREATE INDEX "state_guid_tvrage" ON "state" ("guid_tvrage");
CREATE INDEX "state_guid_anidb" ON "state" ("guid_anidb");

-- # migrate_down

ALTER TABLE "state"
    RENAME TO "old_state";

CREATE TABLE "state"
(
    "id"          integer NOT NULL PRIMARY KEY AUTOINCREMENT,
    "type"        text    NOT NULL,
    "updated"     integer NOT NULL,
    "watched"     integer NOT NULL DEFAULT '0',
    "meta"        text    NULL,
    "guid_plex"   text    NULL,
    "guid_imdb"   text    NULL,
    "guid_tvdb"   text    NULL,
    "guid_tmdb"   text    NULL,
    "guid_tvmaze" text    NULL,
    "guid_tvrage" text    NULL,
    "guid_anidb"  text    NULL
);

INSERT INTO "state" ("id", "type", "updated", "watched", "meta", "guid_plex", "guid_imdb", "guid_tvdb",
                     "guid_tmdb", "guid_tvmaze", "guid_tvrage", "guid_anidb")
SELECT "id",
       "type",
       "updated",
       "watched",
       "meta",
       "guid_plex",
       "guid_imdb",
       "guid_tvdb",
       "guid_tmdb",
       "guid_tvmaze",
       "guid_tvrage",
       "guid_anidb"
FROM "old_state";

UPDATE sqlite_sequence
SET "seq" = (SELECT MAX("id") FROM "state")
WHERE "name" = 'state';

DROP TABLE "old_state";

CREATE INDEX "state_type" ON "state" ("type");
CREATE INDEX "state_updated" ON "state" ("updated");
CREATE INDEX "state_watched" ON "state" ("watched");
CREATE INDEX "state_meta" ON "state" ("meta");
CREATE INDEX "state_guid_plex" ON "state" ("guid_plex");
CREATE INDEX "state_guid_imdb" ON "state" ("guid_imdb");
CREATE INDEX "state_guid_tvdb" ON "state" ("guid_tvdb");
CREATE INDEX "state_guid_tvmaze" ON "state" ("guid_tvmaze");
CREATE INDEX "state_guid_tvrage" ON "state" ("guid_tvrage");
CREATE INDEX "state_guid_anidb" ON "state" ("guid_anidb");

-- put your downgrade database commands here.
