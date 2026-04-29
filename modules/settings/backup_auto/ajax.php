<?php
// AJAX handler for Auto Backup actions

require_once __DIR__ . '/backup_auto_helper.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'download' && isset($_GET['file'])) {
    $file = $_GET['file'];
    $path = auto_backup_resolve_file($file);

    if (!$path || !is_file($path)) {
        http_response_code(404);
        exit('File not found.');
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

try {
    if ($action === 'backup_now') {
        echo json_encode(auto_backup_run_local());
        exit;
    }

    if ($action === 'get_history') {
        echo json_encode(auto_backup_get_history());
        exit;
    }

    if ($action === 'get_rclone_status') {
        echo json_encode(auto_backup_rclone_status());
        exit;
    }

    if ($action === 'get_settings') {
        echo json_encode(['success' => true, 'settings' => auto_backup_get_settings()]);
        exit;
    }

    if ($action === 'save_settings') {
        $saved = auto_backup_save_settings([
            'enabled' => $_POST['enabled'] ?? null,
            'frequency' => $_POST['frequency'] ?? null,
            'backup_time' => $_POST['backup_time'] ?? null,
            'storage' => $_POST['storage'] ?? null,
            'remote' => $_POST['remote'] ?? null,
            'folder' => $_POST['folder'] ?? null,
        ]);

        if (!is_array($saved)) {
            echo json_encode(['success' => false, 'error' => 'Could not save settings.']);
            exit;
        }

        echo json_encode(['success' => true, 'settings' => $saved]);
        exit;
    }

    if ($action === 'test_rclone_conn') {
        $remote = $_POST['remote'] ?? '';
        $folder = $_POST['folder'] ?? '';
        echo json_encode(auto_backup_test_rclone_conn($remote, $folder));
        exit;
    }

    if ($action === 'delete' && isset($_POST['file'])) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!auto_backup_user_can_delete()) {
            echo json_encode(['success' => false, 'error' => 'Permission denied.']);
            exit;
        }

        $file = $_POST['file'];
        $path = auto_backup_resolve_file($file);
        if (!$path || !is_file($path)) {
            echo json_encode(['success' => false, 'error' => 'File not found.']);
            exit;
        }

        if (!@unlink($path)) {
            echo json_encode(['success' => false, 'error' => 'Delete failed.']);
            exit;
        }

        $history = auto_backup_get_history();
        $history = array_values(array_filter($history, function ($row) use ($file) {
            return ($row['file'] ?? '') !== $file;
        }));
        auto_backup_save_history($history);

        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Invalid action.']);
    exit;
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
