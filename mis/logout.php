<?php

declare(strict_types=1);

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;

SessionAuth::logout();
redirect('mis/login.php');
