<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Db.php';
require_once __DIR__ . '/../includes/functions.php';

$db = Db::getInstance();
$designer = getCurrentDesigner($db);
$erpLogoUrl = ERP_BASE_URL . '/pwa_icon.php?size=192';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Dashboard'; ?> - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@600;700&display=swap" rel="stylesheet">
</head>
<body class="dashboard-body">
    <div class="sidebar">
        <div class="sidebar-logo">
            <img src="<?php echo sanitize($erpLogoUrl); ?>" alt="ERP Logo" style="width: 26px; height: 26px; object-fit: contain; border-radius: 6px; background: #fff; padding: 2px;">
            <span><?php echo sanitize(APP_NAME); ?></span>
        </div>
        <nav class="sidebar-nav">
            <a href="index.php" class="<?php echo ($activePage ?? '') === 'dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-th-large"></i> Dashboard
            </a>
            <a href="projects.php" class="<?php echo ($activePage ?? '') === 'projects' ? 'active' : ''; ?>">
                <i class="fas fa-folder-open"></i> Projects
            </a>
            <a href="final-projects.php" class="<?php echo ($activePage ?? '') === 'final-projects' ? 'active' : ''; ?>">
                <i class="fas fa-shield-check"></i> Final Projects
            </a>
            <?php if (($designer['role'] ?? '') === 'admin'): ?>
                <a href="users.php" class="<?php echo ($activePage ?? '') === 'users' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> Users
                </a>
            <?php endif; ?>
            <a href="settings.php" class="<?php echo ($activePage ?? '') === 'settings' ? 'active' : ''; ?>">
                <i class="fas fa-cog"></i> Settings
            </a>
        </nav>
        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar"><?php echo strtoupper(substr($designer['name'], 0, 1)); ?></div>
                <div class="user-details">
                    <p class="user-name"><?php echo sanitize($designer['name']); ?></p>
                    <p class="user-role"><?php echo strtoupper(sanitize($designer['role'])); ?></p>
                </div>
            </div>
            <a href="../logout.php" style="margin-top:10px; text-decoration:none; display:inline-flex; align-items:center; gap:8px; font-size:0.82rem; font-weight:700; color:#b91c1c; background:#fee2e2; border:1px solid #fecaca; border-radius:10px; padding:7px 10px;">
                <i class="fas fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </div>

    <main class="main-content">
        <header class="top-bar">
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search projects, clients...">
            </div>
            <div class="top-bar-actions">
                <?php if (($designer['role'] ?? '') === 'admin'): ?>
                    <div class="notification-wrap" id="notification-wrap">
                        <button class="notification-bell" id="notif-bell" type="button" aria-label="Notifications" title="Notifications">
                            <i class="fas fa-bell"></i>
                            <span class="badge" id="notif-count">0</span>
                        </button>
                        <div class="notification-dropdown" id="notif-dropdown" style="display:none;">
                            <div class="notification-head">Notifications</div>
                            <div class="notification-list" id="notif-list">
                                <div class="notification-empty">No new notifications</div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <button class="btn-primary" onclick="window.location.href='new-project.php'">
                    <i class="fas fa-plus"></i> New Project
                </button>
            </div>
        </header>
        <div class="content-wrapper">
