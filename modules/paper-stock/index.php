<?php
require_once __DIR__ . '/../../includes/functions.php';

$target = BASE_URL . '/modules/paper_stock/index.php';
if (!empty($_SERVER['QUERY_STRING'])) {
	$target .= '?' . $_SERVER['QUERY_STRING'];
}

header('Location: ' . $target, true, 302);
exit;
