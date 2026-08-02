import React, {createContext, useCallback, useContext, useEffect, useMemo, useState} from 'react';
import {AppState} from 'react-native';
import {OfflineCache, PendingRecord} from '../api/cache';
import {formsApi, mastersApi, recordsApi, syncApi} from '../api/endpoints';
import {ApiError} from '../api/client';
import {FormDefinition, LocationHierarchy, MasterData, PublishedForm, SyncStatus} from '../types/api';
import {useAuth} from './AuthContext';
import {SYNC} from '../config';

interface DataContextValue {
  forms: PublishedForm[];
  masters: MasterData | null;
  locations: LocationHierarchy | null;
  syncStatus: SyncStatus | null;
  loading: boolean;
  error: string | null;
  refreshing: boolean;
  downloadForm: (code: string | number) => Promise<FormDefinition | null>;
  refresh: () => Promise<void>;
  /** Persist a filled record for background upload. */
  queueRecord: (record: Omit<PendingRecord, 'createdAt' | 'attempts'>) => Promise<void>;
  pending: PendingRecord[];
  uploading: boolean;
  uploadNow: () => Promise<void>;
  clearError: () => void;
}

const DataContext = createContext<DataContextValue | undefined>(undefined);

export function DataProvider({children}: {children: React.ReactNode}) {
  const {profile} = useAuth();
  const [forms, setForms] = useState<PublishedForm[]>([]);
  const [masters, setMasters] = useState<MasterData | null>(null);
  const [locations, setLocations] = useState<LocationHierarchy | null>(null);
  const [syncStatus, setSyncStatus] = useState<SyncStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState<PendingRecord[]>([]);
  const [uploading, setUploading] = useState(false);

  const loadLocal = useCallback(async () => {
    const [cForms, cMasters, cLocations, cPending] = await Promise.all([
      OfflineCache.loadForms(),
      OfflineCache.loadMasters(),
      OfflineCache.loadLocations(),
      OfflineCache.loadPending(),
    ]);
    if (cForms) {
      setForms(cForms);
    }
    if (cMasters) {
      setMasters(cMasters);
    }
    if (cLocations) {
      setLocations(cLocations);
    }
    setPending(cPending ?? []);
  }, []);

  const refresh = useCallback(async () => {
    setRefreshing(true);
    setError(null);
    try {
      const [formsRes, mastersRes, locRes] = await Promise.all([formsApi.list(), mastersApi.list(), mastersApi.locations()]);
      setForms(formsRes.forms);
      setMasters(mastersRes);
      setLocations(locRes);
      await Promise.all([
        OfflineCache.saveForms(formsRes.forms),
        OfflineCache.saveMasters(mastersRes),
        OfflineCache.saveLocations(locRes),
      ]);
      const sync = await syncApi.status();
      setSyncStatus(sync);
    } catch (err) {
      setError(err instanceof ApiError ? err.fieldMessage : 'Could not refresh survey data.');
    } finally {
      setRefreshing(false);
      setLoading(false);
    }
  }, []);

  const uploadNow = useCallback(async () => {
    if (uploading) {
      return;
    }
    setUploading(true);
    setError(null);
    try {
      const queue = await OfflineCache.loadPending();
      const deviceId = await OfflineCache.getOrCreateDeviceId();
      const next: PendingRecord[] = [];
      for (const item of queue) {
        try {
          await recordsApi.store({
            record_uuid: item.localUuid,
            form_id: item.formId,
            form_version_id: item.formVersionId,
            status: item.status,
            device_id: deviceId,
            answers: item.answers,
            gps: item.gps,
          });
          // Uploaded — do not carry forward.
        } catch (err) {
          if (err instanceof ApiError && err.status >= 400 && err.status < 500) {
            // Permanent client error — drop rather than retry forever.
            continue;
          }
          const retry = item.attempts + 1;
          if (retry < SYNC.maxUploadAttempts) {
            next.push({...item, attempts: retry});
          }
        }
      }
      await OfflineCache.savePending(next);
      setPending(next);
      const sync = await syncApi.status();
      setSyncStatus(sync);
    } catch (err) {
      setError(err instanceof ApiError ? err.fieldMessage : 'Upload failed.');
    } finally {
      setUploading(false);
    }
  }, [uploading]);

  const queueRecord = useCallback(
    async (record: Omit<PendingRecord, 'createdAt' | 'attempts'>) => {
      const queue = await OfflineCache.loadPending();
      const next: PendingRecord = {...record, createdAt: new Date().toISOString(), attempts: 0};
      queue.push(next);
      await OfflineCache.savePending(queue);
      setPending([...queue]);
      // Try an immediate upload so connected users don't wait for the sync screen.
      await uploadNow();
    },
    [uploadNow],
  );

  const downloadForm = useCallback(
    async (identifier: string | number): Promise<FormDefinition | null> => {
      try {
        const def = await formsApi.get(identifier);
        await OfflineCache.saveDefinition(def);
        return def;
      } catch (err) {
        if (err instanceof ApiError) {
          setError(err.fieldMessage);
        }
        // Fall back to cached definition (already in `forms`).
        const cached = forms.find((f) => String(f.id) === String(identifier) || f.code === identifier);
        if (cached && cached.sections?.length) {
          return {form: cached, version: cached.version ?? 0, sections: cached.sections};
        }
        return null;
      }
    },
    [forms],
  );

  // Bootstrap offline cache on sign-in.
  useEffect(() => {
    if (!profile) {
      return;
    }
    loadLocal().then(() => refresh());
  }, [profile, loadLocal, refresh]);

  // Auto-upload when the app returns to foreground (offline-first flush).
  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => {
      if (state === 'active' && profile) {
        uploadNow();
      }
    });
    return () => sub.remove();
  }, [profile, uploadNow]);

  const clearError = useCallback(() => setError(null), []);

  const value = useMemo(
    () => ({
      forms,
      masters,
      locations,
      syncStatus,
      loading,
      error,
      refreshing,
      downloadForm,
      refresh,
      queueRecord,
      pending,
      uploading,
      uploadNow,
      clearError,
    }),
    [
      forms,
      masters,
      locations,
      syncStatus,
      loading,
      error,
      refreshing,
      downloadForm,
      refresh,
      queueRecord,
      pending,
      uploading,
      uploadNow,
      clearError,
    ],
  );

  return <DataContext.Provider value={value}>{children}</DataContext.Provider>;
}

export function useData(): DataContextValue {
  const ctx = useContext(DataContext);
  if (!ctx) {
    throw new Error('useData must be used within DataProvider');
  }
  return ctx;
}
