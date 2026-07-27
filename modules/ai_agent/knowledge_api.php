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
    case 'test_provider':
        testProvider();
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

function testProvider()
{
    $provider = $_POST['provider'] ?? 'gemini_pro';
    $apiKey = trim($_POST['api_key'] ?? '');
    $localUrl = trim($_POST['local_url'] ?? '');

    if ($apiKey === '' && $provider !== 'local') {
        echo json_encode(['ok' => false, 'error' => 'API key is required.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $testPrompt = 'Say "hello" in exactly one word.';

    if ($provider === 'gemini_pro') {
        // Google Gemini API
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);
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
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
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
        // OpenAI API
        $url = 'https://api.openai.com/v1/chat/completions';
        $payload = json_encode([
            'model' => 'gpt-4o-mini',
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
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
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
            echo json_encode(['ok' => true, 'message' => 'OpenAI API connected successfully! (HTTP ' . $httpCode . ')'], JSON_UNESCAPED_UNICODE);
        } else {
            $data = json_decode($response, true);
            $errMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
            echo json_encode(['ok' => false, 'error' => 'OpenAI API error: ' . $errMsg], JSON_UNESCAPED_UNICODE);
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
            CURLOPT_TIMEOUT => 15,
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
    } else {
        echo json_encode(['ok' => false, 'error' => 'Unknown provider: ' . $provider], JSON_UNESCAPED_UNICODE);
    }
}
