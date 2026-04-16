<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$settings = getAppSettings();
$tenantSlug = defined('TENANT_SLUG') ? trim((string)TENANT_SLUG) : 'default';
$tenantLabel = defined('TENANT_NAME') ? trim((string)TENANT_NAME) : APP_NAME;
$tenantSettingsPath = getAppSettingsPath();
$projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../'));

$libraryCategories = [
  'company-logo' => 'Company Logo',
  'label-asset' => 'Label Asset',
  'background' => 'Background',
  'product-type' => 'Product Type',
  'misc' => 'Misc',
];

function tenantSettingsZipPath(string $projectRoot, string $settingsPath): string {
  $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
  $settingsPath = str_replace('\\', '/', $settingsPath);
  if ($projectRoot !== '' && strpos($settingsPath, $projectRoot . '/') === 0) {
    return ltrim(substr($settingsPath, strlen($projectRoot)), '/');
  }
  return 'data/tenants/imported/app_settings.json';
}

function normalizeHexColor($value, $fallback) {
  $value = trim((string)$value);
  if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
    return strtoupper($value);
  }
  return $fallback;
}

function isDirectImageUrl($url) {
  $url = trim((string)$url);
  if ($url === '') {
    return false;
  }
  return (bool)preg_match('/\.(gif|png|jpe?g|webp|avif|svg)(\?|#|$)/i', $url);
}

function tenantAssetDirectories(string $tenantSlug): array {
  $safeTenantSlug = preg_replace('/[^a-z0-9._-]+/i', '-', trim($tenantSlug));
  if ($safeTenantSlug === '') {
    $safeTenantSlug = 'default';
  }
  return [
    'company' => 'uploads/company/' . $safeTenantSlug,
    'library' => 'uploads/library/' . $safeTenantSlug,
  ];
}

function parseCsvList(string $csv): array {
  $items = preg_split('/\s*,\s*/', trim($csv)) ?: [];
  $out = [];
  foreach ($items as $item) {
    $item = trim((string)$item);
    if ($item === '') {
      continue;
    }
    $out[] = $item;
  }
  return array_values(array_unique($out));
}

function statusWorkflowDefaults(): array {
  return [
    'version' => 1,
    'updated_at' => '',
    'sections' => [
      [
        'section_key' => 'planning',
        'section_name' => 'Planning',
        'description' => 'Statuses used while planning and stage readiness is tracked.',
        'pages' => [
          [
            'page_key' => 'paperroll_planning',
            'page_name' => 'Paperroll Planning',
            'page_path' => '/modules/planning/paperroll/index.php',
            'statuses' => [
              ['code' => 'Draft', 'label' => 'Draft', 'when' => 'New planning created but not started.', 'concept' => 'Initial planning state', 'bg_color' => '#94A3B8', 'text_color' => '#FFFFFF'],
              ['code' => 'Preparing Slitting', 'label' => 'Preparing Slitting', 'when' => 'Waiting to start slitting preparation.', 'concept' => 'Pre-production setup', 'bg_color' => '#0EA5E9', 'text_color' => '#FFFFFF'],
              ['code' => 'Barcode', 'label' => 'Barcode', 'when' => 'Barcode stage is currently in progress.', 'concept' => 'In-progress barcode work', 'bg_color' => '#7C3AED', 'text_color' => '#FFFFFF'],
              ['code' => 'Barcoded', 'label' => 'Barcoded', 'when' => 'Barcode stage completed successfully.', 'concept' => 'Barcode completed', 'bg_color' => '#16A34A', 'text_color' => '#FFFFFF'],
            ],
          ],
          [
            'page_key' => 'barcode_planning',
            'page_name' => 'Barcode Planning',
            'page_path' => '/modules/planning/barcode/index.php',
            'statuses' => [
              ['code' => 'Pending', 'label' => 'Pending', 'when' => 'Barcode planning is not started.', 'concept' => 'Queue stage', 'bg_color' => '#F59E0B', 'text_color' => '#111827'],
              ['code' => 'Running', 'label' => 'Running', 'when' => 'Barcode planning execution is active.', 'concept' => 'Active work', 'bg_color' => '#2563EB', 'text_color' => '#FFFFFF'],
              ['code' => 'Completed', 'label' => 'Completed', 'when' => 'Barcode planning completed.', 'concept' => 'Finished stage', 'bg_color' => '#16A34A', 'text_color' => '#FFFFFF'],
            ],
          ],
        ],
      ],
      [
        'section_key' => 'inventory',
        'section_name' => 'Inventory',
        'description' => 'Statuses used for stock monitoring and material availability.',
        'pages' => [
          [
            'page_key' => 'paper_stock',
            'page_name' => 'Paper Stock',
            'page_path' => '/modules/paper_stock/index.php',
            'statuses' => [
              ['code' => 'Available', 'label' => 'Available', 'when' => 'Stock is healthy and ready for use.', 'concept' => 'Normal inventory level', 'bg_color' => '#16A34A', 'text_color' => '#FFFFFF'],
              ['code' => 'Low Stock', 'label' => 'Low Stock', 'when' => 'Stock is below safety threshold and should be replenished.', 'concept' => 'Reorder alert', 'bg_color' => '#F59E0B', 'text_color' => '#111827'],
              ['code' => 'Out of Stock', 'label' => 'Out of Stock', 'when' => 'No usable stock remains for production.', 'concept' => 'Critical inventory shortage', 'bg_color' => '#DC2626', 'text_color' => '#FFFFFF'],
            ],
          ],
        ],
      ],
      [
        'section_key' => 'job_cards',
        'section_name' => 'Job Cards',
        'description' => 'Statuses used in operator-facing job card life cycle.',
        'pages' => [
          [
            'page_key' => 'paperroll_jobs',
            'page_name' => 'Paperroll Jobs',
            'page_path' => '/modules/jobs/paperroll/index.php',
            'statuses' => [
              ['code' => 'Pending', 'label' => 'Pending', 'when' => 'Job card assigned but not started.', 'concept' => 'Ready for operator', 'bg_color' => '#F59E0B', 'text_color' => '#111827'],
              ['code' => 'Running', 'label' => 'Running', 'when' => 'Operator has started the job.', 'concept' => 'Work in progress', 'bg_color' => '#0EA5E9', 'text_color' => '#FFFFFF'],
              ['code' => 'Paused', 'label' => 'Paused', 'when' => 'Work temporarily stopped.', 'concept' => 'Temporary stop', 'bg_color' => '#FB7185', 'text_color' => '#FFFFFF'],
              ['code' => 'Completed', 'label' => 'Completed', 'when' => 'Operator marked process complete.', 'concept' => 'Stage complete', 'bg_color' => '#16A34A', 'text_color' => '#FFFFFF'],
            ],
          ],
          [
            'page_key' => 'pos_jobs',
            'page_name' => 'POS Jobs',
            'page_path' => '/modules/jobs/pos/index.php',
            'statuses' => [
              ['code' => 'Barcode', 'label' => 'Barcode', 'when' => 'POS work running in barcode stage.', 'concept' => 'POS active stage', 'bg_color' => '#6D28D9', 'text_color' => '#FFFFFF'],
              ['code' => 'Barcode Pause', 'label' => 'Barcode Pause', 'when' => 'POS paused in barcode stage.', 'concept' => 'POS temporary hold', 'bg_color' => '#A855F7', 'text_color' => '#FFFFFF'],
              ['code' => 'Barcoded', 'label' => 'Barcoded', 'when' => 'POS completed and barcoding done.', 'concept' => 'POS completed stage', 'bg_color' => '#15803D', 'text_color' => '#FFFFFF'],
            ],
          ],
        ],
      ],
      [
        'section_key' => 'quality',
        'section_name' => 'Quality Check',
        'description' => 'Statuses used in QC and closure gates.',
        'pages' => [
          [
            'page_key' => 'qc_panel',
            'page_name' => 'QC Panel',
            'page_path' => '/modules/jobs/api.php',
            'statuses' => [
              ['code' => 'QC Pending', 'label' => 'QC Pending', 'when' => 'Output is waiting for quality check.', 'concept' => 'Awaiting QC', 'bg_color' => '#F97316', 'text_color' => '#FFFFFF'],
              ['code' => 'QC Passed', 'label' => 'QC Passed', 'when' => 'Quality check is approved.', 'concept' => 'Approved output', 'bg_color' => '#16A34A', 'text_color' => '#FFFFFF'],
              ['code' => 'QC Failed', 'label' => 'QC Failed', 'when' => 'Quality check rejected the output.', 'concept' => 'Rework required', 'bg_color' => '#DC2626', 'text_color' => '#FFFFFF'],
            ],
          ],
        ],
      ],
      [
        'section_key' => 'packing_dispatch',
        'section_name' => 'Packing & Dispatch',
        'description' => 'Statuses used after production while preparing and dispatching material.',
        'pages' => [
          [
            'page_key' => 'packing_module',
            'page_name' => 'Packing Module',
            'page_path' => '/modules/packing/index.php',
            'statuses' => [
              ['code' => 'Unpacked', 'label' => 'Unpacked', 'when' => 'Item is not packed yet.', 'concept' => 'Pre-pack stage', 'bg_color' => '#64748B', 'text_color' => '#FFFFFF'],
              ['code' => 'Packed', 'label' => 'Packed', 'when' => 'Packing has been completed.', 'concept' => 'Pack complete', 'bg_color' => '#059669', 'text_color' => '#FFFFFF'],
              ['code' => 'Dispatched', 'label' => 'Dispatched', 'when' => 'Material has left facility.', 'concept' => 'Final shipment', 'bg_color' => '#1D4ED8', 'text_color' => '#FFFFFF'],
            ],
          ],
        ],
      ],
    ],
  ];
}

function sanitizeStatusWorkflowPayload($payload): array {
  $defaults = statusWorkflowDefaults();
  if (!is_array($payload)) {
    return $defaults;
  }

  $out = [
    'version' => 1,
    'updated_at' => date('Y-m-d H:i:s'),
    'sections' => [],
  ];

  $sections = $payload['sections'] ?? [];
  if (!is_array($sections)) {
    return $defaults;
  }

  foreach ($sections as $sIdx => $section) {
    if (!is_array($section)) {
      continue;
    }
    $sectionName = trim((string)($section['section_name'] ?? ''));
    if ($sectionName === '') {
      $sectionName = 'Section ' . ((int)$sIdx + 1);
    }

    $sectionKey = trim((string)($section['section_key'] ?? ''));
    if ($sectionKey === '') {
      $sectionKey = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $sectionName));
    }
    $sectionKey = trim($sectionKey, '_');
    if ($sectionKey === '') {
      $sectionKey = 'section_' . ((int)$sIdx + 1);
    }

    $cleanSection = [
      'section_key' => $sectionKey,
      'section_name' => $sectionName,
      'description' => trim((string)($section['description'] ?? '')),
      'pages' => [],
    ];

    $pages = $section['pages'] ?? [];
    if (!is_array($pages)) {
      continue;
    }

    foreach ($pages as $pIdx => $page) {
      if (!is_array($page)) {
        continue;
      }
      $pageName = trim((string)($page['page_name'] ?? ''));
      if ($pageName === '') {
        $pageName = 'Page ' . ((int)$pIdx + 1);
      }
      $pagePath = trim((string)($page['page_path'] ?? ''));

      $pageKey = trim((string)($page['page_key'] ?? ''));
      if ($pageKey === '') {
        $pageKey = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $pageName));
      }
      $pageKey = trim($pageKey, '_');
      if ($pageKey === '') {
        $pageKey = 'page_' . ((int)$pIdx + 1);
      }

      $cleanPage = [
        'page_key' => $pageKey,
        'page_name' => $pageName,
        'page_path' => $pagePath,
        'statuses' => [],
      ];

      $statuses = $page['statuses'] ?? [];
      if (!is_array($statuses)) {
        continue;
      }

      foreach ($statuses as $stIdx => $status) {
        if (!is_array($status)) {
          continue;
        }

        $code = trim((string)($status['code'] ?? ''));
        $label = trim((string)($status['label'] ?? ''));
        if ($code === '' && $label === '') {
          continue;
        }
        if ($code === '') {
          $code = $label;
        }
        if ($label === '') {
          $label = $code;
        }

        $bg = normalizeHexColor((string)($status['bg_color'] ?? ''), '#64748B');
        $tx = normalizeHexColor((string)($status['text_color'] ?? ''), '#FFFFFF');
        $conceptDefaults = statusConceptDefaults($code);
        $whenText = trim((string)($status['when'] ?? ''));
        $conceptText = trim((string)($status['concept'] ?? ''));
        if ($whenText === '') {
          $whenText = $conceptDefaults['when'];
        }
        if ($conceptText === '') {
          $conceptText = $conceptDefaults['concept'];
        }

        $cleanPage['statuses'][] = [
          'code' => $code,
          'label' => $label,
          'when' => $whenText,
          'concept' => $conceptText,
          'bg_color' => $bg,
          'text_color' => $tx,
        ];

        if (count($cleanPage['statuses']) >= 120) {
          break;
        }
      }

      if (!empty($cleanPage['statuses'])) {
        $cleanSection['pages'][] = $cleanPage;
      }

      if (count($cleanSection['pages']) >= 60) {
        break;
      }
    }

    if (!empty($cleanSection['pages'])) {
      $out['sections'][] = $cleanSection;
    }

    if (count($out['sections']) >= 30) {
      break;
    }
  }

  if (empty($out['sections'])) {
    return $defaults;
  }
  return $out;
}

function statusConceptDefaults(string $status): array {
  $code = trim($status);
  $norm = strtolower(preg_replace('/[^a-z0-9]+/', ' ', $code));
  $norm = trim(preg_replace('/\s+/', ' ', $norm));

  $when = 'Use this status as per defined stage transition.';
  $concept = 'General workflow state';

  if ($norm === 'main' || $norm === 'available') {
    $when = 'Use when item or stock is available and free for planning/use.';
    $concept = 'Ready and available state';
  } elseif (strpos($norm, 'pending') !== false || strpos($norm, 'queue') !== false) {
    $when = 'Use when work is waiting to start in queue.';
    $concept = 'Waiting stage';
  } elseif (strpos($norm, 'preparing') !== false) {
    $when = 'Use when pre-stage setup is in progress before main processing.';
    $concept = 'Preparation stage';
  } elseif (strpos($norm, 'pause') !== false || strpos($norm, 'hold') !== false) {
    $when = 'Use when work is temporarily stopped.';
    $concept = 'Temporary hold stage';
  } elseif (strpos($norm, 'running') !== false || strpos($norm, 'progress') !== false || strpos($norm, 'printing') !== false || strpos($norm, 'slitting') !== false || strpos($norm, 'cutting') !== false || strpos($norm, 'barcode') !== false || strpos($norm, 'packing') !== false) {
    $when = 'Use when this process is currently active.';
    $concept = 'Active processing stage';
  } elseif (strpos($norm, 'completed') !== false || strpos($norm, 'complete') !== false || strpos($norm, 'printed') !== false || strpos($norm, 'slitted') !== false || strpos($norm, 'barcoded') !== false || strpos($norm, 'packed') !== false || strpos($norm, 'die cut') !== false) {
    $when = 'Use when this process/stage has finished successfully.';
    $concept = 'Completed stage';
  } elseif (strpos($norm, 'dispatch') !== false) {
    $when = 'Use when goods leave the facility for delivery.';
    $concept = 'Post-production dispatch stage';
  } elseif (strpos($norm, 'qc passed') !== false || strpos($norm, 'approved') !== false) {
    $when = 'Use when quality validation is successful.';
    $concept = 'Quality approved';
  } elseif (strpos($norm, 'qc failed') !== false || strpos($norm, 'reject') !== false) {
    $when = 'Use when quality validation fails and rework is required.';
    $concept = 'Quality rejected';
  } elseif (strpos($norm, 'consume') !== false || strpos($norm, 'consumed') !== false) {
    $when = 'Use when stock has been consumed in production.';
    $concept = 'Consumed inventory state';
  } elseif (strpos($norm, 'job assign') !== false || strpos($norm, 'assigned') !== false) {
    $when = 'Use when material/job has been assigned for operation.';
    $concept = 'Assignment state';
  } elseif (strpos($norm, 'stock') !== false) {
    $when = 'Use for stock-level visibility and inventory tracking.';
    $concept = 'Inventory tracking state';
  }

  return ['when' => $when, 'concept' => $concept];
}

function statusRowsFromCodes(array $codes): array {
  $rows = [];
  foreach ($codes as $code) {
    $label = trim((string)$code);
    if ($label === '') {
      continue;
    }
    $palette = erp_status_palette($label);
    $concept = statusConceptDefaults($label);
    $rows[] = [
      'code' => $label,
      'label' => $label,
      'when' => $concept['when'],
      'concept' => $concept['concept'],
      'bg_color' => normalizeHexColor((string)($palette['background'] ?? ''), '#64748B'),
      'text_color' => normalizeHexColor((string)($palette['color'] ?? ''), '#FFFFFF'),
    ];
  }
  return $rows;
}

function collectProvisionMigrationFiles(string $migrationDir): array {
  if (!is_dir($migrationDir)) {
    return [];
  }

  $files = [];
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($migrationDir, FilesystemIterator::SKIP_DOTS)
  );

  foreach ($iterator as $entry) {
    if (!$entry->isFile()) {
      continue;
    }
    $path = str_replace('\\', '/', (string)$entry->getPathname());
    if (substr($path, -4) !== '.sql') {
      continue;
    }
    if (stripos($path, '/pending_migrations/backup/') !== false) {
      continue;
    }
    $files[] = $path;
  }

  sort($files, SORT_NATURAL | SORT_FLAG_CASE);
  return $files;
}

function loadDynamicTenantRegistry(string $registryPath): array {
  if (!is_file($registryPath)) {
    return [
      'default_slug' => 'default',
      'tenants' => [],
    ];
  }

  $raw = @file_get_contents($registryPath);
  if ($raw === false) {
    return [
      'default_slug' => 'default',
      'tenants' => [],
    ];
  }

  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    return [
      'default_slug' => 'default',
      'tenants' => [],
    ];
  }

  if (!isset($decoded['tenants']) || !is_array($decoded['tenants'])) {
    $decoded['tenants'] = [];
  }
  if (empty($decoded['default_slug'])) {
    $decoded['default_slug'] = 'default';
  }

  return $decoded;
}

function saveDynamicTenantRegistry(string $registryPath, array $registry): bool {
  $dir = dirname($registryPath);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
    return false;
  }

  if (!isset($registry['tenants']) || !is_array($registry['tenants'])) {
    $registry['tenants'] = [];
  }
  if (empty($registry['default_slug'])) {
    $registry['default_slug'] = 'default';
  }

  $json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    return false;
  }
  return @file_put_contents($registryPath, $json, LOCK_EX) !== false;
}

function fetchWebsiteImageFromUrl(string $url): string {
  $url = trim($url);
  if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    return '';
  }

  $context = stream_context_create([
    'http' => [
      'timeout' => 6,
      'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
      'follow_location' => 1,
    ],
    'https' => [
      'timeout' => 6,
      'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    ],
  ]);

  $html = @file_get_contents($url, false, $context);
  if ($html === false || trim($html) === '') return '';

  $patterns = [
    '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i',
    '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i',
    '/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i',
    '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image["\']/i',
  ];

  foreach ($patterns as $pattern) {
    if (preg_match($pattern, $html, $m)) {
      $candidate = html_entity_decode(trim((string)$m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
      if (filter_var($candidate, FILTER_VALIDATE_URL) && isDirectImageUrl($candidate)) {
        return $candidate;
      }
    }
  }

  return '';
}

function buildDatabaseBackupSql(?mysqli $db = null) {
  if (!$db instanceof mysqli) {
    $db = getDB();
  }
  $sql = [];
  $sql[] = '-- ERP System Database Backup';
  $sql[] = '-- Generated: ' . date('Y-m-d H:i:s');
  $sql[] = '-- Database: ' . DB_NAME;
  $sql[] = '';
  $sql[] = 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";';
  $sql[] = 'SET FOREIGN_KEY_CHECKS = 0;';
  $sql[] = '';

  $tablesRes = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
  if (!$tablesRes) {
    return '';
  }

  while ($row = $tablesRes->fetch_row()) {
    $table = (string)$row[0];
    $safeTable = '`' . str_replace('`', '``', $table) . '`';

    $createRes = $db->query("SHOW CREATE TABLE " . $safeTable);
    if (!$createRes) continue;
    $createRow = $createRes->fetch_assoc();
    $createStmt = (string)($createRow['Create Table'] ?? '');
    if ($createStmt === '') continue;

    $sql[] = '--';
    $sql[] = '-- Structure for table ' . $table;
    $sql[] = '--';
    $sql[] = 'DROP TABLE IF EXISTS ' . $safeTable . ';';
    $sql[] = $createStmt . ';';
    $sql[] = '';

    $dataRes = $db->query("SELECT * FROM " . $safeTable);
    if (!$dataRes) continue;
    if ($dataRes->num_rows > 0) {
      $sql[] = '--';
      $sql[] = '-- Data for table ' . $table;
      $sql[] = '--';
    }

    while ($dataRow = $dataRes->fetch_assoc()) {
      $columns = [];
      $values = [];
      foreach ($dataRow as $col => $val) {
        $columns[] = '`' . str_replace('`', '``', (string)$col) . '`';
        if ($val === null) {
          $values[] = 'NULL';
        } else {
          $values[] = "'" . $db->real_escape_string((string)$val) . "'";
        }
      }
      $sql[] = 'INSERT INTO ' . $safeTable . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ');';
    }
    if ($dataRes->num_rows > 0) $sql[] = '';
  }

  $sql[] = 'SET FOREIGN_KEY_CHECKS = 1;';
  $sql[] = '';
  return implode("\n", $sql);
}

function restoreDatabaseFromSql(mysqli $db, string $sqlDump): array {
  $restoreErr = '';
  $db->query('SET FOREIGN_KEY_CHECKS=0');
  $restoreOk = true;

  if (!$db->multi_query($sqlDump)) {
    $restoreOk = false;
    $restoreErr = $db->error;
  } else {
    do {
      if ($res = $db->store_result()) {
        $res->free();
      }
      if (!$db->more_results()) break;
      if (!$db->next_result()) {
        $restoreOk = false;
        $restoreErr = $db->error;
        break;
      }
    } while (true);
  }

  $db->query('SET FOREIGN_KEY_CHECKS=1');
  return ['ok' => $restoreOk, 'error' => $restoreErr];
}

function addDirectoryToZip(ZipArchive $zip, string $sourceDir, string $zipPrefix): void {
  if (!is_dir($sourceDir)) return;
  $base = rtrim(str_replace('\\', '/', realpath($sourceDir) ?: $sourceDir), '/');
  if (!is_dir($base)) return;

  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );

  foreach ($it as $entry) {
    $path = str_replace('\\', '/', $entry->getPathname());
    $rel = ltrim(substr($path, strlen($base)), '/');
    if ($rel === '') continue;
    $zipPath = trim($zipPrefix, '/') . '/' . $rel;
    if ($entry->isDir()) {
      $zip->addEmptyDir($zipPath);
    } elseif ($entry->isFile()) {
      $zip->addFile($path, $zipPath);
    }
  }
}

function restoreZipAsset(ZipArchive $zip, string $entryName, string $projectRoot, array $allowedPrefixes, array $allowedSingles): void {
  $name = str_replace('\\', '/', $entryName);

  $isAllowed = in_array($name, $allowedSingles, true);
  if (!$isAllowed) {
    foreach ($allowedPrefixes as $prefix) {
      if (strpos($name, $prefix) === 0) {
        $isAllowed = true;
        break;
      }
    }
  }
  if (!$isAllowed) return;
  if (strpos($name, '..') !== false) return;

  $target = $projectRoot . '/' . $name;
  $targetDir = dirname($target);
  if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0777, true);
  }

  $in = $zip->getStream($entryName);
  if (!$in) return;
  $out = @fopen($target, 'wb');
  if (!$out) {
    fclose($in);
    return;
  }
  while (!feof($in)) {
    $chunk = fread($in, 8192);
    if ($chunk === false) break;
    fwrite($out, $chunk);
  }
  fclose($out);
  fclose($in);
}

function saveUploadedImage($fileKey, $targetDir, $prefix) {
  if (empty($_FILES[$fileKey]) || !is_array($_FILES[$fileKey])) return ['', ''];
  $file = $_FILES[$fileKey];
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['', ''];
  if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return ['', 'Upload failed for ' . $fileKey . '.'];
  if (($file['size'] ?? 0) > 5 * 1024 * 1024) return ['', 'File size must be below 5MB.'];

  $tmp = $file['tmp_name'] ?? '';
  $mime = @mime_content_type($tmp);
  $allowed = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
  ];
  if (!isset($allowed[$mime])) return ['', 'Only PNG, JPG, WEBP, GIF files are allowed.'];

  $ext = $allowed[$mime];
  $safeName = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  $absDir = __DIR__ . '/../../uploads/' . trim($targetDir, '/');
  if (!is_dir($absDir)) @mkdir($absDir, 0777, true);
  $absPath = $absDir . '/' . $safeName;
  if (!@move_uploaded_file($tmp, $absPath)) return ['', 'Unable to save uploaded file.'];

  return ['uploads/' . trim($targetDir, '/') . '/' . $safeName, ''];
}

$tenantAssetDirs = tenantAssetDirectories($tenantSlug);
$tenantRegistryPath = $projectRoot . '/data/tenants_registry.json';
$tenantSettingsZipPath = tenantSettingsZipPath($projectRoot, $tenantSettingsPath);
$tenantSettingsDisplayPath = str_replace($projectRoot . '/', '', str_replace('\\', '/', $tenantSettingsPath));

$activeTab = $_GET['tab'] ?? 'company';
$allowedTabs = ['company', 'library', 'theme', 'status-workflow', 'backup', 'update', 'tenant'];
if (!in_array($activeTab, $allowedTabs, true)) $activeTab = 'company';

$statusWorkflowSettings = sanitizeStatusWorkflowPayload($settings['status_workflow_matrix'] ?? statusWorkflowDefaults());
$statusWorkflowGlobalReference = [
  '/modules/paper_stock/index.php' => statusRowsFromCodes(erp_paper_stock_status_options()),
  '/modules/planning/paperroll/index.php' => statusRowsFromCodes(erp_label_planning_status_options()),
  '/modules/planning/barcode/index.php' => statusRowsFromCodes(erp_label_planning_status_options()),
  '/modules/jobs/pos/index.php' => statusRowsFromCodes(['Pending', 'Running', 'Barcode', 'Barcode Pause', 'Barcoded', 'Completed', 'QC Passed', 'QC Failed']),
  '/modules/packing/index.php' => statusRowsFromCodes(['Pending', 'Preparing Packing', 'Packing', 'Packing Pause', 'Packed', 'Dispatched']),
];
$statusWorkflowGlobalFlat = [];
foreach ($statusWorkflowGlobalReference as $path => $statuses) {
  foreach ($statuses as $statusRow) {
    $k = strtolower(trim((string)($statusRow['code'] ?? '')));
    if ($k === '' || isset($statusWorkflowGlobalFlat[$k])) {
      continue;
    }
    $statusWorkflowGlobalFlat[$k] = $statusRow;
  }
}
$statusWorkflowGlobalFlat = array_values($statusWorkflowGlobalFlat);

// Support paper_type parameter from paper stock view
$targetPaperType = trim((string)($_GET['paper_type'] ?? ''));
if ($targetPaperType !== '') {
  $activeTab = 'library';
  $libraryFilter = 'all';
} else {
  $libraryFilter = $_GET['category'] ?? 'all';
}

if ($libraryFilter !== 'all' && !isset($libraryCategories[$libraryFilter])) {
  $libraryFilter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Security token mismatch. Please retry.');
    redirect(BASE_URL . '/modules/settings/index.php?tab=' . urlencode($activeTab));
  }

  $action = $_POST['action'] ?? '';

  if ($action === 'save_company') {
    // Only save ERP settings (company profile is set during tenant provisioning)
    $settings['erp_display_name'] = trim((string)($_POST['erp_display_name'] ?? ''));
    $settings['erp_email'] = trim((string)($_POST['erp_email'] ?? ''));
    $settings['erp_phone'] = trim((string)($_POST['erp_phone'] ?? ''));
    $settings['erp_address'] = trim((string)($_POST['erp_address'] ?? ''));
    $settings['erp_gst'] = trim((string)($_POST['erp_gst'] ?? ''));

    list($erpLogoPath, $erpLogoErr) = saveUploadedImage('erp_logo', $tenantAssetDirs['company'], 'erp_logo');
    if ($erpLogoErr !== '') {
      setFlash('error', $erpLogoErr);
      redirect(BASE_URL . '/modules/settings/index.php?tab=company');
    }
    if ($erpLogoPath !== '') $settings['erp_logo_path'] = $erpLogoPath;

    if (saveAppSettings($settings)) {
      setFlash('success', 'ERP profile saved successfully.');
    } else {
      setFlash('error', 'Unable to save ERP profile settings.');
    }
    redirect(BASE_URL . '/modules/settings/index.php?tab=company');
  }

  if ($action === 'provision_tenant') {
    if (!isAdmin()) {
      setFlash('error', 'Only administrators can provision new company tenants.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=tenant');
    }

    $slugRaw = strtolower(trim((string)($_POST['tenant_slug'] ?? '')));
    $tenantSlugNew = preg_replace('/[^a-z0-9._-]+/', '-', $slugRaw);
    $tenantSlugNew = trim((string)$tenantSlugNew, '-');
    $tenantLabelNew = trim((string)($_POST['tenant_label'] ?? ''));
    $tenantCompanyName = trim((string)($_POST['tenant_company_name'] ?? ''));
    $tenantErpDisplayName = trim((string)($_POST['tenant_erp_display_name'] ?? ''));
    $tenantHostCsv = trim((string)($_POST['tenant_hosts'] ?? ''));
    $tenantPrefixCsv = trim((string)($_POST['tenant_path_prefixes'] ?? ''));

    $newDbHost = trim((string)($_POST['db_host'] ?? 'localhost'));
    $newDbPort = (int)($_POST['db_port'] ?? 3306);
    $newDbName = trim((string)($_POST['db_name'] ?? ''));
    $newDbUser = trim((string)($_POST['db_user'] ?? ''));
    $newDbPass = (string)($_POST['db_pass'] ?? '');
    $newDbCreate = (($_POST['create_database'] ?? '1') === '1');

    $adminName = trim((string)($_POST['admin_name'] ?? 'System Admin'));
    $adminEmail = trim((string)($_POST['admin_email'] ?? 'admin@example.com'));
    $adminPass = (string)($_POST['admin_password'] ?? 'admin123');

    // Company details (comprehensive - captured during provisioning)
    $tenantCompanyEmailNew = trim((string)($_POST['tenant_company_email'] ?? ''));
    $tenantCompanyPhoneNew = trim((string)($_POST['tenant_company_phone'] ?? ''));
    $tenantCompanyAddressNew = trim((string)($_POST['tenant_company_address'] ?? ''));
    $tenantCompanyCurrencyNew = trim((string)($_POST['tenant_company_currency'] ?? 'INR'));
    $tenantCompanyGstNew = trim((string)($_POST['tenant_company_gst'] ?? ''));
    $tenantContactPersonNew = trim((string)($_POST['tenant_contact_person'] ?? ''));
    $tenantCompanyNameLegalNew = trim((string)($_POST['tenant_company_name_legal'] ?? $tenantCompanyName));
    $tenantFlagEmojiNew = trim((string)($_POST['tenant_flag_emoji'] ?? '🇮🇳')) ?: '🇮🇳';

    if ($tenantSlugNew === '' || strlen($tenantSlugNew) < 2) {
      setFlash('error', 'Tenant slug is required and must be at least 2 characters.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=tenant');
    }
    if ($tenantLabelNew === '') {
      setFlash('error', 'Tenant label is required.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=tenant');
    }
    if ($tenantCompanyName === '') {
      setFlash('error', 'Company legal name is required.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=tenant');
    }
    if ($newDbHost === '' || $newDbName === '' || $newDbUser === '') {
      setFlash('error', 'DB host, name and user are required.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=tenant');
    }
    if ($newDbPort < 1 || $newDbPort > 65535) {
      setFlash('error', 'DB port must be between 1 and 65535.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=tenant');
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
      setFlash('error', 'Valid admin email is required.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=tenant');
    }
    if (strlen($adminPass) < 6) {
      setFlash('error', 'Admin password must be at least 6 characters.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=tenant');
    }

    $fullRegistry = function_exists('erp_load_tenant_registry') ? erp_load_tenant_registry() : ['tenants' => []];
    if (isset($fullRegistry['tenants'][$tenantSlugNew])) {
      setFlash('error', 'Tenant slug already exists. Choose another slug.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=tenant');
    }

    $schemaPath = $projectRoot . '/database/schema.sql';
    if (!is_file($schemaPath)) {
      setFlash('error', 'Schema file missing: database/schema.sql');
      redirect(BASE_URL . '/modules/settings/index.php?tab=tenant');
    }
    $schemaSql = file_get_contents($schemaPath);
    if ($schemaSql === false || trim($schemaSql) === '') {
      setFlash('error', 'Schema file could not be read.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=tenant');
    }

    $migrationFiles = collectProvisionMigrationFiles($projectRoot . '/pending_migrations');
    $serverConn = @new mysqli($newDbHost, $newDbUser, $newDbPass, '', $newDbPort);
    if ($serverConn->connect_error) {
      setFlash('error', 'Cannot connect to DB server: ' . $serverConn->connect_error);
      redirect(BASE_URL . '/modules/settings/index.php?tab=tenant');
    }
    $serverConn->set_charset('utf8mb4');

    if ($newDbCreate) {
      $createSql = 'CREATE DATABASE IF NOT EXISTS `' . $serverConn->real_escape_string($newDbName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
      if (!$serverConn->query($createSql)) {
        $serverConn->close();
        setFlash('error', 'Database create failed: ' . $serverConn->error);
        redirect(BASE_URL . '/modules/settings/index.php?tab=tenant');
      }
    }
    $serverConn->close();

    $tenantDb = @new mysqli($newDbHost, $newDbUser, $newDbPass, $newDbName, $newDbPort);
    if ($tenantDb->connect_error) {
      setFlash('error', 'Cannot connect to tenant database: ' . $tenantDb->connect_error);
      redirect(BASE_URL . '/modules/settings/index.php?tab=tenant');
    }
    $tenantDb->set_charset('utf8mb4');

    $schemaRes = restoreDatabaseFromSql($tenantDb, $schemaSql);
    if (!$schemaRes['ok']) {
      $tenantDb->close();
      setFlash('error', 'Schema import failed: ' . ($schemaRes['error'] ?: 'Unknown SQL error.'));
      redirect(BASE_URL . '/modules/settings/index.php?tab=tenant');
    }

    foreach ($migrationFiles as $mf) {
      $mfContent = file_get_contents($mf);
      if ($mfContent === false || trim($mfContent) === '') {
        continue;
      }
      $migRes = restoreDatabaseFromSql($tenantDb, $mfContent);
      if (!$migRes['ok']) {
        $tenantDb->close();
        setFlash('error', 'Migration failed (' . basename($mf) . '): ' . ($migRes['error'] ?: 'Unknown SQL error.'));
        redirect(BASE_URL . '/modules/settings/index.php?tab=tenant');
      }
    }

    $adminHash = password_hash($adminPass, PASSWORD_BCRYPT);
    $check = $tenantDb->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    if ($check) {
      $check->bind_param('s', $adminEmail);
      $check->execute();
      $existing = $check->get_result()->fetch_assoc();
      $check->close();

      if ($existing) {
        $upd = $tenantDb->prepare('UPDATE users SET name = ?, password = ?, role = ?, is_active = 1 WHERE email = ?');
        if ($upd) {
          $role = 'admin';
          $upd->bind_param('ssss', $adminName, $adminHash, $role, $adminEmail);
          $upd->execute();
          $upd->close();
        }
      } else {
        $ins = $tenantDb->prepare('INSERT INTO users (name, email, password, role, is_active) VALUES (?, ?, ?, ?, 1)');
        if ($ins) {
          $role = 'admin';
          $ins->bind_param('ssss', $adminName, $adminEmail, $adminHash, $role);
          $ins->execute();
          $ins->close();
        }
      }
    }
    $tenantDb->close();

    $tenantDataDir = $projectRoot . '/data/tenants/' . $tenantSlugNew;
    if (!is_dir($tenantDataDir)) {
      @mkdir($tenantDataDir, 0775, true);
    }
    $tenantSettingsFile = $tenantDataDir . '/app_settings.json';
    $tenantDefaults = appSettingsDefaults();
    
    // Company brand and contact details
    $tenantDefaults['company_name'] = $tenantCompanyName;
    $tenantDefaults['company_legal_name'] = $tenantCompanyNameLegalNew !== '' ? $tenantCompanyNameLegalNew : $tenantCompanyName;
    $tenantDefaults['contact_person'] = $tenantContactPersonNew;
    $tenantDefaults['company_email'] = $tenantCompanyEmailNew !== '' ? $tenantCompanyEmailNew : $adminEmail;
    $tenantDefaults['company_phone'] = $tenantCompanyPhoneNew;
    $tenantDefaults['company_mobile'] = $tenantCompanyPhoneNew;
    $tenantDefaults['company_address'] = $tenantCompanyAddressNew;
    $tenantDefaults['company_currency'] = $tenantCompanyCurrencyNew;
    $tenantDefaults['company_gst'] = $tenantCompanyGstNew;
    $tenantDefaults['flag_emoji'] = $tenantFlagEmojiNew;
    
    // ERP details from global settings
    $tenantDefaults['erp_display_name'] = $tenantErpDisplayName;
    $tenantDefaults['image_library'] = [];
    @file_put_contents($tenantSettingsFile, json_encode($tenantDefaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);

    // Handle company logo upload
    if (isset($_FILES['tenant_company_logo']) && $_FILES['tenant_company_logo']['error'] === UPLOAD_ERR_OK) {
      $newTenantAssetDirs = tenantAssetDirectories($tenantSlugNew);
      list($logoPath, $logoErr) = saveUploadedImage('tenant_company_logo', $newTenantAssetDirs['company'], 'logo');
      if ($logoErr === '' && $logoPath !== '') {
        $tenantDefaults['logo_path'] = $logoPath;
        @file_put_contents($tenantSettingsFile, json_encode($tenantDefaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
      }
    }

    $newTenantAssetDirs = tenantAssetDirectories($tenantSlugNew);
    $companyAssetDir = $projectRoot . '/' . $newTenantAssetDirs['company'];
    $libraryAssetDir = $projectRoot . '/' . $newTenantAssetDirs['library'];
    if (!is_dir($companyAssetDir)) {
      @mkdir($companyAssetDir, 0775, true);
    }
    if (!is_dir($libraryAssetDir)) {
      @mkdir($libraryAssetDir, 0775, true);
    }

    $tenantHosts = parseCsvList($tenantHostCsv);
    $tenantPrefixes = parseCsvList($tenantPrefixCsv);
    if (empty($tenantPrefixes)) {
      $tenantPrefixes = ['/' . $tenantSlugNew];
    }

    $dynamicRegistry = loadDynamicTenantRegistry($tenantRegistryPath);
    $dynamicRegistry['tenants'][$tenantSlugNew] = [
      'label' => $tenantLabelNew,
      'active' => true,
      'hosts' => $tenantHosts,
      'path_prefixes' => $tenantPrefixes,
      'settings_file' => 'data/tenants/' . $tenantSlugNew . '/app_settings.json',
      'erp_display_name' => $tenantErpDisplayName,
      'db' => [
        'local' => [
          'DB_HOST' => $newDbHost,
          'DB_PORT' => $newDbPort,
          'DB_USER' => $newDbUser,
          'DB_PASS' => $newDbPass,
          'DB_NAME' => $newDbName,
        ],
        'live' => [
          'DB_HOST' => $newDbHost,
          'DB_PORT' => $newDbPort,
          'DB_USER' => $newDbUser,
          'DB_PASS' => $newDbPass,
          'DB_NAME' => $newDbName,
        ],
      ],
    ];
    if (empty($dynamicRegistry['default_slug'])) {
      $dynamicRegistry['default_slug'] = $tenantSlug;
    }

    if (!saveDynamicTenantRegistry($tenantRegistryPath, $dynamicRegistry)) {
      setFlash('error', 'Tenant created, but registry file write failed. Check data folder permissions.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=tenant');
    }

    setFlash('success', 'Tenant provisioned: ' . $tenantLabelNew . ' (' . $tenantSlugNew . '). Admin: ' . $adminEmail);
    redirect(BASE_URL . '/modules/settings/index.php?tab=tenant');
  }

  if ($action === 'upload_library_image') {
    $category = $_POST['image_category'] ?? 'misc';
    if (!isset($libraryCategories[$category])) $category = 'misc';

    $paperType = trim((string)($_POST['paper_type_tag'] ?? ''));
    if ($category === 'product-type' && $paperType === '') {
      setFlash('error', 'Paper type name is required for Product Type images.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=library');
    }

    list($imgPath, $imgErr) = saveUploadedImage('library_image', $tenantAssetDirs['library'], 'lib');
    if ($imgErr !== '') {
      setFlash('error', $imgErr);
      redirect(BASE_URL . '/modules/settings/index.php?tab=library');
    }
    if ($imgPath !== '') {
      if (!isset($settings['image_library']) || !is_array($settings['image_library'])) $settings['image_library'] = [];
      $entry = [
        'path' => $imgPath,
        'name' => basename($imgPath),
        'uploaded_at' => date('Y-m-d H:i:s'),
        'category' => $category,
      ];
      if ($category === 'product-type' && $paperType !== '') {
        $entry['paper_type'] = $paperType;
      }
      $settings['image_library'][] = $entry;
      if ($category === 'background' && trim((string)($settings['login_background_image'] ?? '')) === '') {
        $settings['login_background_image'] = $imgPath;
      }
      if (saveAppSettings($settings)) {
        if ($category === 'background' && ($settings['login_background_image'] ?? '') === $imgPath) {
          setFlash('success', 'Background uploaded and set as login background.');
        } else {
          setFlash('success', 'Image uploaded to library.');
        }
      } else {
        setFlash('error', 'Image uploaded but settings file could not be updated.');
      }
    }
    redirect(BASE_URL . '/modules/settings/index.php?tab=library');
  }

  if ($action === 'remove_library_image') {
    $idx = (int)($_POST['image_index'] ?? -1);
    if (isset($settings['image_library'][$idx])) {
      $path = (string)$settings['image_library'][$idx]['path'];
      $abs = __DIR__ . '/../../' . ltrim($path, '/');
      if (is_file($abs)) @unlink($abs);
      array_splice($settings['image_library'], $idx, 1);
      saveAppSettings($settings);
      setFlash('success', 'Image removed from library.');
    }
    redirect(BASE_URL . '/modules/settings/index.php?tab=library&category=' . urlencode($libraryFilter));
  }

  if ($action === 'assign_image_to_papertype') {
    $imageIdx = (int)($_POST['image_index'] ?? -1);
    $paperType = trim((string)($_POST['paper_type'] ?? ''));
    
    if ($imageIdx >= 0 && isset($settings['image_library'][$imageIdx]) && $paperType !== '') {
      $imagePath = (string)($settings['image_library'][$imageIdx]['path'] ?? '');
      
      // Find existing entry for this paper type and update it
      $found = false;
      foreach (($settings['image_library'] ?? []) as &$img) {
        if (($img['category'] ?? '') === 'product-type' && 
            strtolower(trim((string)($img['paper_type'] ?? ''))) === strtolower($paperType)) {
          $img['path'] = $imagePath;
          $img['paper_type'] = $paperType;
          $found = true;
          break;
        }
      }
      
      // If not found, add new entry
      if (!$found) {
        $settings['image_library'][] = [
          'category' => 'product-type',
          'paper_type' => $paperType,
          'path' => $imagePath,
          'name' => basename($imagePath),
          'title' => $paperType . ' Thumbnail',
          'uploaded_at' => date('Y-m-d H:i:s')
        ];
      }
      
      if (saveAppSettings($settings)) {
        setFlash('success', 'Image assigned to paper type "' . htmlspecialchars($paperType) . '" successfully.');
      } else {
        setFlash('error', 'Failed to assign image to paper type.');
      }
    }
    redirect(BASE_URL . '/modules/settings/index.php?tab=library&category=product-type');
  }

  if ($action === 'save_theme') {
    $settings['theme_mode'] = (($_POST['theme_mode'] ?? 'light') === 'dark') ? 'dark' : 'light';
    $settings['sidebar_button_color'] = normalizeHexColor($_POST['sidebar_button_color'] ?? '', '#22C55E');
    $settings['sidebar_hover_color'] = normalizeHexColor($_POST['sidebar_hover_color'] ?? '', '#263445');
    $settings['sidebar_active_bg'] = normalizeHexColor($_POST['sidebar_active_bg'] ?? '', '#214036');
    $settings['sidebar_active_text'] = normalizeHexColor($_POST['sidebar_active_text'] ?? '', '#BBF7D0');
    $settings['sidebar_collapse_delay_ms'] = min(600000, max(300, (int)($_POST['sidebar_collapse_delay_ms'] ?? 1000)));
    $settings['login_background_image'] = trim((string)($_POST['login_background_image'] ?? ''));

    $validLibraryPaths = [];
    foreach (($settings['image_library'] ?? []) as $img) {
      if (!empty($img['path'])) $validLibraryPaths[] = (string)$img['path'];
    }
    if ($settings['login_background_image'] !== '' && !in_array($settings['login_background_image'], $validLibraryPaths, true)) {
      $settings['login_background_image'] = '';
    }

    if (saveAppSettings($settings)) {
      setFlash('success', 'Theme settings saved successfully.');
    } else {
      setFlash('error', 'Unable to save theme settings.');
    }
    redirect(BASE_URL . '/modules/settings/index.php?tab=theme');
  }

  if ($action === 'save_status_workflow') {
    $jsonPayload = trim((string)($_POST['status_workflow_json'] ?? ''));
    if ($jsonPayload === '') {
      setFlash('error', 'Status workflow payload is empty.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=status-workflow');
    }

    $decoded = json_decode($jsonPayload, true);
    if (!is_array($decoded)) {
      setFlash('error', 'Invalid workflow payload format. Please retry.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=status-workflow');
    }

    $settings['status_workflow_matrix'] = sanitizeStatusWorkflowPayload($decoded);

    if (saveAppSettings($settings)) {
      setFlash('success', 'Status workflow configuration saved successfully.');
    } else {
      setFlash('error', 'Unable to save status workflow configuration.');
    }
    redirect(BASE_URL . '/modules/settings/index.php?tab=status-workflow');
  }



  if ($action === 'download_backup') {
    if (!isset($db) || !($db instanceof mysqli)) {
      $db = getDB();
    }
    $backupSql = buildDatabaseBackupSql($db ?? null);
    if ($backupSql === '') {
      setFlash('error', 'Unable to generate backup. Please check database access.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $stamp = date('Ymd_His');
    if (!class_exists('ZipArchive')) {
      $fileName = $tenantSlug . '_' . DB_NAME . '_backup_' . $stamp . '.sql';
      header('Content-Type: application/sql; charset=UTF-8');
      header('Content-Disposition: attachment; filename="' . $fileName . '"');
      header('Content-Length: ' . strlen($backupSql));
      echo $backupSql;
      exit;
    }

    $tmpZip = tempnam(sys_get_temp_dir(), 'erpbk_');
    if ($tmpZip === false) {
      setFlash('error', 'Unable to create backup file. Please try again.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
      @unlink($tmpZip);
      setFlash('error', 'Unable to open backup archive for writing.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $zip->addFromString('backup/database.sql', $backupSql);
    $settingsFile = $tenantSettingsPath;
    if (is_file($settingsFile)) {
      $zip->addFile($settingsFile, $tenantSettingsZipPath);
    }
    addDirectoryToZip($zip, $projectRoot . '/' . $tenantAssetDirs['company'], $tenantAssetDirs['company']);
    addDirectoryToZip($zip, $projectRoot . '/' . $tenantAssetDirs['library'], $tenantAssetDirs['library']);
    $zip->close();

    $fileName = $tenantSlug . '_' . DB_NAME . '_full_backup_' . $stamp . '.zip';
    $size = @filesize($tmpZip);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    if ($size !== false) {
      header('Content-Length: ' . $size);
    }
    readfile($tmpZip);
    @unlink($tmpZip);
    exit;
  }

  if ($action === 'restore_backup') {
    if (!isset($db) || !($db instanceof mysqli)) {
      $db = getDB();
    }
    $fileInput = $_FILES['backup_file'] ?? $_FILES['backup_sql'] ?? null;
    if (empty($fileInput) || !is_array($fileInput)) {
      setFlash('error', 'Please choose a backup file (.zip or .sql) to restore.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $file = $fileInput;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      setFlash('error', 'Backup upload failed. Please try again.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }
    if (($file['size'] ?? 0) > 30 * 1024 * 1024) {
      setFlash('error', 'Backup file is too large. Maximum allowed size is 30MB.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $name = strtolower((string)($file['name'] ?? ''));
    $isSql = (substr($name, -4) === '.sql');
    $isZip = (substr($name, -4) === '.zip');
    if (!$isSql && !$isZip) {
      setFlash('error', 'Invalid file format. Please upload a .zip (full backup) or .sql file.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    if ($isSql) {
      $sqlDump = @file_get_contents((string)$file['tmp_name']);
      if ($sqlDump === false || trim($sqlDump) === '') {
        setFlash('error', 'Could not read uploaded SQL backup file.');
        redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
      }
      $res = restoreDatabaseFromSql($db, $sqlDump);
      if ($res['ok']) {
        setFlash('success', 'Database restore completed successfully.');
      } else {
        setFlash('error', 'Restore failed: ' . ($res['error'] ?: 'Unknown SQL error.'));
      }
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    if (!class_exists('ZipArchive')) {
      setFlash('error', 'ZIP restore requires ZipArchive extension in PHP.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $zip = new ZipArchive();
    if ($zip->open((string)$file['tmp_name']) !== true) {
      setFlash('error', 'Could not open ZIP backup file.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $sqlDump = '';
    $sqlIndex = $zip->locateName('backup/database.sql', ZipArchive::FL_NOCASE | ZipArchive::FL_NODIR);
    if ($sqlIndex === false) {
      $sqlIndex = $zip->locateName('database.sql', ZipArchive::FL_NOCASE | ZipArchive::FL_NODIR);
    }
    if ($sqlIndex !== false) {
      $sqlDump = (string)$zip->getFromIndex($sqlIndex);
    }
    if (trim($sqlDump) === '') {
      $zip->close();
      setFlash('error', 'Invalid backup ZIP: database.sql not found.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $dbRes = restoreDatabaseFromSql($db, $sqlDump);
    if (!$dbRes['ok']) {
      $zip->close();
      setFlash('error', 'Database restore failed: ' . ($dbRes['error'] ?: 'Unknown SQL error.'));
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $allowedRestorePrefixes = [
      rtrim($tenantAssetDirs['company'], '/') . '/',
      rtrim($tenantAssetDirs['library'], '/') . '/',
      'uploads/company/',
      'uploads/library/',
    ];
    $allowedRestoreSingles = array_values(array_unique(array_filter([
      $tenantSettingsZipPath,
      'data/app_settings.json',
    ])));
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $entryName = (string)$zip->getNameIndex($i);
      if ($entryName === '' || substr($entryName, -1) === '/') continue;
      restoreZipAsset($zip, $entryName, $projectRoot, $allowedRestorePrefixes, $allowedRestoreSingles);
    }
    $zip->close();

    setFlash('success', 'Full backup restored successfully (database + images/settings).');
    redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
  }

  // ── Apply System Update ──
  if ($action === 'apply_update') {
    if (!isAdmin()) {
      setFlash('error', 'Only administrators can apply system updates.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }

    $fileInput = $_FILES['update_file'] ?? null;
    if (empty($fileInput) || !is_array($fileInput) || ($fileInput['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      setFlash('error', 'Please select a valid update ZIP file.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }
    if (($fileInput['size'] ?? 0) > 50 * 1024 * 1024) {
      setFlash('error', 'Update file too large. Maximum 50 MB.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }
    $fname = strtolower((string)($fileInput['name'] ?? ''));
    if (substr($fname, -4) !== '.zip') {
      setFlash('error', 'Only .zip update packages are accepted.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }
    if (!class_exists('ZipArchive')) {
      setFlash('error', 'ZipArchive PHP extension is required.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }

    $zip = new ZipArchive();
    if ($zip->open((string)$fileInput['tmp_name']) !== true) {
      setFlash('error', 'Cannot open update ZIP file.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }

    // Read manifest
    $manifestJson = $zip->getFromName('manifest.json');
    if ($manifestJson === false) {
      $zip->close();
      setFlash('error', 'Invalid update package: manifest.json not found.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }
    // Be tolerant of UTF-8 BOM in manifests produced by some zip/build tools.
    $manifestJson = preg_replace('/^\xEF\xBB\xBF/', '', (string)$manifestJson);
    $manifest = json_decode($manifestJson, true);
    if (!is_array($manifest) || empty($manifest['version']) || empty($manifest['files'])) {
      $zip->close();
      setFlash('error', 'Invalid manifest.json: missing version or files list.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }

    // Validate checksum
    $checksumData = '';
    foreach ((array)$manifest['files'] as $relPath) {
      $content = $zip->getFromName('files/' . $relPath);
      if ($content !== false) {
        $checksumData .= hash('sha256', $content);
      }
    }
    foreach ((array)($manifest['migrations'] ?? []) as $mf) {
      $content = $zip->getFromName('migrations/' . $mf);
      if ($content !== false) {
        $checksumData .= hash('sha256', $content);
      }
    }
    if (!empty($manifest['checksum']) && hash('sha256', $checksumData) !== $manifest['checksum']) {
      $zip->close();
      setFlash('error', 'Checksum verification failed. The update package may be corrupted.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }

    // Security: validate all file paths
    $projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../'));
    foreach ((array)$manifest['files'] as $relPath) {
      $relPath = str_replace('\\', '/', (string)$relPath);
      if (strpos($relPath, '..') !== false || $relPath === 'config/db.php') {
        $zip->close();
        setFlash('error', 'Security violation: invalid file path in update package.');
        redirect(BASE_URL . '/modules/settings/index.php?tab=update');
      }
    }

    // ZIP bomb check: total uncompressed size limit (100 MB)
    $totalSize = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $stat = $zip->statIndex($i);
      if ($stat) $totalSize += (int)($stat['size'] ?? 0);
    }
    if ($totalSize > 100 * 1024 * 1024) {
      $zip->close();
      setFlash('error', 'Update package too large when extracted (>100 MB).');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }

    // ── Auto-backup before applying ──
    $backupsDir = $projectRoot . '/uploads/updates/backups';
    if (!is_dir($backupsDir)) {
      @mkdir($backupsDir, 0755, true);
    }
    $stamp = date('Ymd_His');
    $preBackupPath = $backupsDir . '/pre_update_v' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $manifest['version']) . '_' . $stamp . '.zip';

    $bkZip = new ZipArchive();
    if ($bkZip->open($preBackupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
      // Backup files that will be overwritten
      foreach ((array)$manifest['files'] as $relPath) {
        $absPath = $projectRoot . '/' . str_replace('\\', '/', $relPath);
        if (is_file($absPath)) {
          $bkZip->addFile($absPath, 'files/' . $relPath);
        }
      }
      // Backup database
      if (!isset($db) || !($db instanceof mysqli)) $db = getDB();
      $bkSql = buildDatabaseBackupSql($db);
      if ($bkSql !== '') {
        $bkZip->addFromString('backup/database.sql', $bkSql);
      }
      // Save old version in backup manifest
      $bkManifest = [
        'version' => APP_VERSION,
        'timestamp' => date('c'),
        'description' => 'Pre-update backup before applying v' . $manifest['version'],
        'files' => (array)$manifest['files'],
      ];
      $bkZip->addFromString('manifest.json', json_encode($bkManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      $bkZip->close();
    }

    // ── Extract files ──
    $extractedCount = 0;
    foreach ((array)$manifest['files'] as $relPath) {
      $relPath = str_replace('\\', '/', (string)$relPath);
      $content = $zip->getFromName('files/' . $relPath);
      if ($content === false) continue;
      $absTarget = $projectRoot . '/' . $relPath;
      $targetDir = dirname($absTarget);
      if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0755, true);
      }
      if (file_put_contents($absTarget, $content) !== false) {
        $extractedCount++;
      }
    }

    // ── Run SQL migrations ──
    $migrationResults = [];
    $migrations = (array)($manifest['migrations'] ?? []);
    sort($migrations);
    if (!empty($migrations)) {
      if (!isset($db) || !($db instanceof mysqli)) $db = getDB();
      foreach ($migrations as $mf) {
        $sqlContent = $zip->getFromName('migrations/' . $mf);
        if ($sqlContent === false || trim($sqlContent) === '') continue;
        $res = restoreDatabaseFromSql($db, $sqlContent);
        $migrationResults[] = [
          'file' => $mf,
          'ok' => $res['ok'],
          'error' => $res['error'] ?? '',
        ];
      }
    }

    $zip->close();

    // ── Bump APP_VERSION in config/db.php ──
    $configPath = $projectRoot . '/config/db.php';
    if (is_file($configPath) && is_writable($configPath)) {
      $configContent = file_get_contents($configPath);
      if ($configContent !== false) {
        $newVersion = preg_replace('/[^a-zA-Z0-9._-]/', '', $manifest['version']);
        $configContent = preg_replace(
          "/('APP_VERSION'\s*=>\s*')[^']*(')/",
          '${1}' . $newVersion . '${2}',
          $configContent
        );
        file_put_contents($configPath, $configContent);
      }
    }

    // ── Log update ──
    $logFile = $projectRoot . '/data/update_log.json';
    $log = [];
    if (is_file($logFile)) {
      $logRaw = file_get_contents($logFile);
      $log = json_decode($logRaw, true);
      if (!is_array($log)) $log = [];
    }
    $failedMigrations = array_filter($migrationResults, function($r) { return !$r['ok']; });
    $log[] = [
      'version'         => $manifest['version'],
      'from_version'    => $manifest['from_version'] ?? APP_VERSION,
      'description'     => $manifest['description'] ?? '',
      'timestamp'       => date('c'),
      'files_count'     => $extractedCount,
      'files'           => (array)$manifest['files'],
      'migrations_run'  => count($migrationResults),
      'migrations_failed' => count($failedMigrations),
      'backup_path'     => str_replace($projectRoot . '/', '', $preBackupPath),
      'applied_by'      => $_SESSION['username'] ?? 'admin',
    ];
    file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Build result message
    $msg = "Update v{$manifest['version']} applied: {$extractedCount} file(s) updated";
    if (!empty($migrationResults)) {
      $msg .= ', ' . count($migrationResults) . ' migration(s) executed';
    }
    if (!empty($failedMigrations)) {
      $failNames = array_map(function($r) { return $r['file']; }, $failedMigrations);
      $msg .= '. WARNING: Failed migrations: ' . implode(', ', $failNames);
      setFlash('error', $msg);
    } else {
      $msg .= '.';
      setFlash('success', $msg);
    }
    redirect(BASE_URL . '/modules/settings/index.php?tab=update');
  }

  // ── Rollback Update ──
  if ($action === 'rollback_update') {
    if (!isAdmin()) {
      setFlash('error', 'Only administrators can rollback updates.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }

    $rollbackIdx = (int)($_POST['rollback_index'] ?? -1);
    $projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../'));
    $logFile = $projectRoot . '/data/update_log.json';
    $log = [];
    if (is_file($logFile)) {
      $log = json_decode(file_get_contents($logFile), true);
      if (!is_array($log)) $log = [];
    }

    if ($rollbackIdx < 0 || !isset($log[$rollbackIdx])) {
      setFlash('error', 'Invalid rollback target.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }

    $entry = $log[$rollbackIdx];
    $backupZipPath = $projectRoot . '/' . ($entry['backup_path'] ?? '');
    if (!is_file($backupZipPath)) {
      setFlash('error', 'Pre-update backup file not found. Cannot rollback.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }

    $zip = new ZipArchive();
    if ($zip->open($backupZipPath) !== true) {
      setFlash('error', 'Cannot open backup ZIP for rollback.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }

    // Restore files
    $restoredCount = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $eName = str_replace('\\', '/', (string)$zip->getNameIndex($i));
      if (strpos($eName, 'files/') === 0 && substr($eName, -1) !== '/') {
        $relPath = substr($eName, 6); // strip 'files/'
        if (strpos($relPath, '..') !== false) continue;
        $content = $zip->getFromIndex($i);
        if ($content === false) continue;
        $absTarget = $projectRoot . '/' . $relPath;
        $targetDir = dirname($absTarget);
        if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
        if (file_put_contents($absTarget, $content) !== false) {
          $restoredCount++;
        }
      }
    }

    // Restore database if backup contains it
    $sqlDump = $zip->getFromName('backup/database.sql');
    $dbRestored = false;
    if ($sqlDump !== false && trim($sqlDump) !== '') {
      if (!isset($db) || !($db instanceof mysqli)) $db = getDB();
      $dbRes = restoreDatabaseFromSql($db, $sqlDump);
      $dbRestored = $dbRes['ok'];
    }

    // Read old version from backup manifest
    $bkManifestJson = $zip->getFromName('manifest.json');
    $zip->close();

    $oldVersion = $entry['from_version'] ?? '';
    if ($bkManifestJson !== false) {
      $bkManifest = json_decode($bkManifestJson, true);
      if (is_array($bkManifest) && !empty($bkManifest['version'])) {
        $oldVersion = $bkManifest['version'];
      }
    }

    // Revert APP_VERSION
    if ($oldVersion !== '') {
      $configPath = $projectRoot . '/config/db.php';
      if (is_file($configPath) && is_writable($configPath)) {
        $configContent = file_get_contents($configPath);
        if ($configContent !== false) {
          $safeVer = preg_replace('/[^a-zA-Z0-9._-]/', '', $oldVersion);
          $configContent = preg_replace(
            "/('APP_VERSION'\s*=>\s*')[^']*(')/",
            '${1}' . $safeVer . '${2}',
            $configContent
          );
          file_put_contents($configPath, $configContent);
        }
      }
    }

    // Mark rollback in log
    $log[$rollbackIdx]['rolled_back']    = true;
    $log[$rollbackIdx]['rolled_back_at'] = date('c');
    $log[$rollbackIdx]['rolled_back_by'] = $_SESSION['username'] ?? 'admin';
    file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $msg = "Rollback complete: {$restoredCount} file(s) restored";
    if ($dbRestored) $msg .= ', database restored';
    $msg .= ", version reverted to {$oldVersion}.";
    setFlash('success', $msg);
    redirect(BASE_URL . '/modules/settings/index.php?tab=update');
  }
}

$filteredLibrary = [];
foreach (($settings['image_library'] ?? []) as $idx => $img) {
  $cat = $img['category'] ?? 'misc';
  if ($libraryFilter === 'all' || $libraryFilter === $cat) {
    $filteredLibrary[$idx] = $img;
  }
}

$backgroundOptions = [];
foreach (($settings['image_library'] ?? []) as $img) {
  if (($img['category'] ?? '') === 'background' && !empty($img['path'])) {
    $backgroundOptions[] = $img;
  }
}

$pageTitle = 'Settings';
include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Master</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Settings</span>
</div>

<div class="page-header">
  <div>
    <h1>Settings</h1>
    <p>Modern configuration panel for company profile, image assets, and visual theme.</p>
    <p style="margin-top:6px;font-size:.85rem;color:#475569">Workspace: <strong><?= e($tenantLabel) ?></strong> | Tenant: <strong><?= e($tenantSlug) ?></strong> | Settings File: <strong><?= e($tenantSettingsDisplayPath) ?></strong></p>
  </div>
</div>

<div class="card settings-card settings-modern">
  <div class="settings-tabs" role="tablist" aria-label="Settings Tabs">
    <a class="settings-tab <?= $activeTab==='company'?'active':'' ?>" href="?tab=company">ERP Profile</a>
    <a class="settings-tab <?= $activeTab==='library'?'active':'' ?>" href="?tab=library">Image Library</a>
    <a class="settings-tab <?= $activeTab==='theme'?'active':'' ?>" href="?tab=theme">Color Theme</a>
    <a class="settings-tab <?= $activeTab==='status-workflow'?'active':'' ?>" href="?tab=status-workflow">Status Workflow</a>
    <a class="settings-tab <?= $activeTab==='tenant'?'active':'' ?>" href="?tab=tenant">Tenant Provision</a>
    <a class="settings-tab <?= $activeTab==='backup'?'active':'' ?>" href="?tab=backup">Backup &amp; Restore</a>
    <a class="settings-tab <?= $activeTab==='update'?'active':'' ?>" href="?tab=update">System Update</a>
  </div>

  <div class="settings-body">
    <?php if ($activeTab === 'company'): ?>
      <style>
      .info-box { background: linear-gradient(135deg, #dbeafe 0%, #eff6ff 100%); border: 1px solid #0284c7; border-radius: 10px; padding: 14px; margin-bottom: 20px; color: #0c4a6e; font-size: .95rem; }
      .info-box strong { color: #0555cc; }
      .profile-section { border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; margin-bottom: 20px; background: #fafbfc; }
      .profile-section h3 { margin: 0 0 14px; font-size: 1.1rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 10px; }
      .profile-section h3 i { font-size: 1.3rem; }
      .profile-section.erp { border-left: 4px solid #6366f1; background: linear-gradient(135deg, #ede9fe 0%, #f3f4f6 100%); }
      </style>

      <div class="info-box">
        <i class="bi bi-info-circle"></i> <strong>ERP Software Settings Only</strong><br>
        Company profile details (name, logo, address, GST, contact) are created separately in the <strong>Tenant Provision</strong> page when setting up a new company workspace.
      </div>

      <form method="POST" enctype="multipart/form-data" class="form-grid-2">
        <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
        <input type="hidden" name="action" value="save_company">

        <!-- ERP PROFILE SECTION ONLY -->
        <div class="profile-section erp col-span-2">
          <h3><i class="bi bi-gear"></i> ERP Software Profile</h3>
          
          <div class="form-grid-2" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div class="form-group col-span-2">
              <label>ERP Display Name</label>
              <input type="text" name="erp_display_name" value="<?= e($settings['erp_display_name'] ?? '') ?>" placeholder="e.g. e-Flexo ERP">
              <small class="help-text">Global ERP title shown on login page and headers.</small>
            </div>

            <div class="form-group">
              <label>ERP Email</label>
              <input type="email" name="erp_email" value="<?= e($settings['erp_email'] ?? '') ?>" placeholder="e.g. support@yourerp.com">
              <small class="help-text">Global ERP support email address.</small>
            </div>
            <div class="form-group">
              <label>ERP Phone</label>
              <input type="text" name="erp_phone" value="<?= e($settings['erp_phone'] ?? '') ?>" placeholder="e.g. +91 9876543210">
              <small class="help-text">Global ERP support phone number.</small>
            </div>

            <div class="form-group col-span-2">
              <label>ERP Address</label>
              <textarea name="erp_address" rows="2" placeholder="ERP vendor/support office address"><?= e($settings['erp_address'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
              <label>ERP GST / Tax ID</label>
              <input type="text" name="erp_gst" value="<?= e($settings['erp_gst'] ?? '') ?>">
              <small class="help-text">ERP vendor's tax identification number.</small>
            </div>

            <div class="form-group col-span-2">
              <label>ERP Logo</label>
              <input type="file" name="erp_logo" accept="image/png,image/jpeg,image/webp,image/gif">
              <small class="help-text">Displayed on ERP login page, header, and sidebar. Each company can override this.</small>
              <?php if (!empty($settings['erp_logo_path'])): ?>
                <div class="settings-preview mt-8"><img src="<?= e(appUrl($settings['erp_logo_path'])) ?>" alt="Current ERP logo"></div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="form-actions col-span-2">
          <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Save ERP Settings</button>
        </div>
      </form>
    <?php endif; ?>

    <?php if ($activeTab === 'library'): ?>
      <?php if ($targetPaperType !== ''): ?>
        <div style="background:#e0f2fe;border:1px solid #0284c7;border-radius:8px;padding:12px 16px;margin-bottom:16px;color:#0c4a6e;font-size:.95rem">
          <i class="bi bi-info-circle"></i> <strong>Assigning image to paper type:</strong> <strong><?= e($targetPaperType) ?></strong> - Click "<strong>Assign to Type</strong>" on any image below, then enter the paper type name.
        </div>
      <?php endif; ?>
      <form method="POST" enctype="multipart/form-data" class="library-upload-bar mb-16">
        <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
        <input type="hidden" name="action" value="upload_library_image">
        <select name="image_category" id="lib-cat-select" required>
          <?php foreach ($libraryCategories as $k => $label): ?>
            <option value="<?= e($k) ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="paper_type_tag" id="paper-type-tag-input" class="form-control" placeholder="Paper type name (e.g. Thermal Paper)" style="display:none;max-width:220px">
        <input type="file" name="library_image" accept="image/png,image/jpeg,image/webp,image/gif" required>
        <button class="btn btn-primary" type="submit"><i class="bi bi-upload"></i> Upload</button>
      </form>
      <script>
      (function(){
        var sel = document.getElementById('lib-cat-select');
        var inp = document.getElementById('paper-type-tag-input');
        function toggle() {
          var isProductType = sel.value === 'product-type';
          inp.style.display = isProductType ? '' : 'none';
          inp.required = isProductType;
          if (!isProductType) inp.value = '';
        }
        sel.addEventListener('change', toggle);
        toggle();
      })();
      </script>

      <div class="library-category-pills mb-16">
        <a href="?tab=library&category=all" class="library-pill <?= $libraryFilter==='all'?'active':'' ?>">All</a>
        <?php foreach ($libraryCategories as $k => $label): ?>
          <a href="?tab=library&category=<?= e($k) ?>" class="library-pill <?= $libraryFilter===$k?'active':'' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>

      <div class="library-grid library-grid-modern">
        <?php foreach ($filteredLibrary as $idx => $img): ?>
          <div class="library-card library-card-modern">
            <div class="library-chip"><?= e($libraryCategories[$img['category'] ?? 'misc'] ?? 'Misc') ?></div>
            <div class="library-thumb"><img src="<?= e(appUrl((string)$img['path'])) ?>" alt="Library image"></div>
            <div class="library-meta">
              <div class="library-name"><?= e((string)($img['name'] ?? 'image')) ?></div>
              <?php if (($img['category'] ?? '') === 'product-type' && !empty($img['paper_type'])): ?>
                <div style="font-size:.72rem;color:#f97316;font-weight:700;margin-top:2px"><i class="bi bi-tag-fill"></i> <?= e($img['paper_type']) ?></div>
              <?php endif; ?>
              <div class="library-time">Uploaded: <?= e((string)($img['uploaded_at'] ?? '')) ?></div>
            <?php if (($img['category'] ?? '') === 'product-type' || !isset($img['category']) || $img['category'] === 'misc'): ?>
              <button type="button" class="btn btn-primary btn-sm" onclick="openPaperTypeModal(<?= (int)$idx ?>)" style="width:100%;margin-top:8px;margin-bottom:8px"><i class="bi bi-tag"></i> Assign to Type</button>
            <?php endif; ?>
            </div>
            <form method="POST" data-confirm="Remove this image?">
              <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
              <input type="hidden" name="action" value="remove_library_image">
              <input type="hidden" name="image_index" value="<?= (int)$idx ?>">
              <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash"></i> Remove</button>
            </form>
          </div>
        <?php endforeach; ?>
        <?php if (empty($filteredLibrary)): ?>
          <div class="empty-state" style="padding:28px 14px">
            <i class="bi bi-images"></i>
            <p>No images found for this category.</p>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($activeTab === 'theme'): ?>
      <form method="POST" class="form-grid-2">
        <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
        <input type="hidden" name="action" value="save_theme">

        <div class="form-group">
          <label>Theme Mode</label>
          <select name="theme_mode">
            <option value="light" <?= ($settings['theme_mode'] ?? 'light')==='light'?'selected':'' ?>>Light Mode</option>
            <option value="dark" <?= ($settings['theme_mode'] ?? 'light')==='dark'?'selected':'' ?>>Dark Mode</option>
          </select>
        </div>

        <div class="form-group">
          <label>Login Background Image</label>
          <select name="login_background_image">
            <option value="">Default Gradient</option>
            <?php foreach ($backgroundOptions as $bg): ?>
              <option value="<?= e((string)$bg['path']) ?>" <?= (($settings['login_background_image'] ?? '') === (string)$bg['path']) ? 'selected' : '' ?>>
                <?= e((string)($bg['name'] ?? 'background')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label>Sidebar Button Color</label>
          <input type="color" name="sidebar_button_color" value="<?= e(normalizeHexColor($settings['sidebar_button_color'] ?? '#22C55E', '#22C55E')) ?>">
        </div>

        <div class="form-group">
          <label>Sidebar Hover Color</label>
          <input type="color" name="sidebar_hover_color" value="<?= e(normalizeHexColor($settings['sidebar_hover_color'] ?? '#263445', '#263445')) ?>">
        </div>

        <div class="form-group">
          <label>Sidebar Active Background</label>
          <input type="color" name="sidebar_active_bg" value="<?= e(normalizeHexColor($settings['sidebar_active_bg'] ?? '#214036', '#214036')) ?>">
        </div>

        <div class="form-group">
          <label>Sidebar Active Text</label>
          <input type="color" name="sidebar_active_text" value="<?= e(normalizeHexColor($settings['sidebar_active_text'] ?? '#BBF7D0', '#BBF7D0')) ?>">
        </div>

        <div class="form-group">
          <label>Sidebar Collapse Delay (ms)</label>
          <input type="number" name="sidebar_collapse_delay_ms" min="300" max="600000" step="100" value="<?= (int)($settings['sidebar_collapse_delay_ms'] ?? 1000) ?>">
          <small>Time in milliseconds after mouse leaves sidebar before it collapses (300-600000, max 10 minutes)</small>
        </div>

        <div class="form-actions col-span-2">
          <button class="btn btn-primary" type="submit"><i class="bi bi-palette"></i> Save Theme</button>
        </div>
      </form>
    <?php endif; ?>

    <?php if ($activeTab === 'status-workflow'): ?>
      <style>
      .status-workflow-shell { display: grid; gap: 14px; }
      .status-hero {
        border: 1px solid #dbeafe;
        background: linear-gradient(120deg, #eff6ff 0%, #ecfeff 100%);
        border-radius: 12px;
        padding: 14px;
      }
      .status-hero h3 { margin: 0 0 5px; color: #0f172a; font-size: 1.05rem; }
      .status-hero p { margin: 0; color: #334155; font-size: .88rem; }
      .status-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        justify-content: space-between;
        align-items: center;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 10px 12px;
        background: #fff;
      }
      .status-toolbar .left,
      .status-toolbar .right { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
      .status-toolbar input[type="text"] { min-width: 210px; }
      .status-section-card {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background: #ffffff;
        overflow: hidden;
      }
      .status-section-head {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        align-items: center;
        padding: 12px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
      }
      .status-section-head h4 { margin: 0; color: #0f172a; font-size: .95rem; }
      .status-section-head p { margin: 3px 0 0; color: #64748b; font-size: .78rem; }
      .status-section-body { padding: 12px; display: grid; gap: 10px; }
      .status-page-card {
        border: 1px solid #dbe2ea;
        border-radius: 12px;
        padding: 10px;
        background: #ffffff;
      }
      .status-page-head {
        display: grid;
        gap: 8px;
        grid-template-columns: 1fr 1.1fr auto;
        margin-bottom: 8px;
      }
      .status-table { width: 100%; border-collapse: collapse; }
      .status-table th,
      .status-table td { border: 1px solid #e2e8f0; padding: 6px; vertical-align: top; font-size: .78rem; }
      .status-table th { background: #f8fafc; text-align: left; color: #334155; }
      .status-table input,
      .status-table textarea { width: 100%; font-size: .78rem; }
      .status-table textarea { min-height: 46px; resize: vertical; }
      .status-badge-preview {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        min-width: 88px;
        height: 28px;
        font-weight: 700;
        font-size: .72rem;
        padding: 0 10px;
      }
      .status-actions-row { display: flex; justify-content: space-between; margin-top: 8px; gap: 8px; }
      .status-muted { color: #64748b; font-size: .76rem; }
      .status-empty {
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        padding: 18px;
        text-align: center;
        color: #64748b;
        font-size: .82rem;
      }
      .status-reference-card {
        border: 1px solid #cbd5e1;
        border-radius: 12px;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        padding: 12px;
      }
      .status-reference-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 8px;
      }
      .status-reference-item {
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 8px;
        background: #fff;
      }
      .status-reference-item p { margin: 4px 0 0; font-size: .75rem; color: #475569; line-height: 1.35; }
      @media (max-width: 900px) {
        .status-page-head { grid-template-columns: 1fr; }
        .status-toolbar { flex-direction: column; align-items: stretch; }
      }
      </style>

      <div class="status-workflow-shell">
        <div class="status-hero">
          <h3><i class="bi bi-diagram-3"></i> Status Workflow Configuration</h3>
          <p>Define exactly which status appears in which page/section, when it should be used, and what color concept it carries. This is your single reference board to reduce status confusion across ERP.</p>
        </div>

        <form method="POST" id="status-workflow-form">
          <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
          <input type="hidden" name="action" value="save_status_workflow">
          <textarea name="status_workflow_json" id="status-workflow-json" style="display:none"></textarea>

          <div class="status-toolbar">
            <div class="left">
              <button type="button" class="btn btn-secondary btn-sm" id="sw-add-section"><i class="bi bi-plus-circle"></i> Add Section</button>
              <button type="button" class="btn btn-light btn-sm" id="sw-reset-default"><i class="bi bi-arrow-counterclockwise"></i> Reset To Default</button>
            </div>
            <div class="right">
              <input type="text" id="sw-search" placeholder="Search page or status...">
              <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Save Status Workflow</button>
            </div>
          </div>

          <div class="status-muted">Tip: Keep each status concept short and business-friendly. Example concept: "QC approved, ready for packing".</div>

          <div class="status-reference-card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px">
              <strong style="font-size:.86rem;color:#0f172a">Global Status Reference (with concept templates)</strong>
              <span class="status-muted">Use page card button "Use Global Statuses" for one-click fill.</span>
            </div>
            <div class="status-reference-grid">
              <?php foreach ($statusWorkflowGlobalFlat as $refStatus): ?>
                <div class="status-reference-item">
                  <span class="status-badge-preview" style="background:<?= e($refStatus['bg_color']) ?>;color:<?= e($refStatus['text_color']) ?>"><?= e($refStatus['label']) ?></span>
                  <p><strong>When:</strong> <?= e($refStatus['when']) ?></p>
                  <p><strong>Concept:</strong> <?= e($refStatus['concept']) ?></p>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div id="sw-sections-root"></div>
        </form>
      </div>

      <script>
      (function () {
        var defaults = <?= json_encode(statusWorkflowDefaults(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        var loaded = <?= json_encode($statusWorkflowSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        var globalReference = <?= json_encode($statusWorkflowGlobalReference, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        var state = JSON.parse(JSON.stringify((loaded && loaded.sections && loaded.sections.length) ? loaded : defaults));

        var root = document.getElementById('sw-sections-root');
        var searchInput = document.getElementById('sw-search');
        var hiddenJson = document.getElementById('status-workflow-json');
        var form = document.getElementById('status-workflow-form');
        var btnAddSection = document.getElementById('sw-add-section');
        var btnResetDefault = document.getElementById('sw-reset-default');

        function escHtml(v) {
          return String(v || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
        }

        function randomKey(prefix) {
          return prefix + '_' + Math.random().toString(36).slice(2, 8);
        }

        function normalizePath(path) {
          return String(path || '').trim().toLowerCase();
        }

        function ensureStateShape() {
          if (!state || typeof state !== 'object') {
            state = JSON.parse(JSON.stringify(defaults));
          }
          if (!Array.isArray(state.sections)) {
            state.sections = [];
          }
        }

        function statusRowMarkup(sIdx, pIdx, stIdx, st) {
          var bg = st.bg_color || '#64748B';
          var tx = st.text_color || '#FFFFFF';
          var label = st.label || st.code || 'Status';
          return '' +
            '<tr data-status-row="1">' +
              '<td><input data-bind="code" data-s="' + sIdx + '" data-p="' + pIdx + '" data-st="' + stIdx + '" type="text" value="' + escHtml(st.code || '') + '" placeholder="Status code"></td>' +
              '<td><input data-bind="label" data-s="' + sIdx + '" data-p="' + pIdx + '" data-st="' + stIdx + '" type="text" value="' + escHtml(st.label || '') + '" placeholder="Label"></td>' +
              '<td><textarea data-bind="when" data-s="' + sIdx + '" data-p="' + pIdx + '" data-st="' + stIdx + '" placeholder="When this status should appear">' + escHtml(st.when || '') + '</textarea></td>' +
              '<td><textarea data-bind="concept" data-s="' + sIdx + '" data-p="' + pIdx + '" data-st="' + stIdx + '" placeholder="Business concept / meaning">' + escHtml(st.concept || '') + '</textarea></td>' +
              '<td><input data-bind="bg_color" data-s="' + sIdx + '" data-p="' + pIdx + '" data-st="' + stIdx + '" type="color" value="' + escHtml(bg) + '"></td>' +
              '<td><input data-bind="text_color" data-s="' + sIdx + '" data-p="' + pIdx + '" data-st="' + stIdx + '" type="color" value="' + escHtml(tx) + '"></td>' +
              '<td><span class="status-badge-preview" style="background:' + escHtml(bg) + ';color:' + escHtml(tx) + '">' + escHtml(label) + '</span></td>' +
              '<td><button type="button" class="btn btn-danger btn-sm" data-remove-status="1" data-s="' + sIdx + '" data-p="' + pIdx + '" data-st="' + stIdx + '"><i class="bi bi-trash"></i></button></td>' +
            '</tr>';
        }

        function pageCardMarkup(sIdx, pIdx, page) {
          var rows = '';
          var list = Array.isArray(page.statuses) ? page.statuses : [];
          var canUseGlobal = !!globalReference[normalizePath(page.page_path || '')];
          for (var i = 0; i < list.length; i += 1) {
            rows += statusRowMarkup(sIdx, pIdx, i, list[i]);
          }
          if (!rows) {
            rows = '<tr><td colspan="8" class="status-empty">No statuses in this page yet.</td></tr>';
          }

          return '' +
            '<div class="status-page-card" data-page-card="1">' +
              '<div class="status-page-head">' +
                '<input data-page-bind="page_name" data-s="' + sIdx + '" data-p="' + pIdx + '" type="text" value="' + escHtml(page.page_name || '') + '" placeholder="Page name (e.g. Paperroll Planning)">' +
                '<input data-page-bind="page_path" data-s="' + sIdx + '" data-p="' + pIdx + '" type="text" value="' + escHtml(page.page_path || '') + '" placeholder="Path (e.g. /modules/planning/paperroll/index.php)">' +
                '<button type="button" class="btn btn-danger btn-sm" data-remove-page="1" data-s="' + sIdx + '" data-p="' + pIdx + '"><i class="bi bi-trash"></i> Remove Page</button>' +
              '</div>' +
              '<table class="status-table">' +
                '<thead>' +
                  '<tr>' +
                    '<th style="width:12%">Code</th>' +
                    '<th style="width:12%">Label</th>' +
                    '<th style="width:24%">When To Use</th>' +
                    '<th style="width:24%">Concept</th>' +
                    '<th style="width:7%">BG</th>' +
                    '<th style="width:7%">Text</th>' +
                    '<th style="width:10%">Preview</th>' +
                    '<th style="width:4%"></th>' +
                  '</tr>' +
                '</thead>' +
                '<tbody>' + rows + '</tbody>' +
              '</table>' +
              '<div class="status-actions-row">' +
                '<div class="status-muted">Page key: ' + escHtml(page.page_key || '') + '</div>' +
                '<div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">' +
                  (canUseGlobal ? '<button type="button" class="btn btn-light btn-sm" data-use-global-statuses="1" data-s="' + sIdx + '" data-p="' + pIdx + '"><i class="bi bi-stars"></i> Use Global Statuses</button>' : '') +
                  '<button type="button" class="btn btn-secondary btn-sm" data-add-status="1" data-s="' + sIdx + '" data-p="' + pIdx + '"><i class="bi bi-plus"></i> Add Status</button>' +
                '</div>' +
              '</div>' +
            '</div>';
        }

        function sectionCardMarkup(sIdx, section) {
          var pagesHtml = '';
          var pages = Array.isArray(section.pages) ? section.pages : [];
          for (var i = 0; i < pages.length; i += 1) {
            pagesHtml += pageCardMarkup(sIdx, i, pages[i]);
          }
          if (!pagesHtml) {
            pagesHtml = '<div class="status-empty">No pages in this section yet.</div>';
          }

          return '' +
            '<section class="status-section-card" data-section-card="1">' +
              '<div class="status-section-head">' +
                '<div>' +
                  '<h4>' + escHtml(section.section_name || '') + '</h4>' +
                  '<p>' + escHtml(section.description || '') + '</p>' +
                '</div>' +
                '<div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">' +
                  '<button type="button" class="btn btn-secondary btn-sm" data-add-page="1" data-s="' + sIdx + '"><i class="bi bi-plus"></i> Add Page</button>' +
                  '<button type="button" class="btn btn-danger btn-sm" data-remove-section="1" data-s="' + sIdx + '"><i class="bi bi-trash"></i> Remove Section</button>' +
                '</div>' +
              '</div>' +
              '<div class="status-section-body">' +
                '<div class="status-page-head" style="margin-bottom:2px;grid-template-columns: 1fr 1fr;">' +
                  '<input data-section-bind="section_name" data-s="' + sIdx + '" type="text" value="' + escHtml(section.section_name || '') + '" placeholder="Section name (e.g. Planning)">' +
                  '<input data-section-bind="description" data-s="' + sIdx + '" type="text" value="' + escHtml(section.description || '') + '" placeholder="Section description">' +
                '</div>' +
                '<div class="status-muted">Section key: ' + escHtml(section.section_key || '') + '</div>' +
                '<div data-pages-wrapper="1">' + pagesHtml + '</div>' +
              '</div>' +
            '</section>';
        }

        function render() {
          ensureStateShape();
          var html = '';
          for (var i = 0; i < state.sections.length; i += 1) {
            html += sectionCardMarkup(i, state.sections[i]);
          }
          if (!html) {
            html = '<div class="status-empty">No workflow sections found. Click "Add Section" to begin.</div>';
          }
          root.innerHTML = html;
          applyFilter();
        }

        function applyFilter() {
          var q = (searchInput.value || '').trim().toLowerCase();
          var sections = root.querySelectorAll('[data-section-card="1"]');
          sections.forEach(function (sec) {
            if (!q) {
              sec.style.display = '';
              return;
            }
            var txt = (sec.textContent || '').toLowerCase();
            sec.style.display = txt.indexOf(q) >= 0 ? '' : 'none';
          });
        }

        function addSection() {
          state.sections.push({
            section_key: randomKey('section'),
            section_name: 'New Section',
            description: 'Describe this stage group',
            pages: [{
              page_key: randomKey('page'),
              page_name: 'New Page',
              page_path: '',
              statuses: [{ code: 'Pending', label: 'Pending', when: '', concept: '', bg_color: '#64748B', text_color: '#FFFFFF' }]
            }]
          });
          render();
        }

        function buildSubmitPayload() {
          hiddenJson.value = JSON.stringify(state);
        }

        root.addEventListener('input', function (ev) {
          var t = ev.target;
          var s = parseInt(t.getAttribute('data-s') || '-1', 10);
          var p = parseInt(t.getAttribute('data-p') || '-1', 10);
          var st = parseInt(t.getAttribute('data-st') || '-1', 10);

          var secBind = t.getAttribute('data-section-bind');
          if (secBind && state.sections[s]) {
            state.sections[s][secBind] = t.value;
            return;
          }

          var pageBind = t.getAttribute('data-page-bind');
          if (pageBind && state.sections[s] && state.sections[s].pages && state.sections[s].pages[p]) {
            state.sections[s].pages[p][pageBind] = t.value;
            return;
          }

          var bind = t.getAttribute('data-bind');
          if (bind && state.sections[s] && state.sections[s].pages && state.sections[s].pages[p] && state.sections[s].pages[p].statuses && state.sections[s].pages[p].statuses[st]) {
            state.sections[s].pages[p].statuses[st][bind] = t.value;
            if (bind === 'label' || bind === 'bg_color' || bind === 'text_color') {
              render();
            }
          }
        });

        root.addEventListener('click', function (ev) {
          var btn = ev.target.closest('button');
          if (!btn) return;

          var s = parseInt(btn.getAttribute('data-s') || '-1', 10);
          var p = parseInt(btn.getAttribute('data-p') || '-1', 10);
          var st = parseInt(btn.getAttribute('data-st') || '-1', 10);

          if (btn.hasAttribute('data-remove-section')) {
            state.sections.splice(s, 1);
            render();
            return;
          }
          if (btn.hasAttribute('data-add-page') && state.sections[s]) {
            state.sections[s].pages = state.sections[s].pages || [];
            state.sections[s].pages.push({
              page_key: randomKey('page'),
              page_name: 'New Page',
              page_path: '',
              statuses: [{ code: 'Pending', label: 'Pending', when: '', concept: '', bg_color: '#64748B', text_color: '#FFFFFF' }]
            });
            render();
            return;
          }
          if (btn.hasAttribute('data-remove-page') && state.sections[s] && state.sections[s].pages) {
            state.sections[s].pages.splice(p, 1);
            render();
            return;
          }
          if (btn.hasAttribute('data-add-status') && state.sections[s] && state.sections[s].pages && state.sections[s].pages[p]) {
            state.sections[s].pages[p].statuses = state.sections[s].pages[p].statuses || [];
            state.sections[s].pages[p].statuses.push({ code: 'New Status', label: 'New Status', when: '', concept: '', bg_color: '#64748B', text_color: '#FFFFFF' });
            render();
            return;
          }
          if (btn.hasAttribute('data-use-global-statuses') && state.sections[s] && state.sections[s].pages && state.sections[s].pages[p]) {
            var pagePath = normalizePath(state.sections[s].pages[p].page_path || '');
            var refRows = globalReference[pagePath] || [];
            if (!Array.isArray(refRows) || !refRows.length) {
              window.alert('No global status reference mapped for this page path yet.');
              return;
            }
            state.sections[s].pages[p].statuses = JSON.parse(JSON.stringify(refRows));
            render();
            return;
          }
          if (btn.hasAttribute('data-remove-status') && state.sections[s] && state.sections[s].pages && state.sections[s].pages[p] && state.sections[s].pages[p].statuses) {
            state.sections[s].pages[p].statuses.splice(st, 1);
            render();
          }
        });

        btnAddSection.addEventListener('click', addSection);
        btnResetDefault.addEventListener('click', function () {
          if (!window.confirm('Reset workflow matrix to default values?')) {
            return;
          }
          state = JSON.parse(JSON.stringify(defaults));
          render();
        });
        searchInput.addEventListener('input', applyFilter);
        form.addEventListener('submit', function () {
          buildSubmitPayload();
        });

        render();
      })();
      </script>
    <?php endif; ?>

    <?php if ($activeTab === 'tenant'): ?>
      <style>
      .tenant-shell { display:grid; grid-template-columns:1.4fr .8fr; gap:16px; }
      .tenant-hero {
        border:1px solid #c7d2fe;
        background:linear-gradient(135deg,#eef2ff 0%, #ecfeff 100%);
        border-radius:14px;
        padding:14px 16px;
        margin-bottom:14px;
      }
      .tenant-hero h3 { margin:0 0 4px; color:#1e1b4b; font-size:1rem; }
      .tenant-hero p { margin:0; color:#334155; font-size:.85rem; }
      .tenant-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
      .tenant-group { border:1px solid #e2e8f0; background:#fff; border-radius:12px; padding:12px; }
      .tenant-group h4 { margin:0 0 10px; font-size:.82rem; text-transform:uppercase; letter-spacing:.05em; color:#475569; }
      .tenant-field { margin-bottom:10px; }
      .tenant-field:last-child { margin-bottom:0; }
      .tenant-field label { display:block; font-size:.76rem; font-weight:700; color:#334155; margin-bottom:4px; }
      .tenant-field input { width:100%; }
      .tenant-field small { color:#64748b; font-size:.72rem; display:block; margin-top:4px; }
      .tenant-check { border:1px solid #e2e8f0; background:#f8fafc; border-radius:10px; padding:10px 12px; font-size:.82rem; color:#334155; }
      .tenant-note { border:1px solid #bfdbfe; background:linear-gradient(180deg,#eff6ff 0%,#f8fbff 100%); border-radius:12px; padding:12px; margin-bottom:12px; }
      .tenant-note h4 { margin:0 0 8px; font-size:.84rem; color:#1d4ed8; text-transform:uppercase; letter-spacing:.05em; }
      .tenant-note ol { margin:0; padding-left:18px; color:#334155; font-size:.82rem; line-height:1.55; }
      .tenant-meta-card { border:1px solid #e2e8f0; border-radius:12px; background:#fff; overflow:hidden; }
      .tenant-meta-head { padding:10px 12px; background:#f8fafc; border-bottom:1px solid #e2e8f0; font-weight:700; font-size:.82rem; color:#0f172a; }
      .tenant-meta-body { padding:10px 12px; }
      .tenant-meta-row { display:flex; gap:8px; justify-content:space-between; padding:6px 0; font-size:.8rem; border-bottom:1px dashed #e2e8f0; }
      .tenant-meta-row:last-child { border-bottom:none; }
      .tenant-actions { margin-top:12px; display:flex; justify-content:flex-start; }
      @media (max-width: 980px) {
        .tenant-shell { grid-template-columns:1fr; }
        .tenant-grid { grid-template-columns:1fr; }
      }
      </style>

      <div class="tenant-hero">
        <h3><i class="bi bi-diagram-3"></i> Provision New Company Workspace</h3>
        <p>Create a new branded tenant with isolated database, auto schema/migration setup, default admin, settings file, and asset folders.</p>
      </div>

      <div class="tenant-shell">
        <form method="POST" enctype="multipart/form-data" data-confirm="Provision this tenant now?">
          <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
          <input type="hidden" name="action" value="provision_tenant">

          <div class="tenant-grid">
            <div class="tenant-group">
              <h4>Identity</h4>
              <div class="tenant-field">
                <label>Tenant Slug</label>
                <input type="text" id="tenant_slug" name="tenant_slug" required placeholder="e.g. ram">
                <small>Use lowercase letters, numbers, dot, underscore, hyphen.</small>
              </div>
              <div class="tenant-field">
                <label>Tenant Label</label>
                <input type="text" name="tenant_label" required placeholder="e.g. Ram Company">
              </div>
              <div class="tenant-field">
                <label>Company Legal Name</label>
                <input type="text" name="tenant_company_name" required placeholder="e.g. Ram Flexible Packaging Pvt Ltd">
              </div>
              <div class="tenant-field">
                <label>ERP Display Name</label>
                <input type="text" name="tenant_erp_display_name" placeholder="e.g. e-Flexo for Ram">
              </div>
            </div>

            <div class="tenant-group">
              <h4>Routing</h4>
              <div class="tenant-field">
                <label>Hosts / Subdomains</label>
                <input type="text" name="tenant_hosts" placeholder="e.g. ram.localhost, ram.example.com">
                <small>Comma separated values.</small>
              </div>
              <div class="tenant-field">
                <label>Path Prefixes</label>
                <input type="text" id="tenant_path_prefixes" name="tenant_path_prefixes" placeholder="e.g. /ram">
                <small>Fallback routing when host mapping is unavailable.</small>
              </div>
            </div>

            <div class="tenant-group">
              <h4>Database</h4>
              <div class="tenant-field">
                <label>DB Host</label>
                <input type="text" name="db_host" value="localhost" required>
              </div>
              <div class="tenant-field">
                <label>DB Port</label>
                <input type="number" name="db_port" value="3306" min="1" max="65535" required>
              </div>
              <div class="tenant-field">
                <label>DB Name</label>
                <input type="text" name="db_name" required placeholder="e.g. ram_erp">
              </div>
              <div class="tenant-field">
                <label>DB User</label>
                <input type="text" name="db_user" required>
              </div>
              <div class="tenant-field">
                <label>DB Password</label>
                <input type="password" name="db_pass" placeholder="Database password">
              </div>
              <label class="tenant-check"><input type="checkbox" name="create_database" value="1" checked> Create database if missing</label>
            </div>

            <div class="tenant-group">
              <h4>Default Admin</h4>
              <div class="tenant-field">
                <label>Admin Name</label>
                <input type="text" name="admin_name" value="System Admin" required>
              </div>
              <div class="tenant-field">
                <label>Admin Email</label>
                <input type="email" name="admin_email" value="admin@example.com" required>
              </div>
              <div class="tenant-field">
                <label>Admin Password</label>
                <input type="password" name="admin_password" value="admin123" minlength="6" required>
              </div>
            </div>

            <div class="tenant-group">
              <h4><i class="bi bi-building"></i> Company Profile</h4>
              <div class="tenant-field">
                <label>Company Legal Name</label>
                <input type="text" name="tenant_company_name_legal" placeholder="e.g. Ram Flexible Packaging Private Limited">
                <small>Actual company name registered with government/tax authorities.</small>
              </div>
              <div class="tenant-field">
                <label>Contact Person / Owner Name</label>
                <input type="text" name="tenant_contact_person" placeholder="e.g. Raj Kumar Sharma">
                <small>Primary contact person for this company.</small>
              </div>
              <div class="tenant-field">
                <label>Company Email</label>
                <input type="email" name="tenant_company_email" placeholder="e.g. contact@company.com">
              </div>
              <div class="tenant-field">
                <label>Company Phone</label>
                <input type="text" name="tenant_company_phone" placeholder="e.g. +91 9876543210">
              </div>
              <div class="tenant-field">
                <label>Currency</label>
                <input type="text" name="tenant_company_currency" value="INR" placeholder="e.g. INR, USD">
              </div>
              <div class="tenant-field">
                <label>GST / Tax ID</label>
                <input type="text" name="tenant_company_gst" placeholder="e.g. 27AAFCD5055K1Z0">
              </div>
            </div>

            <div class="tenant-group">
              <h4><i class="bi bi-map"></i> Address & Branding</h4>
              <div class="tenant-field">
                <label>Company Address</label>
                <textarea name="tenant_company_address" rows="2" placeholder="Street address, city, state, PIN code"></textarea>
              </div>
              <div class="tenant-field">
                <label>Company Logo (JPG/PNG)</label>
                <input type="file" name="tenant_company_logo" accept="image/png,image/jpeg,image/webp,image/gif">
                <small>Logo to be used for company documents and exports. Optional - can be uploaded later.</small>
              </div>
              <div class="tenant-field">
                <label>Flag Emoji Fallback</label>
                <input type="text" name="tenant_flag_emoji" value="🇮🇳" maxlength="4" placeholder="e.g. 🇮🇳">
                <small>Country flag emoji for branding.</small>
              </div>
            </div>
          </div>

          <div class="tenant-actions">
            <button class="btn btn-primary" type="submit"><i class="bi bi-plus-circle"></i> Provision Tenant</button>
          </div>
        </form>

        <div>
          <div class="tenant-note">
            <h4>How To Use</h4>
            <ol>
              <li>In Identity, enter tenant slug, tenant label, and legal company name.</li>
              <li>If host mapping is ready, enter Hosts; otherwise provide a path prefix like /ram.</li>
              <li>Provide database host, port, name, user, and password for this company.</li>
              <li>Set default admin name, email, and password for first login.</li>
              <li>Click Provision Tenant to create DB schema, run migrations, and seed admin/settings.</li>
              <li>Open the configured tenant URL and complete company branding from profile settings.</li>
            </ol>
          </div>

          <div class="tenant-meta-card">
            <div class="tenant-meta-head">Provisioning Target</div>
            <div class="tenant-meta-body">
              <div class="tenant-meta-row"><span>Registry File</span><strong><?= e(str_replace($projectRoot . '/', '', str_replace('\\', '/', $tenantRegistryPath))) ?></strong></div>
              <div class="tenant-meta-row"><span>Schema Source</span><strong>database/schema.sql + pending_migrations/*.sql</strong></div>
              <div class="tenant-meta-row"><span>Asset Folders</span><strong>uploads/company/{slug}, uploads/library/{slug}</strong></div>
              <div class="tenant-meta-row"><span>Settings Path</span><strong>data/tenants/{slug}/app_settings.json</strong></div>
            </div>
          </div>
        </div>
      </div>

      <script>
      (function() {
        var slugInput = document.getElementById('tenant_slug');
        var prefixInput = document.getElementById('tenant_path_prefixes');
        if (!slugInput || !prefixInput) return;
        slugInput.addEventListener('input', function() {
          var clean = (slugInput.value || '')
            .toLowerCase()
            .replace(/[^a-z0-9._-]+/g, '-')
            .replace(/^-+|-+$/g, '');
          slugInput.value = clean;
          if ((prefixInput.value || '').trim() === '' && clean !== '') {
            prefixInput.value = '/' + clean;
          }
        });
      })();
      </script>
    <?php endif; ?>

    <?php if ($activeTab === 'backup'): ?>
      <div class="backup-banner mb-16">
        <i class="bi bi-shield-lock"></i>
        <div>
          <strong>Database Safety Zone</strong>
          <p>Create full SQL backups before restore operations. Restore replaces existing data.</p>
        </div>
      </div>

      <div class="backup-grid">
        <div class="backup-panel">
          <h3><i class="bi bi-download"></i> Backup Database</h3>
          <p>Download full backup package: database SQL + image library + company logo/settings.</p>
          <form method="POST" class="mt-12">
            <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
            <input type="hidden" name="action" value="download_backup">
            <button class="btn btn-primary" type="submit"><i class="bi bi-cloud-arrow-down"></i> Download Full Backup (.zip)</button>
          </form>
        </div>

        <div class="backup-panel backup-danger">
          <h3><i class="bi bi-upload"></i> Restore Database</h3>
          <p>Restore from full backup (.zip) or SQL (.sql). ZIP restore also restores company/library images.</p>
          <form method="POST" enctype="multipart/form-data" class="mt-12" data-confirm="This will restore database data. Continue?">
            <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
            <input type="hidden" name="action" value="restore_backup">
            <input type="file" name="backup_file" accept=".zip,.sql" required>
            <div class="mt-12">
              <button class="btn btn-danger" type="submit"><i class="bi bi-cloud-arrow-up"></i> Restore from Backup</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card mt-16">
        <div class="card-header"><span class="card-title">System Information</span></div>
        <div class="card-body">
          <table style="font-size:.82rem">
            <tbody>
              <tr><td class="text-muted" style="padding:5px 0">PHP Version</td><td class="fw-600"><?= phpversion() ?></td></tr>
              <tr><td class="text-muted" style="padding:5px 0">MySQL</td><td class="fw-600"><?= $db->server_info ?></td></tr>
              <tr><td class="text-muted" style="padding:5px 0">Database Name</td><td class="fw-600"><?= e(DB_NAME) ?></td></tr>
              <tr><td class="text-muted" style="padding:5px 0">App Version</td><td class="fw-600"><?= e(APP_VERSION) ?></td></tr>
              <tr><td class="text-muted" style="padding:5px 0">Server Time</td><td class="fw-600"><?= date('d M Y H:i') ?></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($activeTab === 'update'): ?>
      <?php
        // Load update log
        $updateLogFile = realpath(__DIR__ . '/../../') . '/data/update_log.json';
        $updateLog = [];
        if (is_file($updateLogFile)) {
          $raw = file_get_contents($updateLogFile);
          $updateLog = json_decode($raw, true);
          if (!is_array($updateLog)) $updateLog = [];
        }
        $lastUpdate = !empty($updateLog) ? end($updateLog) : null;
      ?>

      <!-- Version Banner -->
      <div class="upd-version-banner">
        <div class="upd-version-main">
          <div class="upd-version-icon"><i class="bi bi-box-seam"></i></div>
          <div>
            <span class="upd-version-label">Current Version</span>
            <span class="upd-version-number"><?= e(APP_VERSION) ?></span>
          </div>
        </div>
        <div class="upd-version-meta">
          <?php if ($lastUpdate): ?>
            <span><i class="bi bi-clock-history"></i> Last updated: <?= e(date('d M Y, H:i', strtotime($lastUpdate['timestamp'] ?? ''))) ?></span>
            <span><i class="bi bi-person"></i> By: <?= e($lastUpdate['applied_by'] ?? 'admin') ?></span>
          <?php else: ?>
            <span><i class="bi bi-info-circle"></i> No updates applied yet</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="backup-grid mt-16">
        <!-- Upload Update Panel -->
        <div class="backup-panel">
          <h3><i class="bi bi-cloud-arrow-up"></i> Apply Update</h3>
          <p>Upload an update package (.zip) created with the build tool. A backup is created automatically before applying.</p>
          <form method="POST" enctype="multipart/form-data" class="mt-12" data-confirm="Apply this update? A backup will be created automatically before changes are applied.">
            <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
            <input type="hidden" name="action" value="apply_update">
            <div class="upd-upload-zone" id="upd-drop-zone">
              <input type="file" name="update_file" id="upd-file-input" accept=".zip" required>
              <div class="upd-upload-placeholder" id="upd-placeholder">
                <i class="bi bi-file-earmark-zip" style="font-size:28px;color:#3b82f6"></i>
                <span>Choose update ZIP or drag &amp; drop</span>
                <small>Max 50 MB &middot; .zip only</small>
              </div>
              <div class="upd-upload-selected" id="upd-selected" style="display:none">
                <i class="bi bi-file-earmark-check" style="font-size:24px;color:#16a34a"></i>
                <span id="upd-file-name"></span>
                <small id="upd-file-size"></small>
              </div>
            </div>
            <div class="mt-12">
              <button class="btn btn-primary" type="submit"><i class="bi bi-rocket-takeoff"></i> Apply Update</button>
            </div>
          </form>
        </div>

        <!-- Package Builder Info -->
        <div class="backup-panel" style="background:linear-gradient(135deg,#f8fafc 0%,#eef6ff 100%)">
          <h3><i class="bi bi-tools"></i> Build Update Package</h3>
          <p>Use the CLI tool to create update packages from your git changes.</p>
          <div class="upd-code-block mt-12">
            <code>cd <?= e(realpath(__DIR__ . '/../../') ?: __DIR__ . '/../../') ?></code>
            <code>php build_update.php</code>
          </div>
          <div class="upd-steps mt-12">
            <div class="upd-step"><span class="upd-step-num">1</span><span>Make changes in VS Code &amp; commit to Git</span></div>
            <div class="upd-step"><span class="upd-step-num">2</span><span>Run <strong>php build_update.php</strong> in terminal</span></div>
            <div class="upd-step"><span class="upd-step-num">3</span><span>Choose version &amp; select changed files</span></div>
            <div class="upd-step"><span class="upd-step-num">4</span><span>Upload the generated ZIP here</span></div>
          </div>
          <div class="mt-12" style="font-size:.82rem;color:#64748b">
            <strong>Package format:</strong> manifest.json + files/ + migrations/<br>
            <strong>SQL migrations:</strong> Place .sql files in <code>pending_migrations/</code> before building
          </div>
        </div>
      </div>

      <!-- Update History -->
      <div class="card mt-16">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
          <span class="card-title"><i class="bi bi-clock-history"></i> Update History</span>
          <span class="badge badge-draft"><?= count($updateLog) ?> update(s)</span>
        </div>
        <div class="card-body" style="overflow-x:auto">
          <?php if (empty($updateLog)): ?>
            <div class="table-empty"><i class="bi bi-inbox"></i>No updates have been applied yet.</div>
          <?php else: ?>
            <table style="font-size:.82rem;width:100%">
              <thead>
                <tr>
                  <th style="padding:8px 6px">#</th>
                  <th style="padding:8px 6px">Version</th>
                  <th style="padding:8px 6px">Description</th>
                  <th style="padding:8px 6px">Date</th>
                  <th style="padding:8px 6px">Files</th>
                  <th style="padding:8px 6px">Migrations</th>
                  <th style="padding:8px 6px">Applied By</th>
                  <th style="padding:8px 6px">Status</th>
                  <th style="padding:8px 6px">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (array_reverse($updateLog, true) as $idx => $entry): ?>
                  <tr>
                    <td style="padding:6px"><?= (int)$idx + 1 ?></td>
                    <td style="padding:6px">
                      <strong>v<?= e($entry['version'] ?? '') ?></strong>
                      <br><small class="text-muted">from v<?= e($entry['from_version'] ?? '') ?></small>
                    </td>
                    <td style="padding:6px"><?= e($entry['description'] ?? '—') ?></td>
                    <td style="padding:6px;white-space:nowrap"><?= e(date('d M Y H:i', strtotime($entry['timestamp'] ?? ''))) ?></td>
                    <td style="padding:6px;text-align:center"><?= (int)($entry['files_count'] ?? 0) ?></td>
                    <td style="padding:6px;text-align:center">
                      <?= (int)($entry['migrations_run'] ?? 0) ?>
                      <?php if (($entry['migrations_failed'] ?? 0) > 0): ?>
                        <span class="badge badge-cancelled"><?= (int)$entry['migrations_failed'] ?> failed</span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:6px"><?= e($entry['applied_by'] ?? 'admin') ?></td>
                    <td style="padding:6px">
                      <?php if (!empty($entry['rolled_back'])): ?>
                        <span class="badge badge-cancelled">Rolled Back</span>
                      <?php else: ?>
                        <span class="badge badge-available">Applied</span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:6px">
                      <?php if (empty($entry['rolled_back']) && !empty($entry['backup_path'])): ?>
                        <form method="POST" style="margin:0" data-confirm="Rollback this update? This will restore files and database to the pre-update state.">
                          <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
                          <input type="hidden" name="action" value="rollback_update">
                          <input type="hidden" name="rollback_index" value="<?= (int)$idx ?>">
                          <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Rollback</button>
                        </form>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <style>
      .upd-version-banner {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 18px 22px;
        border-radius: 16px;
        color: #fff;
        background: linear-gradient(135deg, #0f172a 0%, #1e40af 55%, #0ea5e9 100%);
        box-shadow: 0 12px 28px rgba(29, 78, 216, .2);
      }
      .upd-version-main { display: flex; align-items: center; gap: 14px; }
      .upd-version-icon {
        width: 48px; height: 48px;
        display: grid; place-items: center;
        background: rgba(255,255,255,.15);
        border-radius: 12px;
        font-size: 22px;
        backdrop-filter: blur(6px);
      }
      .upd-version-label {
        display: block;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .1em;
        opacity: .8;
      }
      .upd-version-number {
        display: block;
        font-size: 24px;
        font-weight: 800;
        letter-spacing: -.02em;
        margin-top: 2px;
      }
      .upd-version-meta {
        display: flex;
        flex-direction: column;
        gap: 4px;
        font-size: .82rem;
        opacity: .85;
        text-align: right;
      }
      .upd-upload-zone {
        position: relative;
        border: 2px dashed #cbd5e1;
        border-radius: 12px;
        padding: 24px;
        text-align: center;
        transition: .18s ease;
        background: #fafbfc;
        cursor: pointer;
      }
      .upd-upload-zone.drag-over {
        border-color: #3b82f6;
        background: #eff6ff;
      }
      .upd-upload-zone input[type="file"] {
        position: absolute;
        inset: 0;
        opacity: 0;
        cursor: pointer;
        width: 100%;
        height: 100%;
      }
      .upd-upload-placeholder,
      .upd-upload-selected {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
        pointer-events: none;
      }
      .upd-upload-placeholder span,
      .upd-upload-selected span { font-weight: 600; color: #334155; font-size: .92rem; }
      .upd-upload-placeholder small,
      .upd-upload-selected small { color: #94a3b8; font-size: .78rem; }
      .upd-code-block {
        background: #0f172a;
        color: #e2e8f0;
        border-radius: 10px;
        padding: 14px 16px;
        font-family: 'Consolas', 'Courier New', monospace;
        font-size: .82rem;
        line-height: 1.7;
      }
      .upd-code-block code {
        display: block;
        color: #67e8f9;
      }
      .upd-code-block code::before {
        content: '> ';
        color: #64748b;
      }
      .upd-steps { display: grid; gap: 10px; }
      .upd-step {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: .85rem;
        color: #334155;
      }
      .upd-step-num {
        width: 24px; height: 24px;
        display: grid; place-items: center;
        background: #dbeafe;
        color: #1d4ed8;
        border-radius: 50%;
        font-size: .72rem;
        font-weight: 800;
        flex-shrink: 0;
      }
      @media (max-width: 720px) {
        .upd-version-banner { flex-direction: column; align-items: flex-start; }
        .upd-version-meta { text-align: left; }
      }
      </style>

      <script>
      (function(){
        var fileInput = document.getElementById('upd-file-input');
        var placeholder = document.getElementById('upd-placeholder');
        var selected = document.getElementById('upd-selected');
        var fileNameEl = document.getElementById('upd-file-name');
        var fileSizeEl = document.getElementById('upd-file-size');
        var dropZone = document.getElementById('upd-drop-zone');

        if (fileInput) {
          fileInput.addEventListener('change', function(){
            if (fileInput.files && fileInput.files[0]) {
              var f = fileInput.files[0];
              placeholder.style.display = 'none';
              selected.style.display = '';
              fileNameEl.textContent = f.name;
              fileSizeEl.textContent = (f.size / 1024 / 1024).toFixed(2) + ' MB';
            } else {
              placeholder.style.display = '';
              selected.style.display = 'none';
            }
          });
        }

        if (dropZone) {
          ['dragenter','dragover'].forEach(function(ev){
            dropZone.addEventListener(ev, function(e){ e.preventDefault(); dropZone.classList.add('drag-over'); });
          });
          ['dragleave','drop'].forEach(function(ev){
            dropZone.addEventListener(ev, function(e){ e.preventDefault(); dropZone.classList.remove('drag-over'); });
          });
          dropZone.addEventListener('drop', function(e){
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
              fileInput.files = e.dataTransfer.files;
              fileInput.dispatchEvent(new Event('change'));
            }
          });
        }
      })();
      </script>
    <?php endif; ?>
  </div>
</div>

<!-- Paper Type Assignment Modal -->
<div id="paperTypeModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:24px;width:90%;max-width:400px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1)">
    <h3 style="margin:0 0 16px;font-size:1.1rem">Assign to Paper Type</h3>
    <form method="POST" id="assignPaperTypeForm">
      <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
      <input type="hidden" name="action" value="assign_image_to_papertype">
      <input type="hidden" name="image_index" id="modalImageIndex" value="">
      
      <div style="margin-bottom:16px">
        <label style="display:block;font-weight:600;margin-bottom:8px;color:#334155">Paper Type Name</label>
        <input type="text" name="paper_type" id="modalPaperType" placeholder="e.g., Thermal Paper, Chromo Matt" style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:.95rem" required>
      </div>
      
      <div style="display:flex;gap:12px;justify-content:space-between">
        <button type="button" onclick="closePaperTypeModal()" style="flex:1;padding:10px;border:1px solid #e2e8f0;background:#fff;border-radius:6px;cursor:pointer;font-weight:600">Cancel</button>
        <button type="submit" style="flex:1;padding:10px;background:#3b82f6;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600">Assign</button>
      </div>
    </form>
  </div>
</div>

<script>
let currentImageIndex = null;

function openPaperTypeModal(imageIndex) {
  currentImageIndex = imageIndex;
  document.getElementById('modalImageIndex').value = imageIndex;
  document.getElementById('modalPaperType').value = '<?= e($targetPaperType) ?>';
  document.getElementById('paperTypeModal').style.display = 'flex';
  document.getElementById('modalPaperType').focus();
}

function closePaperTypeModal() {
  document.getElementById('paperTypeModal').style.display = 'none';
  currentImageIndex = null;
}

// Close modal when clicking outside
document.getElementById('paperTypeModal')?.addEventListener('click', function(e) {
  if (e.target === this) closePaperTypeModal();
});

// Handle form submission
document.getElementById('assignPaperTypeForm')?.addEventListener('submit', function(e) {
  const paperType = document.getElementById('modalPaperType').value.trim();
  if (!paperType) {
    e.preventDefault();
    alert('Please enter a paper type name');
    return;
  }
  // Form will submit naturally
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>