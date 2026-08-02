<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Services\UserService;
use App\Support\Validator;

SessionAuth::requireAuth();
SessionAuth::requirePermission('users.manage');

$user = SessionAuth::user();
$service = new UserService();
$id = (int) ($_POST['id'] ?? 0);

$v = Validator::make($_POST, [
    'full_name' => 'required|string|min_length:3',
    'username'  => 'required|string|min_length:3|regex:/^[a-zA-Z0-9_.-]+$/',
    'mobile'    => 'nullable|mobile',
    'email'     => 'nullable|email',
]);

try {
    if ($v->fails()) {
        flash('error', implode(' ', array_merge(...array_values($v->errors()))));
    } elseif ($id > 0) {
        $service->update($id, $_POST);
        flash('success', 'User updated.');
    } else {
        $service->create($_POST, $user->id());
        flash('success', 'User created. Default password: Welcome@123');
    }
} catch (Throwable $e) {
    flash('error', exception_message($e));
}
redirect('mis/users/index.php');
