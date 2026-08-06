<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

// Logout is a state change, so it's only wired to a POST form (see
// index.php) rather than a plain link — avoids a stray GET (link prefetch,
// crawler, etc.) silently logging Kathryn out.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    auth_logout();
}

header('Location: /login.php');
exit;
