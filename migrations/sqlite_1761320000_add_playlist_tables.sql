-- # migrate_up

CREATE TABLE IF NOT EXISTS "playlists"
(
    "id"          integer NOT NULL PRIMARY KEY AUTOINCREMENT,
    "backend"     text    NOT NULL,
    "backend_id"  text    NOT NULL,
    "title"       text    NOT NULL,
    "type"        text    NOT NULL DEFAULT 'video',
    "summary"     text    NULL,
    "is_editable" integer NOT NULL DEFAULT '1',
    "is_smart"    integer NOT NULL DEFAULT '0',
    "is_public"   integer NOT NULL DEFAULT '0',
    "item_count"  integer NOT NULL DEFAULT '0',
    "sync_id"     text    NULL,
    "content_hash" text   NOT NULL DEFAULT '',
    "remote_updated_at" integer NOT NULL DEFAULT '0',
    "deleted_at"  integer NULL,
    "metadata"    text    NOT NULL DEFAULT '{}',
    "created_at"  integer NOT NULL,
    "updated_at"  integer NOT NULL,
    "synced_at"   integer NOT NULL,
    UNIQUE ("backend", "backend_id")
);

CREATE INDEX IF NOT EXISTS "playlists_backend" ON "playlists" ("backend");
CREATE INDEX IF NOT EXISTS "playlists_title" ON "playlists" ("title");
CREATE INDEX IF NOT EXISTS "playlists_sync_id" ON "playlists" ("sync_id");
CREATE INDEX IF NOT EXISTS "playlists_remote_updated_at" ON "playlists" ("remote_updated_at");
CREATE INDEX IF NOT EXISTS "playlists_deleted_at" ON "playlists" ("deleted_at");

CREATE TABLE IF NOT EXISTS "playlist_items"
(
    "id"               integer NOT NULL PRIMARY KEY AUTOINCREMENT,
    "playlist_id"      integer NOT NULL,
    "position"         integer NOT NULL,
    "state_id"         integer NULL,
    "backend_item_id"  text    NULL,
    "backend_entry_id" text    NULL,
    "item_type"        text    NULL,
    "title"            text    NOT NULL,
    "metadata"         text    NOT NULL DEFAULT '{}',
    "created_at"       integer NOT NULL,
    "updated_at"       integer NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS "playlist_items_position" ON "playlist_items" ("playlist_id", "position");
CREATE INDEX IF NOT EXISTS "playlist_items_playlist_id" ON "playlist_items" ("playlist_id");
CREATE INDEX IF NOT EXISTS "playlist_items_state_id" ON "playlist_items" ("state_id");
CREATE INDEX IF NOT EXISTS "playlist_items_backend_item" ON "playlist_items" ("backend_item_id");

-- # migrate_down

DROP TABLE IF EXISTS "playlist_items";
DROP TABLE IF EXISTS "playlists";
