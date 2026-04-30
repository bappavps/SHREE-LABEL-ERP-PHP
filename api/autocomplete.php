<?php
/**
 * Autocomplete API Endpoint
 * Returns suggestions for various fields based on query
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

// Get database connection
$db = getDB();

// Get parameters
$type = trim($_GET['type'] ?? '');
$query = trim($_GET['query'] ?? '');

$response = [
  'ok' => false,
  'suggestions' => [],
  'message' => 'Invalid request'
];

try {
  // Paper type autocomplete
  if ($type === 'paper_type') {
    if (strlen($query) < 1) {
      $response['ok'] = true;
      $response['suggestions'] = [];
      http_response_code(200);
    } else {
      $searchQuery = '%' . $db->real_escape_string($query) . '%';
      
      // Get from master_paper_types table
      $sql = "SELECT id, name FROM master_paper_types WHERE name LIKE ? AND is_active = 1 ORDER BY name ASC LIMIT 20";
      $stmt = $db->prepare($sql);
      
      if (!$stmt) {
        $response['ok'] = false;
        $response['message'] = 'Database query failed';
        http_response_code(500);
      } else {
        $stmt->bind_param('s', $searchQuery);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $suggestions = [];
        while ($row = $result->fetch_assoc()) {
          $suggestions[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'label' => $row['name']
          ];
        }
        
        $response['ok'] = true;
        $response['suggestions'] = $suggestions;
        http_response_code(200);
        
        $stmt->close();
      }
    }
  } else {
    $response['message'] = 'Unknown autocomplete type';
    http_response_code(400);
  }
  
} catch (Exception $e) {
  $response['ok'] = false;
  $response['message'] = 'Error: ' . $e->getMessage();
  http_response_code(500);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
?>
