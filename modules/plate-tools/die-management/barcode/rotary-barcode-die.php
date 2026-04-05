<?php
require_once __DIR__ . '/../../../../config/db.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/auth_check.php';

redirect(BASE_URL . '/modules/plate-tools/die-management/barcode/index.php?mode=design');
