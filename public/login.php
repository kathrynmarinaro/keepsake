<?php
/* The login screen.
 *
 * Deliberately NOT gated — require_login_page() redirects here, so gating it
 * would be an infinite redirect. Rendered standalone rather than sharing a
 * header/footer with index.php: a signed-out visitor must not be shown any
 * authenticated chrome, and there's currently only one other screen anyway.
 *
 * Username + password against the `users` table, not a single site-wide
 * password — see lib/auth.php for why Keepsake diverges from the sibling
 * config-only pattern here. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

noindex();

if (auth_is_logged_in()) {
    header('Location: /index.php');
    exit;
}

/**
 * Only allow redirecting back to a local, same-app path — never an
 * absolute/external URL — to avoid this becoming an open redirect.
 */
function keepsake_safe_redirect_target(?string $target): string
{
    $default = '/index.php';

    if (!is_string($target) || $target === '') {
        return $default;
    }

    // Must start with a single "/" (local path), not "//" (protocol-relative
    // external URL), and not contain a scheme.
    if ($target[0] !== '/' || str_starts_with($target, '//') || str_contains($target, '://')) {
        return $default;
    }

    return $target;
}

$error = null;
$redirect = keepsake_safe_redirect_target($_GET['redirect'] ?? null);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $redirect = keepsake_safe_redirect_target($_POST['redirect'] ?? null);

    if ($username === '' || $password === '') {
        $error = 'Please enter both a username and password.';
    } elseif (!auth_is_configured()) {
        // Say so plainly instead of failing as a wrong password — with the
        // gate failing open, the app is fully usable in this state, and
        // "incorrect password" would send you hunting for a typo that isn't
        // there.
        $error = 'No user is set up yet. Run tools/seed_user.php to create one.';
    } elseif (auth_attempt_login($username, $password)) {
        header('Location: ' . $redirect);
        exit;
    } else {
        $error = 'Incorrect username or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Keepsake</title>
<link rel="stylesheet" href="<?= asset('assets/styles.css') ?>">
</head>
<body class="login-body">
  <main class="login-card">
    <h1 class="login-title">Keepsake</h1>

    <form method="post" action="/login.php" class="login-form" autocomplete="on">
      <input type="hidden" name="redirect" value="<?= h($redirect) ?>">

      <label class="field">
        <span class="label">Username</span>
        <input
          class="input"
          type="text"
          name="username"
          autocomplete="username"
          autofocus
          required>
      </label>

      <label class="field">
        <span class="label">Password</span>
        <input
          class="input"
          type="password"
          name="password"
          autocomplete="current-password"
          required>
      </label>

      <?php if ($error !== null): ?>
        <p class="login-error" role="alert"><?= h($error) ?></p>
      <?php endif; ?>

      <button class="btn-primary" type="submit">Log in</button>
    </form>
  </main>
</body>
</html>
