<?php
session_id($_GET['sid']);
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['user_name'] = 'Test User';
echo "Auth mock successful";
