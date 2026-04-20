<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

function tally_normalize_ip(string $ip): string {
    $value = trim($ip);
    if ($value === '') {
        return '';
    }
    if (filter_var($value, FILTER_VALIDATE_IP)) {
        return $value;
    }
    if (preg_match('/^[a-z0-9.-]+$/i', $value)) {
        return strtolower($value);
    }
    return '';
}

function tally_normalize_port($port): int {
    $p = (int)$port;
    if ($p < 1 || $p > 65535) {
        return 9000;
    }
    return $p;
}

function tally_settings(): array {
    $s = getAppSettings();
    $ip = tally_normalize_ip((string)($s['tally_ip'] ?? ''));
    $port = tally_normalize_port($s['tally_port'] ?? 9000);
    return ['ip' => $ip, 'port' => $port];
}

function tally_base_url(string $ip, int $port): string {
    return 'http://' . $ip . ':' . $port;
}

function tally_ping(string $ip, int $port, int $timeoutSeconds = 2): bool {
    $ip = tally_normalize_ip($ip);
    if ($ip === '') {
        return false;
    }

    $timeout = max(1, min(10, $timeoutSeconds));
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($ip, $port, $errno, $errstr, $timeout);
    if (!is_resource($fp)) {
        return false;
    }
    fclose($fp);
    return true;
}

function tally_ensure_cache_table(mysqli $db): void {
    $sql = "CREATE TABLE IF NOT EXISTS tally_cache (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        cache_type VARCHAR(30) NOT NULL,
        json_data LONGTEXT NOT NULL,
        last_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_cache_type (cache_type),
        KEY idx_last_updated (last_updated)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->query($sql);
}

function tally_cache_get(mysqli $db, string $type): ?array {
    tally_ensure_cache_table($db);
    $stmt = $db->prepare('SELECT json_data, last_updated FROM tally_cache WHERE cache_type = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $type);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }
    $decoded = json_decode((string)($row['json_data'] ?? ''), true);
    if (!is_array($decoded)) {
        return null;
    }
    return [
        'rows' => $decoded,
        'last_updated' => (string)($row['last_updated'] ?? ''),
    ];
}

function tally_cache_set(mysqli $db, string $type, array $rows): bool {
    tally_ensure_cache_table($db);
    $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $stmt = $db->prepare('INSERT INTO tally_cache (cache_type, json_data, last_updated) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE json_data = VALUES(json_data), last_updated = NOW()');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $type, $json);
    return (bool)$stmt->execute();
}

function tally_http_xml_post(string $ip, int $port, string $xml, int $timeout = 6): array {
    $url = tally_base_url($ip, $port);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=utf-8',
                'Accept: text/xml',
            ],
            CURLOPT_CONNECTTIMEOUT => max(2, min(10, $timeout)),
            CURLOPT_TIMEOUT => max(3, min(15, $timeout + 2)),
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'error' => ($err !== '' ? $err : 'Unable to reach Tally endpoint.')];
        }
        if ($status >= 400) {
            return ['ok' => false, 'error' => 'Tally endpoint returned HTTP ' . $status . '.'];
        }
        return ['ok' => true, 'body' => (string)$body];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: text/xml; charset=utf-8\r\nAccept: text/xml\r\n",
            'content' => $xml,
            'timeout' => max(3, min(15, $timeout + 2)),
        ]
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return ['ok' => false, 'error' => 'Unable to reach Tally endpoint.'];
    }
    return ['ok' => true, 'body' => (string)$body];
}

function tally_xml_request_for(string $type): string {
    $type = strtolower(trim($type));
    if ($type === 'po') {
        $reportName = 'Daybook';
        $voucherType = 'Purchase Order';
    } elseif ($type === 'clients') {
        $reportName = 'List of Accounts';
        $voucherType = '';
    } else {
        $reportName = 'Daybook';
        $voucherType = 'Sales';
    }

    $voucherFilter = $voucherType !== ''
        ? '<STATICVARIABLES><SVVOUCHERTYPE>' . htmlspecialchars($voucherType, ENT_QUOTES, 'UTF-8') . '</SVVOUCHERTYPE></STATICVARIABLES>'
        : '<STATICVARIABLES></STATICVARIABLES>';

    return '<?xml version="1.0" encoding="utf-8"?>'
        . '<ENVELOPE>'
        . '<HEADER><VERSION>1</VERSION><TALLYREQUEST>Export</TALLYREQUEST><TYPE>Data</TYPE><ID>' . htmlspecialchars($reportName, ENT_QUOTES, 'UTF-8') . '</ID></HEADER>'
        . '<BODY><DESC>' . $voucherFilter . '</DESC></BODY>'
        . '</ENVELOPE>';
}

function tally_first_tag($node, array $candidates): string {
    foreach ($candidates as $tag) {
        if (isset($node->{$tag}) && trim((string)$node->{$tag}) !== '') {
            return trim((string)$node->{$tag});
        }
    }
    return '';
}

function tally_parse_invoices(string $xml): array {
    $sx = @simplexml_load_string($xml);
    if (!($sx instanceof SimpleXMLElement)) {
        return [];
    }

    $out = [];
    $vouchers = $sx->xpath('//VOUCHER');
    if (!is_array($vouchers)) {
        return [];
    }

    foreach ($vouchers as $v) {
        $invoiceNo = tally_first_tag($v, ['VOUCHERNUMBER', 'REFERENCE', 'PARTYINVOICENUMBER']);
        $dateRaw = tally_first_tag($v, ['DATE']);
        $client = tally_first_tag($v, ['PARTYNAME']);
        $amount = tally_first_tag($v, ['AMOUNT']);

        if ($invoiceNo === '' && $client === '' && $amount === '') {
            continue;
        }

        $out[] = [
            'invoice_no' => $invoiceNo,
            'date' => $dateRaw,
            'client_name' => $client,
            'amount' => (float)str_replace(',', '', $amount),
        ];
    }

    return $out;
}

function tally_parse_po(string $xml): array {
    $sx = @simplexml_load_string($xml);
    if (!($sx instanceof SimpleXMLElement)) {
        return [];
    }

    $out = [];
    $vouchers = $sx->xpath('//VOUCHER');
    if (!is_array($vouchers)) {
        return [];
    }

    foreach ($vouchers as $v) {
        $poNo = tally_first_tag($v, ['VOUCHERNUMBER', 'ORDERNO', 'REFERENCE']);
        $dateRaw = tally_first_tag($v, ['DATE']);
        $client = tally_first_tag($v, ['PARTYNAME']);

        if ($poNo === '' && $client === '' && $dateRaw === '') {
            continue;
        }

        $out[] = [
            'po_number' => $poNo,
            'date' => $dateRaw,
            'client_name' => $client,
        ];
    }

    return $out;
}

function tally_parse_clients(string $xml): array {
    $sx = @simplexml_load_string($xml);
    if (!($sx instanceof SimpleXMLElement)) {
        return [];
    }

    $out = [];
    $ledgers = $sx->xpath('//LEDGER');
    if (!is_array($ledgers)) {
        return [];
    }

    foreach ($ledgers as $l) {
        $name = tally_first_tag($l, ['NAME']);
        $address = tally_first_tag($l, ['ADDRESS', 'MAILINGNAME']);
        $gst = tally_first_tag($l, ['GSTREGISTRATIONNUMBER', 'PARTYGSTIN']);

        if ($name === '' && $address === '' && $gst === '') {
            continue;
        }

        $out[] = [
            'name' => $name,
            'address' => $address,
            'gst_number' => $gst,
        ];
    }

    return $out;
}

function tally_fetch_type(mysqli $db, string $type, bool $forceRefresh = false): array {
    $type = strtolower(trim($type));
    if (!in_array($type, ['invoice', 'po', 'client'], true)) {
        return ['ok' => false, 'status' => 'error', 'message' => 'Invalid tally cache type.', 'rows' => []];
    }

    $settings = tally_settings();
    $ip = $settings['ip'];
    $port = $settings['port'];

    $cache = tally_cache_get($db, $type);
    $cacheFresh = false;
    if ($cache && !empty($cache['last_updated'])) {
        $ts = strtotime((string)$cache['last_updated']);
        if ($ts !== false) {
            $cacheFresh = (time() - $ts) < 600;
        }
    }

    if (!$forceRefresh && $cacheFresh) {
        return [
            'ok' => true,
            'status' => 'cached',
            'message' => 'Loaded from cache.',
            'rows' => $cache['rows'],
            'last_updated' => $cache['last_updated'] ?? '',
            'connected' => true,
        ];
    }

    if ($ip === '' || !tally_ping($ip, $port, 2)) {
        return [
            'ok' => !empty($cache),
            'status' => 'disconnected',
            'message' => 'Tally Disconnected',
            'rows' => $cache['rows'] ?? [],
            'last_updated' => $cache['last_updated'] ?? '',
            'connected' => false,
        ];
    }

    $requestType = $type === 'client' ? 'clients' : $type;
    $xml = tally_xml_request_for($requestType);
    $res = tally_http_xml_post($ip, $port, $xml, 6);
    if (empty($res['ok'])) {
        return [
            'ok' => !empty($cache),
            'status' => 'disconnected',
            'message' => 'Tally Disconnected',
            'rows' => $cache['rows'] ?? [],
            'last_updated' => $cache['last_updated'] ?? '',
            'connected' => false,
        ];
    }

    $body = (string)($res['body'] ?? '');
    if ($type === 'invoice') {
        $rows = tally_parse_invoices($body);
    } elseif ($type === 'po') {
        $rows = tally_parse_po($body);
    } else {
        $rows = tally_parse_clients($body);
    }

    tally_cache_set($db, $type, $rows);
    $updatedCache = tally_cache_get($db, $type);

    return [
        'ok' => true,
        'status' => 'live',
        'message' => 'Fetched from Tally successfully.',
        'rows' => $rows,
        'last_updated' => $updatedCache['last_updated'] ?? date('Y-m-d H:i:s'),
        'connected' => true,
    ];
}
