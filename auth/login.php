<?php
// ============================================================
// ERP System — Login Page
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Already logged in
if (isset($_SESSION['user_id'])) {
    redirect(BASE_URL . '/modules/dashboard/index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $error = 'Email and password are required.';
        } else {
            $db   = getDB();
            $stmt = $db->prepare("SELECT id, name, email, password, role FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email']= $user['email'];
                $_SESSION['role']      = $user['role'];
                redirect(BASE_URL . '/modules/dashboard/index.php');
            } else {
                $error = 'Invalid email or password.';
            }
        }
    }
}

$csrf = generateCSRF();
$settings = getAppSettings();
$companyName = trim((string)($settings['company_name'] ?? '')) ?: APP_NAME;
$erpDisplayName = function_exists('getErpDisplayName') ? getErpDisplayName($companyName) : APP_NAME;
$logoPath = (string)($settings['logo_path'] ?? '');
$loginBg = (string)($settings['login_background_image'] ?? '');
if ($loginBg === '') {
  $library = $settings['image_library'] ?? [];
  if (is_array($library)) {
    for ($i = count($library) - 1; $i >= 0; $i--) {
      $img = $library[$i] ?? null;
      if (is_array($img) && (($img['category'] ?? '') === 'background') && !empty($img['path'])) {
        $loginBg = (string)$img['path'];
        break;
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — <?= e($erpDisplayName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<?php if ($loginBg !== ''): ?>
<style>
.login-body {
  background: linear-gradient(rgba(15,23,42,.48), rgba(15,23,42,.48)), url('<?= e(BASE_URL . '/' . ltrim($loginBg, '/')) ?>') center/cover no-repeat fixed;
}
</style>
<?php endif; ?>
</head>
<body class="login-body">
<div class="login-wrap">
  <div class="login-card">
    <div class="login-logo">
      <?php if ($logoPath !== ''): ?>
        <img src="<?= e(BASE_URL . '/' . ltrim($logoPath, '/')) ?>" alt="Logo">
      <?php else: ?>
        <i class="bi bi-layers"></i>
      <?php endif; ?>
    </div>
    <h1 class="login-title"><?= e($erpDisplayName) ?></h1>
    <p class="login-sub" style="margin-bottom:8px"><?= e($companyName) ?></p>
    <p class="login-sub">Sign in to your account</p>

    <?php if ($error): ?>
    <div class="alert alert-danger" role="alert">
      <span><i class="bi bi-exclamation-circle me-2"></i><?= e($error) ?></span>
      <button class="alert-close" type="button">&times;</button>
    </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off" class="login-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

      <div class="form-group">
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email"
               class="form-control" placeholder="you@company.com"
               value="<?= e($_POST['email'] ?? '') ?>" required autofocus>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               class="form-control" placeholder="••••••••" required>
      </div>

      <div class="mt-20">
        <button type="submit" class="btn btn-primary btn-full">
          <i class="bi bi-box-arrow-in-right"></i> Sign In
        </button>
      </div>
    </form>

    <p class="text-center text-sm text-muted mt-16">
      Default admin: <strong>admin@example.com</strong> / <strong>admin123</strong>
    </p>
  </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
</body>
</html>
