-- # migrate_up

CREATE TABLE `events`
(
    `id`         char(36)     NOT NULL,
    `status`     tinyint(1)   NOT NULL DEFAULT 0,
    `event`      varchar(255) NOT NULL,
    `event_data` longtext     NOT NULL DEFAULT '{}',
    `options`    longtext     NOT NULL DEFAULT '{}',
    `attempts`   tinyint(1)   NOT NULL DEFAULT 0,
    `logs`       longtext     NOT NULL DEFAULT '{}',
    `created_at` datetime     NOT NULL,
    `updated_at` datetime              DEFAULT NULL,
    PRIMARY KEY (`id`)
);

CREATE INDEX "events_event" ON "events" ("event");
CREATE INDEX "events_status" ON "events" ("status");

-- # migrate_down

DROP TABLE IF EXISTS "events";
