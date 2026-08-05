/** Shared API payload types (mirror the backend's JSON contracts). */

export interface ApiUser {
  id: number;
  username: string;
  full_name?: string | null;
  role?: string | null;
  [key: string]: unknown;
}

export interface TokenResponse {
  access_token: string;
  refresh_token: string;
  token_type: string;
  expires_in: number;
  user: ApiUser;
}

export interface FieldOption {
  option_label: string;
  option_value: string;
}

export interface FieldValidation {
  rule: string;
  rule_value?: string | null;
  error_message?: string | null;
}

export interface FieldCondition {
  target_field_id?: number | null;
  target_field_key?: string | null;
  operator: string;
  condition_value?: string | null;
  action: string;
}

export interface FormField {
  id: number;
  field_key: string;
  label: string;
  type: string;
  is_mandatory: number;
  placeholder?: string | null;
  default_value?: string | null;
  help_text?: string | null;
  show_in_table?: number;
  allow_multiple?: number;
  sort_order?: number;
  options?: FieldOption[];
  validations?: FieldValidation[];
  conditions?: FieldCondition[];
  settings?: Record<string, unknown> | null;
  master_group_id?: number | null;
  master_group_name?: string | null;
}

export interface FormSection {
  id: number;
  title?: string | null;
  description?: string | null;
  is_heading?: number;
  fields: FormField[];
}

export interface SurveyForm {
  id: number;
  code: string;
  title: string;
  description?: string | null;
  current_version?: number | null;
  version?: number | null;
  updated_at?: string | null;
  sections?: FormSection[];
}

export interface FormDefinition {
  form: SurveyForm;
  version: number;
  sections: FormSection[];
}

export interface ServerNotification {
  id: number;
  title: string;
  body?: string | null;
  type?: string | null;
  created_at?: string | null;
  is_read: number;
  read_at?: string | null;
}

export interface SyncStatus {
  user_id: number;
  pending: number;
  by_status: Array<{ status: string; c: number }>;
  server_time: string;
}

export interface LocationScope {
  district_id?: number | null;
  block_id?: number | null;
  panchayat_id?: number | null;
  village_id?: number | null;
}

export interface RecordCreated {
  record_uuid: string;
  record_id: number;
  status: string;
}
