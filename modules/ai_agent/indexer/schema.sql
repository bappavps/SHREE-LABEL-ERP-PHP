CREATE TABLE IF NOT EXISTS `ai_knowledge_entities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(50) NOT NULL COMMENT 'module, controller, service, helper, api, config, constant, workflow, setting, cron',
  `name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `line_start` int(11) DEFAULT NULL,
  `line_end` int(11) DEFAULT NULL,
  `signature` text DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `file_hash` varchar(64) DEFAULT NULL,
  `last_indexed` datetime DEFAULT NULL,
  `schema_version` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  KEY `idx_file_path` (`file_path`(191)),
  KEY `idx_hash` (`file_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_entity_keywords` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_id` int(11) NOT NULL,
  `keyword` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_entity_id` (`entity_id`),
  KEY `idx_keyword` (`keyword`),
  CONSTRAINT `fk_ai_entity_keywords_entity` FOREIGN KEY (`entity_id`) REFERENCES `ai_knowledge_entities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_relationships` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_entity_id` int(11) NOT NULL,
  `target_entity_id` int(11) NOT NULL,
  `relationship_type` varchar(50) NOT NULL,
  `confidence` float NOT NULL DEFAULT 1.0,
  PRIMARY KEY (`id`),
  KEY `idx_source` (`source_entity_id`),
  KEY `idx_target` (`target_entity_id`),
  CONSTRAINT `fk_ai_rel_source` FOREIGN KEY (`source_entity_id`) REFERENCES `ai_knowledge_entities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_rel_target` FOREIGN KEY (`target_entity_id`) REFERENCES `ai_knowledge_entities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
