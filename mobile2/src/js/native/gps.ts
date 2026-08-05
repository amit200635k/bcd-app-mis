import { Geolocation, Position } from '@capacitor/geolocation';

export interface GpsFix {
  latitude: number;
  longitude: number;
  accuracy: number;
  altitude: number | null;
  captured_at: string;
}

export async function captureGps(): Promise<GpsFix> {
  try {
    await Geolocation.requestPermissions();
  } catch {
    // permission prompt may be skipped on some builds
  }
  const pos: Position = await Geolocation.getCurrentPosition({
    enableHighAccuracy: true,
    timeout: 20_000,
    maximumAge: 5_000,
  });
  return {
    latitude: pos.coords.latitude,
    longitude: pos.coords.longitude,
    accuracy: pos.coords.accuracy ?? 0,
    altitude: pos.coords.altitude ?? null,
    captured_at: new Date().toISOString(),
  };
}

export function formatLatLng(lat: number | null, lng: number | null): string {
  if (lat == null || lng == null) return '—';
  return `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
}