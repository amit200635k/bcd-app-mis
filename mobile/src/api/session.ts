import * as Keychain from 'react-native-keychain';

const ACCESS = 'bcd.access_token';
const REFRESH = 'bcd.refresh_token';
const PROFILE = 'bcd.profile';

const OPTIONS: Keychain.SetOptions = {
  service: 'com.bcdmobile.session',
  accessible: Keychain.ACCESSIBLE.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
};

export interface StoredProfile {
  id: number;
  username: string;
  full_name: string;
  roles: string[];
}

export class SessionStore {
  static async save(tokens: {access_token: string; refresh_token: string; expires_in: number}, profile: StoredProfile): Promise<void> {
    await Promise.all([
      Keychain.setGenericPassword(ACCESS, tokens.access_token, OPTIONS),
      Keychain.setGenericPassword(REFRESH, tokens.refresh_token, OPTIONS),
    ]);
    await this.setProfile(profile);
    this.scheduleExpiry(tokens.expires_in);
  }

  static async readAccessToken(): Promise<string | null> {
    const r = await Keychain.getGenericPassword(OPTIONS);
    return r && r.username === ACCESS ? r.password : null;
  }

  static async readRefreshToken(): Promise<string | null> {
    const r = await Keychain.getGenericPassword(OPTIONS);
    return r && r.username === REFRESH ? r.password : null;
  }

  static async readProfile(): Promise<StoredProfile | null> {
    const r = await Keychain.getGenericPassword({...OPTIONS, service: 'com.bcdmobile.profile'});
    if (!r || r.username !== PROFILE) {
      return null;
    }
    try {
      return JSON.parse(r.password) as StoredProfile;
    } catch {
      return null;
    }
  }

  private static async setProfile(profile: StoredProfile): Promise<void> {
    await Keychain.setGenericPassword(PROFILE, JSON.stringify(profile), {
      ...OPTIONS,
      service: 'com.bcdmobile.profile',
    });
  }

  static async clear(): Promise<void> {
    await Promise.all([
      Keychain.resetGenericPassword(OPTIONS),
      Keychain.resetGenericPassword({...OPTIONS, service: 'com.bcdmobile.profile'}),
    ]);
  }

  /**
   * Fire-and-forget in-app warning once the access token is past its TTL.
   * A real production app would silently refresh at the 80% mark; the API
   * client already refreshes on 401, so this is just a background signal.
   */
  private static scheduleExpiry(expiresIn: number): void {
    const ms = Math.max(1000, expiresIn * 1000);
    setTimeout(() => {
      // No-op by design: refresh-on-401 is handled by ApiClient. Keeping a
      // hook here documents the intent and reserves a place for proactive
      // refresh without adding complexity to the token lifecycle.
    }, ms);
  }
}
