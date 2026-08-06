<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Keepsake\Auth;

// Logout is a state change, so it's only wired to a POST form (see
// partials/header.php) rather than a plain link — avoids a stray GET
// (link prefetch, crawler, etc.) silently logging Kathryn out.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::logout();
}

header('Location: /login.php');
exit;
