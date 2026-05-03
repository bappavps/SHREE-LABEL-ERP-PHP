<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

appSessionStart();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    exit;
}

$token = trim((string)($_POST['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing review token',
    ]);
    exit;
}

$pdo = Db::getInstance();

$projectStmt = $pdo->prepare('SELECT id, client_name FROM artwork_projects WHERE token = :token LIMIT 1');
$projectStmt->execute(['token' => $token]);
$project = $projectStmt->fetch();

if (!$project) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Project not found',
    ]);
    exit;
}

$projectId = (int)$project['id'];
$clientName = trim((string)($project['client_name'] ?? 'Client'));
if ($clientName === '') {
    $clientName = 'Client';
}

$viewMode = strtolower(trim((string)($_POST['view_mode'] ?? '')));
if ($viewMode !== 'designer' && $viewMode !== 'client') {
    $viewMode = '';
}

$authUser = getAuthUser();
$authIsDesigner = $authUser !== null && in_array((string)($authUser['role'] ?? ''), ['designer', 'admin'], true);
$isDesigner = $viewMode === 'designer' ? true : ($viewMode === 'client' ? false : $authIsDesigner);

if ($isDesigner) {
    $viewerRole = 'designer';
    $viewerRoleLabel = 'Designer';
    $viewerName = trim((string)($authUser['name'] ?? 'Designer'));
    if ($viewerName === '') {
        $viewerName = 'Designer';
    }
    $viewerKey = 'designer:' . (string)($authUser['id'] ?? '0');
    $viewerUserId = isset($authUser['id']) ? (int)$authUser['id'] : null;
} else {
    $viewerRole = 'client';
    $viewerRoleLabel = 'Client';
    $viewerName = $clientName;
    $viewerKey = 'client:' . session_id();
    $viewerUserId = null;
}

$counterpartyRole = $viewerRole === 'designer' ? 'client' : 'designer';
$counterpartyRoleLabel = $viewerRole === 'designer' ? 'Client' : 'Designer';

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS artwork_presence_sessions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        review_token VARCHAR(128) NOT NULL,
        actor_role VARCHAR(20) NOT NULL,
        actor_key VARCHAR(191) NOT NULL,
        actor_name VARCHAR(255) NOT NULL,
        user_id INT NULL,
        last_seen DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_actor_project (project_id, actor_role, actor_key),
        KEY idx_presence_project_seen (project_id, last_seen),
        KEY idx_presence_token_seen (review_token, last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$upsert = $pdo->prepare(
    'INSERT INTO artwork_presence_sessions
        (project_id, review_token, actor_role, actor_key, actor_name, user_id, last_seen)
     VALUES
        (:project_id, :review_token, :actor_role, :actor_key, :actor_name, :user_id, NOW())
     ON DUPLICATE KEY UPDATE
        actor_name = VALUES(actor_name),
        user_id = VALUES(user_id),
        review_token = VALUES(review_token),
        last_seen = NOW()'
);

$upsert->execute([
    'project_id' => $projectId,
    'review_token' => $token,
    'actor_role' => $viewerRole,
    'actor_key' => $viewerKey,
    'actor_name' => $viewerName,
    'user_id' => $viewerUserId,
]);

$cleanup = $pdo->prepare('DELETE FROM artwork_presence_sessions WHERE last_seen < (NOW() - INTERVAL 1 DAY)');
$cleanup->execute();

$presenceStmt = $pdo->prepare(
    'SELECT actor_role, actor_name, last_seen
     FROM artwork_presence_sessions
     WHERE project_id = :project_id
       AND last_seen >= (NOW() - INTERVAL 90 SECOND)
     ORDER BY actor_role ASC, last_seen DESC'
);
$presenceStmt->execute(['project_id' => $projectId]);
$rows = $presenceStmt->fetchAll();

$latestByRole = [
    'designer' => null,
    'client' => null,
];
$countByRole = [
    'designer' => 0,
    'client' => 0,
];

foreach ($rows as $row) {
    $role = (string)($row['actor_role'] ?? '');
    if (!isset($countByRole[$role])) {
        continue;
    }

    $countByRole[$role]++;
    if ($latestByRole[$role] === null) {
        $name = trim((string)($row['actor_name'] ?? ''));
        $latestByRole[$role] = $name !== '' ? $name : ($role === 'designer' ? 'Designer' : 'Client');
    }
}

$viewerOnline = $countByRole[$viewerRole] > 0;
$counterpartyOnline = $countByRole[$counterpartyRole] > 0;

$viewerNameOut = $latestByRole[$viewerRole] ?? $viewerName;
$counterpartyNameOut = $latestByRole[$counterpartyRole] ?? ($counterpartyRole === 'designer' ? 'Designer' : $clientName);

if ($countByRole['designer'] > 1 && $counterpartyRole === 'designer') {
    $counterpartyNameOut .= ' +' . ($countByRole['designer'] - 1);
}
if ($countByRole['client'] > 1 && $counterpartyRole === 'client') {
    $counterpartyNameOut .= ' +' . ($countByRole['client'] - 1);
}
if ($countByRole['designer'] > 1 && $viewerRole === 'designer') {
    $viewerNameOut .= ' +' . ($countByRole['designer'] - 1);
}
if ($countByRole['client'] > 1 && $viewerRole === 'client') {
    $viewerNameOut .= ' +' . ($countByRole['client'] - 1);
}

echo json_encode([
    'success' => true,
    'viewer' => [
        'role' => $viewerRole,
        'roleLabel' => $viewerRoleLabel,
        'name' => $viewerNameOut,
        'online' => $viewerOnline,
        'count' => $countByRole[$viewerRole],
    ],
    'counterparty' => [
        'role' => $counterpartyRole,
        'roleLabel' => $counterpartyRoleLabel,
        'name' => $counterpartyNameOut,
        'online' => $counterpartyOnline,
        'count' => $countByRole[$counterpartyRole],
    ],
]);
