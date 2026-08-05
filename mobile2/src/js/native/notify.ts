import { LocalNotifications } from '@capacitor/local-notifications';

let permitted: boolean | null = null;

export async function requestNotifyPermission(): Promise<boolean> {
  if (permitted !== null) {
    return permitted;
  }
  try {
    const perms = await LocalNotifications.requestPermissions();
    permitted = perms.display === 'granted';
  } catch {
    permitted = false;
  }
  return permitted;
}

/** Fire a single local notification (used for sync completion feedback). */
export async function notify(title: string, body: string): Promise<void> {
  const ok = await requestNotifyPermission();
  if (!ok) {
    return;
  }
  try {
    await LocalNotifications.schedule({
      notifications: [
        {
          id: Date.now() % 2147483647,
          title,
          body,
          schedule: { at: new Date(Date.now() + 1000) },
        },
      ],
    });
  } catch (e) {
    console.warn('local notification failed', e);
  }
}