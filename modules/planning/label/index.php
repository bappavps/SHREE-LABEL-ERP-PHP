<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';

// Keep access on the label route and render the shared planning board for label department.
if (!isset($_GET['department']) || trim((string)$_GET['department']) === '') {
	$_GET['department'] = 'label-printing';
}

require __DIR__ . '/../index.php';
exit;
