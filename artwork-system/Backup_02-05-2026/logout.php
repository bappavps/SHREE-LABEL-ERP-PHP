<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

appSessionStart();
clearAuthSession();
redirect('login.php?logged_out=1');
