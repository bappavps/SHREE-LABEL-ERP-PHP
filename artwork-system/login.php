<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Db.php';
require_once __DIR__ . '/includes/functions.php';

appSessionStart();
$db = Db::getInstance();
ensureDefaultAuthUsers($db);

if (!ARTWORK_ALLOW_STANDALONE_LOGIN) {
	redirect(ERP_ARTWORK_BRIDGE_URL);
}

if (getAuthUser()) {
    redirect('designer/index.php');
}

$error = '';
$email = '';
$notice = '';

if (isset($_GET['logged_out']) && $_GET['logged_out'] === '1') {
    $notice = 'You have been logged out successfully.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$email = trim((string) ($_POST['email'] ?? ''));
	$password = (string) ($_POST['password'] ?? '');

	if ($email === '' || $password === '') {
		$error = 'Email and password are required.';
	} else {
		$user = authenticateUser($db, $email, $password);
		if (!$user) {
			$error = 'Invalid login credentials.';
		} else {
			setAuthSession($user);
			redirect('designer/index.php');
		}
	}
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Login - <?php echo APP_NAME; ?></title>
	<link rel="stylesheet" href="assets/css/style.css">
	<style>
		.auth-wrap {
			min-height: 100vh;
			display: grid;
			place-items: center;
			padding: 1.5rem;
			background: radial-gradient(circle at 12% 10%, #d9f3ee 0%, rgba(217, 243, 238, 0) 36%), radial-gradient(circle at 88% 85%, #fde6d5 0%, rgba(253, 230, 213, 0) 32%), linear-gradient(160deg, #f5f7f8, #e9eff2);
		}

		.auth-card {
			width: min(460px, 100%);
			background: rgba(255, 255, 255, 0.9);
			border: 1px solid rgba(255, 255, 255, 0.9);
			border-radius: 20px;
			box-shadow: 0 18px 38px rgba(16, 38, 46, 0.14);
			padding: 1.5rem;
		}

		.auth-title {
			margin: 0 0 0.35rem;
			font-size: 1.35rem;
			font-weight: 800;
			color: #0a5b55;
		}

		.auth-muted {
			margin: 0 0 1.1rem;
			color: #5d727b;
			font-size: 0.9rem;
		}

		.auth-group {
			margin-bottom: 0.9rem;
		}

		.auth-label {
			display: block;
			margin-bottom: 0.45rem;
			font-size: 0.86rem;
			font-weight: 700;
			color: #142226;
		}

		.auth-input {
			width: 100%;
			border-radius: 10px;
			border: 1px solid #dce6eb;
			padding: 0.7rem 0.85rem;
			font-size: 0.92rem;
			outline: none;
		}

		.auth-input:focus {
			border-color: #73bcb4;
			box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.14);
		}

		.auth-error {
			background: #fee2e2;
			color: #b91c1c;
			border: 1px solid #fecaca;
			border-radius: 10px;
			padding: 0.65rem 0.75rem;
			font-size: 0.84rem;
			margin-bottom: 0.9rem;
		}

		.auth-notice {
			background: #ecfeff;
			color: #0f766e;
			border: 1px solid #99f6e4;
			border-radius: 10px;
			padding: 0.65rem 0.75rem;
			font-size: 0.84rem;
			margin-bottom: 0.9rem;
		}

		.auth-btn {
			width: 100%;
			border: none;
			border-radius: 10px;
			background: linear-gradient(120deg, #0f766e, #14b8a6);
			color: #fff;
			padding: 0.75rem 1rem;
			font-weight: 700;
			cursor: pointer;
			margin-top: 0.2rem;
		}

		.auth-cred-box {
			margin-top: 1rem;
			border: 1px dashed #b7d6d2;
			border-radius: 12px;
			background: #f0f7f6;
			padding: 0.85rem;
		}

		.auth-cred-title {
			margin: 0 0 0.45rem;
			font-size: 0.78rem;
			color: #0a5b55;
			font-weight: 800;
			text-transform: uppercase;
			letter-spacing: 0.04em;
		}

		.auth-cred-item {
			margin: 0.25rem 0;
			font-size: 0.84rem;
			color: #334155;
		}
	</style>
</head>
<body>
	<div class="auth-wrap">
		<form class="auth-card" method="post" novalidate>
			<h1 class="auth-title">Artwork Approval Hub</h1>
			<p class="auth-muted">Sign in to continue to your dashboard.</p>

			<?php if ($notice !== ''): ?>
				<div class="auth-notice"><?php echo sanitize($notice); ?></div>
			<?php endif; ?>

			<?php if ($error !== ''): ?>
				<div class="auth-error"><?php echo sanitize($error); ?></div>
			<?php endif; ?>

			<div class="auth-group">
				<label class="auth-label" for="email">Email</label>
				<input class="auth-input" id="email" name="email" type="email" value="<?php echo sanitize($email); ?>" autocomplete="username" required>
			</div>

			<div class="auth-group">
				<label class="auth-label" for="password">Password</label>
				<input class="auth-input" id="password" name="password" type="password" autocomplete="current-password" required>
			</div>

			<button class="auth-btn" type="submit">Login</button>

			<div class="auth-cred-box">
				<p class="auth-cred-title">Temporary Login Credentials</p>
				<p class="auth-cred-item">Admin: admin@example.com / admin123</p>
				<p class="auth-cred-item">Designer: designer@example.com / designer123</p>
			</div>
		</form>
	</div>
</body>
</html>
