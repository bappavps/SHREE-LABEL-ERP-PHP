<?php
// ============================================================
// AI Agent Plugin — Knowledge Base & Settings CRUD API
// Admin-Only — Handles all knowledge training and provider config
// ============================================================

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

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
$action = trim($_REQUEST['action'] ?? '');

// Ensure ai_agent_knowledge table exists
$db->query("CREATE TABLE IF NOT EXISTS `ai_agent_knowledge` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `category` ENUM('FAQ','Business Rule','Terminology','Quick Chip') NOT NULL DEFAULT 'FAQ',
  `keywords` TEXT NOT NULL,
  `question` VARCHAR(500) NULL,
  `answer` TEXT NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_by` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

// ─── LIST (any authenticated user can view) ───
if ($action === 'list') {
    $category = trim($_REQUEST['category'] ?? '');
    $sql = "SELECT * FROM ai_agent_knowledge";
    $params = [];
    $types = '';

    if ($category !== '' && $category !== 'all') {
        $sql .= " WHERE category = ?";
        $params[] = $category;
        $types .= 's';
    }
    $sql .= " ORDER BY sort_order ASC, id DESC";

    if (!empty($params)) {
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $res = $db->query($sql);
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }

    echo json_encode(['ok' => true, 'data' => $rows, 'total' => count($rows)]);
    exit;
}

// ─── Admin-only actions below ───
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin access required.']);
    exit;
}

// ─── ADD ───
if ($action === 'add') {
    $category = trim($_POST['category'] ?? 'FAQ');
    $keywords = trim($_POST['keywords'] ?? '');
    $question = trim($_POST['question'] ?? '');
    $answer   = trim($_POST['answer'] ?? '');
    $isActive = (int)($_POST['is_active'] ?? 1);
    $sortOrder = (int)($_POST['sort_order'] ?? 0);

    if ($keywords === '' || $answer === '') {
        echo json_encode(['ok' => false, 'error' => 'Keywords and Answer are required.']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO ai_agent_knowledge (category, keywords, question, answer, is_active, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $userId = (int)$_SESSION['user_id'];
    $stmt->bind_param('ssssiis', $category, $keywords, $question, $answer, $isActive, $sortOrder, $userId);

    if ($stmt->execute()) {
        echo json_encode(['ok' => true, 'id' => $stmt->insert_id, 'message' => 'Knowledge entry added successfully.']);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Database error: ' . $db->error]);
    }
    exit;
}

// ─── EDIT ───
if ($action === 'edit') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid ID.']);
        exit;
    }

    $category  = trim($_POST['category'] ?? 'FAQ');
    $keywords  = trim($_POST['keywords'] ?? '');
    $question  = trim($_POST['question'] ?? '');
    $answer    = trim($_POST['answer'] ?? '');
    $isActive  = (int)($_POST['is_active'] ?? 1);
    $sortOrder = (int)($_POST['sort_order'] ?? 0);

    if ($keywords === '' || $answer === '') {
        echo json_encode(['ok' => false, 'error' => 'Keywords and Answer are required.']);
        exit;
    }

    $stmt = $db->prepare("UPDATE ai_agent_knowledge SET category=?, keywords=?, question=?, answer=?, is_active=?, sort_order=? WHERE id=?");
    $stmt->bind_param('ssssiii', $category, $keywords, $question, $answer, $isActive, $sortOrder, $id);

    if ($stmt->execute()) {
        echo json_encode(['ok' => true, 'message' => 'Knowledge entry updated.']);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Database error: ' . $db->error]);
    }
    exit;
}

// ─── DELETE ───
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid ID.']);
        exit;
    }

    $stmt = $db->prepare("DELETE FROM ai_agent_knowledge WHERE id=?");
    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        echo json_encode(['ok' => true, 'message' => 'Knowledge entry deleted.']);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Database error: ' . $db->error]);
    }
    exit;
}

// ─── TOGGLE ACTIVE ───
if ($action === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid ID.']);
        exit;
    }

    $stmt = $db->prepare("UPDATE ai_agent_knowledge SET is_active = IF(is_active=1, 0, 1) WHERE id=?");
    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        echo json_encode(['ok' => true, 'message' => 'Status toggled.']);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Database error: ' . $db->error]);
    }
    exit;
}

// ─── SAVE AI SETTINGS ───
if ($action === 'save_settings') {
    $settings = function_exists('getAppSettings') ? getAppSettings() : [];

    $settings['ai_agent_provider']    = trim($_POST['ai_agent_provider'] ?? 'gemini_pro');
    $settings['ai_agent_model']       = trim($_POST['ai_agent_model'] ?? 'gemini-2.0-flash');
    $settings['gemini_api_key']       = trim($_POST['gemini_api_key'] ?? '');
    $settings['openai_api_key']       = trim($_POST['openai_api_key'] ?? '');
    $settings['local_ai_url']         = trim($_POST['local_ai_url'] ?? 'http://localhost:11434/v1/chat/completions');
    $settings['ai_agent_temperature'] = max(0, min(1, (float)($_POST['ai_agent_temperature'] ?? 0.2)));
    $settings['ai_agent_max_tokens']  = max(100, min(4000, (int)($_POST['ai_agent_max_tokens'] ?? 1500)));
    $settings['ai_agent_enabled']     = (int)($_POST['ai_agent_enabled'] ?? 1);

    if (function_exists('saveAppSettings') && saveAppSettings($settings)) {
        echo json_encode(['ok' => true, 'message' => 'AI Agent settings saved successfully.']);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Unable to save settings.']);
    }
    exit;
}

// ─── TEST PROVIDER CONNECTION ───
if ($action === 'test_provider') {
    $provider = trim($_POST['provider'] ?? 'gemini_pro');
    $apiKey   = trim($_POST['api_key'] ?? '');

    if ($apiKey === '') {
        echo json_encode(['ok' => false, 'error' => 'API key is required to test connection.']);
        exit;
    }

    if ($provider === 'gemini_pro') {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode($apiKey);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 && $resp) {
            $data = json_decode($resp, true);
            $models = [];
            if (!empty($data['models'])) {
                foreach (array_slice($data['models'], 0, 5) as $m) {
                    $models[] = $m['name'] ?? 'unknown';
                }
            }
            echo json_encode(['ok' => true, 'message' => 'Gemini API connected successfully!', 'models' => $models, 'http_code' => $httpCode]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Gemini API unreachable. HTTP ' . $httpCode . '. ' . $err]);
        }
    } elseif ($provider === 'openai') {
        $ch = curl_init('https://api.openai.com/v1/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 && $resp) {
            echo json_encode(['ok' => true, 'message' => 'OpenAI API connected successfully!', 'http_code' => $httpCode]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'OpenAI API unreachable. HTTP ' . $httpCode . '. ' . $err]);
        }
    } elseif ($provider === 'local') {
        $localUrl = trim($_POST['local_url'] ?? 'http://localhost:11434/v1/models');
        $ch = curl_init($localUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200) {
            echo json_encode(['ok' => true, 'message' => 'Local LLM endpoint reachable!', 'http_code' => $httpCode]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Local LLM unreachable. HTTP ' . $httpCode . '. ' . $err]);
        }
    } else {
        echo json_encode(['ok' => false, 'error' => 'Unknown provider.']);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
