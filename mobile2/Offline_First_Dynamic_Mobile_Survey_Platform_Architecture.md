# Offline-First Dynamic Mobile Survey Platform Architecture

## Overview

This document describes an offline-first Android application built with:

- Capacitor
- Alpine.js
- Bootstrap 5
- SQLite
- PHP APIs

The APK acts as a runtime. Almost all business logic, forms, menus, permissions and workflows are downloaded from the backend.

## Architecture

```text
APK
 └── Capacitor Runtime
      ├── Alpine.js UI
      ├── Bootstrap
      ├── Custom Router
      ├── Dynamic Form Engine
      ├── SQLite
      ├── Sync Engine
      ├── API Client
      └── Native Plugins
```

## First Launch

1. Install APK.
2. Register/Login.
3. Credentials sent to API.
4. Server verifies user.
5. JWT token returned.
6. Token saved in Preferences.
7. User profile saved in SQLite.
8. Download:
   - menus
   - forms
   - field definitions
   - lookup masters
   - permissions
   - workflow
   - application settings
9. Store everything in SQLite.

## Auto Login

- Read JWT from Preferences.
- If valid, load profile from SQLite.
- Open dashboard immediately.
- Refresh configuration when internet is available.

## SQLite Tables

- users
- app_settings
- menus
- screens
- forms
- form_fields
- lookup_master
- permissions
- survey_header
- survey_answers
- attachments
- gps_logs
- sync_queue
- notifications
- api_cache
- audit_log

## Dynamic Menu

Backend returns JSON.

Each menu contains:
- title
- icon
- screen
- permissions
- display order

Client renders menu dynamically.

## Dynamic Form Engine

Supported controls:

- Text
- Number
- Date
- Time
- Dropdown
- Radio
- Checkbox
- GPS
- Camera
- File Picker
- Signature
- QR Scanner
- Barcode
- Repeat Sections

Validation rules come from metadata.

## Survey Workflow

1. Open form.
2. Capture GPS.
3. Capture images.
4. Pick files.
5. Save locally.
6. Status = Pending Sync.

## Sync Engine

Queue-based sync.

Each queue record stores:

- table
- record id
- operation
- payload
- retry count
- status

States:

Pending
→ Syncing
→ Synced

or

Pending
→ Failed
→ Retry

Images upload first, then survey JSON.

## Offline Behaviour

Everything works offline:

- Login session
- Menus
- Forms
- Lookup masters
- Survey creation
- Survey editing
- Attachments
- Reports based on local data

## Native Features

- GPS
- Camera
- Gallery
- File Picker
- SQLite
- Local Notifications
- Network Detection
- Device Information

## API Endpoints

POST /login

POST /refresh-token

GET /app/config

GET /menus

GET /forms

GET /masters

GET /permissions

POST /survey

POST /upload

GET /notifications

POST /sync/status

## Versioning

Every configuration contains:

- version
- checksum
- updated_at

Only changed resources are downloaded.

## Security

- HTTPS
- JWT
- Refresh Token
- SQLCipher (optional)
- Encrypted Preferences
- API request signing
- Offline audit logs

## Recommended Folder Structure

```text
app/
 assets/
 css/
 js/
   api/
   db/
   engine/
   forms/
   native/
   router/
   sync/
   ui/
 index.html
```

## Advantages

- No APK update for new forms.
- Backend controls menus and workflows.
- Offline-first.
- Small application.
- High performance.
- Suitable for enterprise and government field surveys.

## Future Enhancements

- Background sync
- Push notifications
- AI-assisted validation
- Digital signatures
- GIS maps
- Biometric authentication
- Multi-language support
