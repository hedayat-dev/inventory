<?php

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');

if (session_status() !== PHP_SESSION_ACTIVE) {
	$isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
	session_set_cookie_params([
		'httponly' => true,
		'secure' => $isSecure,
		'samesite' => 'Lax',
	]);
	session_start();
}

function auth_csrf_token(): string
{
	if (!isset($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}

	return $_SESSION['csrf_token'];
}

function auth_verify_csrf($token): bool
{
	return is_string($token)
		&& isset($_SESSION['csrf_token'])
		&& hash_equals($_SESSION['csrf_token'], $token);
}

function auth_is_logged_in(): bool
{
	return isset($_SESSION['user_id']) && is_int($_SESSION['user_id']) && $_SESSION['user_id'] > 0;
}

function auth_safe_next(string $target): string
{
	$allowedPages = ['index.php', 'stockin.php', 'stockout.php', 'stock_movement.php', 'masters.php'];
	$parts = parse_url($target);
	if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
		return 'index.php';
	}

	$page = basename($parts['path'] ?? '');
	if (!in_array($page, $allowedPages, true)) {
		return 'index.php';
	}

	$query = [];
	parse_str($parts['query'] ?? '', $parameters);
	if (isset($parameters['page']) && is_scalar($parameters['page']) && filter_var((string) $parameters['page'], FILTER_VALIDATE_INT) !== false && (int) $parameters['page'] > 0) {
		$query['page'] = (int) $parameters['page'];
	}
	if (isset($parameters['sort']) && is_string($parameters['sort']) && in_array($parameters['sort'], ['oldest', 'newest'], true)) {
		$query['sort'] = $parameters['sort'];
	}
	if (isset($parameters['per_page']) && is_scalar($parameters['per_page']) && in_array((int) $parameters['per_page'], [10, 25, 50, 100, 200, 500], true)) {
		$query['per_page'] = (int) $parameters['per_page'];
	}
	if (isset($parameters['add_product']) && $parameters['add_product'] === '1' && $page === 'index.php') {
		$query['add_product'] = '1';
	}

	return $page . ($query !== [] ? '?' . http_build_query($query) : '');
}

function require_login(bool $jsonResponse = false): void
{
	if (auth_is_logged_in()) {
		return;
	}

	if ($jsonResponse) {
		http_response_code(401);
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(['success' => false, 'message' => 'Please sign in to continue.']);
		exit;
	}

	$requestedPage = $_SERVER['REQUEST_URI'] ?? 'index.php';
	header('Location: login.php?next=' . rawurlencode(auth_safe_next($requestedPage)));
	exit;
}
