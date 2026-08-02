# MEMORY — BCD Survey Platform Session State

> Continuation/memory document for AI agents and future syncs.
> Update this file at the end of each working session and commit it so the
> context travels with the repo (local + GitHub).

## Goal

Build/continue the **BCD Generic Dynamic Survey Platform** at `C:\xampp\htdocs\bcd-app`, synced to GitHub (`https://github.com/amit200635k/bcd-app-mis`, branch `main`).

Current state of the product: Phases 0–3 + parts of 5–8 of `ROADMAP.md`, plus an admin panel, a headed-Chrome E2E harness, master-data field type, and dependent location-dropdown (cascade) field type.

## Instructions / Environment

- PHP 8.2 via XAMPP, MariaDB 10.4.32 (root, no password, port 3306), Node 24, npm 11.5.1, Puppeteer 23.11.1.
- **XAMPP Apache is on port 81** (port 80 is IIS on this machine). `APP_URL=http://localhost:81/bcd-app` is set in `config/.env` (gitignored).
- Login: `admin` / `Admin@12345` (state admin). Demo users (`dh_surveyor`, `rk_surveyor`, `jb_block`, `sk_district`) have password `Demo@123`.
- `.env`, `config/*.php` (except `.example.php`) are gitignored. `.gitignore` ignores `node_modules/` at any depth.
- Sync to git after every milestone (commit + `git push origin main`).
- Run smoke tests with `php tests/smoke.php`; E2E with `node tests/e2e/run.js` (headed Chrome; auto-resets DB at start/end).
- `tests/e2e/reset.php` purges E2E/smoke artifacts (`E2E_`, `e2e_`, `test.`, `E2E-`, `SMOKE_`, `Smoke`, `Test Dist`, `E2E_GRP_%`) so the demo stays pristine.
- Follow the phasing in `ROADMAP.md`.

## Key Architecture

- **DB schema**: `database/schema.sql`; seed data via `database/seed_demo.php` + `database/seed_jharkhand.php` (idempotent, 24 districts / 96 blocks / 288 panchayats / 1152 villages).
- **Core services** in `common/src/Services/` (SurveyService, RecordService, UserService, LocationService, NotificationService, ReplicationService, ReportService).
- **Auth**: session (`SessionAuth`, MIS + admin) and JWT (`ApiAuth`, mobile REST via `api/index.php`).
- **Router**: `common/src/Http/Router.php` (route params `{id}`).
- **API front controller** `api/index.php`; `.htaccess` rewrites `^api(?:/(.*))?$` only when the physical file doesn't exist.
- **MIS pages** under `mis/`, admin panel under `admin/`, layouts in `common/views/`.

## Discoveries & Gotchas (IMPORTANT)

- Apache port 80 is IIS → test via `http://localhost:81/bcd-app`.
- **Authorization header bug (FIXED):** XAMPP Apache exposes `Authorization` via `getallheaders()` but NOT `$_SERVER['HTTP_AUTHORIZATION']`. `Request::header()` now falls back to `getallheaders()`; without this, every token-authed API call returns 401.
- `.htaccess` only rewrites `^api` to the front controller when a physical file doesn't exist, so `/api/dropdowns.php` and `/api/user_data.php` remain real scripts.
- MIS sidebar uses `url()` absolute links; admin sidebar uses relative links (admin pages live in `admin/`).
- `smoke.php` pollutes the live DB (creates `Test Dist`, `SMOKE_*` forms, `Smoke` notification) — `reset.php` cleans those too.
- `SurveyService::saveStructure()` accepts both `label/value` and `option_label/option_value` option conventions and skips empty option rows.
- `mis/users/index.php` `openModal()` must reset fields **after** async dropdown loading (race fixed).
- `plain_password` column on `users` is dev-only (null in production), shown as “Password (dev)” in MIS users list, stripped from `user_data.php`.
- `seed_jharkhand.php` is idempotent (`ON DUPLICATE KEY UPDATE`); child rows re-query parent ids (never rely on `lastInsertId` with upserts).

## Feature Blueprints (current design decisions)

### Master-data field type (`master`)
- Admin CRUD at `admin/masters.php` (groups + items; system groups like `DISTRICT` are undeletable; audit-logged).
- Field settings: `settings_json → {"master_group_id": N}`.
- `SurveyService::fields()` hydrates master fields with `options` = active `master_items` (label=name, value=id) + `master_group_id/name`.
- `RecordService::normalizeMaster()` stores answer as `value_text = item name`, `value_json = {"master_id":N,"name":"…"}` (resolves by id, falls back name→id by group).
- `master_items` has `UNIQUE KEY uq_master_items_group_code (group_id, code)` for idempotent sync.
- DISTRICT master items (24 districts) are synced from the `districts` table by `seed_jharkhand.php`.

### Dependent location dropdowns (`location_cascade`)
- Field type chains District → Block → Panchayat → Village; builder checkboxes pick included levels → `settings.levels`.
- API (Bearer auth, for mobile):
  - `GET /api/v1/location/children?level=block&parent_id=5` → `{scope, items:[{id,name}]}`.
  - `GET /api/v1/location/scope` → user's fixed scope `{district_id, block_id, panchayat_id, village_id}`.
- Session-based feed for MIS preview: `api/dropdowns.php?type=scope|district|block|panchayat|village`.
- Scope-aware: state admin sees all districts and cascades down; district/block-scoped user gets the topmost scoped level pre-selected + locked and the next level auto-populated.
- `RecordService::normalizeLocation()` stores `value_text = "Ranchi / Kanke / …"` and `value_json = {"district_id":…,"district_name":…,…}`.
- Mobile client (not in this repo) must call `/v1/location/scope` on form load and `/v1/location/children` on each select change.

## E2E Harness

- `tests/e2e/run.js` — headed Chrome (Puppeteer), resets DB at start + end. Runs `mis` and/or `admin` suites (`node tests/e2e/run.js [all|mis|admin]`). Current total: 119 checks, all passing.
- Helpers in `tests/e2e/lib.js` (`BASE`, `CREDS`, `step/check/ok`, `clickText`, `type`, `waitForText`, `hasText`, `assertNoPhpWarnings`, `wirePage`, `summary`); dialogs auto-accepted.
- Form submits in tests go through `page.evaluate(form.submit())` (bypasses `onsubmit` confirm dialogs).
- Puppeteer does NOT support `:has-text()`; use `page.evaluate` + `Array.from(...).find`.

## Milestones (git history)

- `06b5a1c` — location_cascade field type + `/v1/location/*` API + scope-aware preview + `Request::header()` fix. (latest)
- `7641300` — master-data field type (admin CRUD, builder linkage, id+name storage) + draft-form preview.
- `d088f8b` — survey builder option save fix + user-modal race fix + `plain_password` dev column.
- `e1b958c` — E2E suite (headed Chrome) + bugs it surfaced (.htaccess API rewrite, absolute sidebar URLs, `../../api/` fetch fix, audit logging for auth + transitions).
- `9279818` — gitignore fix (untracked nested `tests/e2e/node_modules`).
- `f9152c5` — admin panel + Jharkhand seed data.
- `400b6c0` — notifications, replication queue, docs, smoke tests.

## Next Steps / Ideas (not yet done)

- Mobile app itself is NOT in this repo — only the backend/API it consumes.
- Possible future work: `master` + `location_cascade` answer rendering in MIS reports/monitoring tables; cascade auto-population on edit of an existing record; validation of master/cascade answers on the API; field-level permissions.

## Relevant Files

- **Schema/Seed:** `database/schema.sql`, `database/seed.sql`, `database/seed_demo.php`, `database/seed_jharkhand.php`
- **Core services:** `common/src/Services/SurveyService.php`, `common/src/Services/RecordService.php`, `common/src/Services/UserService.php`
- **HTTP/Auth:** `common/src/Http/Request.php` (header fix), `common/src/Http/Router.php`, `common/src/Http/Response.php`, `common/src/Auth/SessionAuth.php`, `common/src/Auth/ApiAuth.php`
- **API:** `api/index.php`, `api/dropdowns.php`, `api/user_data.php`, `api/Controllers/LocationController.php`, `api/Controllers/MasterController.php`, `api/Controllers/FormController.php`
- **Builder UI:** `mis/builder/edit.php`, `mis/builder/preview.php`, `mis/builder/index.php`
- **Admin panel:** `admin/masters.php`, `admin/dashboard.php`, `admin/settings.php`, `admin/notifications.php`, `admin/audit.php`, `admin/replication.php`, `admin/health.php`
- **Layouts:** `common/views/layout.php` (MIS), `common/views/admin_layout.php` (admin)
- **Tests:** `tests/smoke.php`, `tests/e2e/run.js`, `tests/e2e/lib.js`, `tests/e2e/reset.php`
- **Config (gitignored):** `config/.env` (`APP_URL=http://localhost:81/bcd-app`)
- **Root:** `.htaccess`, `.gitignore`, `ROADMAP.md`, `readme.md`, `docs/API.md`, `docs/INSTALL.md`, `MEMORY.md` (this file)
