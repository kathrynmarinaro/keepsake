<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Keepsake\Auth;

Auth::requireLogin();

$pageTitle = 'Year Projects';

require __DIR__ . '/../src/views/partials/header.php';
require __DIR__ . '/../src/views/dashboard.php';
require __DIR__ . '/../src/views/partials/footer.php';
