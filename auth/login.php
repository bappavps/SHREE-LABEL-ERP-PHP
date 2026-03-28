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
$footerErpName = function_exists('getErpDisplayName') ? getErpDisplayName($companyName) : APP_NAME;
$logoPath = (string)($settings['logo_path'] ?? '');
$companyLogoUrl = $logoPath !== '' ? (BASE_URL . '/' . ltrim($logoPath, '/')) : (BASE_URL . '/assets/img/logo.svg');
$themeColor = (string)($settings['sidebar_button_color'] ?? '#22c55e');
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
<link rel="icon" href="<?= e($companyLogoUrl) ?>">
<link rel="apple-touch-icon" href="<?= e($companyLogoUrl) ?>">
<link rel="manifest" href="<?= BASE_URL ?>/manifest.php">
<meta name="theme-color" content="<?= e($themeColor) ?>">
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
<style>
.login-page-shell {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}
.login-page-main {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
}
.login-wrap {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.login-card {
  position: relative;
  overflow: hidden;
  animation: loginCardIn .55s cubic-bezier(.16,.84,.3,1) both;
}
.login-card::before {
  content: "";
  position: absolute;
  top: 0;
  left: -120%;
  width: 120%;
  height: 3px;
  background: linear-gradient(90deg, transparent 0%, rgba(34,197,94,.8) 45%, rgba(59,130,246,.85) 70%, transparent 100%);
  animation: loginShimmer 2.8s ease-in-out 1;
}
.login-card::after {
  content: "";
  position: absolute;
  inset: 0;
  border-radius: 12px;
  padding: 2px;
  background: conic-gradient(from 0deg,
    transparent 0deg,
    transparent 304deg,
    rgba(250, 204, 21, .95) 324deg,
    rgba(34, 197, 94, .95) 338deg,
    rgba(234, 179, 8, .95) 352deg,
    transparent 360deg);
  -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
  -webkit-mask-composite: xor;
  mask-composite: exclude;
  pointer-events: none;
  opacity: .92;
  filter: drop-shadow(0 0 5px rgba(250, 204, 21, .6)) drop-shadow(0 0 7px rgba(34, 197, 94, .45));
  animation: loginOrbitOnce 2.1s linear .25s 1 both;
}
.login-logo {
  animation: loginLogoPop .6s ease-out .12s both;
}
.login-inline-footer {
  background: rgba(255,255,255,.92);
  border: 1px solid rgba(226,232,240,.9);
  border-radius: 10px;
  padding: 8px 12px;
  text-align: center;
  box-shadow: 0 4px 14px rgba(15,23,42,.08);
  animation: loginFooterIn .45s ease-out .25s both;
}
.login-inline-footer .line1 {
  font-size: .68rem;
  color: #475569;
  font-weight: 700;
}
.login-inline-footer .line2 {
  margin-top: 2px;
  font-size: .64rem;
  color: #64748b;
  font-weight: 600;
}
@keyframes loginCardIn {
  from { opacity: 0; transform: translateY(18px) scale(.985); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
@keyframes loginLogoPop {
  from { opacity: 0; transform: scale(.9); }
  to { opacity: 1; transform: scale(1); }
}
@keyframes loginFooterIn {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}
@keyframes loginShimmer {
  0% { left: -120%; }
  100% { left: 110%; }
}
@keyframes loginOrbitOnce {
  0% { transform: rotate(0deg); opacity: 0; }
  8% { opacity: .96; }
  85% { opacity: .92; }
  100% { transform: rotate(360deg); opacity: 0; }
}
</style>
</head>
<body class="login-body">
<div class="login-page-shell">
  <div class="login-page-main">
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

      <div class="login-inline-footer" role="contentinfo">
        <div class="line1">Version : <?= e(APP_VERSION) ?></div>
        <div class="line2">&copy; <?= date('Y') ?> <?= e($footerErpName) ?> &bull; ERP Master System v<?= e(APP_VERSION) ?> | @ Developed by Mriganka Bhusan Debnath</div>
      </div>
    </div>
  </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
</body>
</html>
