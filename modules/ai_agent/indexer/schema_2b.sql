ALTER TABLE `ai_knowledge_entities` ADD FULLTEXT INDEX `ft_name_sig_sum` (`name`, `signature`, `summary`);
ALTER TABLE `ai_entity_keywords` ADD FULLTEXT INDEX `ft_keyword` (`keyword`);
