<?php
// ============================================================
// ERP System — Login Page
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$tenantInactiveMessage = 'This company workspace is currently inactive. Please contact E-Flexo support.';

// Already logged in
if (isset($_SESSION['user_id']) && (!defined('TENANT_ACTIVE') || TENANT_ACTIVE)) {
    redirect(BASE_URL . '/modules/dashboard/index.php');
}

$error = '';

if (function_exists('ensureRbacSchema')) {
  ensureRbacSchema();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (defined('TENANT_ACTIVE') && !TENANT_ACTIVE) {
    $error = $tenantInactiveMessage;
  } elseif (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $email    = trim(strtolower($_POST['email'] ?? ''));
        if (preg_match('/@gmail\.com$/i', $email)) {
          $email = preg_replace('/@gmail\.com$/i', '@shreelabel.com', $email);
        }
        $password = trim($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $error = 'Email and password are required.';
        } else {
            $db   = getDB();
            $stmt = $db->prepare("SELECT id, name, email, password, role, group_id FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email']= $user['email'];
                $_SESSION['role']      = $user['role'];
                $_SESSION['group_id']  = isset($user['group_id']) ? (int)$user['group_id'] : 0;
              $_SESSION['tenant_slug'] = defined('TENANT_SLUG') ? TENANT_SLUG : 'default';
              $_SESSION['tenant_name'] = defined('TENANT_NAME') ? TENANT_NAME : APP_NAME;

              $userLabel = trim((string)($user['name'] ?? ''));
              if ($userLabel === '') {
                $userLabel = trim((string)($user['email'] ?? 'Unknown user'));
              }
              createDepartmentNotifications(
                $db,
                ['global'],
                0,
                $userLabel . ' logged in.',
                'info',
                '/modules/dashboard/index.php'
              );
                redirect(BASE_URL . '/modules/dashboard/index.php');
            } else {
                $error = 'Invalid email or password.';
            }
        }
    }
}

if ($error === '' && defined('TENANT_ACTIVE') && !TENANT_ACTIVE) {
  $error = $tenantInactiveMessage;
}

$csrf = generateCSRF();
$settings = getAppSettings();
$companyName = trim((string)($settings['company_name'] ?? '')) ?: APP_NAME;
$erpDisplayName = function_exists('getErpDisplayName') ? getErpDisplayName($companyName) : APP_NAME;
$footerErpName = function_exists('getErpDisplayName') ? getErpDisplayName($companyName) : APP_NAME;
$logoPath = (string)($settings['logo_path'] ?? '');
$erpLogoPath = (string)($settings['erp_logo_path'] ?? '');
$uiLogoPath = $erpLogoPath !== '' ? $erpLogoPath : $logoPath;
$companyLogoUrl = $uiLogoPath !== '' ? appUrl($uiLogoPath) : appUrl('assets/img/logo.svg');
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
<link rel="icon" type="image/png" sizes="192x192" href="<?= e(appUrl('pwa_icon.php?size=192')) ?>">
<link rel="apple-touch-icon" sizes="192x192" href="<?= e(appUrl('pwa_icon.php?size=192')) ?>">
<link rel="manifest" href="<?= e(appUrl('manifest.php')) ?>">
<meta name="theme-color" content="<?= e($themeColor) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(appUrl('assets/css/style.css')) ?>">
<?php if ($loginBg !== ''): ?>
<style>
.login-body {
  background-color: #0f172a;
  background-image: linear-gradient(rgba(15,23,42,.48), rgba(15,23,42,.48)), url('<?= e(appUrl($loginBg)) ?>');
  background-size: cover;
  background-position: center;
  background-repeat: no-repeat;
  background-attachment: fixed;
}
</style>
<?php else: ?>
<style>
.login-body {
  background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
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
  width: 100%;
  max-width: 420px;
  padding: 20px;
}
.login-card {
  position: relative;
  overflow: hidden;
  animation: loginCardIn .55s cubic-bezier(.16,.84,.3,1) both;
  background: #fff;
  border-radius: 16px;
  padding: 32px 28px;
  box-shadow: 0 20px 60px rgba(0,0,0,.18);
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
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 16px;
  min-height: 60px;
}
.login-logo img {
  max-width: 120px;
  max-height: 80px;
  width: auto;
  height: auto;
  object-fit: contain;
}
.login-logo i {
  font-size: 3.5rem;
  color: #22c55e;
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
.login-title {
  font-size: 1.8rem;
  font-weight: 800;
  color: #0f172a;
  margin: 0 0 12px 0;
  text-align: center;
}
.login-sub {
  font-size: .95rem;
  color: #64748b;
  text-align: center;
  font-weight: 500;
  margin: 0;
}
.login-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin-top: 24px;
  margin-bottom: 14px;
}
.form-group {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.form-group label {
  font-size: .85rem;
  font-weight: 600;
  color: #0f172a;
}
.form-control {
  padding: 10px 14px;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  font-size: .95rem;
  font-family: inherit;
  outline: none;
  transition: border-color .2s, box-shadow .2s;
}
.form-control:focus {
  border-color: #60a5fa;
  box-shadow: 0 0 0 3px rgba(96,165,250,.1);
}
.btn {
  padding: 11px 16px;
  border-radius: 10px;
  font-size: .95rem;
  font-weight: 600;
  border: none;
  cursor: pointer;
  transition: all .2s;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.btn-primary {
  background: #1d4ed8;
  color: #fff;
}
.btn-primary:hover {
  background: #1e40af;
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(29,78,216,.3);
}
.btn-full {
  width: 100%;
}
.alert {
  padding: 12px 16px;
  border-radius: 10px;
  font-size: .9rem;
  margin-bottom: 16px;
}
.alert-danger {
  background: #fee2e2;
  border: 1px solid #fecaca;
  color: #dc2626;
}
.alert-close {
  background: none;
  border: none;
  color: inherit;
  cursor: pointer;
  font-size: 1.2rem;
  padding: 0;
  margin-left: auto;
}
.text-center {
  text-align: center;
}
.text-sm {
  font-size: .85rem;
}
.text-muted {
  color: #64748b;
}
.mt-16 {
  margin-top: 16px;
}
.mt-20 {
  margin-top: 20px;
}
</style>
</head>
<body class="login-body">
<div class="login-page-shell">
  <div class="login-page-main">
    <div class="login-wrap">
      <div class="login-card">
    <div class="login-logo">
      <?php if ($uiLogoPath !== ''): ?>
        <img src="<?= e(appUrl($uiLogoPath)) ?>" alt="Logo">
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

    <form method="POST" autocomplete="on" class="login-form" id="loginForm">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

      <div class="form-group">
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email"
               class="form-control" placeholder="you@company.com"
           value="<?= e($_POST['email'] ?? '') ?>" required autofocus
           autocomplete="username" inputmode="email" spellcheck="false" autocapitalize="off">
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password"
           class="form-control" placeholder="••••••••" required
           autocomplete="current-password" spellcheck="false" autocapitalize="off">
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
        <div class="line1">&copy; <?= date('Y') ?> <?= e($footerErpName) ?> &bull; ERP Master System v<?= e(APP_VERSION) ?></div>
        <div class="line2">@ Developed by Mriganka Bhusan Debnath</div>
      </div>
    </div>
  </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
</body>
</html>
