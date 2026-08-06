<?php
/* The login screen.
 *
 * Deliberately NOT gated — require_login_page() redirects here, so gating it
 * would be an infinite redirect. Rendered standalone rather than sharing a
 * header/footer with index.php: a signed-out visitor must not be shown any
 * authenticated chrome, and there's currently only one other screen anyway.
 *
 * One password, no username — matching every sibling app (see lib/auth.php).
 * Also enforces the login-throttle lockout (lib/auth.php's login_attempts
 * table): a client that has failed too many times in the window gets turned
 * away with a wait time instead of being allowed to keep guessing. */

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

// Checked before touching the POST body at all: a locked-out client doesn't
// get to spend a password guess just to find out it was going to be refused.
$blockedFor = auth_blocked_for();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $redirect = keepsake_safe_redirect_target($_POST['redirect'] ?? null);

    if ($blockedFor > 0) {
        $error = 'Too many attempts. Try again in ' . $blockedFor . ' seconds.';
    } elseif ($password === '') {
        $error = 'Please enter a password.';
    } elseif (!auth_is_configured()) {
        // Say so plainly instead of failing as a wrong password — with the
        // gate failing open, the app is fully usable in this state, and
        // "incorrect password" would send you hunting for a typo that isn't
        // there.
        $error = 'No password is set up yet. Run tools/make-hash.php to create one.';
    } elseif (auth_attempt_login($password)) {
        header('Location: ' . $redirect);
        exit;
    } else {
        $error = 'Incorrect password.';
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
        <span class="label">Password</span>
        <input
          class="input"
          type="password"
          name="password"
          autocomplete="current-password"
          autofocus
          required>
      </label>

      <?php if ($error !== null): ?>
        <p class="login-error" role="alert"><?= h($error) ?></p>
      <?php endif; ?>

      <button class="btn-primary" type="submit"<?= $blockedFor > 0 ? ' disabled' : '' ?>>Log in</button>
    </form>
  </main>
</body>
</html>
