# BCD Survey Platform — API Documentation

Base URL: `http://localhost/bcd-app/api/` (via `.htaccess`: `http://localhost/bcd-app/api/v1/...`)

All authenticated endpoints require header: `Authorization: Bearer <access_token>`

## Authentication

| Method | Endpoint | Description |
|---|---|---|
| POST | `/v1/auth/login` | Login. Body: `{username, password, device_id?}` → access+refresh tokens |
| POST | `/v1/auth/refresh` | Refresh access token. Body: `{refresh_token}` |
| POST | `/v1/auth/logout` | Revoke all refresh tokens |
| GET | `/v1/auth/me` | Current user profile + roles |

## Masters

| Method | Endpoint | Description |
|---|---|---|
| GET | `/v1/masters/locations` | District/Block/Panchayat/Village hierarchy (offline download) |
| GET | `/v1/masters` | Generic master groups + items |

## Survey Forms

| Method | Endpoint | Description |
|---|---|---|
| GET | `/v1/forms` | All published forms with full structure (sections/fields/options) |
| GET | `/v1/forms/{code or id}` | Single published form definition |

## Survey Records (mobile sync)

| Method | Endpoint | Description |
|---|---|---|
| POST | `/v1/records` | Upsert record. Body: `{record_uuid, form_id, form_version_id, status, answers{field_key:value}, gps?}` |
| GET | `/v1/records` | List records. Query: `form_id`, `status`, `page`, `per_page` |
| POST | `/v1/records/{id}/status` | Workflow transition. Body: `{status, remark?}` |

## GIS

| Method | Endpoint | Description |
|---|---|---|
| GET | `/v1/gis/points` | Records with GPS coordinates. Query: `form_id`, `status` |

## Notifications & System

| Method | Endpoint | Description |
|---|---|---|
| GET | `/v1/notifications` | User's notifications |
| GET | `/v1/notifications/unread` | Unread count |
| POST | `/v1/notifications/{id}/read` | Mark notification read |
| GET | `/v1/replication/stats` | Replication queue stats |
| GET | `/v1/health` | Health check (DB status) |
| GET | `/v1/version` | Version info |

## Workflow statuses

`draft → submitted → block_verified → district_verified → approved → published`
`rejected` sends back for re-survey.

## Example: mobile login + sync

```bash
# 1. Login
TOKEN=$(curl -s -X POST -d '{"username":"ram.surveyor","password":"Survey@123"}' \
  -H 'Content-Type: application/json' \
  http://localhost/bcd-app/api/v1/auth/login | jq -r '.data.access_token')

# 2. Download survey forms
curl -s -H "Authorization: Bearer $TOKEN" http://localhost/bcd-app/api/v1/forms

# 3. Upload a record
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"record_uuid":"abc-123","form_id":2,"form_version_id":2,"status":"submitted",
       "answers":{"school_name":"Govt HS","school_type":"secondary","student_count":340},
       "gps":{"latitude":28.6139,"longitude":77.2090,"accuracy":4.0}}' \
  http://localhost/bcd-app/api/v1/records
```
