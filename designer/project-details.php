<?php
// Legacy compatibility bridge: redirect old designer URL to artwork-system designer URL.
$scriptName = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
$basePath = rtrim(str_replace('\\', '/', dirname(dirname($scriptName))), '/');
$target = $basePath . '/artwork-system/designer/project-details.php';

$query = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
if ($query !== '') {
    $target .= '?' . $query;
}

header('Location: ' . $target, true, 302);
exit;
