<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
header('Content-Type: application/json');

// Simulate the mark_packed API call
$_REQUEST['action'] = 'mark_packed';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['job_id'] = 3;

// Include the packing API
include __DIR__ . '/api.php';
