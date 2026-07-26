-- ============================================================
-- AI Agent Plugin — Database Migration
-- Creates the ai_agent_knowledge table for admin training data
-- Safe to run multiple times (IF NOT EXISTS)
-- ============================================================

CREATE TABLE IF NOT EXISTS `ai_agent_knowledge` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `category` ENUM('FAQ','Business Rule','Terminology','Quick Chip') NOT NULL DEFAULT 'FAQ',
  `keywords` TEXT NOT NULL COMMENT 'Comma-separated keywords for matching user queries',
  `question` VARCHAR(500) NULL COMMENT 'Optional display question text',
  `answer` TEXT NOT NULL COMMENT 'The response AI should give when keywords match',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_by` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
