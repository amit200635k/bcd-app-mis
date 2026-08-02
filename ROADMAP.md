# BCD Survey Platform — Development Roadmap

> Guiding principle: **Build once, configure forever.**
> The platform must support any future government survey by changing configuration rather than modifying application code.

## Branch strategy
- `main` — stable, release-ready code only
- `develop` — integration branch for ongoing work
- Feature branches: `feature/<phase>-<name>`
- Every merge to `main` is tagged and synced to GitHub.

## Definition of done (per phase)
- Feature works end-to-end as per the readme spec
- PDO prepared statements used (no raw SQL injection)
- No plaintext secrets in code (`.env` only)
- PHP 8.2 compatible, `error_reporting(E_ALL)` clean
- Schema changes go through `database/migrations/`
- Commit + push to GitHub on phase completion

---

## Phase 0 — Foundation & Repo Setup
- Git repo, remote, `.gitignore`, folder scaffold
- Composer PSR-4 autoload, `.env` loader, PDO bootstrap
- MySQL 8 full schema (core tables) + migration runner
- Bootstrap 5 base layout, login page, error/exception handling
- **Deliverable:** runnable skeleton with DB + auth scaffolding

## Phase 1 — Auth, RBAC & User Hierarchy
- Users / Roles / Permissions tables & CRUD
- 7-level hierarchy: State → Department → District → Block → Panchayat → Village → Surveyor
- JWT auth API (login, refresh, logout), password hashing, device registration
- User management screens + audit logging
- **Deliverable:** secure login + hierarchical user management

## Phase 2 — Location Masters & Configuration
- District / Block / Panchayat / Village master CRUD
- Bulk CSV/Excel import
- Master download API (for mobile)
- **Deliverable:** masters ready for offline mobile use

## Phase 3 — Dynamic Survey Builder
- Forms / Sections / Fields / Options / Versions tables
- 17+ field types (text, textarea, number, decimal, date, time, dropdown, radio, checkbox, multi-select, GPS, camera, signature, barcode, QR, file, heading, section, auto-number)
- Validations: mandatory, regex, min/max, Aadhaar, PAN, email, mobile, PIN
- Conditional logic engine (IF/THEN show/hide)
- Builder UI + preview + versioning/publish
- **Deliverable:** admins design surveys without code changes

## Phase 4 — Mobile Application + REST API
- React Native CLI app: login, dashboard, offline mode
- SQLite local store; download masters + form definitions
- Offline filling, drafts/resume, camera, GPS, signature, barcode/QR
- Image compression, background sync queue
- Upload API (records, photos, GPS, logs) + conflict resolution
- **Deliverable:** offline-first field data collection

## Phase 5 — Workflow & Approval Engine
- Status flow: Draft → Submitted → Block → District → State → Published
- Rejection → re-survey loop
- MIS monitoring screens (per-status lists, monitoring dashboard)
- **Deliverable:** full approval pipeline

## Phase 6 — GIS Dashboard
- Leaflet maps: markers, heatmaps, clusters, polygons; OSM + satellite
- Filters: district, block, panchayat, village, status, survey type
- Geo query APIs
- **Deliverable:** spatial visualization of survey data

## Phase 7 — Reporting & Export
- Reports: district/block/panchayat/village/user/survey-wise, daily progress, GPS missing, photo missing, duplicates
- Export to Excel / CSV / PDF
- Dashboard charts
- **Deliverable:** decision-grade reports

## Phase 8 — Replication & Notifications
- Replication queue + service (retry, logging, failure recovery)
- MS SQL Server connector; Oracle / PostgreSQL connectors (config-driven)
- Notification service (in-app + push) with device tokens
- **Deliverable:** external DB sync + live notifications

## Phase 9 — Hardening, Performance & Security
- HTTPS, rate limiting, session timeout, password policy, field encryption
- Image compression tuning, pagination, API caching, DB indexing
- Audit-log review, load testing
- **Deliverable:** production-ready security & performance

## Phase 10 — Future Enhancements (backlog)
- AI validation, OCR document reading, face verification
- Drone imagery, workflow designer, multilingual surveys
- Voice input, digital signatures, eKYC, IoT integration

---

## Folder structure
```
bcd-app/
  admin/         State/department admin panel
  api/           REST API layer (JWT)
  common/        Shared libs, helpers, traits
  config/        Config templates (.env-driven)
  database/      Schema + migrations
  docs/          Manuals, API docs, deployment guide
  gis/           GIS dashboard
  mis/           MIS web portal (PHP 8 + Bootstrap 5)
  mobile/        React Native app
  replication/   Replication service
  reports/       Reporting engine
  uploads/       User uploads (gitignored)
  logs/          Logs (gitignored)
```
