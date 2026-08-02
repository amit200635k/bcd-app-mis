<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Services\UserService;

SessionAuth::requireAuth();
SessionAuth::requirePermission('users.manage');

$current = SessionAuth::user();
$id = (int) ($_POST['id'] ?? 0);
if ($id === $current->id()) {
    flash('error', 'You cannot deactivate your own account.');
} else {
    (new UserService())->deactivate($id);
    flash('success', 'User deactivated.');
}
redirect('mis/users/index.php');
