<?php
/**
 * Shared page header: opens the HTML document, renders the common styles,
 * masthead, navigation menu and any flash message.
 *
 * Pages must set the following variables before requiring this file:
 *   $pageTitle         string  <title> text
 *   $heading           string  H1 heading text
 *   $subtitle          string  Text shown above the date in the masthead
 *   $activeMasterLink  bool    Highlight the "Master Management" link (optional)
 *   $bodyClass         string  Extra class(es) added to <body>, e.g. 'page-stockout' (optional)
 *   $message           string  Flash message text (optional)
 *   $messageType       string  'success' or 'error' (optional)
 */

$pageTitle = $pageTitle ?? 'Inventory Management';
$heading = $heading ?? '';
$subtitle = $subtitle ?? '';
$activeMasterLink = $activeMasterLink ?? false;
$bodyClass = $bodyClass ?? '';
$message = $message ?? '';
$messageType = $messageType ?? '';

if (!function_exists('output')) {
	function output(string $value): string
	{
		return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?= output($pageTitle) ?></title>	
	<link rel="stylesheet" href="style.css">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.7.2/css/all.min.css">
	<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="<?= output($bodyClass) ?>">
	<main class="shell">
		<header class="masthead">
			<div>
				<p class="eyebrow">Inventory Management</p>
				<h1><?= output($heading) ?></h1>
			</div>
			<div class="date"><?= date('F j, Y') ?><br><?= output($subtitle) ?></div>
		</header>
		<div class="menu-row">
			<nav class="menu" aria-label="Main navigation">
				<button class="menu-toggle" id="menu-toggle" type="button" aria-expanded="false" aria-controls="menu-list">
					<span class="menu-icon" aria-hidden="true"><span></span><span></span><span></span></span>
					Menu
				</button>
				<ul class="menu-list" id="menu-list" hidden>
					<li class="menu-user">Signed in as <?= output($_SESSION['username'] ?? 'User') ?></li>
					<li>
						<form method="post" action="login.php">
							<input type="hidden" name="action" value="logout">
							<input type="hidden" name="csrf_token" value="<?= output(auth_csrf_token()) ?>">
							<button class="menu-logout" type="submit">Log out</button>
						</form>
					</li>
					<li><a href="index.php">Dashboard</a></li>
					<li><a href="index.php?add_product=1">Add product</a></li>
					<li><a href="stockin.php">Stock In</a></li>
					<li><a href="stockout.php">Stock Out</a></li>
                    <li><a href="stock_movement.php">View stock movement/history</a></li>
					<!-- <li><a href="stockout.php#history">View stock movement/history</a></li> -->
					<!-- <li><a href="profile.php">Profile/settings</a></li> -->
				</ul>
			</nav>
			<a class="master-link<?= $activeMasterLink ? ' active' : '' ?>" href="masters.php">Master Management</a>
		</div>

		<?php if ($message !== ''): ?>
			<div class="notice <?= output($messageType) ?>"><?= output($message) ?></div>
		<?php endif; ?>
