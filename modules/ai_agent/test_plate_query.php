<?php
$_REQUEST['action'] = 'query';
$_REQUEST['prompt'] = '/plate hum ko "aleaxa" printing korata hai 5000 pcs, kita paper lagela?';
$_REQUEST['user_lang'] = 'english';
require 'mock_auth.php';
$_SERVER['REQUEST_METHOD'] = 'POST';

session_id("mock_session_999");
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['company_id'] = 1;

ob_start();
require 'api.php';
$output = ob_get_clean();
echo $output;
