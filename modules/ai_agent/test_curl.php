<?php
$ch = curl_init('http://localhost:8000/modules/ai_agent/api.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
// Mock the session by sending a Cookie or just ignore session for a moment?
// Wait, we need to bypass auth. We can't unless we set a cookie.
// Let's modify api.php temporarily to bypass auth.
