<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? sanitize($pageTitle) . ' | ' : ''; ?><?php echo APP_NAME; ?></title>
    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <style>
        :root {
            --primary: #1a3c6e;
            --secondary: #e8501a;
            --sidebar-width: 250px;
        }
        body { background-color: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .sidebar {
            width: var(--sidebar-width);
            min-height: 100vh;
            background: var(--primary);
            position: fixed;
            top: 0; left: 0;
            z-index: 100;
            transition: all 0.3s;
            overflow-y: auto;
        }
        .sidebar .brand {
            padding: 18px 20px;
            background: rgba(0,0,0,0.2);
            color: #fff;
            font-size: 1.1rem;
            font-weight: 700;
            text-decoration: none;
            display: block;
        }
        .sidebar .brand span { color: var(--secondary); }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 10px 20px;
            border-radius: 0;
            font-size: 0.9rem;
            transition: all 0.2s;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            color: #fff;
            background: rgba(255,255,255,0.15);
            border-left: 3px solid var(--secondary);
        }
        .sidebar .nav-link i { width: 22px; margin-right: 8px; }
        .sidebar .nav-section {
            color: rgba(255,255,255,0.45);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 12px 20px 4px;
        }
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }
        .topbar {
            background: #fff;
            padding: 10px 24px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 99;
        }
        .page-content { padding: 24px; }
        .stat-card {
            border-radius: 10px;
            border: none;
            overflow: hidden;
        }
        .stat-card .icon-box {
            width: 56px; height: 56px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }
        .badge-status-pending { background: #fff3cd; color: #856404; }
        .badge-status-processing { background: #cfe2ff; color: #084298; }
        .badge-status-completed { background: #d1e7dd; color: #0f5132; }
        .badge-status-cancelled { background: #f8d7da; color: #842029; }
        .table th { font-weight: 600; font-size: 0.85rem; text-transform: uppercase; color: #6c757d; }
        .btn-action { padding: 4px 10px; font-size: 0.8rem; }
        @media (max-width: 768px) {
            .sidebar { margin-left: calc(-1 * var(--sidebar-width)); }
            .sidebar.show { margin-left: 0; }
            .main-content { margin-left: 0; }
        }
    </style>
</head>
<body>
<?php
$currentUser = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$baseUrl = getBaseUrl();
?>
<div class="sidebar" id="sidebar">
    <a href="<?php echo $baseUrl; ?>/dashboard.php" class="brand">
        <i class="fas fa-tags me-2"></i><span>Shree</span> Label ERP
    </a>
    <nav class="mt-2">
        <div class="nav-section">Main</div>
        <a href="<?php echo $baseUrl; ?>/dashboard.php"
           class="nav-link <?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>

        <div class="nav-section">Sales</div>
        <a href="<?php echo $baseUrl; ?>/modules/orders/index.php"
           class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], '/orders/') !== false ? 'active' : ''; ?>">
            <i class="fas fa-shopping-cart"></i> Orders
        </a>
        <a href="<?php echo $baseUrl; ?>/modules/invoices/index.php"
           class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], '/invoices/') !== false ? 'active' : ''; ?>">
            <i class="fas fa-file-invoice-dollar"></i> Invoices
        </a>

        <div class="nav-section">Management</div>
        <a href="<?php echo $baseUrl; ?>/modules/customers/index.php"
           class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], '/customers/') !== false ? 'active' : ''; ?>">
            <i class="fas fa-users"></i> Customers
        </a>
        <a href="<?php echo $baseUrl; ?>/modules/products/index.php"
           class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], '/products/') !== false ? 'active' : ''; ?>">
            <i class="fas fa-tag"></i> Products / Labels
        </a>
    </nav>

    <div class="mt-auto p-3" style="position:absolute; bottom:0; width:100%;">
        <div class="text-white-50 small text-center"><?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?></div>
    </div>
</div>

<div class="main-content">
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-sm btn-outline-secondary d-md-none" onclick="document.getElementById('sidebar').classList.toggle('show')">
                <i class="fas fa-bars"></i>
            </button>
            <nav aria-label="breadcrumb" class="mb-0">
                <ol class="breadcrumb mb-0 small">
                    <?php if (isset($breadcrumbs) && is_array($breadcrumbs)): ?>
                        <?php foreach ($breadcrumbs as $i => $bc): ?>
                            <?php if ($i === count($breadcrumbs) - 1): ?>
                                <li class="breadcrumb-item active"><?php echo sanitize($bc['label']); ?></li>
                            <?php else: ?>
                                <li class="breadcrumb-item"><a href="<?php echo $bc['url']; ?>"><?php echo sanitize($bc['label']); ?></a></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ol>
            </nav>
        </div>
        <div class="d-flex align-items-center gap-3">
            <span class="small text-muted">
                <i class="fas fa-user-circle me-1"></i>
                <?php echo sanitize($currentUser['name'] ?? 'User'); ?>
            </span>
            <a href="<?php echo $baseUrl; ?>/logout.php" class="btn btn-sm btn-outline-danger">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
    <div class="page-content">
        <?php
        $flash = getFlash();
        if ($flash):
        ?>
        <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'error' ? 'danger' : 'info'); ?> alert-dismissible fade show" role="alert">
            <?php echo sanitize($flash['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
