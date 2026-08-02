-- ============================================================
-- AI Agent — Alias Pipeline Data Fix (BUG #2)
-- ERP Master System — Shree Label
-- ============================================================
-- PROBLEM:
--   The alias pipeline (AliasResolver → canonical → retrieval → LLM) never
--   actually expanded the query "mriganka", because `ai_knowledge_aliases`
--   contained ONLY the alias "MDB" → kb_id 23. The bare alias "Mriganka"
--   (and its variants) were missing, so AliasResolver::resolve("mriganka")
--   returned the prompt unchanged.
--
-- FIX:
--   Seed the missing alias rows for KB entry id=23 ("Who is Mriganka Bhusan
--   Debnath?"). Safe to run multiple times (guarded INSERT ... WHERE NOT
--   EXISTS). The table is created on demand by AliasResolver::ensureTable().
--
-- NOTE: These AI-agent tables are NOT part of the canonical ERP backup
--   (`shree_label_erp_full_fresh_backup_*.sql`), so they must also be seeded
--   on any fresh / live deployment or the KB + alias layer is empty there.
-- ============================================================

INSERT INTO `ai_knowledge_aliases` (`kb_id`, `alias`)
SELECT 23, 'mriganka'
WHERE NOT EXISTS (SELECT 1 FROM `ai_knowledge_aliases` WHERE `kb_id` = 23 AND `alias` = 'mriganka');

INSERT INTO `ai_knowledge_aliases` (`kb_id`, `alias`)
SELECT 23, 'Mriganka Debnath'
WHERE NOT EXISTS (SELECT 1 FROM `ai_knowledge_aliases` WHERE `kb_id` = 23 AND `alias` = 'Mriganka Debnath');

INSERT INTO `ai_knowledge_aliases` (`kb_id`, `alias`)
SELECT 23, 'মৃগাঙ্ক'
WHERE NOT EXISTS (SELECT 1 FROM `ai_knowledge_aliases` WHERE `kb_id` = 23 AND `alias` = 'মৃগাঙ্ক');

INSERT INTO `ai_knowledge_aliases` (`kb_id`, `alias`)
SELECT 23, 'মৃগাঙ্ক ভূষণ দেবনাথ'
WHERE NOT EXISTS (SELECT 1 FROM `ai_knowledge_aliases` WHERE `kb_id` = 23 AND `alias` = 'মৃগাঙ্ক ভূষণ দেবনাথ');

-- ============================================================
-- KB entry id=24 ("Who is Aditya Sitani ?")
--   keywords: 'Aditya, sitani, aditya sitani'
--   The bare aliases 'aditya', 'sitani', 'aditya sitani' were
--   MISSING from ai_knowledge_aliases, so AliasResolver::resolve()
--   never expanded them and the retrieval/LLM pipeline could not
--   ground on this KB entry. Same data-gap pattern as kb 23.
-- ============================================================

INSERT INTO `ai_knowledge_aliases` (`kb_id`, `alias`)
SELECT 24, 'aditya'
WHERE NOT EXISTS (SELECT 1 FROM `ai_knowledge_aliases` WHERE `kb_id` = 24 AND `alias` = 'aditya');

INSERT INTO `ai_knowledge_aliases` (`kb_id`, `alias`)
SELECT 24, 'sitani'
WHERE NOT EXISTS (SELECT 1 FROM `ai_knowledge_aliases` WHERE `kb_id` = 24 AND `alias` = 'sitani');

INSERT INTO `ai_knowledge_aliases` (`kb_id`, `alias`)
SELECT 24, 'aditya sitani'
WHERE NOT EXISTS (SELECT 1 FROM `ai_knowledge_aliases` WHERE `kb_id` = 24 AND `alias` = 'aditya sitani');

INSERT INTO `ai_knowledge_aliases` (`kb_id`, `alias`)
SELECT 24, 'Aditya Sitani'
WHERE NOT EXISTS (SELECT 1 FROM `ai_knowledge_aliases` WHERE `kb_id` = 24 AND `alias` = 'Aditya Sitani');

INSERT INTO `ai_knowledge_aliases` (`kb_id`, `alias`)
SELECT 24, 'আদিত্য'
WHERE NOT EXISTS (SELECT 1 FROM `ai_knowledge_aliases` WHERE `kb_id` = 24 AND `alias` = 'আদিত্য');
