<?php

declare(strict_types=1);

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Support\Validator;

if (SessionAuth::check()) {
    redirect('mis/dashboard.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $validator = Validator::make($_POST, [
        'username' => 'required|string',
        'password' => 'required|string',
    ]);

    if ($validator->fails()) {
        $error = 'Please enter both username and password.';
    } elseif (SessionAuth::attempt((string) $_POST['username'], (string) $_POST['password'])) {
        $loggedIn = SessionAuth::user();
        if ($loggedIn !== null && $loggedIn->hasPortal('mis')) {
            flash('success', 'Welcome back!');
            redirect($loggedIn->homeUrl());
        }
        SessionAuth::logout();
        $error = 'You do not have access to the MIS portal.';
    } else {
        $error = 'Invalid credentials or account is locked.';
    }
}

$errorHtml = $error !== null
    ? '<div class="alert alert-danger py-2">' . e($error) . '</div>'
    : '';

ob_start(); ?>
<div class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
    <div class="card shadow-sm" style="width:400px;">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <i class="bi bi-clipboard-data fs-1 text-success"></i>
                <h4 class="mt-2 mb-0">BCD Survey Platform</h4>
                <p class="text-muted small">MIS Portal</p>
            </div>
            <?= $errorHtml ?>
            <form method="post" autocomplete="off">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-success"><i class="bi bi-box-arrow-in-right me-1"></i>Sign In</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php $content = ob_get_clean();

echo view('layout', [
    'title'   => 'Login',
    'content' => $content,
]);
