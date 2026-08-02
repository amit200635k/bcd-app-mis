<?php

declare(strict_types=1);

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;

SessionAuth::requireAuth();
redirect('admin/dashboard.php');
