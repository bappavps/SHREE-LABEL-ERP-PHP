<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';

// Route legacy department page to the live planning board implementation.
redirect(BASE_URL . '/modules/planning/index.php?department=label-printing');
