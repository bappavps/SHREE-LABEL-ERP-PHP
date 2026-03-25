<?php
// ============================================================
// Shree Label ERP — Login Page
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="login-body">
<div class="login-wrap">
  <div class="login-card">
    <div class="login-logo"><i class="bi bi-layers"></i></div>
    <h1 class="login-title"><?= APP_NAME ?></h1>
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
               class="form-control" placeholder="you@shreelabel.com"
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
      Default admin: <strong>admin@shreelabel.com</strong> / <strong>admin123</strong>
    </p>
  </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
</body>
</html>
