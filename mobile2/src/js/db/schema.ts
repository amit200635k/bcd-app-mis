export const DB_NAME = 'bcd_survey';
export const DB_VERSION = 1;

/**
 * SQLite schema (v1). Mirrors the architecture doc's table set, mapped to the
 * real backend contracts (forms/fields from /v1/forms, locations from
 * /v1/masters/locations, records via /v1/records upsert-by-uuid, …).
 */
export const SCHEMA_STATEMENTS: string[] = [
  `CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY,
    username TEXT NOT NULL,
    full_name TEXT,
    role TEXT,
    scope_json TEXT,
    profile_json TEXT,
    updated_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS app_settings (
    key TEXT PRIMARY KEY,
    value TEXT,
    updated_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS menus (
    id INTEGER PRIMARY KEY,
    code TEXT,
    title TEXT,
    icon TEXT,
    screen TEXT,
    sort_order INTEGER
  )`,
  `CREATE TABLE IF NOT EXISTS permissions (
    code TEXT PRIMARY KEY,
    name TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS forms (
    id INTEGER PRIMARY KEY,
    code TEXT,
    title TEXT,
    description TEXT,
    current_version INTEGER,
    version INTEGER,
    updated_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS form_fields (
    id INTEGER PRIMARY KEY,
    form_id INTEGER,
    section_id INTEGER,
    section_title TEXT,
    field_key TEXT,
    label TEXT,
    type TEXT,
    is_mandatory INTEGER DEFAULT 0,
    placeholder TEXT,
    default_value TEXT,
    help_text TEXT,
    allow_multiple INTEGER DEFAULT 0,
    sort_order INTEGER,
    options_json TEXT,
    validations_json TEXT,
    conditions_json TEXT,
    settings_json TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS lookup_master (
    group_id INTEGER,
    group_code TEXT,
    group_name TEXT,
    item_id INTEGER,
    item_code TEXT,
    item_name TEXT,
    parent_id INTEGER
  )`,
  `CREATE TABLE IF NOT EXISTS locations (
    level TEXT,
    id INTEGER,
    parent_id INTEGER,
    code TEXT,
    name TEXT,
    latitude REAL,
    longitude REAL,
    PRIMARY KEY (level, id)
  )`,
  `CREATE TABLE IF NOT EXISTS survey_header (
    record_uuid TEXT PRIMARY KEY,
    form_id INTEGER,
    form_version_id INTEGER,
    form_code TEXT,
    form_title TEXT,
    status TEXT,
    device_id TEXT,
    server_record_id INTEGER,
    gps_json TEXT,
    created_at TEXT,
    updated_at TEXT,
    synced_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS survey_answers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    record_uuid TEXT,
    field_key TEXT,
    field_label TEXT,
    field_type TEXT,
    value_text TEXT,
    value_number REAL,
    value_date TEXT,
    value_json TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    record_uuid TEXT,
    field_key TEXT,
    category TEXT,
    local_uri TEXT,
    file_name TEXT,
    mime_type TEXT,
    size_bytes INTEGER,
    upload_state TEXT DEFAULT 'pending',
    server_image_id INTEGER,
    server_file_path TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS gps_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    record_uuid TEXT,
    latitude REAL,
    longitude REAL,
    accuracy REAL,
    altitude REAL,
    captured_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS sync_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    record_uuid TEXT,
    action TEXT,
    payload_json TEXT,
    status TEXT DEFAULT 'pending',
    retry_count INTEGER DEFAULT 0,
    next_retry_at TEXT,
    error TEXT,
    created_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY,
    title TEXT,
    body TEXT,
    type TEXT,
    is_read INTEGER DEFAULT 0,
    created_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS api_cache (
    url TEXT PRIMARY KEY,
    payload_json TEXT,
    fetched_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event TEXT,
    detail_json TEXT,
    created_at TEXT
  )`,
  `CREATE INDEX IF NOT EXISTS idx_form_fields_form ON form_fields (form_id, section_id, sort_order)`,
  `CREATE INDEX IF NOT EXISTS idx_answers_record ON survey_answers (record_uuid)`,
  `CREATE INDEX IF NOT EXISTS idx_attachments_record ON attachments (record_uuid)`,
  `CREATE INDEX IF NOT EXISTS idx_queue_status ON sync_queue (status, next_retry_at)`,
  `CREATE INDEX IF NOT EXISTS idx_lookup_group ON lookup_master (group_id)`,
  `CREATE INDEX IF NOT EXISTS idx_locations_parent ON locations (level, parent_id)`,
];
