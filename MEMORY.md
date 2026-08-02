# MEMORY — BCD Survey Platform Session State

> Continuation/memory document for AI agents and future syncs.
> Update this file at the end of each working session and commit it so the
> context travels with the repo (local + GitHub).

## Goal

Build/continue the **BCD Generic Dynamic Survey Platform** at `C:\xampp\htdocs\bcd-app`, synced to GitHub (`https://github.com/amit200635k/bcd-app-mis`, branch `main`).

Current state of the product: Phases 0–3 + parts of 5–8 of `ROADMAP.md`, plus an admin panel, a headed-Chrome E2E harness, master-data field type, dependent location-dropdown (cascade) field type, the "edit published form + sync to all users" flow, the full **Government Building Survey** form (17 sections / 132 fields, published), **RBAC portal + per-user form access** (admin assigns MIS/Admin portal + which surveys a user may fill/view), and the expanded **mobile REST API** (records show/photos, devices, sync status).

## Instructions / Environment

- PHP 8.2 via XAMPP, MariaDB 10.4.32 (root, no password, port 3306), Node 24, npm 11.5.1, Puppeteer 23.11.1.
- **XAMPP Apache is on port 81** (port 80 is IIS on this machine). `APP_URL=http://localhost:81/bcd-app` is set in `config/.env` (gitignored).
- Login: `admin` / `Admin@12345` (state admin). Demo users (`dh_surveyor`, `rk_surveyor`, `jb_block`, `sk_district`) have password `Demo@123`.
- `database/seed_govt_building.php` (already executed) created + published form `GOVT_BUILDING_SURVEY` (id=40, 17 sections / 132 fields) and master groups `DEPARTMENT` + `BUILDING_SUBCATEGORY`; `database/seed_portal_access.php` backfills portal access for admin + demo users.
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
- **`UserService::create()`/`update()` must convert empty-string fields (`''`) to `null`** for FK columns (district_id, block_id, etc.) — empty strings violate FK constraints.
- **`UserService::setPortals()`/`setFormAccess()` must NOT open their own transactions** — they are called inside `create()`/`update()` outer transactions (nested transactions throw "already an active transaction").
- **Bootstrap modals opened from JS (`openModal`, `openAccess`) must call `bootstrap.Modal.getOrCreateInstance(...).show()` and should show the modal FIRST, then populate asynchronously** — showing after a fetch leaves the modal invisible during the request (flaky).
- **E2E typing during a Bootstrap modal fade / in-flight `loadLocations()` fetch truncates typed text** in headed Chrome (e.g. `e2e_64813915` → `e2e_6481`). The `type()` helper in `tests/e2e/lib.js` now verifies the field value and retries up to 3×; tests also `waitForNetworkIdle` after opening a modal.
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

### Edit published form + sync to all (web + mobile)
- **Flow:** published form → admin clicks edit → `SurveyService::draftForEditing()` resumes the existing non-empty draft OR clones the latest published structure into a new draft (so the editor opens a working copy of the live form, never a blank page). Admin edits the draft, then clicks **"Save & Sync to All"**.
- `draftForEditing(int $formId, int $userId)` — resumes latest draft only if it has structure (`hasStructure()` = ≥1 section); otherwise creates a new draft cloned from the latest published version. Works for both published and never-published forms.
- `versionInfo(int $formId)` → `['published_version' => int, 'draft_version' => int, 'pending_changes' => bool]` (pending = draft_version > published_version).
- `mis/builder/sync.php` (NEW): requires `survey_builder.publish`; guards "no pending draft changes"; calls `publish()` then `NotificationService::send(title, body, null, null, $user->id(), 'info')` to **broadcast to all active users** (web + mobile get a "new form version" notification); writes `survey.sync` audit entry; flashes "Draft published as vN and synced to all users (web + mobile).".
- `mis/builder/edit.php`: POST `action=save` (save draft) or `action=save_sync` (save then redirect to `sync.php`). Header shows `published vN` badge + `pending changes — vN` badge; **Save & Sync to All** button only for users with `survey_builder.publish`.
- `mis/builder/index.php`: version column shows `vN draft` pending badge when `pending_changes`; action buttons — sync button (`sync.php`) for published+pending (publish permission), else rocket publish button (`publish.php`) for unpublished forms. Edit (pencil) always available.
- Publish permission gate: `survey_builder.publish`. Non-publishers can edit/save drafts but see no sync/publish buttons.
- Gotcha: `createForm()` always creates an empty draft v1; `draftForEditing()` ignores it (no structure) and clones from published instead.

### RBAC portal + per-user form access
- Tables: `user_portal_access(portal ENUM('mis','admin'))`, `user_form_access(form_id, can_fill, can_view)` — migrations in `database/migrations/002_portal_form_access.sql`.
- `User` helpers: `portals()`, `hasPortal($p)` (state_admin implicit all), `assignedFormIds()`, `canAccessForm($id)` (state_admin implicit all).
- `UserService` is **scope-aware**: `list/create/update` enforce the acting user's hierarchy (`scopeConditions()`, `assertInScope()`); `assignableRoles()` limits which roles may be granted by each role; `assignableForms()` filters to published forms the actor may grant; `setPortals()/setFormAccess()/updateAccess()` assign access (no nested transactions).
- `mis/users/index.php` — create/edit modal has portal checkboxes (Admin only if `canGrantAdmin`) + form multiselect; roles/forms are scoped to the actor.
- `admin/access.php` (state-admin only) — Roles & Access page: list users, manage portals + forms per user, audit-logged.
- `mis/login.php` — after auth, gates on `hasPortal('mis')`; admin panel pages are state-admin only.
- API exposure: `api/user_data.php` returns `portals` + `form_ids`; `FormController::index()`/`show()` filter by `canAccessForm()` (403 otherwise); `RecordController::store()` checks access too.
- `database/seed.sql` grants `dashboard.view`, `users.view`, `users.manage` to district/block/panchayat/village roles (applied to live DB).

### Government Building Survey (seed form)
- `database/seed_govt_building.php` builds `GOVT_BUILDING_SURVEY` from a PHP array: **17 sections, 132 fields**, includes master fields (`DEPARTMENT`, `BUILDING_SUBCATEGORY`) and a `location_cascade` field. Published as v1. Source of truth (field-by-field spec) is `Government_Building_Survey_Form.md` (untracked — do NOT commit).

### Mobile REST API additions (this milestone)
- `POST /v1/auth/*`, `GET /v1/forms` (access-filtered), `GET /v1/forms/{id}` (403 if no access), `POST /v1/records` (checks `canAccessForm`), `GET /v1/records` (scoped list), `GET /v1/records/{identifier}` (by id or uuid, with answers + images), `POST /v1/records/{id}/photos` (multipart `files[]` → `survey_images` + `uploads/survey/<recordId>/`), `POST /v1/devices` / `DELETE /v1/devices/{device_id}` (register + FCM token), `GET /v1/sync/status` (pending queue count by status), `/v1/location/*`, `/v1/master/*`, `/v1/notifications/*`, `/v1/gis/*`, `/v1/replication/*`, `/v1/health`.
- `RecordController` uses `api/user_data.php`-independent `identifier` lookup (id or uuid).

## E2E Harness

- `tests/e2e/run.js` — headed Chrome (Puppeteer), resets DB at start + end. Runs `mis` and/or `admin` suites (`node tests/e2e/run.js [all|mis|admin]`). Current total: 144 checks, all passing.
- Helpers in `tests/e2e/lib.js` (`BASE`, `CREDS`, `step/check/ok`, `clickText`, `type`, `waitForText`, `hasText`, `assertNoPhpWarnings`, `wirePage`, `summary`); dialogs auto-accepted.
- `type()` is self-verifying: triple-clicks, types with `delay:10`, reads back the field value and retries up to 3× (headed-Chrome truncates text typed during modal animation/network load).
- Form submits in tests go through `page.evaluate(form.submit())` (bypasses `onsubmit` confirm dialogs).
- Puppeteer does NOT support `:has-text()`; use `page.evaluate` + `Array.from(...).find`.
- E2E MIS suite now covers: create draft → publish → edit published (clone) → Save & Sync → verify pending badge cleared + sync button gone + broadcast flash, **create a user via modal (portal + form access), edit-user access persistence, admin Roles & Access page** (Manage modal shows portals/forms, submits, flash).
- `tests/smoke.php` covers `draftForEditing` clone/resume, `versionInfo` pending states, sync publish bump, live definition containing the synced field, broadcast recipient count, **GOVT_BUILDING_SURVEY integrity (17 sections / 132 fields / master groups), portal + form-access helpers, state-admin implicit access, scope enforcement, block-admin role-assignment limits, assignableForms scoping**. (Note: notification "send/deliver" check asserts id presence in `forUser()` list, not index 0 — same-second ordering is nondeterministic.)

## Milestones (git history)

- (pending this session) — Government Building Survey form + RBAC portal/form access + mobile API records/devices/sync endpoints (form+access commit, then mobile-API commit).
- `1c55442` — edit published form + sync to all users (builder sync flow + E2E).
- `06b5a1c` — location_cascade field type + `/v1/location/*` API + scope-aware preview + `Request::header()` fix. (previous)
- `7641300` — master-data field type (admin CRUD, builder linkage, id+name storage) + draft-form preview.
- `d088f8b` — survey builder option save fix + user-modal race fix + `plain_password` dev column.
- `e1b958c` — E2E suite (headed Chrome) + bugs it surfaced (.htaccess API rewrite, absolute sidebar URLs, `../../api/` fetch fix, audit logging for auth + transitions).
- `9279818` — gitignore fix (untracked nested `tests/e2e/node_modules`).
- `f9152c5` — admin panel + Jharkhand seed data.
- `400b6c0` — notifications, replication queue, docs, smoke tests.

## Next Steps / Ideas (not yet done)

- Mobile app itself is NOT in this repo — only the backend/API it consumes. The sync notification is broadcast via `NotificationService`; the mobile client must implement a "check for new form version" flow triggered by it (API `/v1/forms` download already exists).
- `SurveyService::publishedForms()` still returns full definitions for ALL published forms at the service level — API filtering is handled per-user, but `mis/builder/index.php`/reports may still show all forms to scoped users (out of scope unless asked).
- `user_form_access.can_fill` / `can_view` columns exist but are not yet distinguished on the mobile side.
- Mobile sync queue (`sync_queue` table) is not yet populated by `RecordController::store()` — sync status endpoint exists but nothing inserts into the queue yet.
- Possible future work: `master` + `location_cascade` answer rendering in MIS reports/monitoring tables; cascade auto-population on edit of an existing record; validation of master/cascade answers on the API; field-level permissions; schedule/expiry on published versions.

## Relevant Files

- **Schema/Seed:** `database/schema.sql`, `database/seed.sql`, `database/seed_demo.php`, `database/seed_jharkhand.php`, `database/seed_govt_building.php` (GOVT_BUILDING_SURVEY), `database/seed_portal_access.php`, `database/migrations/002_portal_form_access.sql`
- **Core services:** `common/src/Services/SurveyService.php`, `common/src/Services/RecordService.php`, `common/src/Services/UserService.php` (scope + access)
- **HTTP/Auth:** `common/src/Http/Request.php` (header fix), `common/src/Http/Router.php`, `common/src/Http/Response.php`, `common/src/Auth/SessionAuth.php`, `common/src/Auth/ApiAuth.php`
- **API:** `api/index.php`, `api/dropdowns.php`, `api/user_data.php` (portals + form_ids + scope), `api/Controllers/LocationController.php`, `api/Controllers/MasterController.php`, `api/Controllers/FormController.php` (access-filtered), `api/Controllers/RecordController.php` (store/show/photos/syncStatus), `api/Controllers/DeviceController.php`
- **Builder UI:** `mis/builder/edit.php`, `mis/builder/preview.php`, `mis/builder/index.php`, `mis/builder/sync.php` (publish + notify), `mis/builder/publish.php`
- **Admin panel:** `admin/access.php` (Roles & Access), `admin/masters.php`, `admin/dashboard.php`, `admin/settings.php`, `admin/notifications.php`, `admin/audit.php`, `admin/replication.php`, `admin/health.php`
- **MIS users:** `mis/users/index.php` (portal + form access modal), `mis/users/save.php` (actor-scoped), `mis/login.php` (portal gate)
- **Layouts:** `common/views/layout.php` (MIS), `common/views/admin_layout.php` (admin)
- **Tests:** `tests/smoke.php`, `tests/e2e/run.js`, `tests/e2e/lib.js`, `tests/e2e/reset.php`
- **Config (gitignored):** `config/.env` (`APP_URL=http://localhost:81/bcd-app`)
- **Root:** `.htaccess`, `.gitignore`, `ROADMAP.md`, `readme.md`, `docs/API.md`, `docs/INSTALL.md`, `MEMORY.md` (this file)
