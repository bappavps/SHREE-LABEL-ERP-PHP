<?php
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter email and password.';
    } else {
        $conn  = db();
        $email = $conn->real_escape_string($email);
        $result = $conn->query("SELECT id, name, email, password, role FROM users WHERE email = '$email' AND status = 1");
        if ($result && $row = $result->fetch_assoc()) {
            if (password_verify($password, $row['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']   = $row['id'];
                $_SESSION['user_name'] = $row['name'];
                $_SESSION['user_role'] = $row['role'];
                header('Location: dashboard.php');
                exit;
            }
        }
        $error = 'Invalid email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #1a3c6e 0%, #0d2244 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            width: 100%;
            max-width: 420px;
        }
        .login-logo {
            background: linear-gradient(135deg, #1a3c6e, #e8501a);
            width: 70px; height: 70px;
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px;
            font-size: 2rem;
            color: #fff;
        }
        .btn-login {
            background: linear-gradient(135deg, #1a3c6e, #e8501a);
            border: none;
            color: #fff;
            padding: 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .btn-login:hover { opacity: 0.9; color: #fff; }
        .form-control:focus { border-color: #1a3c6e; box-shadow: 0 0 0 3px rgba(26,60,110,0.15); }
    </style>
</head>
<body>
<div class="login-card">
    <div class="login-logo"><i class="fas fa-tags"></i></div>
    <h4 class="text-center mb-1 fw-bold">Shree Label ERP</h4>
    <p class="text-center text-muted small mb-4">Sign in to your account</p>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-sm py-2 small"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="mb-3">
            <label class="form-label fw-semibold">Email Address</label>
            <div class="input-group">
                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                <input type="email" name="email" class="form-control"
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                       placeholder="admin@shreelabel.com" required autofocus>
            </div>
        </div>
        <div class="mb-4">
            <label class="form-label fw-semibold">Password</label>
            <div class="input-group">
                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                <input type="password" name="password" class="form-control" placeholder="••••••••" required>
            </div>
        </div>
        <button type="submit" class="btn btn-login w-100 rounded-3">
            <i class="fas fa-sign-in-alt me-2"></i>Sign In
        </button>
    </form>

    <div class="text-center mt-4 text-muted small">
        <i class="fas fa-info-circle me-1"></i>
        Default: admin@shreelabel.com / password
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
