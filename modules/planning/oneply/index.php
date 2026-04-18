<?php
// 1 Ply Planning — thin wrapper around the shared PaperRoll planning page.
// Sets planning type override so only 1 Ply entries are shown and new entries
// default to the one_ply type. All logic, functions, and DB calls live in
// planning/paperroll/index.php — nothing else is affected.
$planningTypeOverride = 'one_ply';
require __DIR__ . '/../paperroll/index.php';
