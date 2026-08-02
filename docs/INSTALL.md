# Installation & Deployment Guide

## Requirements
- PHP 8.2+ (ext: pdo, pdo_mysql, mbstring, openssl, json)
- MySQL 8 / MariaDB 10.4+
- Composer 2.x
- Apache with `mod_rewrite` (XAMPP/WAMP) or PHP built-in server (dev)

## Setup (local / XAMPP)

1. Clone or copy the project into `C:\xampp\htdocs\bcd-app`.
2. Install dependencies:
   ```bash
   composer install --no-interaction
   ```
3. Configure environment:
   ```bash
   copy config\.env.example config\.env
   ```
   Edit `config/.env`: set `DB_NAME`, `DB_USER`, `DB_PASS`, and generate a strong `JWT_SECRET`.

4. Create the database and seed:
   ```bash
   php database/migrate.php
   ```
   > `--fresh` drops and recreates all tables; `--seed` runs seeds only.

5. Serve:
   - Apache: open `http://localhost/bcd-app/mis/login.php`
   - Dev server: `php -S localhost:8090 -t .` → `http://localhost:8090/mis/login.php`

## Default login
- URL: `http://localhost/bcd-app/mis/login.php`
- Username: `admin`
- Password: `Admin@12345`
- Change immediately after first login.

## Verifying the install
```bash
php tests/smoke.php                 # end-to-end service tests
curl http://localhost/bcd-app/api/v1/health   # API health check
```

## Running the replication worker
```bash
php replication/worker.php --once   # process one queued job
php replication/worker.php          # drain the queue
php replication/worker.php --daemon # keep running (cron/systemd/scheduled task)
```

## Folder layout
```
admin/         Admin panel (future)
api/           REST API layer (JWT) + front controller
common/        Shared src (App\*), views, bootstrap, helpers
config/        Config templates + .env (gitignored)
database/      schema.sql, seed.sql, migrate.php
docs/          API + install docs
gis/           GIS dashboard (Leaflet)
mis/           MIS web portal (Bootstrap 5)
mobile/        React Native app (future)
replication/   Replication worker
reports/       Reporting engine entry (future)
uploads/       User uploads (gitignored)
logs/          Runtime logs (gitignored)
```

## Production notes
- Set `APP_DEBUG=false` in `.env`
- Force HTTPS; set `session.cookie_secure`
- Run replication worker as a managed background process
- Schedule `php database/migrate.php` for upgrade migrations
