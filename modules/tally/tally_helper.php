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

function tally_normalize_scheme(string $scheme): string {
    $value = strtolower(trim($scheme));
    return $value === 'https' ? 'https' : 'http';
}

function tally_settings(): array {
    $s = getAppSettings();
    $ip = tally_normalize_ip((string)($s['tally_ip'] ?? ''));
    $port = tally_normalize_port($s['tally_port'] ?? 9000);
    $scheme = tally_normalize_scheme((string)($s['tally_scheme'] ?? 'http'));
    return ['ip' => $ip, 'port' => $port, 'scheme' => $scheme];
}

function tally_base_url(string $ip, int $port, string $scheme = 'http'): string {
    return tally_normalize_scheme($scheme) . '://' . $ip . ':' . $port;
}

function tally_project_root(): string {
    return dirname(__DIR__, 2);
}

function tally_snapshot_root_dir(): string {
    return tally_project_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tally_snapshots';
}

function tally_ensure_dir(string $path): bool {
    if (is_dir($path)) {
        return true;
    }
    return @mkdir($path, 0775, true);
}

function tally_recursive_copy_dir(string $source, string $dest, array $skipDirNames = []): bool {
    if (!is_dir($source)) {
        return false;
    }

    if (!tally_ensure_dir($dest)) {
        return false;
    }

    $items = @scandir($source);
    if (!is_array($items)) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $srcPath = $source . DIRECTORY_SEPARATOR . $item;
        $dstPath = $dest . DIRECTORY_SEPARATOR . $item;

        if (is_dir($srcPath)) {
            if (in_array($item, $skipDirNames, true)) {
                continue;
            }
            if (!tally_recursive_copy_dir($srcPath, $dstPath, $skipDirNames)) {
                return false;
            }
            continue;
        }

        if (!@copy($srcPath, $dstPath)) {
            return false;
        }
    }

    return true;
}

function tally_snapshot_local_data_folder(): array {
    $source = tally_project_root() . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($source)) {
        return [
            'ok' => false,
            'path' => '',
            'message' => 'Local data folder not found.',
        ];
    }

    $root = tally_snapshot_root_dir() . DIRECTORY_SEPARATOR . 'local_data_backup';
    if (!tally_ensure_dir($root)) {
        return [
            'ok' => false,
            'path' => '',
            'message' => 'Unable to create snapshot directory.',
        ];
    }

    $dest = $root . DIRECTORY_SEPARATOR . date('Ymd_His');
    // Avoid recursive self-copy of data/tally_snapshots into its own backup destination.
    $ok = tally_recursive_copy_dir($source, $dest, ['tally_snapshots']);

    return [
        'ok' => $ok,
        'path' => $ok ? $dest : '',
        'message' => $ok ? 'Local data folder snapshot created.' : 'Failed to snapshot local data folder.',
    ];
}

function tally_save_response_snapshot(string $type, string $stage, string $xmlBody, array $rows = []): void {
    $base = tally_snapshot_root_dir() . DIRECTORY_SEPARATOR . 'responses';
    if (!tally_ensure_dir($base)) {
        return;
    }

    $safeType = preg_replace('/[^a-z0-9_\-]/i', '_', strtolower($type));
    $safeStage = preg_replace('/[^a-z0-9_\-]/i', '_', strtolower($stage));
    $stamp = date('Ymd_His');
    $prefix = $base . DIRECTORY_SEPARATOR . $stamp . '_' . $safeType . '_' . $safeStage;

    @file_put_contents($prefix . '.xml', $xmlBody);

    $meta = [
        'type' => $type,
        'stage' => $stage,
        'saved_at' => date('Y-m-d H:i:s'),
        'rows_count' => count($rows),
    ];
    @file_put_contents(
        $prefix . '.json',
        (string)json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
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

function tally_http_xml_post(string $ip, int $port, string $xml, int $timeout = 6, int $retries = 1, string $scheme = 'http'): array {
    $url = tally_base_url($ip, $port, $scheme);
    $attempts = max(1, min(4, $retries + 1));
    $connectTimeout = max(2, min(20, $timeout));
    $totalTimeout = max(4, min(30, $timeout + 4));
    $lastError = 'Unable to reach Tally endpoint.';

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
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
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT => $totalTimeout,
            ]);
            if (tally_normalize_scheme($scheme) === 'https') {
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            }
            $body = curl_exec($ch);
            $err = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body !== false && $status < 400) {
                return ['ok' => true, 'body' => (string)$body];
            }

            if ($body === false) {
                $lastError = ($err !== '' ? $err : 'Unable to reach Tally endpoint.');
                $isTimeout = stripos($lastError, 'timed out') !== false;
                if ($attempt < $attempts && $isTimeout) {
                    continue;
                }
                return ['ok' => false, 'error' => $lastError];
            }

            $lastError = 'Tally endpoint returned HTTP ' . $status . '.';
            return ['ok' => false, 'error' => $lastError];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: text/xml; charset=utf-8\r\nAccept: text/xml\r\n",
                'content' => $xml,
                'timeout' => $totalTimeout,
            ]
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body !== false) {
            return ['ok' => true, 'body' => (string)$body];
        }

        $lastError = 'Unable to reach Tally endpoint.';
    }

    return ['ok' => false, 'error' => $lastError];
}

function tally_xml_request_for(string $type): string {
    $type = strtolower(trim($type));
    if ($type === 'po') {
        // TDL Collection export — defines a custom collection that filters
        // all Voucher objects where VoucherTypeName = "Purchase Order".
        // This is the standard Tally Prime API pattern for fetching vouchers by type.
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<ENVELOPE>'
            . '<HEADER><VERSION>1</VERSION><TALLYREQUEST>Export</TALLYREQUEST><TYPE>Collection</TYPE><ID>POVouchers</ID></HEADER>'
            . '<BODY><DESC>'
            . '<TDL><TDLMESSAGE>'
            . '<COLLECTION NAME="POVouchers" ISMODIFY="No">'
            . '<TYPE>Voucher</TYPE>'
            . '<FILTER>IsTypeOfPO</FILTER>'
            . '<FETCH>VoucherNumber,Date,PartyLedgerName,VoucherTypeName,Amount,</FETCH>'
            . '<FETCH>AllInventoryEntries.StockItemName,AllInventoryEntries.BilledQty,AllInventoryEntries.Rate,AllInventoryEntries.Amount</FETCH>'
            . '</COLLECTION>'
            . '<SYSTEM TYPE="Formulae" NAME="IsTypeOfPO">$VoucherTypeName = &quot;Purchase Order&quot;</SYSTEM>'
            . '</TDLMESSAGE></TDL>'
            . '</DESC></BODY>'
            . '</ENVELOPE>';
    } elseif ($type === 'clients') {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<ENVELOPE>'
            . '<HEADER><VERSION>1</VERSION><TALLYREQUEST>Export</TALLYREQUEST><TYPE>Data</TYPE><ID>List of Accounts</ID></HEADER>'
            . '<BODY><DESC><STATICVARIABLES></STATICVARIABLES></DESC></BODY>'
            . '</ENVELOPE>';
    } else {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<ENVELOPE>'
            . '<HEADER><VERSION>1</VERSION><TALLYREQUEST>Export</TALLYREQUEST><TYPE>Data</TYPE><ID>Daybook</ID></HEADER>'
            . '<BODY><DESC><STATICVARIABLES><SVVOUCHERTYPE>Sales</SVVOUCHERTYPE></STATICVARIABLES></DESC></BODY>'
            . '</ENVELOPE>';
    }
}

// Fallback: plain Daybook (today only) — parser VCHTYPE filter will keep only POs.
function tally_xml_request_for_po_register(): string {
    return '<?xml version="1.0" encoding="utf-8"?>'
        . '<ENVELOPE>'
        . '<HEADER><VERSION>1</VERSION><TALLYREQUEST>Export</TALLYREQUEST><TYPE>Data</TYPE><ID>Daybook</ID></HEADER>'
        . '<BODY><DESC><STATICVARIABLES></STATICVARIABLES></DESC></BODY>'
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

function tally_clean_xml(string $xml): string {
    $value = trim($xml);
    if ($value === '') {
        return '';
    }

    // Remove UTF-8 BOM when present.
    if (strncmp($value, "\xEF\xBB\xBF", 3) === 0) {
        $value = substr($value, 3);
    }

    // Tally responses can contain control characters (for example \x05)
    // and invalid numeric entities (for example &#4;) which make XML invalid.
    $value = (string)preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value);
    $value = (string)preg_replace_callback('/&#(x?[0-9A-Fa-f]+);/', function (array $m): string {
        $num = (string)$m[1];
        $cp = (stripos($num, 'x') === 0) ? hexdec(substr($num, 1)) : (int)$num;
        return tally_is_valid_xml_codepoint($cp) ? $m[0] : '';
    }, $value);

    return trim($value);
}

function tally_is_valid_xml_codepoint(int $cp): bool {
    return $cp === 0x09
        || $cp === 0x0A
        || $cp === 0x0D
        || ($cp >= 0x20 && $cp <= 0xD7FF)
        || ($cp >= 0xE000 && $cp <= 0xFFFD)
        || ($cp >= 0x10000 && $cp <= 0x10FFFF);
}

function tally_xml_load(string $xml): ?SimpleXMLElement {
    $clean = tally_clean_xml($xml);
    if ($clean === '') {
        return null;
    }

    libxml_use_internal_errors(true);
    $sx = @simplexml_load_string($clean);
    if ($sx instanceof SimpleXMLElement) {
        return $sx;
    }

    if (class_exists('DOMDocument')) {
        $dom = new DOMDocument();
        if (@$dom->loadXML($clean, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)) {
            $domSx = @simplexml_import_dom($dom);
            if ($domSx instanceof SimpleXMLElement) {
                return $domSx;
            }
        }
    }

    return null;
}

function tally_parse_invoices(string $xml): array {
    $sx = tally_xml_load($xml);
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
    $sx = tally_xml_load($xml);
    if (!($sx instanceof SimpleXMLElement)) {
        return [];
    }

    $out = [];
    $vouchers = $sx->xpath('//VOUCHER');
    if (!is_array($vouchers)) {
        return [];
    }

    foreach ($vouchers as $v) {
        // Filter: only keep Purchase Order voucher type.
        // VCHTYPE can appear as an XML attribute on <VOUCHER> or as a child element.
        $vchType = trim((string)($v['VCHTYPE'] ?? ''));
        if ($vchType === '') {
            $vchType = tally_first_tag($v, ['VCHTYPE', 'VOUCHERTYPE']);
        }
        // If VCHTYPE is present and is NOT Purchase Order, skip it.
        // If VCHTYPE is absent (older Tally / different export), allow it through.
        $knownNonPO = ['sales', 'sales pi', 'purchase', 'payment', 'receipt', 'contra',
                       'journal', 'debit note', 'credit note', 'delivery note', 'goods receipt note'];
        if ($vchType !== '' && strtolower($vchType) !== 'purchase order'
            && in_array(strtolower($vchType), $knownNonPO, true)) {
            continue;
        }

        $poNo = tally_first_tag($v, ['VOUCHERNUMBER', 'ORDERNO', 'REFERENCE', 'PERSISTEDVIEW']);
        $dateRaw = tally_first_tag($v, ['DATE']);
        $client = tally_first_tag($v, ['PARTYNAME', 'PARTYLEDGERNAME']);
        $amount = tally_first_tag($v, ['AMOUNT']);
        // Tally stores PO amounts as negative (credit side); convert to positive
        if ($amount !== '' && is_numeric(str_replace([',', ' '], '', $amount))) {
            $amount = (string)abs((float)str_replace([',', ' '], '', $amount));
        }

        if ($poNo === '' && $client === '' && $dateRaw === '' && $amount === '') {
            continue;
        }

        $lineItems = [];
        $entries = $v->xpath('.//ALLINVENTORYENTRIES.LIST|.//INVENTORYENTRIES.LIST');
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                $itemName = tally_first_tag($entry, ['STOCKITEMNAME', 'ITEMNAME']);
                $qty = tally_first_tag($entry, ['BILLEDQTY', 'ACTUALQTY', 'ORDERDUEQTY']);
                $rate = tally_first_tag($entry, ['RATE']);
                $lineAmount = tally_first_tag($entry, ['AMOUNT']);
                // Tally stores PO amounts as negative (credit side); convert to positive
                if ($lineAmount !== '' && is_numeric(str_replace([',', ' '], '', $lineAmount))) {
                    $lineAmount = (string)abs((float)str_replace([',', ' '], '', $lineAmount));
                }

                if ($itemName === '' && $qty === '' && $rate === '' && $lineAmount === '') {
                    continue;
                }

                $lineItems[] = [
                    'item_name' => $itemName,
                    'quantity' => $qty,
                    'rate' => $rate,
                    'amount' => $lineAmount,
                ];
            }
        }

        if (!empty($lineItems)) {
            foreach ($lineItems as $li) {
                $out[] = [
                    'po_number' => $poNo,
                    'date' => $dateRaw,
                    'client_name' => $client,
                    'item_name' => $li['item_name'],
                    'quantity' => $li['quantity'],
                    'rate' => $li['rate'],
                    'amount' => $li['amount'] !== '' ? $li['amount'] : $amount,
                ];
            }
            continue;
        }

        $out[] = [
            'po_number' => $poNo,
            'date' => $dateRaw,
            'client_name' => $client,
            'item_name' => '',
            'quantity' => '',
            'rate' => '',
            'amount' => $amount,
        ];
    }

    return $out;
}

function tally_parse_clients(string $xml): array {
    $sx = tally_xml_load($xml);
    if (!($sx instanceof SimpleXMLElement)) {
        return [];
    }

    $out = [];
    $ledgers = $sx->xpath('//LEDGER');
    if (!is_array($ledgers)) {
        return [];
    }

    foreach ($ledgers as $l) {
        $name = trim((string)($l['NAME'] ?? ''));
        if ($name === '') {
            $name = tally_first_tag($l, ['NAME', 'LEDGERNAME', 'MAILINGNAME']);
        }

        // Address: prefer LEDMAILINGDETAILS.LIST > ADDRESS.LIST > ADDRESS (multi-line)
        $addressParts = [];
        $addrNodes = $l->xpath('./LEDMAILINGDETAILS.LIST/ADDRESS.LIST/ADDRESS');
        if (!is_array($addrNodes) || empty($addrNodes)) {
            $addrNodes = $l->xpath('./ADDRESS.LIST/ADDRESS|./BASICBUYERADDRESS.LIST/BASICBUYERADDRESS');
        }
        if (is_array($addrNodes)) {
            foreach ($addrNodes as $a) {
                $part = trim((string)$a);
                if ($part !== '') {
                    $addressParts[] = $part;
                }
            }
        }
        $address = !empty($addressParts)
            ? implode("\n", $addressParts)
            : tally_first_tag($l, ['ADDRESS', 'MAILINGNAME']);

        // Pincode from LEDMAILINGDETAILS or LEDPINCODE
        $pincodeNodes = $l->xpath('./LEDMAILINGDETAILS.LIST/PINCODE');
        $pincode = (is_array($pincodeNodes) && !empty($pincodeNodes))
            ? trim((string)$pincodeNodes[0])
            : tally_first_tag($l, ['LEDPINCODE', 'PINCODE']);

        // State from LEDMAILINGDETAILS.LIST > STATE, or STATENAME, or LEDSTATE
        $stateNodes = $l->xpath('./LEDMAILINGDETAILS.LIST/STATE');
        $state = (is_array($stateNodes) && !empty($stateNodes))
            ? trim((string)$stateNodes[0])
            : '';
        if ($state === '') {
            $state = tally_first_tag($l, ['LEDSTATE', 'STATENAME', 'PRIORSTATENAME']);
        }

        // GSTIN: prefer LEDGSTREGDETAILS.LIST > GSTIN (most recent / first entry)
        $gstNodes = $l->xpath('./LEDGSTREGDETAILS.LIST/GSTIN');
        $gst = (is_array($gstNodes) && !empty($gstNodes))
            ? trim((string)$gstNodes[0])
            : tally_first_tag($l, ['LEDGSTIN', 'PARTYGSTIN', 'GSTREGISTRATIONNUMBER']);

        // Place of supply / state code
        $posNodes = $l->xpath('./LEDGSTREGDETAILS.LIST/PLACEOFSUPPLY');
        $placeOfSupply = (is_array($posNodes) && !empty($posNodes))
            ? trim((string)$posNodes[0])
            : '';

        // MSME
        $msme = tally_first_tag($l, ['MSMEREGNUMBER', 'LEDUDYAMREGNUMBER']);

        // Email
        $email = tally_first_tag($l, ['EMAIL']);

        // Phone
        $phone = tally_first_tag($l, ['LEDGERPHONE', 'LEDGERMOBILE']);

        $parent = tally_first_tag($l, ['PARENT', 'PARENTGROUP']);

        if ($name === '' && $address === '' && $gst === '') {
            continue;
        }

        $out[] = [
            'name'           => $name,
            'address'        => $address,
            'pincode'        => $pincode,
            'state'          => $state ?: $placeOfSupply,
            'gst_number'     => $gst,
            'place_of_supply'=> $placeOfSupply ?: $state,
            'msme'           => $msme,
            'email'          => $email,
            'phone'          => $phone,
            'parent'         => $parent,
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
    $scheme = $settings['scheme'] ?? 'http';

    $cache = tally_cache_get($db, $type);
    $cacheFresh = false;
    if ($cache && !empty($cache['last_updated'])) {
        $ts = strtotime((string)$cache['last_updated']);
        if ($ts !== false) {
            $cacheFresh = (time() - $ts) < 600;
        }
    }

    $cacheRows = is_array($cache['rows'] ?? null) ? $cache['rows'] : [];
    $isEmptyPoCache = ($type === 'po' && count($cacheRows) === 0);

    if (!$forceRefresh && $cacheFresh && !$isEmptyPoCache) {
        return [
            'ok' => true,
            'status' => 'cached',
            'message' => 'Loaded from cache.',
            'rows' => $cacheRows,
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
    $res = tally_http_xml_post($ip, $port, $xml, 20, 2, $scheme);
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
        tally_save_response_snapshot($type, 'primary', $body, $rows);
    } elseif ($type === 'po') {
        $rows = tally_parse_po($body);
        tally_save_response_snapshot($type, 'primary', $body, $rows);
        if (empty($rows)) {
            $altXml = tally_xml_request_for_po_register();
            $altRes = tally_http_xml_post($ip, $port, $altXml, 20, 2, $scheme);
            if (!empty($altRes['ok'])) {
                $altBody = (string)($altRes['body'] ?? '');
                $rows = tally_parse_po($altBody);
                tally_save_response_snapshot($type, 'po_register_fallback', $altBody, $rows);
            }
        }
    } else {
        $rows = tally_parse_clients($body);
        tally_save_response_snapshot($type, 'primary', $body, $rows);
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
