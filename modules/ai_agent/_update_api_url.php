<?php
// app.php
$file = 'app.php';
$content = file_get_contents($file);
$content = preg_replace("/const API_URL = '<\?= \\\$baseUrl \?>\/modules\/ai_agent\/api.php';/", "const API_URL = 'http://localhost:8000/api/query';", $content);
file_put_contents($file, $content);
echo "Updated app.php\n";

// floating_widget.php
$file = 'floating_widget.php';
$content = file_get_contents($file);
$content = preg_replace("/var API_URL = '<\?= \\\$moduleBaseUrl \?>\/api.php';/", "var API_URL = 'http://localhost:8000/api/query';", $content);
file_put_contents($file, $content);
echo "Updated floating_widget.php\n";
