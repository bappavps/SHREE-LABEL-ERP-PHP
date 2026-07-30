# AI Agent Command Architecture

## Core Concept: Three-Tier Query Routing

```
User Input
    │
    ├── Normal Query (no / prefix, e.g. "how many paper rolls?")
    │       → ❌ Knowledge Base skipped
    │       → ❌ ERP Database skipped
    │       → ✅ External LLM only
    │
    ├── /erp <query>
    │       → Knowledge Base (trained Q&A)
    │       → ERP Database (paper_stock, master_plate_data, etc.)
    │       → ❌ External LLM skipped → "No knowledge" message
    │
    ├── /paperstock | /plate | other module commands
    │       → Priority Mode: search that specific module FIRST
    │       → ERP Database (scoped to the priority module)
    │       → ❌ External LLM skipped → "No knowledge" message
    │
    └── /"quoted term" (future: universal quick search)
            → Search ALL ERP tables (paper_stock, master_plate_data, jobs, dispatch)
            → AI decides which module matches best
            → Ask user to narrow down if ambiguous
```

## Key Flags

### `$skipNormalDb` (Normal Mode)
- Defined at line ~1074: `$skipNormalDb = false;`
- Set to `true` when: `!$commandType && !$erpOnlyMode && strpos($pTrimmed, '/') !== 0`
- When `true`: KB check, arithmetic evaluator, mm² converter, math engine, and DB router are ALL skipped
- Unmatched query goes directly to `call_llm_api()`

### `$erpOnlyMode` (ERP-Only / Skip LLM)
- Defined at line ~1073: `$erpOnlyMode = false;`
- Set to `true` by:
  - `/erp <query>` — any query prefixed with `/erp`
  - `/plate <query>` — any query prefixed with `/plate`
  - `/paperstock <query>` — any query prefixed with `/paperstock` or `paper`
  - Inline quoted term (`"product name"` inside prompt) — auto-detected product lookup
- Used at line ~4397:
  ```php
  if ($erpOnlyMode) {
      $llmAnswer = null;  // Skip external LLM call
  } else {
      $llmAnswer = call_llm_api($prompt, $config);
  }
  ```
- When `$erpOnlyMode = true` and no KB/DB match found → shows "No knowledge" message with admin tip

## Priority Mode Session Variable

- `$_SESSION['ai_priority_mode']` stores current priority module
- Set by `/paperstock` → `'paperstock'`, `/plate` → `'plate'`
- Priority handler runs BEFORE the general DB query router
- Auto-cleared when user asks about a different module
- Persists until explicitly cleared (`/clear`, `reset`, `normal`)

## Guarded Code Blocks

The following sections are guarded with `$skipNormalDb` to bypass ERP processing for normal queries:

| Code Section | Location (approx.) | Guard |
|---|---|---|
| Bare quoted handler | Line 1141 | `strpos($pTrimmed, '/') === 0` (only fires with `/` prefix) |
| Inline quoted term handler | Line 1324 | `if (!$skipNormalDb && !$erpOnlyMode && ...)` |
| Knowledge Base check | Line 1610 | `$knowledgeMatch = ($skipKB \|\| $skipNormalDb) ? null : ...` |
| Arithmetic evaluator | Line 1752 | Whole block wrapped in `if (!$skipNormalDb) { ... }` |
| mm² → Square Inch converter | Line 1813 | `if (!$skipNormalDb && preg_match(...))` |
| Math engine (label costing) | Line 1859 | `if ($isMathIntent && !$skipNormalDb)` |
| DB router (`fetch_erp_data_by_intent`) | Line 4319 | `if ($skipNormalDb) { return dummy 'Unmatched Query Assistant' }` |
| No-knowledge fallback message | Lines 4391-4414 | Conditional message text based on `$skipNormalDb` |

## `/paperstock` Command Flow

1. User types `/paperstock`, `paper stock`, `/paper`, `পেপার`, `पेपर` etc.
2. Sets `$_SESSION['ai_priority_mode'] = 'paperstock'`
3. Strips command prefix from prompt
4. If sub-query empty → defaults to `"Show total paper rolls"`
5. Sets `$erpOnlyMode = true` (skips external LLM)
6. Priority handler runs paper_stock SQL with jumbo/slitting breakdown
7. Returns formatted table

## `/plate` Command Flow

1. User types `/plate`, `plate`, `প্লেট`, `प्लेट`
2. Sets `$_SESSION['ai_priority_mode'] = 'plate'`
3. Strips command prefix
4. If sub-query empty → shows help with example queries
5. Sets `$erpOnlyMode = true` (skips external LLM)
6. Priority handler runs master_plate_data SQL

## `/erp` Command Flow

1. User types `/erp <query>`
2. Sets `$erpOnlyMode = true`
3. Strips `/erp` prefix
4. Normal processing (no priority mode) — KB first, then DB
5. External LLM skipped if unmatched

## Why `/` Commands Skip External LLM

- ERP database contains LIVE production data (paper stock, plates, jobs)
- External LLMs have NO knowledge of this proprietary data
- `/paperstock` sent to a general LLM returns useless generic answers
- The `/` prefix guarantees ERP data only — never irrelevant AI hallucination

## File Location

- **Main API engine**: `api.php`
- **Command handlers**: Lines ~978–1140 (routing) and ~1940–4310 (priority execution)
- **Normal mode detection**: Line ~1316
- **ERP-only skip (LLM bypass)**: Line ~4397
