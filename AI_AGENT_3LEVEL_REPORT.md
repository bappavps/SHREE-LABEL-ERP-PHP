# Debugging Report — AI Agent 3-Level Suggestions (Floating Widget Regression)

Date: 2026-08-02 · Project: `shree-label-php` · Scope: UI/UX only — **no ERP business logic changed**

## 1. User-reported bug (active)
> "user quotation start korle plate management page theke item ar name show korche na"
> (When the user starts a quotation `"`, the plate names from Plate Management are not showing.)

**Repro:** Open the floating AI widget on a real ERP page (e.g. Dashboard) → type `/plate "b` → expected a dropdown with plate names from `master_plate_data`; actually nothing appeared.

### Root causes found (3, all in `modules/ai_agent/floating_widget.php`)

| # | Cause | Effect |
|---|-------|--------|
| 1 | `processChatInput` re-renders the input as `<span class="cmd-highlight">/plate</span> "b`. The Level-3 `input` handler read **only the selection's text node** (` "b`), so the text before the quote was empty → the `/plate` entity-command check failed → **no fetch was ever made**. | Detection broken (span-splitting) |
| 2 | The handler referenced `cmdSuggestionsPopup`, a variable declared with `var` **inside the IIFE** → `ReferenceError: cmdSuggestionsPopup is not defined` was thrown **before** the fetch, crashing the whole handler. | JS crash on every keystroke |
| 3 | The handler builds the API URL from a hidden `<input id="aiBaseUrl">` that **did not exist** in the markup. On real pages the fetch therefore resolved relative to the host page → `/modules/dashboard/api.php` → **HTTP 404**. | API call 404 |

### Fixes (all in `modules/ai_agent/floating_widget.php`)
1. **Span-agnostic text reconstruction** — the Level-3 handler now clones the selection range up to the caret and reads the **full text before the caret** (ignoring highlight spans), then finds the last `"` and checks `/^(job|plate|paper|product)$/`. Debounce 200 ms, AJAX to `api.php?action=autocomplete&prompt=…`, capped at **3** results.
2. **Removed the out-of-scope variable** — replaced `if (cmdSuggestionsPopup) …` with `document.getElementById('aiFloatingCmdSuggestionsPopup')`.
3. **Added the missing hidden input** before the trigger button:
   `<input type="hidden" id="aiBaseUrl" value="<?= $moduleBaseUrl ?>">` so the fetch hits the real `…/modules/ai_agent/api.php`.
4. **Hardened `applyFloatAutocomplete(name)`** — now operates on the full `innerText` (via a new `floatCaretOffset()` helper using the same cloned-range technique), replaces the partial search term, auto-closes the quote + trailing space, rebuilds the input as a plain text node, and places the caret **after the closing quote** (then hides the dropdown and refocuses).

## 2. BUG#1 — standalone `index.php` regression (fixed earlier)
- Root causes: no `#aiCmdSuggestions` element; `aiAgentParams` was undefined (`ReferenceError` on Level-3 fetch); the controller wrote `innerHTML` on a plain `<input>`.
- Fixes: added the dropdown element + inline script `<script>window.aiAgentParams = { baseUrl: <?= json_encode(BASE_URL) ?> };</script>` and made the controller use `.value` + `setSelectionRange`.

## 3. BUG#2 — Knowledge Alias Pipeline (fixed earlier, re-verified)
- Root cause: KB-24 aliases for Aditya/Sitani were missing; `ai_knowledge_entities` had no Mriganka/Aditya rows, so `RetrievalEngine::search()` returned nothing.
- Fix: seeded 24 aliases (`ai_knowledge_aliases_seed.sql`) + added `RetrievalEngine::searchKnowledgeBase()` grounding.

## 4. 3-Level Suggestion System (as implemented)
- **Level 1:** `/` shows all matching AI commands (`/job /plate /planning /paper /product /client /dispatch /order /stock`); typing `/pl` filters. Full list shown for discoverability (Levels 2/3 cap at 3).
- **Level 2:** command + SPACE (`/plate ␣`) hides the command list and shows up to 3 query examples.
- **Level 3:** typing inside an **unclosed** `"` (odd quote count → the last `"` is the opening quote) hides query suggestions, switches to Entity Search Mode → AJAX search of `master_plate_data.name`. It now triggers for `/job|/plate|/paper|/product` **even when there are extra words between the command and the quote**, e.g. `/job how many label will be print if "blue 500`. **If the quote is empty (`/plate "`)** it shows **every job/plate** (like Plate Management, ordered by sl_no); **once a term is typed** it returns **ALL matching** plates (no cap — dropdown max-height 180–260px, `overflow-y:auto`, scrollable). Selecting yields e.g. `/job how many label will be print if "Blue 500ml" ` — text before the opening quote is preserved, auto-closing quote + caret after it. A **balanced** (closed) quote, e.g. `/plate "Blue 500ml" 12 per inch`, hides the dropdown so the calculation flow is untouched.
- **Browse-all API:** `api.php?action=autocomplete&prompt=` (empty prompt) returns all 1070 plates ordered exactly like the Plate Management page (`CASE … sl_no ASC, id ASC`). An empty prompt is only rejected for regular queries, not for autocomplete.
- **3-Color Level Coding (PWA `app.php` + floating widget `floating_widget.php`):** Level 1 commands `/` = BLUE (`#3b82f6`), Level 2 query examples `/cmd ` = GREEN (`#10b981`), Level 3 entities `/cmd "term` = AMBER (`#f59e0b`). Applied via container border + glow (and item/badge accents: `#cmdSuggestions` / `#cmdSuggestionsPopup` / `#autocompleteSuggestions` in PWA; `#aiFloatingCmdSuggestions` / `#aiFloatingCmdSuggestionsPopup` / `.ai-float-autocomplete-dropdown` in floating widget).
- **Interaction:** AJAX only, 200 ms debounce, ↑/↓ + Enter, mouse click, ESC closes, outside-click closes. Shared-hosting compatible. UI not redesigned.

## 5. Verification (real browser, logged in as admin)
| Test | Result |
|------|--------|
| Dashboard floating widget `/plate "b` | ✅ 112 matching plate entities (scrollable), no console errors |
| Floating widget `/plate "` (empty) | ✅ shows ALL 1070 jobs (scrollable, Plate-Management order) |
| index.php `/plate "` (empty) | ✅ 1070 jobs; `/plate "b` → 112 |
| app.php `/plate "` (empty) | ✅ 1070 jobs; `/plate "b` → 112; `/plate "chir` → Chiring 250ml/500ml |
| `/job "Grass" details` | ✅ plate details (Printing Plates Master Tool) instead of "Found 0 Master Jobs" |
| Click suggestion | ✅ input → `/plate "Baby foot" `, dropdown hidden, caret after closing quote |
| Sentence-with-quote `/job how many label will be print if "blue 500` (app.php) | ✅ shows 2 plates (Blue 500ml, Blue Express Line 500ml), no console errors |
| Sentence-with-quote (floating widget) | ✅ shows 2 plates; click → `/job how many label will be print if "Blue 500ml" ` (prefix preserved) |
| Sentence-with-quote (index.php) | ✅ shows 2 plates; click → prefix preserved |
| Balanced quote `/plate "Blue 500ml" 12 per inch` | ✅ dropdown hidden (all 3 frontends) — calc flow untouched |
| ↑ + Enter on suggestion | ✅ inserts entity, dropdown hidden |
| ESC / outside click | ✅ dropdown hides |
| Level 1 (`/`) & `/pl` | ✅ command box shows, filters |
| Level 2 (`/plate ␣`) | ✅ exactly 3 query examples |
| Backend regression | ✅ `search('Mriganka')→kb_23`, `search('Aditya Sitani')→kb_24` |

## 6. Files changed (main project only, no backups)
- `modules/ai_agent/floating_widget.php` — Level-3 detection, `applyFloatAutocomplete`, missing `#aiBaseUrl`, IIFE-scope fix; later: unclosed-quote trigger with extra words between command and quote
- `js/ai_agent.js`, `index.php` — standalone frontend 3-level system (earlier); later: unclosed-quote trigger + prefix-preserving insert
- `app.php` — PWA frontend 3-level system (earlier); later: unclosed-quote trigger + prefix-preserving insert
- `services/RetrievalEngine.php` — KB grounding (BUG#2)
- `ai_knowledge_aliases_seed.sql` — KB aliases (BUG#2)

Temp diagnostic files (`_tmp_alias_diag.php`, `_tmp_diag_serve.php`, `_tmp_cli_harness.php`) were removed. Nothing was committed or pushed.
