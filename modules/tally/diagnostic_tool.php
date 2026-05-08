<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/tally_helper.php';

header('Content-Type: text/html; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$testType = isset($_GET['type']) ? (string)$_GET['type'] : 'po';
$testType = in_array($testType, ['po', 'invoice', 'client'], true) ? $testType : 'po';

$action = isset($_GET['action']) ? (string)$_GET['action'] : 'home';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tally Diagnostic Tool</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h1 { color: #333; margin-bottom: 20px; border-bottom: 3px solid #007bff; padding-bottom: 10px; }
        h2 { color: #555; margin-top: 20px; margin-bottom: 10px; border-left: 4px solid #007bff; padding-left: 10px; }
        .nav { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .nav a { display: inline-block; padding: 10px 15px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; cursor: pointer; }
        .nav a:hover { background: #0056b3; }
        .nav a.active { background: #28a745; }
        .section { margin-bottom: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; }
        .status-box { padding: 15px; border-radius: 4px; margin-bottom: 15px; }
        .status-ok { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .status-error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .status-warn { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; }
        .status-info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; }
        .code-block { background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 4px; overflow-x: auto; font-family: 'Courier New', monospace; font-size: 12px; margin: 10px 0; line-height: 1.4; }
        .code-block pre { margin: 0; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        table th, table td { padding: 10px; text-align: left; border: 1px solid #ddd; }
        table th { background: #f0f0f0; font-weight: bold; }
        table tr:nth-child(even) { background: #f9f9f9; }
        .button-group { display: flex; gap: 10px; flex-wrap: wrap; margin: 15px 0; }
        button { padding: 10px 15px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
        button:hover { background: #0056b3; }
        button.secondary { background: #6c757d; }
        button.secondary:hover { background: #5a6268; }
        .setting-item { display: flex; gap: 10px; align-items: center; margin: 10px 0; }
        .setting-item label { font-weight: bold; width: 150px; }
        .setting-item input { padding: 8px; border: 1px solid #ddd; border-radius: 4px; flex: 1; max-width: 300px; }
        .copy-btn { padding: 5px 10px; background: #6c757d; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; }
        .copy-btn:hover { background: #5a6268; }
        .cache-row { padding: 10px; margin: 5px 0; background: white; border: 1px solid #ddd; border-radius: 4px; }
        .file-info { padding: 10px; background: #e8f4f8; border-left: 4px solid #17a2b8; border-radius: 4px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Tally Diagnostic Tool</h1>
        
        <div class="nav">
            <a href="?action=home" class="<?php echo $action === 'home' ? 'active' : ''; ?>">Home</a>
            <a href="?action=settings" class="<?php echo $action === 'settings' ? 'active' : ''; ?>">Settings</a>
            <a href="?action=connection" class="<?php echo $action === 'connection' ? 'active' : ''; ?>">Connection Test</a>
            <a href="?action=po" class="<?php echo $action === 'po' ? 'active' : ''; ?>">Test PO Fetch</a>
            <a href="?action=cache" class="<?php echo $action === 'cache' ? 'active' : ''; ?>">Cached Data</a>
            <a href="?action=snapshots" class="<?php echo $action === 'snapshots' ? 'active' : ''; ?>">Snapshots</a>
        </div>

        <?php
        if ($action === 'home') {
            ?>
            <div class="section">
                <h2>📋 Overview</h2>
                <p>আপনার Tally connection debug করার জন্য এই tool ব্যবহার করুন। কোনো internet লাগবে না।</p>
                
                <h3 style="margin-top: 15px;">কী করতে পারবেন:</h3>
                <ul style="margin-left: 20px; margin-top: 10px;">
                    <li><strong>Settings:</strong> Tally IP/Port check করুন</li>
                    <li><strong>Connection Test:</strong> Tally-র সাথে সরাসরি ping করুন</li>
                    <li><strong>Test PO Fetch:</strong> Actual PO request পাঠিয়ে response দেখুন</li>
                    <li><strong>Cached Data:</strong> Database-এ save থাকা data দেখুন</li>
                    <li><strong>Snapshots:</strong> আগের responses save হয়েছে কিনা দেখুন</li>
                </ul>

                <h3 style="margin-top: 15px;">দ্রুত শুরু:</h3>
                <ol style="margin-left: 20px; margin-top: 10px;">
                    <li>Settings page যান এবং IP/Port verify করুন</li>
                    <li>Connection Test করুন</li>
                    <li>Test PO Fetch করুন এবং response দেখুন</li>
                    <li>কোনো issue থাকলে Snapshots থেকে raw XML দেখুন</li>
                </ol>
            </div>

            <?php
        } elseif ($action === 'settings') {
            $settings = tally_settings();
            $currentIp = $settings['ip'];
            $currentPort = $settings['port'];
            $currentScheme = $settings['scheme'] ?? 'http';
            ?>
            <div class="section">
                <h2>⚙️ Tally Settings</h2>
                
                <div class="status-info">
                    <strong>Current Configuration:</strong>
                </div>

                <table>
                    <tr>
                        <th>Property</th>
                        <th>Value</th>
                    </tr>
                    <tr>
                        <td>Tally Host / IP</td>
                        <td><code><?php echo htmlspecialchars($currentIp); ?></code></td>
                    </tr>
                    <tr>
                        <td>Protocol</td>
                        <td><code><?php echo htmlspecialchars($currentScheme); ?></code></td>
                    </tr>
                    <tr>
                        <td>Tally Port</td>
                        <td><code><?php echo htmlspecialchars($currentPort); ?></code></td>
                    </tr>
                    <tr>
                        <td>Base URL</td>
                        <td><code><?php echo htmlspecialchars(tally_base_url($currentIp, $currentPort, $currentScheme)); ?></code></td>
                    </tr>
                </table>

                <div class="file-info">
                    <strong>📁 Settings location:</strong> shree-label-php/data/app_settings.json
                </div>

                <div style="margin-top: 15px;">
                    <p style="color: #666; margin-bottom: 10px;">Settings JSON format:</p>
                    <div class="code-block"><pre>{
    "tally_scheme": "https",
  "tally_ip": "192.168.1.100",
  "tally_port": 9000
}</pre></div>
                </div>
            </div>

            <?php
        } elseif ($action === 'connection') {
            $settings = tally_settings();
            $ip = $settings['ip'];
            $port = $settings['port'];
            $scheme = $settings['scheme'] ?? 'http';
            $baseUrl = tally_base_url($ip, $port, $scheme);

            $status = '?';
            $message = '';
            $details = [];

            if ($ip === '') {
                $status = 'error';
                $message = 'Tally IP not configured';
            } else {
                $result = tally_ping($ip, $port, 3);
                if ($result) {
                    $status = 'ok';
                    $message = 'Successfully connected to Tally';
                    $details[] = "✓ Connection established";
                    $details[] = "✓ Tally is reachable at {$baseUrl}";
                } else {
                    $status = 'error';
                    $message = 'Unable to connect to Tally';
                    $details[] = "✗ Connection failed to {$baseUrl}";
                    $details[] = "✗ Check if Tally is running";
                    $details[] = "✗ Check if office network is connected";
                    $details[] = "✗ Check if host/protocol/port is correct";
                }
            }
            ?>
            <div class="section">
                <h2>🔌 Connection Test</h2>
                
                <div class="status-box status-<?php echo $status === 'ok' ? 'ok' : ($status === 'error' ? 'error' : 'info'); ?>">
                    <strong>Status:</strong> <?php echo htmlspecialchars($message); ?>
                </div>

                <div style="margin-top: 15px;">
                    <h3>Details:</h3>
                    <ul style="margin-left: 20px; margin-top: 10px;">
                        <?php foreach ($details as $detail) { ?>
                            <li><?php echo htmlspecialchars($detail); ?></li>
                        <?php } ?>
                    </ul>
                </div>

                <div style="margin-top: 20px;">
                    <button onclick="location.href='?action=connection'" class="secondary">🔄 Retry Connection Test</button>
                </div>
            </div>

            <?php
        } elseif ($action === 'po') {
            $settings = tally_settings();
            $ip = $settings['ip'];
            $port = $settings['port'];
            $scheme = $settings['scheme'] ?? 'http';

            $xml = tally_xml_request_for('po');
            $response = [];
            $rows = [];
            $error = '';

            if ($ip === '') {
                $error = 'Tally IP not configured';
            } elseif (!tally_ping($ip, $port, 3)) {
                $error = 'Unable to connect to Tally (see Connection Test)';
            } else {
                $res = tally_http_xml_post($ip, $port, $xml, 20, 2, $scheme);
                if (empty($res['ok'])) {
                    $error = $res['error'] ?? 'Unknown error';
                } else {
                    $body = (string)($res['body'] ?? '');
                    $response['raw_xml'] = $body;
                    $rows = tally_parse_po($body);
                    $response['parsed_rows'] = $rows;
                    $response['row_count'] = count($rows);

                    if (empty($rows)) {
                        $altXml = tally_xml_request_for_po_register();
                        $altRes = tally_http_xml_post($ip, $port, $altXml, 20, 2, $scheme);
                        if (!empty($altRes['ok'])) {
                            $altBody = (string)($altRes['body'] ?? '');
                            $altRows = tally_parse_po($altBody);
                            if (!empty($altRows)) {
                                $response['fallback_used'] = 'po_register_fallback';
                                $response['fallback_raw_xml'] = $altBody;
                                $rows = $altRows;
                                $response['row_count'] = count($rows);
                            }
                        }
                    }

                    tally_save_response_snapshot('po', 'diagnostic', $body, $rows);
                }
            }
            ?>
            <div class="section">
                <h2>📊 Test PO Fetch</h2>
                
                <?php if ($error !== '') { ?>
                    <div class="status-box status-error">
                        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php } else { ?>
                    <div class="status-box status-ok">
                        <strong>Success:</strong> Received <?php echo count($rows); ?> PO records
                    </div>

                    <h3 style="margin-top: 20px;">Request XML:</h3>
                    <div class="code-block"><pre><?php echo htmlspecialchars($xml); ?></pre></div>

                    <h3 style="margin-top: 20px;">Response Analysis:</h3>
                    <table>
                        <tr>
                            <th>Property</th>
                            <th>Value</th>
                        </tr>
                        <tr>
                            <td>Total Records Parsed</td>
                            <td><?php echo $response['row_count'] ?? 0; ?></td>
                        </tr>
                        <tr>
                            <td>Fallback Used</td>
                            <td><?php echo isset($response['fallback_used']) ? htmlspecialchars($response['fallback_used']) : 'No'; ?></td>
                        </tr>
                        <tr>
                            <td>Response Length</td>
                            <td><?php echo strlen($response['raw_xml'] ?? ''); ?> bytes</td>
                        </tr>
                    </table>

                    <?php if (count($rows) > 0) { ?>
                        <h3 style="margin-top: 20px;">Sample Records (First 5):</h3>
                        <table>
                            <tr>
                                <th>Field</th>
                                <th>Value</th>
                            </tr>
                            <?php
                            $sampleRow = $rows[0] ?? [];
                            foreach (array_slice($sampleRow, 0, 10) as $key => $value) {
                                ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($key); ?></code></td>
                                    <td><?php echo htmlspecialchars(is_array($value) ? json_encode($value) : $value); ?></td>
                                </tr>
                                <?php
                            }
                            ?>
                        </table>
                    <?php } ?>

                    <h3 style="margin-top: 20px;">Raw Response (First 2000 chars):</h3>
                    <div class="code-block"><pre><?php echo htmlspecialchars(substr($response['raw_xml'] ?? '', 0, 2000)); ?></pre></div>
                    
                    <p style="margin-top: 10px; color: #666; font-size: 12px;">
                        Full response saved to: data/tally_snapshots/responses/
                    </p>

                    <div style="margin-top: 20px;">
                        <button onclick="location.href='?action=po'" class="secondary">🔄 Retry PO Fetch</button>
                    </div>
                <?php } ?>
            </div>

            <?php
        } elseif ($action === 'cache') {
            $db = getDB();
            if (!$db) {
                ?>
                <div class="section">
                    <div class="status-box status-error">Database connection failed</div>
                </div>
                <?php
            } else {
                tally_ensure_cache_table($db);
                
                $types = ['po', 'invoice', 'client'];
                $cacheData = [];
                foreach ($types as $t) {
                    $cache = tally_cache_get($db, $t);
                    $cacheData[$t] = $cache;
                }
                ?>
                <div class="section">
                    <h2>💾 Cached Data in Database</h2>
                    
                    <?php foreach ($cacheData as $type => $data) { ?>
                        <div style="margin-bottom: 20px; padding: 15px; background: white; border: 1px solid #ddd; border-radius: 4px;">
                            <h3><?php echo strtoupper($type); ?></h3>
                            
                            <?php if ($data === null) { ?>
                                <div class="status-box status-warn">
                                    No cached data for <?php echo htmlspecialchars($type); ?>
                                </div>
                            <?php } else { ?>
                                <table>
                                    <tr>
                                        <th>Property</th>
                                        <th>Value</th>
                                    </tr>
                                    <tr>
                                        <td>Last Updated</td>
                                        <td><?php echo htmlspecialchars($data['last_updated'] ?? ''); ?></td>
                                    </tr>
                                    <tr>
                                        <td>Record Count</td>
                                        <td><?php echo count($data['rows'] ?? []); ?></td>
                                    </tr>
                                </table>

                                <?php if (count($data['rows'] ?? []) > 0) { ?>
                                    <h4 style="margin-top: 15px;">Sample (First Record):</h4>
                                    <div class="code-block"><pre><?php echo htmlspecialchars(json_encode($data['rows'][0] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre></div>
                                <?php } ?>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </div>

                <?php
            }

        } elseif ($action === 'snapshots') {
            $snapshotRoot = tally_snapshot_root_dir();
            $responsesDir = $snapshotRoot . DIRECTORY_SEPARATOR . 'responses';
            $localBackupDir = $snapshotRoot . DIRECTORY_SEPARATOR . 'local_data_backup';

            $responseFiles = [];
            if (is_dir($responsesDir)) {
                $files = @scandir($responsesDir);
                if (is_array($files)) {
                    foreach ($files as $file) {
                        if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'xml') {
                            $responseFiles[] = $file;
                        }
                    }
                    rsort($responseFiles);
                }
            }

            $backupDirs = [];
            if (is_dir($localBackupDir)) {
                $dirs = @scandir($localBackupDir);
                if (is_array($dirs)) {
                    foreach ($dirs as $dir) {
                        if ($dir !== '.' && $dir !== '..' && is_dir($localBackupDir . DIRECTORY_SEPARATOR . $dir)) {
                            $backupDirs[] = $dir;
                        }
                    }
                    rsort($backupDirs);
                }
            }
            ?>
            <div class="section">
                <h2>📸 Snapshots & Backups</h2>

                <h3>Response Snapshots</h3>
                <p style="color: #666; margin-bottom: 10px;">Saved Tally responses (raw XML):</p>

                <?php if (empty($responseFiles)) { ?>
                    <div class="status-box status-warn">
                        No response snapshots found yet
                    </div>
                <?php } else { ?>
                    <table>
                        <tr>
                            <th>Filename</th>
                            <th>Action</th>
                        </tr>
                        <?php foreach (array_slice($responseFiles, 0, 10) as $file) { ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($file); ?></code></td>
                                <td>
                                    <button class="secondary" onclick="viewSnapshot('<?php echo htmlspecialchars($file); ?>')">View</button>
                                </td>
                            </tr>
                        <?php } ?>
                    </table>

                    <div id="snapshot-viewer" style="display:none; margin-top: 20px;">
                        <h4>Snapshot Content:</h4>
                        <div id="snapshot-content" class="code-block"><pre></pre></div>
                    </div>

                    <script>
                    function viewSnapshot(filename) {
                        fetch('?action=snapshots&view=' + encodeURIComponent(filename))
                            .then(r => r.text())
                            .then(data => {
                                document.getElementById('snapshot-content').innerHTML = '<pre>' + data + '</pre>';
                                document.getElementById('snapshot-viewer').style.display = 'block';
                                document.getElementById('snapshot-viewer').scrollIntoView();
                            });
                    }
                    </script>
                <?php } ?>

                <h3 style="margin-top: 30px;">Local Data Backups</h3>
                <p style="color: #666; margin-bottom: 10px;">Snapshots of local data folder (before Tally fetch):</p>

                <?php if (empty($backupDirs)) { ?>
                    <div class="status-box status-warn">
                        No local data backups found yet
                    </div>
                <?php } else { ?>
                    <table>
                        <tr>
                            <th>Timestamp</th>
                            <th>Path</th>
                        </tr>
                        <?php foreach (array_slice($backupDirs, 0, 5) as $dir) { ?>
                            <tr>
                                <td><?php echo htmlspecialchars($dir); ?></td>
                                <td><code><?php echo htmlspecialchars($localBackupDir . DIRECTORY_SEPARATOR . $dir); ?></code></td>
                            </tr>
                        <?php } ?>
                    </table>
                <?php } ?>

                <div class="file-info" style="margin-top: 20px;">
                    <strong>📁 Snapshots location:</strong><br>
                    data/tally_snapshots/responses/<br>
                    data/tally_snapshots/local_data_backup/
                </div>
            </div>

            <?php
            if (isset($_GET['view'])) {
                $filename = (string)$_GET['view'];
                $filename = basename($filename);
                $filepath = $responsesDir . DIRECTORY_SEPARATOR . $filename;
                
                if (file_exists($filepath) && is_file($filepath)) {
                    echo htmlspecialchars(file_get_contents($filepath));
                    exit;
                }
            }
        }
        ?>

    </div>
</body>
</html>
