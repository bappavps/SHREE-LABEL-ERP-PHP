<?php
$_REQUEST['action'] = 'query';
$_REQUEST['prompt'] = '/cal "Blue 500ml" budget 45000 rate 0.08';
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
require 'api.php';
