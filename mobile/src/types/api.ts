/** Mirror of the backend field types (database/schema.sql + SurveyService). */
export type FieldType =
  | 'textbox'
  | 'textarea'
  | 'number'
  | 'decimal'
  | 'date'
  | 'time'
  | 'dropdown'
  | 'radio'
  | 'checkbox'
  | 'multi_select'
  | 'gps'
  | 'photo'
  | 'signature'
  | 'barcode'
  | 'qr'
  | 'file'
  | 'heading'
  | 'auto_number'
  | 'master'
  | 'location_cascade';

export interface FieldOption {
  option_label: string;
  option_value: string;
}

export interface FieldValidation {
  rule: string;
  rule_value: string | null;
  error_message: string | null;
}

export interface Condition {
  target_field_id: number;
  target_field_key: string | null;
  operator: string;
  condition_value: string;
  action: 'show' | 'hide' | 'required';
}

export interface SurveyField {
  id: number;
  section_id: number;
  field_key: string;
  label: string;
  type: FieldType;
  is_mandatory: number;
  placeholder: string | null;
  default_value: string | null;
  help_text: string | null;
  show_in_table: number;
  allow_multiple: number;
  sort_order: number;
  settings: Record<string, unknown> | null;
  options: FieldOption[];
  validations: FieldValidation[];
  conditions: Condition[];
  master_group_id: number | null;
  master_group_name: string | null;
}

export interface SurveySection {
  id: number;
  form_version_id: number;
  title: string;
  description: string | null;
  is_heading: number;
  sort_order: number;
  fields: SurveyField[];
}

export interface SurveyFormMeta {
  id: number;
  code: string;
  title: string;
  description: string | null;
  current_version: number;
  updated_at: string;
}

export interface FormDefinition {
  form: SurveyFormMeta;
  version: number;
  sections: SurveySection[];
}

export interface PublishedForm extends SurveyFormMeta {
  version: number | null;
  sections: SurveySection[];
}

export interface MasterItem {
  id: number;
  group_id: number;
  code: string | null;
  name: string;
  parent_id: number | null;
  extra_json: string | null;
}

export interface MasterGroup {
  id: number;
  code: string;
  name: string;
}

export interface MasterData {
  updated_at: string;
  groups: MasterGroup[];
  items: MasterItem[];
}

export interface LocationNode {
  id: number;
  code: string;
  name: string;
}

export interface District extends LocationNode {}
export interface Block extends LocationNode {
  district_id: number;
}
export interface Panchayat extends LocationNode {
  block_id: number;
}
export interface Village extends LocationNode {
  panchayat_id: number;
  latitude: string | null;
  longitude: string | null;
}

export interface LocationHierarchy {
  updated_at: string;
  districts: District[];
  blocks: Block[];
  panchayats: Panchayat[];
  villages: Village[];
}

export interface AuthTokens {
  access_token: string;
  refresh_token: string;
  token_type: string;
  expires_in: number;
  user: {
    id: number;
    username: string;
    full_name: string;
    role?: string[];
  };
}

export interface ApiResponse<T> {
  success: boolean;
  message?: string;
  errors?: Record<string, string[]>;
  data: T;
}

export interface UserScope {
  district_id: number | null;
  block_id: number | null;
  panchayat_id: number | null;
  village_id: number | null;
}

export interface GpsPoint {
  latitude: number;
  longitude: number;
  accuracy: number | null;
  altitude: number | null;
  captured_at: string | null;
}

export interface SyncStatus {
  user_id: number;
  pending: number;
  by_status: Array<{status: string; c: number}>;
  server_time: string;
}

export interface RecordResult {
  record_uuid: string;
  record_id: number;
  status: string;
}

export interface DeviceRegistration {
  id: number;
  device_id: string;
  is_active: number;
}
