<?php
// 2 Ply Planning — thin wrapper around the shared PaperRoll planning page.
// Sets planning type override so only 2 Ply entries are shown and new entries
// default to the two_ply type. All logic, functions, and DB calls live in
// planning/paperroll/index.php — nothing else is affected.
$planningTypeOverride = 'two_ply';
require __DIR__ . '/../paperroll/index.php';
