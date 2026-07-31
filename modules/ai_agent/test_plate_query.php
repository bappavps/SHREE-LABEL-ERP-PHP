<?php
require 'mock_auth.php';
$ch = curl_init('http://localhost/calipot-erp/shree-label-php/modules/ai_agent/api.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'action'=>'query',
    'prompt'=>'/plate to print "grass" job qnty for 20000 pices, how many paper required for this job',
    'user_lang'=>'english'
]);
curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=mock_session_999');
echo curl_exec($ch);
