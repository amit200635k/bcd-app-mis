import AsyncStorage from '@react-native-async-storage/async-storage';
import {FormDefinition, LocationHierarchy, MasterData, PublishedForm} from '../types/api';

const KEYS = {
  forms: 'cache.forms', // PublishedForm[]
  locations: 'cache.locations', // LocationHierarchy
  masters: 'cache.masters', // MasterData
  pending: 'cache.pending', // PendingRecord[]
  deviceId: 'cache.deviceId',
  serverBase: 'cache.serverBase',
};

export interface PendingRecord {
  localUuid: string;
  formId: number;
  formVersionId: number;
  answers: Record<string, unknown>;
  gps: unknown;
  status: string;
  createdAt: string;
  attempts: number;
  deviceId: string;
}

const store = {
  get: <T>(key: string): Promise<T | null> => AsyncStorage.getItem(key).then((s) => (s ? (JSON.parse(s) as T) : null)),
  set: (key: string, value: unknown): Promise<void> => AsyncStorage.setItem(key, JSON.stringify(value)),
  remove: (key: string): Promise<void> => AsyncStorage.removeItem(key),
};

export const OfflineCache = {
  async saveForms(forms: PublishedForm[]): Promise<void> {
    await store.set(KEYS.forms, forms);
  },
  async loadForms(): Promise<PublishedForm[] | null> {
    return store.get<PublishedForm[]>(KEYS.forms);
  },
  async saveDefinition(def: FormDefinition): Promise<void> {
    const forms = (await this.loadForms()) ?? [];
    const idx = forms.findIndex((f) => f.id === def.form.id);
    if (idx >= 0) {
      forms[idx] = {...forms[idx], version: def.version, sections: def.sections};
    } else {
      forms.unshift({...def.form, version: def.version, sections: def.sections});
    }
    await this.saveForms(forms);
  },
  async saveLocations(locations: LocationHierarchy): Promise<void> {
    await store.set(KEYS.locations, locations);
  },
  async loadLocations(): Promise<LocationHierarchy | null> {
    return store.get<LocationHierarchy>(KEYS.locations);
  },
  async saveMasters(masters: MasterData): Promise<void> {
    await store.set(KEYS.masters, masters);
  },
  async loadMasters(): Promise<MasterData | null> {
    return store.get<MasterData>(KEYS.masters);
  },
  async loadPending(): Promise<PendingRecord[]> {
    return (await store.get<PendingRecord[]>(KEYS.pending)) ?? [];
  },
  async savePending(pending: PendingRecord[]): Promise<void> {
    await store.set(KEYS.pending, pending);
  },
  async getOrCreateDeviceId(): Promise<string> {
    let id = await AsyncStorage.getItem(KEYS.deviceId);
    if (!id) {
      id = `bcd-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
      await AsyncStorage.setItem(KEYS.deviceId, id);
    }
    return id;
  },
  async getServerBase(): Promise<string | null> {
    return AsyncStorage.getItem(KEYS.serverBase);
  },
  async setServerBase(base: string): Promise<void> {
    await AsyncStorage.setItem(KEYS.serverBase, base);
  },
};
