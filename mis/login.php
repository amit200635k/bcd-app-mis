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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>Sign In — BCD Survey Platform</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #0d9488;
            --primary-dark: #0f766e;
            --bg: #f0f4f8;
            --card-shadow: 0 4px 24px rgba(0,0,0,.08);
            --radius: .75rem;
        }

        * { box-sizing: border-box; }

        body {
            background: linear-gradient(135deg, #0d9488 0%, #134e4a 50%, #0a3d3a 100%);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .login-card {
            background: #fff;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            width: 100%;
            max-width: 420px;
            overflow: hidden;
        }

        .login-header {
            background: linear-gradient(135deg, #0d9488, #134e4a);
            padding: 2rem 1.5rem 1.5rem;
            text-align: center;
            color: #fff;
        }

        .login-header .brand-icon {
            width: 56px;
            height: 56px;
            background: rgba(255,255,255,.15);
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: .75rem;
        }

        .login-header h1 {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: .25rem;
        }

        .login-header p {
            font-size: .85rem;
            opacity: .85;
            margin-bottom: 0;
        }

        .login-body {
            padding: 1.5rem;
        }

        .login-body .form-label {
            font-weight: 500;
            font-size: .85rem;
            color: #475569;
            margin-bottom: .35rem;
        }

        .login-body .form-control {
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            padding: .55rem .75rem;
            font-size: .9rem;
        }

        .login-body .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(13,148,136,.12);
        }

        .login-body .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            border-radius: 6px;
            padding: .55rem 1rem;
            font-weight: 600;
            font-size: .9rem;
        }

        .login-body .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        .alert {
            border-radius: 6px;
            font-size: .85rem;
        }

        .login-footer {
            text-align: center;
            padding: 1rem 1.5rem;
            border-top: 1px solid #f1f5f9;
            font-size: .78rem;
            color: #94a3b8;
        }

        @media (max-width: 480px) {
            body { padding: .5rem; }
            .login-header { padding: 1.5rem 1rem 1.25rem; }
            .login-body { padding: 1rem; }
        }
    </style>
</head>
<body>
<div class="login-card">
    <div class="login-header">
        <div class="brand-icon"><i class="bi bi-clipboard-data"></i></div>
        <h1>BCD Survey Platform</h1>
        <p>MIS Portal — Sign in to continue</p>
    </div>
    <div class="login-body">
        <?php if ($error !== null): ?>
        <div class="alert alert-danger d-flex align-items-center">
            <i class="bi bi-exclamation-triangle me-2"></i><?= e($error) ?>
        </div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <div class="mb-3">
                <label class="form-label" for="username">Username</label>
                <input type="text" name="username" id="username" class="form-control" required autofocus placeholder="Enter your username">
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <input type="password" name="password" id="password" class="form-control" required placeholder="Enter your password">
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-primary"><i class="bi bi-box-arrow-in-right me-1"></i>Sign In</button>
            </div>
        </form>
    </div>
    <div class="login-footer">
        &copy; <?= date('Y') ?> BCD Survey Platform
    </div>
</div>
</body>
</html>