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

-- # migrate_down

DROP TABLE IF EXISTS "state";
