CREATE TABLE IF NOT EXISTS `#__updatemonitor_updates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uid` char(40) NOT NULL,
  `ident` varchar(255) NOT NULL,
  `extension_id` int unsigned NOT NULL DEFAULT 0,
  `element` varchar(100) NOT NULL DEFAULT '',
  `type` varchar(20) NOT NULL DEFAULT '',
  `folder` varchar(100) NOT NULL DEFAULT '',
  `client_id` tinyint NOT NULL DEFAULT 0,
  `name` varchar(255) NOT NULL DEFAULT '',
  `type_label` varchar(40) NOT NULL DEFAULT '',
  `installed_version` varchar(50) NOT NULL DEFAULT '',
  `available_version` varchar(50) NOT NULL DEFAULT '',
  `severity` varchar(10) NOT NULL DEFAULT '',
  `security_info` mediumtext NULL,
  `infourl` varchar(1024) NOT NULL DEFAULT '',
  `changelogurl` varchar(1024) NOT NULL DEFAULT '',
  `status` varchar(10) NOT NULL DEFAULT 'pending',
  `ignored` tinyint NOT NULL DEFAULT 0,
  `first_detected` datetime NOT NULL,
  `last_seen` datetime NOT NULL,
  `resolved_at` datetime NULL,
  `resolve_reason` varchar(20) NOT NULL DEFAULT '',
  `missed` tinyint unsigned NOT NULL DEFAULT 0,
  `notified_at` datetime NULL,
  `notified_severity` varchar(10) NOT NULL DEFAULT '',
  `notify_count` int unsigned NOT NULL DEFAULT 0,
  `last_reminder_at` datetime NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_uid` (`uid`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__updatemonitor_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `created` datetime NOT NULL,
  `level` varchar(10) NOT NULL DEFAULT 'info',
  `event` varchar(20) NOT NULL DEFAULT '',
  `message` varchar(1000) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__updatemonitor_state` (
  `name` varchar(50) NOT NULL,
  `value` text NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `#__updatemonitor_state` (`name`, `value`) VALUES ('check_running', '');
