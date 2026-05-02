<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

appSessionStart();
clearAuthSession();
redirect(ERP_BASE_URL . '/modules/dashboard/index.php');
