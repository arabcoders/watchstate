-- # migrate_up

CREATE SEQUENCE state_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE "state" (
      "id" integer DEFAULT nextval('state_id_seq') NOT NULL,
      "type" character varying NOT NULL,
      "updated" integer NOT NULL,
      "watched" smallint NOT NULL,
      "meta" json,
      "guid_plex" character varying,
      "guid_imdb" character varying,
      "guid_tvdb" character varying,
      "guid_tmdb" character varying,
      "guid_tvmaze" character varying,
      "guid_tvrage" character varying,
      "guid_anidb" character varying,
      CONSTRAINT "state_pkey" PRIMARY KEY ("id")
) WITH (oids = false);

CREATE INDEX "state_guid_anidb" ON "state" USING btree ("guid_anidb");
CREATE INDEX "state_guid_imdb" ON "state" USING btree ("guid_imdb");
CREATE INDEX "state_guid_plex" ON "state" USING btree ("guid_plex");
CREATE INDEX "state_guid_tmdb" ON "state" USING btree ("guid_tmdb");
CREATE INDEX "state_guid_tvdb" ON "state" USING btree ("guid_tvdb");
CREATE INDEX "state_guid_tvmaze" ON "state" USING btree ("guid_tvmaze");
CREATE INDEX "state_guid_tvrage" ON "state" USING btree ("guid_tvrage");
CREATE INDEX "state_type" ON "state" USING btree ("type");
CREATE INDEX "state_updated" ON "state" USING btree ("updated");
CREATE INDEX "state_watched" ON "state" USING btree ("watched");

-- # migrate_down

DROP TABLE IF EXISTS "state";
DROP SEQUENCE IF EXISTS state_id_seq;
