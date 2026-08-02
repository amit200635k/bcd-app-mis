import React, {createContext, useCallback, useContext, useEffect, useMemo, useState} from 'react';
import DeviceInfo from 'react-native-device-info';
import {authApi, devicesApi} from '../api/endpoints';
import {ApiError} from '../api/client';
import {OfflineCache} from '../api/cache';
import {SessionStore, StoredProfile} from '../api/session';

interface AuthContextValue {
  profile: StoredProfile | null;
  bootstrapping: boolean;
  loggingIn: boolean;
  loginError: string | null;
  login: (username: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({children}: {children: React.ReactNode}) {
  const [profile, setProfile] = useState<StoredProfile | null>(null);
  const [bootstrapping, setBootstrapping] = useState(true);
  const [loggingIn, setLoggingIn] = useState(false);
  const [loginError, setLoginError] = useState<string | null>(null);

  useEffect(() => {
    (async () => {
      try {
        const token = await SessionStore.readAccessToken();
        const stored = await SessionStore.readProfile();
        if (token && stored) {
          setProfile(stored);
        }
      } finally {
        setBootstrapping(false);
      }
    })();
  }, []);

  const login = useCallback(async (username: string, password: string) => {
    setLoggingIn(true);
    setLoginError(null);
    try {
      const deviceId = await OfflineCache.getOrCreateDeviceId();
      const tokens = await authApi.login(username, password, deviceId);

      const savedProfile: StoredProfile = {
        id: tokens.user.id,
        username: tokens.user.username,
        full_name: tokens.user.full_name,
        roles: Array.isArray(tokens.user.role) ? tokens.user.role : [],
      };
      await SessionStore.save(
        {access_token: tokens.access_token, refresh_token: tokens.refresh_token, expires_in: tokens.expires_in},
        savedProfile,
      );
      setProfile(savedProfile);

      // Register the device so the server can route sync-queue items to it.
      try {
        const [deviceName, systemName, systemVersion, appVersion] = [
          await DeviceInfo.getDeviceName().catch(() => 'BCD Mobile'),
          DeviceInfo.getSystemName(),
          DeviceInfo.getSystemVersion(),
          DeviceInfo.getVersion(),
        ];
        await devicesApi.register({
          device_id: deviceId,
          device_name: deviceName || 'BCD Mobile',
          platform: systemName || 'android',
          os_version: systemVersion || undefined,
          app_version: appVersion || '1.0.0',
        });
      } catch {
        // Device registration is best-effort; token issuance already succeeded.
      }
    } catch (err) {
      setLoginError(err instanceof ApiError ? err.fieldMessage : 'Unable to log in. Please try again.');
      throw err;
    } finally {
      setLoggingIn(false);
    }
  }, []);

  const logout = useCallback(async () => {
    try {
      await authApi.logout();
    } catch {
      // Even if the server call fails, clear the local session.
    }
    await SessionStore.clear();
    setProfile(null);
  }, []);

  const value = useMemo(
    () => ({profile, bootstrapping, loggingIn, loginError, login, logout}),
    [profile, bootstrapping, loggingIn, loginError, login, logout],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error('useAuth must be used within AuthProvider');
  }
  return ctx;
}
