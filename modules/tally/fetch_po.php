<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/tally_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

$db = getDB();
$force = isset($_GET['refresh']) && (string)$_GET['refresh'] === '1';

$snapshot = null;
if ($force) {
    $snapshot = tally_snapshot_local_data_folder();
}

$out = tally_fetch_type($db, 'po', $force);

// Attach party details (address, GSTIN, state) from client cache
if (!empty($out['rows'])) {
    $clientCache = tally_cache_get($db, 'client');
    $clientMap = [];
    if (!empty($clientCache['rows']) && is_array($clientCache['rows'])) {
        foreach ($clientCache['rows'] as $c) {
            $n = strtolower(trim((string)($c['name'] ?? '')));
            if ($n !== '') {
                $clientMap[$n] = $c;
            }
        }
    }
    foreach ($out['rows'] as &$row) {
        $partyKey = strtolower(trim((string)($row['client_name'] ?? $row['party_name'] ?? '')));
        if ($partyKey !== '' && isset($clientMap[$partyKey])) {
            $c = $clientMap[$partyKey];
            $row['party_address']  = $c['address']      ?? '';
            $row['party_gstin']    = $c['gst_number']   ?? '';
            $row['party_state']    = $c['state']        ?? ($c['place_of_supply'] ?? '');
            $row['party_pincode']  = $c['pincode']      ?? '';
            $row['party_email']    = $c['email']        ?? '';
            $row['party_phone']    = $c['phone']        ?? '';
            $row['party_msme']     = $c['msme']         ?? '';
        }
    }
    unset($row);
}

if (is_array($snapshot)) {
    $out['local_data_snapshot'] = $snapshot;
}

http_response_code(!empty($out['ok']) ? 200 : 503);
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
