<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Keepsake\Auth;

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
    // external URL) and not contain a scheme.
    if ($target[0] !== '/' || str_starts_with($target, '//') || str_contains($target, '://')) {
        return $default;
    }

    return $target;
}

if (Auth::check()) {
    header('Location: /index.php');
    exit;
}

$error = null;
$redirect = keepsake_safe_redirect_target($_GET['redirect'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $redirect = keepsake_safe_redirect_target($_POST['redirect'] ?? null);

    if ($username === '' || $password === '') {
        $error = 'Please enter both a username and password.';
    } elseif (Auth::attempt($username, $password)) {
        header('Location: ' . $redirect);
        exit;
    } else {
        $error = 'Incorrect username or password.';
    }
}

$pageTitle = 'Log in';

require __DIR__ . '/../src/views/login.php';
