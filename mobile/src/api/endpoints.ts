import {api} from './client';
import {
  AuthTokens,
  DeviceRegistration,
  FormDefinition,
  LocationHierarchy,
  MasterData,
  PublishedForm,
  RecordResult,
  SyncStatus,
} from '../types/api';

export const authApi = {
  login: (username: string, password: string, deviceId?: string) =>
    api.post<AuthTokens>('/auth/login', {username, password, device_id: deviceId}, {public: true}),
  logout: () => api.post<{message: string}>('/auth/logout'),
};

export const formsApi = {
  list: () => api.get<{updated_at: string; forms: PublishedForm[]}>('/forms'),
  get: (identifier: string | number) => api.get<FormDefinition>(`/forms/${encodeURIComponent(identifier)}`),
};

export const mastersApi = {
  list: () => api.get<MasterData>('/masters'),
  locations: () => api.get<LocationHierarchy>('/masters/locations'),
};

export const recordsApi = {
  store: (payload: {
    record_uuid: string;
    form_id: number;
    form_version_id: number;
    status?: string;
    device_id?: string;
    answers: Record<string, unknown>;
    gps?: unknown;
  }) => api.post<RecordResult>('/records', payload),
};

export const devicesApi = {
  register: (device: {device_id: string; device_name?: string; platform?: string; os_version?: string; app_version?: string}) =>
    api.post<{device: DeviceRegistration}>('/devices', device),
};

export const syncApi = {
  status: () => api.get<SyncStatus>('/sync/status'),
};
