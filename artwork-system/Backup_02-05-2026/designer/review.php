<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$token = isset($_GET['token']) ? sanitize($_GET['token']) : '';
if ($token === '') {
    redirect('../review.php');
}

redirect('../review.php?token=' . urlencode($token));
