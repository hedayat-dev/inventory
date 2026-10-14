<?php
require_once __DIR__ . '/settings/routes.php';
require_once __DIR__ . '/auth.php';

$error = '';
$nextTarget = $_POST['next'] ?? $_GET['next'] ?? 'index.php';
$next = auth_safe_next(is_string($nextTarget) ? $nextTarget : 'index.php');
$connection = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfToken = $_POST['csrf_token'] ?? null;
	if (!auth_verify_csrf(is_string($csrfToken) ? $csrfToken : null)) {
		http_response_code(400);
		$error = 'Your session expired. Please try again.';
	} elseif (($_POST['action'] ?? '') === 'logout') {
		$_SESSION = [];
		if (ini_get('session.use_cookies')) {
			$cookieParameters = session_get_cookie_params();
			setcookie(session_name(), '', [
				'expires' => time() - 42000,
				'path' => $cookieParameters['path'],
				'domain' => $cookieParameters['domain'],
				'secure' => $cookieParameters['secure'],
				'httponly' => $cookieParameters['httponly'],
				'samesite' => $cookieParameters['samesite'] ?? 'Lax',
			]);
		}
		session_destroy();
		header('Location: login.php?logged_out=1');
		exit;
	} else {
		$usernameInput = $_POST['username'] ?? '';
		$username = is_string($usernameInput) ? trim($usernameInput) : '';
		$password = $_POST['password'] ?? '';

		if ($username === '' || strlen($username) > 100 || !is_string($password) || $password === '' || strlen($password) > 4096) {
			$error = 'Enter a valid username and password.';
		} else {
			try {
				$connection = Database::getConnection();
				$statement = mysqli_prepare($connection, 'SELECT id, username, password_hash FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
				mysqli_stmt_bind_param($statement, 's', $username);
				mysqli_stmt_execute($statement);
				$user = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
				//print_r($user);
				if ($user && password_verify($password, $user['password_hash'])) {
					session_regenerate_id(true);
					$_SESSION['user_id'] = (int) $user['id'];
					$_SESSION['username'] = $user['username'];
					unset($_SESSION['csrf_token']);
					header('Location: ' . $next);
					exit;
				}

				$error = 'Invalid username or password.';
			} catch (mysqli_sql_exception $exception) {
				$error = 'Login is not configured yet. Import the users table from tables.sql, then create a user account.';
			}
		}

	}
}

if (auth_is_logged_in() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
	header('Location: ' . $next);
	exit;
}

if (isset($_GET['logged_out'])) {
	$loggedOut = true;
} else {
	$loggedOut = false;
}

function login_output(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Login | Inventory Management</title>
	<link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
	<main class="login-card">
		<p class="eyebrow">Inventory Management</p>
		<h1>Sign in</h1>
		<p class="login-intro">Sign in to manage inventory.</p>
		<?php if ($loggedOut): ?>
			<div class="notice success" role="status">You have been signed out.</div>
		<?php endif; ?>
		<?php if ($error !== ''): ?>
			<div class="notice error" role="alert"><?= login_output($error) ?></div>
		<?php endif; ?>
		<form method="post" action="login.php">
			<input type="hidden" name="csrf_token" value="<?= login_output(auth_csrf_token()) ?>">
			<input type="hidden" name="next" value="<?= login_output($next) ?>">
			<div class="field">
				<label for="username">Username</label>
				<input id="username" name="username" type="text" maxlength="100" autocomplete="username" required autofocus>
			</div>
			<div class="field">
				<label for="password">Password</label>
				<input id="password" name="password" type="password" autocomplete="current-password" required>
			</div>
			<button type="submit">Sign in</button>
		</form>
	</main>
</body>
</html>
