<?php
/**
 * Shared page shell — top half. Included by every logged-in page.
 * Expects `$pageTitle` (string) to be set by the caller before including
 * this file. Closed by partials/footer.php.
 */
declare(strict_types=1);

use Keepsake\Auth;

$currentUser = Auth::user();
$pageTitle = $pageTitle ?? 'Keepsake';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · Keepsake</title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<div class="app-shell">
    <header class="site-header">
        <div class="site-header__inner">
            <a class="site-header__brand" href="/index.php">Keepsake</a>
            <?php if ($currentUser): ?>
                <nav class="site-header__nav" aria-label="Primary">
                    <a href="/index.php">Year Projects</a>
                    <form class="site-header__logout" method="post" action="/logout.php">
                        <button type="submit" class="link-button">Log out</button>
                    </form>
                </nav>
            <?php endif; ?>
        </div>
    </header>
    <main class="site-main">
