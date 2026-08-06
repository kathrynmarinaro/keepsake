<?php

declare(strict_types=1);

/**
 * Login page. Standalone shell (not partials/header+footer) since there's
 * no authenticated nav to show yet — kept intentionally minimal.
 * Expects $error (string|null) and $redirect (string) from login.php.
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in · Keepsake</title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<div class="auth-shell">
    <main class="auth-card">
        <h1 class="auth-card__brand">Keepsake</h1>
        <p class="auth-card__tagline">Sign in to keep capturing the year.</p>

        <?php if ($error): ?>
            <div class="notice notice--error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/login.php" class="form-stack">
            <input type="hidden" name="redirect" value="<?= e($redirect) ?>">

            <label class="form-field">
                <span class="form-field__label">Username</span>
                <input
                    type="text"
                    name="username"
                    autocomplete="username"
                    required
                    autofocus
                >
            </label>

            <label class="form-field">
                <span class="form-field__label">Password</span>
                <input
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >
            </label>

            <button type="submit" class="button button--primary button--block">Log in</button>
        </form>
    </main>
</div>
</body>
</html>
