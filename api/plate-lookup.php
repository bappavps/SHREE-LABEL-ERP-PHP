<?php
/**
 * Plate Lookup API
 * Returns full plate metadata from master_plate_data for Final Artwork auto-fill.
 * Read-only endpoint — does not modify any data.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

$db = getDB();

$type = trim($_GET['type'] ?? '');
$query = trim($_GET['query'] ?? '');

$response = [
    'ok' => false,
    'data' => null,
    'suggestions' => [],
    'message' => 'Invalid request',
];

try {
    if ($type === 'suggest') {
        // Suggestion list while user types plate number
        if (strlen($query) < 1) {
            $response['ok'] = true;
            $response['suggestions'] = [];
            echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $like = '%' . $db->real_escape_string($query) . '%';
        $sql = "SELECT plate, name FROM master_plate_data
                WHERE plate LIKE ? OR name LIKE ?
                ORDER BY plate ASC LIMIT 25";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Query preparation failed.');
        }
        $stmt->bind_param('ss', $like, $like);
        $stmt->execute();
        $result = $stmt->get_result();

        $suggestions = [];
        while ($row = $result->fetch_assoc()) {
            $suggestions[] = [
                'plate' => (string)($row['plate'] ?? ''),
                'label' => trim(($row['plate'] ?? '') . ' — ' . ($row['name'] ?? '')),
            ];
        }
        $stmt->close();

        $response['ok'] = true;
        $response['suggestions'] = $suggestions;
        http_response_code(200);

    } elseif ($type === 'get') {
        // Exact plate record for auto-fill
        if ($query === '') {
            $response['message'] = 'Plate number required.';
            http_response_code(400);
            echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $sql = "SELECT plate, name, date_received, size, paper_size, paper_type, make_by, die
                FROM master_plate_data
                WHERE plate = ?
                ORDER BY id DESC LIMIT 1";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Query preparation failed.');
        }
        $stmt->bind_param('s', $query);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $response['ok'] = false;
            $response['message'] = 'No plate found with that number.';
            $response['data'] = null;
            http_response_code(200);
        } else {
            $na = function ($v) {
                $v = trim((string)($v ?? ''));
                return ($v === '' || strtolower($v) === 'n/a' || strtolower($v) === 'na') ? 'N/A' : $v;
            };

            $response['ok'] = true;
            $response['message'] = 'ok';
            $response['data'] = [
                'plate'         => (string)($row['plate'] ?? ''),
                'job_name'      => $na($row['name']),
                'date_received' => trim((string)($row['date_received'] ?? '')),
                'job_size'      => $na($row['size']),
                'paper_size'    => $na($row['paper_size']),
                'paper_type'    => $na($row['paper_type']),
                'make_by'       => $na($row['make_by']),
                'die_number'    => $na($row['die']),
            ];
            http_response_code(200);
        }

    } else {
        $response['message'] = 'Unknown type. Use type=suggest or type=get.';
        http_response_code(400);
    }

} catch (Throwable $e) {
    $response['ok'] = false;
    $response['message'] = 'Error: ' . $e->getMessage();
    http_response_code(500);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
?>
