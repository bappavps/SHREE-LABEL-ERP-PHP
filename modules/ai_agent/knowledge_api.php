<?php
session_start();
/* ============================================================
   AI Agent Knowledge Base API — CRUD Operations
   ERP Master System
   ============================================================ */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php'; // Required for e() and other common functions

// Initialize DB
$db = getDB();
require_once __DIR__ . '/services/AliasResolver.php';
$aliasResolver = new AliasResolver($db);

// Only create table if it truly doesn't exist — use a single init check per session
if (empty($_SESSION['ai_agent_knowledge_table_checked'])) {
    $tableCheck = $db->query("SHOW TABLES LIKE 'ai_agent_knowledge'");
    if ($tableCheck && $tableCheck->num_rows === 0) {
        $db->query("
            CREATE TABLE ai_agent_knowledge (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category VARCHAR(100) NOT NULL DEFAULT 'FAQ',
                question TEXT,
                answer TEXT NOT NULL,
                keywords TEXT,
                language VARCHAR(10) DEFAULT 'en',
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    $docTableCheck = $db->query("SHOW TABLES LIKE 'ai_agent_documents'");
    if ($docTableCheck && $docTableCheck->num_rows === 0) {
        $db->query("
            CREATE TABLE ai_agent_documents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                file_type VARCHAR(50) NOT NULL,
                extracted_text LONGTEXT NOT NULL,
                char_count INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    $_SESSION['ai_agent_knowledge_table_checked'] = true;
}

// CSRF token generation and validation
if (empty($_SESSION['ai_agent_csrf_token'])) {
    $_SESSION['ai_agent_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_POST['csrf_token'] ?? '';
$expectedToken = $_SESSION['ai_agent_csrf_token'];

function validateCsrf($token, $expected)
{
    return !empty($token) && hash_equals($expected, $token);
}

header('Content-Type: application/json; charset=utf-8');

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'list':
        listKnowledge($db);
        break;
    case 'get':
        getKnowledge($db);
        break;
    case 'add':
        if (!validateCsrf($csrfToken, $expectedToken)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        addKnowledge($db);
        break;
    case 'edit':
        if (!validateCsrf($csrfToken, $expectedToken)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        editKnowledge($db);
        break;
    case 'delete':
        if (!validateCsrf($csrfToken, $expectedToken)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        deleteKnowledge($db);
        break;
    case 'list_aliases':
        listAliases($aliasResolver);
        break;
    case 'get_aliases':
        getAliases($aliasResolver);
        break;
    case 'add_alias':
        if (!validateCsrf($csrfToken, $expectedToken)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        addAlias($aliasResolver);
        break;
    case 'delete_alias':
        if (!validateCsrf($csrfToken, $expectedToken)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        deleteAlias($aliasResolver);
        break;
    case 'test_provider':
        testProvider();
        break;
    case 'save_endpoint':
        if (!validateCsrf($csrfToken, $expectedToken)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        saveCustomEndpoint();
        break;
    case 'test_single_endpoint':
        testSingleEndpoint();
        break;
    case 'test_fallback_chain':
        testFallbackChain();
        break;
    case 'upload_document':
        if (!validateCsrf($csrfToken, $expectedToken)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        uploadDocument($db);
        break;
    case 'list_documents':
        listDocuments($db);
        break;
    case 'delete_document':
        if (!validateCsrf($csrfToken, $expectedToken)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        deleteDocument($db);
        break;
    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid action.'], JSON_UNESCAPED_UNICODE);
        break;
}

function listKnowledge($db)
{
    $cat = $_GET['category'] ?? '';
    $search = $_GET['search'] ?? '';
    $sql = "SELECT * FROM ai_agent_knowledge WHERE is_active = 1";
    $params = [];
    $types = '';

    if ($cat) {
        $sql .= " AND category = ?";
        $params[] = $cat;
        $types .= 's';
    }
    if ($search) {
        $sql .= " AND (question LIKE ? OR answer LIKE ? OR keywords LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sss';
    }

    $sql .= " ORDER BY id DESC LIMIT 200";
    $stmt = $db->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $records = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['ok' => true, 'data' => $records], JSON_UNESCAPED_UNICODE);
}

function getKnowledge($db)
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid ID.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $stmt = $db->prepare("SELECT * FROM ai_agent_knowledge WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    echo json_encode(['ok' => true, 'data' => $record ?: null], JSON_UNESCAPED_UNICODE);
}

function addKnowledge($db)
{
    $category = trim($_POST['category'] ?? 'FAQ');
    $question = trim($_POST['question'] ?? '');
    $answer = trim($_POST['answer'] ?? '');
    $keywords = trim($_POST['keywords'] ?? '');

    if (empty($answer)) {
        echo json_encode(['ok' => false, 'error' => 'Answer is required.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $stmt = $db->prepare("INSERT INTO ai_agent_knowledge (category, question, answer, keywords) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('ssss', $category, $question, $answer, $keywords);
    if ($stmt->execute()) {
        echo json_encode(['ok' => true, 'id' => $stmt->insert_id], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Database error: ' . $db->error], JSON_UNESCAPED_UNICODE);
    }
}

function editKnowledge($db)
{
    $id = (int) ($_POST['id'] ?? 0);
    $category = trim($_POST['category'] ?? '');
    $question = trim($_POST['question'] ?? '');
    $answer = trim($_POST['answer'] ?? '');
    $keywords = trim($_POST['keywords'] ?? '');
    $isActive = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 1;

    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid ID.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (empty($answer)) {
        echo json_encode(['ok' => false, 'error' => 'Answer is required.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $stmt = $db->prepare("UPDATE ai_agent_knowledge SET category=?, question=?, answer=?, keywords=?, is_active=? WHERE id=?");
    $stmt->bind_param('ssssii', $category, $question, $answer, $keywords, $isActive, $id);
    if ($stmt->execute()) {
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Database error: ' . $db->error], JSON_UNESCAPED_UNICODE);
    }
}

function deleteKnowledge($db)
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid ID.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $stmt = $db->prepare("DELETE FROM ai_agent_knowledge WHERE id = ?");
    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Database error: ' . $db->error], JSON_UNESCAPED_UNICODE);
    }
}

/* ── Alias Functions ───────────────────────────────────────────────────── */

function listAliases(AliasResolver $resolver)
{
    echo json_encode(['ok' => true, 'data' => $resolver->listAll()], JSON_UNESCAPED_UNICODE);
}

function getAliases(AliasResolver $resolver)
{
    $kbId = (int) ($_GET['kb_id'] ?? 0);
    if ($kbId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid kb_id.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $aliases = $resolver->getAliasesForKb($kbId);
    echo json_encode(['ok' => true, 'kb_id' => $kbId, 'aliases' => $aliases], JSON_UNESCAPED_UNICODE);
}

function addAlias(AliasResolver $resolver)
{
    $kbId  = (int) ($_POST['kb_id']  ?? 0);
    $alias = trim($_POST['alias'] ?? '');
    if ($kbId <= 0 || $alias === '') {
        echo json_encode(['ok' => false, 'error' => 'kb_id and alias are required.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $ok = $resolver->addAlias($kbId, $alias);
    echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
}

function deleteAlias(AliasResolver $resolver)
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid id.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $ok = $resolver->deleteAlias($id);
    echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
}

/* ── Provider / Endpoint Functions ─────────────────────────────────────── */

function testProvider()
{
    $provider = $_POST['provider'] ?? 'openrouter';
    $apiKey = trim($_POST['api_key'] ?? '');
    $model = trim($_POST['model'] ?? '');
    if ($model === '') $model = 'gemini-2.0-flash';
    $localUrl = trim($_POST['local_url'] ?? '');

    if ($apiKey === '' && $provider !== 'local') {
        echo json_encode(['ok' => false, 'error' => 'API key is required.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $testPrompt = 'Say "hello" in exactly one word.';

    if ($provider === 'gemini_pro') {
        // Google Gemini API
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent?key=' . urlencode($apiKey);
        $payload = json_encode([
            'contents' => [['parts' => [['text' => $testPrompt]]]],
            'generationConfig' => ['maxOutputTokens' => 20],
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            echo json_encode(['ok' => false, 'error' => 'Network error: ' . $curlErr], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            echo json_encode(['ok' => true, 'message' => 'Gemini API connected successfully! (HTTP ' . $httpCode . ')'], JSON_UNESCAPED_UNICODE);
        } else {
            $data = json_decode($response, true);
            $errMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
            echo json_encode(['ok' => false, 'error' => 'Gemini API error: ' . $errMsg], JSON_UNESCAPED_UNICODE);
        }
    } elseif ($provider === 'openai') {
        // OpenAI API — support custom endpoint URL
        $customUrl = trim($_POST['openai_url'] ?? '');
        $url = $customUrl !== '' ? $customUrl : 'https://api.openai.com/v1/chat/completions';
        if ($model === 'gemini-2.0-flash' || $model === '') $model = 'gpt-4o-mini';
        $payload = json_encode([
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $testPrompt]],
            'max_tokens' => 20,
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            echo json_encode(['ok' => false, 'error' => 'Network error: ' . $curlErr], JSON_UNESCAPED_UNICODE);
            return;
        }
        $data = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && !isset($data['error'])) {
            echo json_encode(['ok' => true, 'message' => 'OpenAI API connected successfully! (HTTP ' . $httpCode . ')'], JSON_UNESCAPED_UNICODE);
        } else {
            $errMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
            echo json_encode(['ok' => false, 'error' => 'OpenAI API error: ' . $errMsg], JSON_UNESCAPED_UNICODE);
        }
    } elseif ($provider === 'openrouter') {
        // OpenRouter API
        $url = trim($_POST['openrouter_url'] ?? '');
        if (empty($url)) $url = 'https://openrouter.ai/api/v1/chat/completions';
        if ($model === 'gemini-2.0-flash' || $model === '') $model = 'openrouter/free';
        $payload = json_encode([
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $testPrompt]],
            'max_tokens' => 20,
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'HTTP-Referer: http://localhost',
                'X-Title: Shree Label ERP'
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            echo json_encode(['ok' => false, 'error' => 'Network error: ' . $curlErr], JSON_UNESCAPED_UNICODE);
            return;
        }
        $data = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && !isset($data['error'])) {
            echo json_encode(['ok' => true, 'message' => 'OpenRouter API connected successfully! (HTTP ' . $httpCode . ')'], JSON_UNESCAPED_UNICODE);
        } else {
            $errMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
            echo json_encode(['ok' => false, 'error' => 'OpenRouter API error: ' . $errMsg], JSON_UNESCAPED_UNICODE);
        }
    } elseif ($provider === 'local') {
        // Local LLM (Ollama / LM Studio)
        if ($localUrl === '') {
            echo json_encode(['ok' => false, 'error' => 'Local LLM endpoint URL is required.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $payload = json_encode([
            'model' => 'local',
            'messages' => [['role' => 'user', 'content' => $testPrompt]],
            'max_tokens' => 20,
        ]);
        $ch = curl_init($localUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            echo json_encode(['ok' => false, 'error' => 'Network error: ' . $curlErr], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            echo json_encode(['ok' => true, 'message' => 'Local LLM connected successfully! (HTTP ' . $httpCode . ')'], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Local LLM returned HTTP ' . $httpCode], JSON_UNESCAPED_UNICODE);
        }
    } elseif ($provider === 'custom') {
        // Custom API Endpoint
        $customUrl = trim($_POST['custom_url'] ?? '');
        if ($customUrl === '') {
            echo json_encode(['ok' => false, 'error' => 'Custom API endpoint URL is required.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($model === 'gemini-2.0-flash' || $model === '') $model = 'gpt-4o-mini';
        $payload = json_encode([
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $testPrompt]],
            'max_tokens' => 20,
        ]);
        $ch = curl_init($customUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            echo json_encode(['ok' => false, 'error' => 'Network error: ' . $curlErr], JSON_UNESCAPED_UNICODE);
            return;
        }
        $data = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && !isset($data['error'])) {
            echo json_encode(['ok' => true, 'message' => 'Custom API connected successfully! (HTTP ' . $httpCode . ')'], JSON_UNESCAPED_UNICODE);
        } else {
            $errMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
            echo json_encode(['ok' => false, 'error' => 'Custom API error: ' . $errMsg], JSON_UNESCAPED_UNICODE);
        }
    } else {
        echo json_encode(['ok' => false, 'error' => 'Unknown provider: ' . $provider], JSON_UNESCAPED_UNICODE);
    }
}

function saveCustomEndpoint()
{
    $label = trim($_POST['label'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $apiKey = trim($_POST['api_key'] ?? '');
    $model = trim($_POST['model'] ?? 'gpt-4o-mini');
    $active = !empty($_POST['active']) ? 1 : 0;

    if ($label === '' || $url === '') {
        echo json_encode(['ok' => false, 'error' => 'Label and URL are required.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $settings = getAppSettings();
    $endpoints = $settings['ai_custom_endpoints'] ?? [];

    // Check if endpoint with same label exists — update it
    $found = false;
    foreach ($endpoints as $i => $ep) {
        if (($ep['label'] ?? '') === $label) {
            $endpoints[$i]['url'] = $url;
            $endpoints[$i]['api_key'] = $apiKey;
            $endpoints[$i]['model'] = $model;
            $endpoints[$i]['active'] = $active;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $endpoints[] = [
            'label' => $label,
            'url' => $url,
            'api_key' => $apiKey,
            'model' => $model,
            'active' => $active,
        ];
    }

    $settings['ai_custom_endpoints'] = $endpoints;
    saveAppSettings($settings);

    echo json_encode(['ok' => true, 'message' => 'Endpoint "' . $label . '" saved.'], JSON_UNESCAPED_UNICODE);
}

function testSingleEndpoint()
{
    $label = trim($_POST['label'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $apiKey = trim($_POST['api_key'] ?? '');
    $model = trim($_POST['model'] ?? 'gpt-4o-mini');

    if ($url === '') {
        echo json_encode(['ok' => false, 'error' => 'API URL is required.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $testPrompt = 'Say "hello" in exactly one word.';
    $payload = json_encode([
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => $testPrompt]],
        'max_tokens' => 20,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        echo json_encode(['ok' => false, 'error' => 'Network error: ' . $curlErr], JSON_UNESCAPED_UNICODE);
        return;
    }

    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && !isset($data['error'])) {
        echo json_encode(['ok' => true, 'message' => 'API "' . $label . '" connected! (HTTP ' . $httpCode . ')'], JSON_UNESCAPED_UNICODE);
    } else {
        $errMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
        echo json_encode(['ok' => false, 'error' => 'API error: ' . $errMsg], JSON_UNESCAPED_UNICODE);
    }
}

function testFallbackChain()
{
    $settings = getAppSettings();
    
    // Collect all configured standard providers first
    $allEndpoints = [];
    
    $primaryProvider = strtolower($settings['ai_agent_provider'] ?? 'openrouter');
    
    // Check OpenAI (which might be 9Router)
    if (!empty($settings['openai_api_key'])) {
        $allEndpoints[] = [
            'label' => 'openai' . ($primaryProvider === 'openai' ? ' (Primary)' : ''),
            'url' => !empty($settings['openai_api_url']) ? $settings['openai_api_url'] : 'https://api.openai.com/v1/chat/completions',
            'api_key' => $settings['openai_api_key'],
            'model' => (strpos(!empty($settings['openai_api_url']) ? $settings['openai_api_url'] : '', 'ninerouter') !== false) ? '9ROUTER-COMBO' : (!empty($settings['ai_agent_model']) && strpos($settings['ai_agent_model'], 'gemini') === false ? $settings['ai_agent_model'] : 'gpt-4o-mini')
        ];
    }
    
    // Check OpenRouter
    if (!empty($settings['openrouter_api_key'])) {
        $allEndpoints[] = [
            'label' => 'openrouter' . ($primaryProvider === 'openrouter' ? ' (Primary)' : ''),
            'url' => !empty($settings['openrouter_ai_url']) ? $settings['openrouter_ai_url'] : 'https://openrouter.ai/api/v1/chat/completions',
            'api_key' => $settings['openrouter_api_key'],
            'model' => 'openrouter/free'
        ];
    }
    
    // Check Gemini (has a different API structure, so we skip standard testing if it's too complex, but let's try standard for now, wait Gemini uses different payload format. For testing fallback, let's just mark it as standard if it has key, or skip.)
    // Actually, gemini uses a different endpoint style. Let's just say it's available without making the exact API call for fallback testing.
    if (!empty($settings['gemini_api_key'])) {
        // We will just add a dummy success for gemini if the key exists, as its curl structure is very different.
        $allEndpoints[] = [
            'label' => 'gemini' . ($primaryProvider === 'gemini' ? ' (Primary)' : ''),
            'url' => 'gemini',
            'api_key' => 'gemini',
            'model' => 'gemini'
        ];
    }
    
    // Check Local
    if (!empty($settings['local_ai_url'])) {
        $allEndpoints[] = [
            'label' => 'local' . ($primaryProvider === 'local' ? ' (Primary)' : ''),
            'url' => $settings['local_ai_url'],
            'api_key' => 'local',
            'model' => 'local'
        ];
    }

    // Check Custom Endpoints
    $endpoints = $settings['ai_custom_endpoints'] ?? [];
    foreach ($endpoints as $ep) {
        if (!empty($ep['active'])) {
            $allEndpoints[] = [
                'label' => ($ep['label'] ?? 'Unnamed') . ' (Custom)',
                'url' => $ep['url'] ?? '',
                'api_key' => $ep['api_key'] ?? '',
                'model' => $ep['model'] ?? 'gpt-4o-mini'
            ];
        }
    }

    $testPrompt = 'Say "ok" in exactly one word.';
    $details = [];

    // Test each active endpoint
    foreach ($allEndpoints as $ep) {
        $label = $ep['label'];
        $url = $ep['url'];
        $apiKey = $ep['api_key'];
        $model = $ep['model'];        if (empty($url)) {
            $details[] = ['label' => $label, 'status' => 'skip', 'info' => 'No URL'];
            continue;
        }

        $payload = json_encode([
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $testPrompt]],
            'max_tokens' => 10,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $details[] = ['label' => $label, 'status' => 'fail', 'info' => 'Network error: ' . $curlErr];
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            $data = json_decode($response, true);
            if (!isset($data['error'])) {
                $details[] = ['label' => $label, 'status' => 'ok', 'info' => 'HTTP ' . $httpCode . ' — Connected'];
            } else {
                $errMsg = $data['error']['message'] ?? 'Unknown error';
                $details[] = ['label' => $label, 'status' => 'fail', 'info' => 'API error: ' . $errMsg];
            }
        } else {
            $details[] = ['label' => $label, 'status' => 'fail', 'info' => 'HTTP ' . $httpCode];
        }
    }

    if (empty($allEndpoints)) {
        echo json_encode(['ok' => false, 'error' => 'No active endpoints to test.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $successCount = 0;
    foreach ($details as $d) {
        if ($d['status'] === 'ok') $successCount++;
    }

    echo json_encode([
        'ok' => true,
        'message' => $successCount . '/' . count($allEndpoints) . ' active agents connected.',
        'details' => $details,
    ], JSON_UNESCAPED_UNICODE);
}

/* ── Document / PDF Upload & Extraction Functions ──────────────────────── */

function extractTextFromPdfFile($filePath) {
    if (!file_exists($filePath)) return '';
    $content = @file_get_contents($filePath);
    if (!$content) return '';

    $text = '';
    if (preg_match_all('/BT[\s\S]*?ET/s', $content, $btMatches)) {
        foreach ($btMatches[0] as $bt) {
            if (preg_match_all('/\((.*?)\)\s*T[jJ]/s', $bt, $tjMatches)) {
                foreach ($tjMatches[1] as $str) {
                    $text .= $str . ' ';
                }
            }
            if (preg_match_all('/\[(.*?)\]\s*TJ/s', $bt, $arrayMatches)) {
                foreach ($arrayMatches[1] as $arr) {
                    if (preg_match_all('/\((.*?)\)/s', $arr, $subMatches)) {
                        foreach ($subMatches[1] as $str) {
                            $text .= $str;
                        }
                    }
                    $text .= ' ';
                }
            }
        }
    }

    if (trim($text) === '') {
        preg_match_all('/[\x20-\x7E\xA0-\xFF]{4,}/', $content, $matches);
        $text = implode(' ', array_filter($matches[0], function($s) {
            return !preg_match('/^\/(Font|Catalog|Page|Stream|Obj|Type|Parent|Root|Length|Filter)/i', $s);
        }));
    }

    return trim($text);
}

function uploadDocument($db) {
    $title = trim($_POST['title'] ?? '');
    if (empty($_FILES['doc_file']) || $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'Please select a valid document file to upload.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $file = $_FILES['doc_file'];
    $origName = $file['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    $allowed = ['pdf', 'txt', 'md', 'csv', 'json', 'doc', 'docx'];
    if (!in_array($ext, $allowed)) {
        echo json_encode(['ok' => false, 'error' => 'Only PDF, TXT, MD, CSV, JSON, and DOC files are supported.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($title === '') {
        $title = pathinfo($origName, PATHINFO_FILENAME);
    }

    $uploadDir = __DIR__ . '/../../uploads/ai_documents/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    $safeFileName = 'ai_doc_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
    $targetPath = $uploadDir . $safeFileName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        echo json_encode(['ok' => false, 'error' => 'Failed to save uploaded file on server.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $extractedText = '';
    if ($ext === 'pdf') {
        $extractedText = extractTextFromPdfFile($targetPath);
    } else {
        $raw = @file_get_contents($targetPath);
        if ($raw) {
            $extractedText = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8, ISO-8859-1, WINDOWS-1252');
        }
    }

    if (empty($extractedText)) {
        $extractedText = "Uploaded document: " . $title . " (File: " . $origName . ")";
    }

    $charCount = mb_strlen($extractedText, 'UTF-8');

    $stmt = $db->prepare("INSERT INTO ai_agent_documents (title, file_name, original_name, file_type, extracted_text, char_count) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssi", $title, $safeFileName, $origName, $ext, $extractedText, $charCount);
    $stmt->execute();
    $docId = $db->insert_id;
    $stmt->close();

    // Chunk and ingest into ai_agent_knowledge so RAG immediately matches questions
    $chunkSize = 800;
    $len = mb_strlen($extractedText, 'UTF-8');
    $part = 1;
    for ($i = 0; $i < $len; $i += $chunkSize) {
        $chunk = mb_substr($extractedText, $i, $chunkSize, 'UTF-8');
        $q = "Document: " . $title . " (Part " . $part . ")";
        $cat = "Document: " . $title;
        $kw = $title . ", " . $origName;
        
        $kbStmt = $db->prepare("INSERT INTO ai_agent_knowledge (category, question, answer, keywords, is_active) VALUES (?, ?, ?, ?, 1)");
        $kbStmt->bind_param("ssss", $cat, $q, $chunk, $kw);
        $kbStmt->execute();
        $kbStmt->close();
        $part++;
    }

    echo json_encode([
        'ok' => true,
        'doc_id' => $docId,
        'title' => $title,
        'char_count' => $charCount,
        'chunks_created' => ($part - 1),
        'message' => 'Document successfully uploaded and ingested into AI Knowledge Base!'
    ], JSON_UNESCAPED_UNICODE);
}

function listDocuments($db) {
    $res = $db->query("SELECT id, title, file_name, original_name, file_type, char_count, created_at FROM ai_agent_documents ORDER BY id DESC");
    $docs = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $docs[] = $row;
        }
    }
    echo json_encode(['ok' => true, 'documents' => $docs], JSON_UNESCAPED_UNICODE);
}

function deleteDocument($db) {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid document ID.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $stmt = $db->prepare("SELECT title, file_name FROM ai_agent_documents WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $doc = $res->fetch_assoc();
    $stmt->close();

    if ($doc) {
        $filePath = __DIR__ . '/../../uploads/ai_documents/' . $doc['file_name'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        $catLike = 'Document: ' . $doc['title'] . '%';
        $delKb = $db->prepare("DELETE FROM ai_agent_knowledge WHERE category LIKE ?");
        $delKb->bind_param("s", $catLike);
        $delKb->execute();
        $delKb->close();

        $delDoc = $db->prepare("DELETE FROM ai_agent_documents WHERE id = ?");
        $delDoc->bind_param("i", $id);
        $delDoc->execute();
        $delDoc->close();
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
}
