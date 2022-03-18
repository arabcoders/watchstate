-- # migrate_up

CREATE TABLE `state`
(
    `id`          int(11)      NOT NULL AUTO_INCREMENT,
    `type`        varchar(50)  NOT NULL,
    `updated`     int(11)      NOT NULL,
    `watched`     tinyint(4)   NOT NULL DEFAULT 0,
    `meta`        text         DEFAULT NULL,
    `guid_plex`   varchar(255) DEFAULT NULL,
    `guid_imdb`   varchar(255) DEFAULT NULL,
    `guid_tvdb`   varchar(255) DEFAULT NULL,
    `guid_tmdb`   varchar(255) DEFAULT NULL,
    `guid_tvmaze` varchar(255) DEFAULT NULL,
    `guid_tvrage` varchar(255) DEFAULT NULL,
    `guid_anidb`  varchar(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `type` (`type`),
    KEY `watched` (`watched`),
    KEY `updated` (`updated`),
    KEY `guid_plex` (`guid_plex`),
    KEY `guid_imdb` (`guid_imdb`),
    KEY `guid_tvdb` (`guid_tvdb`),
    KEY `guid_tmdb` (`guid_tmdb`),
    KEY `guid_tvmaze` (`guid_tvmaze`),
    KEY `guid_tvrage` (`guid_tvrage`),
    KEY `guid_anidb` (`guid_anidb`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- # migrate_down

DROP TABLE IF EXISTS `state`;
