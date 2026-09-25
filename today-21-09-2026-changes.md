# Changes Summary - 21-09-2026

## Overview
Implemented ArcGIS/MSSQL replication for building survey data with proper OBJECTID generation via `sde.next_rowid`, geometry `Shape` column using `geometry::STGeomFromText`, and photo path mapping from survey images.

---

## Files Modified

### 1. `common/src/Support/ExternalDbConnector.php`
**Changes:**
- Added `'geometry' => 'GEOMETRY'` to `getMssqlType()` method for MSSQL GEOMETRY column type support
- No changes to connection logic - uses existing PDO with sqlsrv driver

### 2. `common/src/Services/ReplicationService.php` (Major Rewrite)
**New Methods Added:**
- `getNextObjectId(ExternalDbConnector $connector)` - Calls `sde.next_rowid` stored procedure to get next OBJECTID
- `buildShapeWkt(array $data)` - Builds WKT POINT from GPS data (lat/lng from multiple possible field locations)
- `getPhotoPaths(int $recordId)` - Retrieves photo file paths from `survey_images` table, maps form field keys to ArcGIS columns
- `mapToArcGisColumns(array $data, array $photoPaths, int $recordId)` - Maps form field keys to ArcGIS building_survey_data columns
- `recordExists(ExternalDbConnector $connector, int $recordId)` - Checks if record exists by OBJECTID

**Updated Methods:**
- `applySurveyRecord()` - Complete rewrite:
  - Calls `sde.next_rowid` for OBJECTID
  - Builds WKT POINT for Shape column using `geometry::STGeomFromText('POINT(lon lat)', 4326)`
  - Fetches photo paths from `survey_images` table
  - Maps all form field keys to ArcGIS columns per specification
  - Handles INSERT with OBJECTID + Shape, UPDATE by OBJECTID

**Field Mapping (Form Field Key → ArcGIS Column):**
| ArcGIS Column | Form Field Key | Notes |
|--------------|----------------|-------|
| OBJECTID | - | From `sde.next_rowid` |
| Shape | - | `geometry::STGeomFromText('POINT(lon lat)', 4326)` |
| survey_id | survey_id | auto_number |
| building_level | Derived from `department` | Education→School, Health→Hospital, Police→Police Station, Revenue→Office, Rural Development→Office |
| building_name | building_name | |
| department | department | master (DEPARTMENT group) |
| office_name | controlling_authority | Section 4 |
| office_incharge | head_of_office | Section 4 |
| contact_mobile | contact_number | Section 4 |
| contact_email | email | Section 4 |
| location | location | location_cascade (JSON) |
| address | address | Not in form, empty |
| landmark | landmark | Section 2 |
| approach_roads | nearest_road | Section 2 |
| construction_year | construction_year | Section 5 |
| building_age | Derived | date('Y') - construction_year |
| construction_cost | construction_cost | Not in form, empty |
| physical_status | current_condition / structural_condition | Section 10 |
| occupancy_status | occupancy_status | Section 3 |
| remarks | general_remarks | Section 17 |
| geo_location | gps_point / location | JSON {lat, lng} |
| photo_front | photo_front | Section 12 (Front Elevation) |
| photo_campus | photo_entrance / photo_left | Section 12 (Entrance/Left Side) |
| photo_back | photo_rear | Section 12 (Rear View) |
| no_floors | num_floors | Section 5 |
| no_rooms | num_rooms | Section 5 |
| toilets_male | num_toilets / 2 | Derived |
| toilets_female | num_toilets / 2 | Derived |

### 3. `replication/worker.php`
**Changes:**
- Simplified `processOne()` call - no longer requires callback function
- Calls `$service->processOne()` directly

### 4. `admin/replication.php`
**New Features:**
- **Test Data Insert Button** - Creates dummy survey record with all required fields
- **Delete DB Config** - AJAX delete with confirmation
- **Debug Output Card** - Displays step-by-step execution log after test insert

**Test Data Insert Handler (`insert_test_data`):**
- Creates dummy survey record with all ArcGIS-required fields
- Includes dummy photo paths:
  - `photo_front` → `uploads/survey/photos/test_front_His.jpg`
  - `photo_campus` → `uploads/survey/photos/test_campus_His.jpg`
  - `photo_back` → `uploads/survey/photos/test_back_His.jpg`
- Enqueues for replication to all enabled external DB targets
- Displays debug output in dismissible card with full execution log

**UI Updates:**
- Added "Insert Test Data" button (green flask icon) next to Retry/Drain buttons
- Added Debug Output card that appears after test insert with full execution log
- Delete buttons on each config row with confirmation

---

## New Files Created

### 1. `common/src/Support/ExternalDbConnector.php`
Multi-database connector supporting MSSQL, PostgreSQL, MySQL, Oracle via PDO
- Added `'geometry' => 'GEOMETRY'` type mapping for MSSQL
- Handles photo paths as-is (returns file paths as-is from database)

### 2. `database/insert_dummy_survey.php`
CLI script to bulk insert dummy survey records for testing
```
php database/insert_dummy_survey.php [count]
```

---

## Database Schema (Already in `database/schema.sql`)

```sql
-- Already exists, verify on server:
CREATE TABLE `external_db_configs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `db_type` ENUM('mssql','oracle','postgres','mysql') NOT NULL,
  `host` VARCHAR(150) NOT NULL,
  `port` INT NULL,
  `database_name` VARCHAR(100) NULL,
  `username` VARCHAR(100) NULL,
  `password_enc` VARCHAR(255) NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `last_success_at` DATETIME NULL,
  PRIMARY KEY (`id`)
);

CREATE TABLE `replication_queue` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_id` VARCHAR(50) NOT NULL,
  `operation` ENUM('insert','update','delete') NOT NULL DEFAULT 'insert',
  `payload_json` JSON NOT NULL,
  `target_db_id` INT UNSIGNED NULL,
  `status` ENUM('pending','processing','success','failed') NOT NULL DEFAULT 'pending',
  `attempt_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `error_message` TEXT NULL,
  PRIMARY KEY (`id`)
);
```

---

## Target Database (ArcGIS / MSSQL) - Must Exist Manually

**The `building_survey_data` table must be created on the external MSSQL server (not managed by this app):**

```sql
-- Run on target MSSQL (ArcGIS Enterprise Geodatabase)
CREATE TABLE [SDE].[building_survey_data] (
    [OBJECTID] INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    [Shape] GEOMETRY NULL,
    [survey_id] NVARCHAR(100) NULL,
    [building_level] NVARCHAR(100) NULL,
    [building_name] NVARCHAR(255) NULL,
    [department] NVARCHAR(100) NULL,
    [office_name] NVARCHAR(255) NULL,
    [office_incharge] NVARCHAR(100) NULL,
    [contact_mobile] NVARCHAR(50) NULL,
    [contact_email] NVARCHAR(100) NULL,
    [location] NVARCHAR(MAX) NULL,
    [address] NVARCHAR(MAX) NULL,
    [landmark] NVARCHAR(255) NULL,
    [approach_roads] NVARCHAR(255) NULL,
    [construction_year] NVARCHAR(10) NULL,
    [building_age] NVARCHAR(10) NULL,
    [construction_cost] NVARCHAR(50) NULL,
    [physical_status] NVARCHAR(50) NULL,
    [occupancy_status] NVARCHAR(50) NULL,
    [remarks] NVARCHAR(MAX) NULL,
    [geo_location] NVARCHAR(MAX) NULL,
    [photo_front] NVARCHAR(MAX) NULL,
    [photo_campus] NVARCHAR(MAX) NULL,
    [photo_back] NVARCHAR(MAX) NULL,
    [no_floors] NVARCHAR(10) NULL,
    [no_rooms] NVARCHAR(10) NULL,
    [toilets_male] NVARCHAR(10) NULL,
    [toilets_female] NVARCHAR(10) NULL
);

-- Register with ArcGIS SDE (if needed - user said table already registered)
-- EXEC sde.layer_register 'building_survey_data', 'Shape', 'GEOMETRY', 4326;
```

**Key Clarifications from User:**
1. ArcGIS Registration: **Skip** `sde.layer_register` - table already registered
2. OBJECTID: Use **`sde.next_rowid` stored procedure** to get next OBJECTID
3. Geometry: Use **`geometry::STGeomFromText('POINT(lon lat)', 4326)`** for Shape column
3. Photo Paths: Store **actual server file paths** (relative to domain, e.g., `uploads/survey/...`)

---

## PHP Extensions Required on Server

| Extension | Purpose |
|-----------|---------|
| `pdo_sqlsrv` + `sqlsrv` | MSSQL/ArcGIS connection |
| `pdo_pgsql` | PostgreSQL |
| `pdo_mysql` | MySQL (already required) |
| `pdo_oci` | Oracle (optional) |
| `openssl` | Encryption (already loaded) |
| `json`, `mbstring` | Standard |

---

## Configuration Updates

### `config/app.php`
Added `'key'` for encryption:
```php
'key' => env('APP_KEY', 'change-this-to-a-secure-random-32-char-string-in-production'),
```

### `config/.env` & `.env.example`
Added `APP_KEY`:
```
APP_KEY=dev-key-change-in-production-32chars
```

---

## Deployment Checklist

1. **Deploy all modified/new PHP files**
2. **Verify `database/schema.sql` tables exist** on main MySQL
3. **Create `building_survey_data` table on target MSSQL** (ArcGIS) - see SQL above
4. **Install PHP SQLSRV drivers** on server (`pdo_sqlsrv`, `sqlsrv`)
5. **Set `APP_KEY` in `.env`** (32-char random string)
6. **Configure external DB target** in Admin → Replication → Add Target
7. **Test**: Click "Insert Test Data" button → check debug output
8. **Run worker**: `php replication/worker.php --daemon`

---

## Usage Flow

1. **Configure External DB** in Admin → Replication → Add Target
2. **Click "Insert Test Data"** - creates dummy record with all fields, enqueues for replication
3. **Check Debug Output Card** - shows full execution log with record ID, OBJECTID, enqueued targets
4. **Run worker**: `php replication/worker.php --once` (single job) or `--daemon` (continuous)
5. **Verify in MSSQL**: 
   ```sql
   SELECT OBJECTID, Shape, building_name, photo_front, photo_campus, photo_back 
   FROM building_survey_data
   ```

---

## Error Handling

- **sde.next_rowid failure**: Job marked `failed` (retries up to maxAttempts)
- **Geometry conversion error**: Job marked `failed` (retries)
- **Connection failure**: Job marked `failed` (retries)
- **Max retries exceeded**: Job stays `failed` - manual retry via "Retry Failed" button
- **All errors logged** in `replication_queue.error_message` and debug output card

---

## Files to Upload to Server

```
common/src/Support/ExternalDbConnector.php
common/src/Services/ReplicationService.php
replication/worker.php
admin/replication.php
common/views/admin_layout.php
config/app.php
config/.env
config/.env.example
common/src/Support/Crypto.php
database/insert_dummy_survey.php
```

---

## Testing Commands

```bash
# Test syntax
php -l common/src/Services/ReplicationService.php
php -l common/src/Support/ExternalDbConnector.php
php -l replication/worker.php
php -l admin/replication.php
php -l database/insert_dummy_survey.php

# Insert 5 dummy records
php database/insert_dummy_survey.php 5

# Process one replication job
php replication/worker.php --once

# Run daemon
php replication/worker.php --daemon
```