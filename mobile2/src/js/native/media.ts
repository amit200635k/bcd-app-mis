import { Camera, CameraResultType, CameraSource } from '@capacitor/camera';
import { Capacitor } from '@capacitor/core';
import { Directory, Filesystem } from '@capacitor/filesystem';

export interface CapturedFile {
  /** Path (relative to Directory.Cache) under which the bytes are stored. */
  uri: string;
  fileName: string;
  mimeType: string;
  sizeBytes: number;
  dataUrl: string;
}

const ATTACH_DIR = 'bcd-attachments';

/* ---------------------------------------------------------------------------
 * generic helpers
 * ------------------------------------------------------------------------ */

function blobToDataUrl(blob: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(blob);
  });
}

function estimateBytes(dataUrl: string): number {
  const base64 = dataUrl.split(',')[1] ?? '';
  return Math.round((base64.length * 3) / 4);
}

export async function storeDataUrl(dataUrl: string, fileName: string): Promise<string> {
  const path = `${ATTACH_DIR}/${fileName}`;
  await Filesystem.writeFile({
    path,
    data: dataUrl,
    directory: Directory.Cache,
    recursive: true,
  });
  return path;
}

/** Read a stored attachment back as a Blob (for multipart upload). */
export async function readAttachmentBlob(uri: string, mimeType: string): Promise<Blob> {
  const res = await Filesystem.readFile({ path: uri, directory: Directory.Cache });
  let dataUrl: string;
  if (typeof res.data === 'string') {
    dataUrl = res.data;
  } else if (res.data instanceof Blob) {
    dataUrl = await blobToDataUrl(res.data);
  } else {
    dataUrl = arrayBufferToBase64(res.data as ArrayBuffer);
  }
  const bytes = atob(dataUrl.replace(/^data:[^;]*;base64,/, ''));
  const arr = new Uint8Array(bytes.length);
  for (let i = 0; i < bytes.length; i++) arr[i] = bytes.charCodeAt(i);
  return new Blob([arr], { type: mimeType });
}

function arrayBufferToBase64(buffer: ArrayBuffer): string {
  let binary = '';
  const bytes = new Uint8Array(buffer);
  const chunk = 0x8000;
  for (let i = 0; i < bytes.length; i += chunk) {
    binary += String.fromCharCode(...bytes.subarray(i, i + chunk));
  }
  return btoa(binary);
}

/* ---------------------------------------------------------------------------
 * camera
 * ------------------------------------------------------------------------ */

export async function takePicture(): Promise<CapturedFile> {
  await Camera.requestPermissions({ permissions: ['camera', 'photos'] });
  const photo = await Camera.getPhoto({
    resultType: CameraResultType.Uri,
    source: CameraSource.Camera,
    quality: 70,
    width: 1600,
    correctOrientation: true,
    saveToGallery: false,
  });

  let dataUrl: string;
  if (Capacitor.isNativePlatform()) {
    // Read the file directly from disk rather than fetching the webPath,
    // which can be a capacitor:// or file:// URL that fails to fetch.
    dataUrl = await readCameraPhoto(photo);
  } else {
    // Web fallback: fetch the blob URL and read it as a data URL.
    const res = await fetch(photo.webPath!);
    const blob = await res.blob();
    dataUrl = await blobToDataUrl(blob);
  }

  const fileName = `img_${Date.now()}.jpg`;
  const uri = await storeDataUrl(dataUrl, fileName);
  const mime = dataUrl.split(';')[0].split(':')[1] ?? 'image/jpeg';
  return { uri, fileName, mimeType: mime, sizeBytes: estimateBytes(dataUrl), dataUrl };
}

/** Read a native camera photo (by path or webPath) into a base64 data URL. */
async function readCameraPhoto(photo: {
  path?: string;
  webPath?: string;
  dataUrl?: string;
  format?: string;
}): Promise<string> {
  // Camera may already return a data URL in some configurations.
  if (photo.dataUrl) return photo.dataUrl;

  const sourcePath = photo.path ?? photo.webPath;
  if (!sourcePath) {
    throw new Error('Camera photo path is missing.');
  }

  try {
    // photo.path is absolute (already includes the cache dir), so read it
    // with Directory.Cache omitted.
    const res = await Filesystem.readFile({ path: sourcePath });
    if (typeof res.data === 'string') return res.data;
    if (res.data instanceof Blob) return blobToDataUrl(res.data);
    return `data:${photo.format === 'png' ? 'image/png' : 'image/jpeg'};base64,${arrayBufferToBase64(res.data as ArrayBuffer)}`;
  } catch {
    // Fall back to fetching the webPath.
    const res = await fetch(photo.webPath!);
    const blob = await res.blob();
    return blobToDataUrl(blob);
  }
}

/* ---------------------------------------------------------------------------
 * image stamping + resize (survey metadata burned into the photo)
 * ------------------------------------------------------------------------ */

export interface ImageStamp {
  /** Right-aligned single-line entries, newest/last drawn lowest. */
  lines: string[];
  /** Target upper bound for the final JPEG, in bytes. */
  maxBytes?: number;
}

/**
 * Decode an image, overlay a semi-transparent band with right-aligned white
 * text at the bottom-right (survey id, district, timestamp, GPS, surveyor id),
 * and re-encode as JPEG compressed to `maxBytes` (default 250 kB).
 *
 * Returns `null` when the source cannot be decoded (the caller keeps the
 * original). The natural (uncropped) aspect ratio is preserved.
 */
export async function stampImage(dataUrl: string, stamp: ImageStamp): Promise<string | null> {
  const maxBytes = stamp.maxBytes ?? 250 * 1024;
  const img = await loadImage(dataUrl).catch(() => null);
  if (!img) return null;

  // Determine a starting scale that keeps the JPEG under the byte budget.
  // Start from the natural size, then step down until the encode fits.
  const canvas = document.createElement('canvas');
  const ctx = canvas.getContext('2d');
  if (!ctx) return null;

  const drawWithScale = (scale: number): { dataUrl: string; bytes: number } => {
    const w = Math.max(1, Math.round(img.naturalWidth * scale));
    const h = Math.max(1, Math.round(img.naturalHeight * scale));
    canvas.width = w;
    canvas.height = h;
    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, w, h);
    ctx.drawImage(img, 0, 0, w, h);

    // Bottom band (height ~14% of image, min 48px).
    const bandH = Math.max(48, Math.round(h * 0.14));
    const fontSize = Math.max(12, Math.round(h * 0.045));
    const g = ctx.createLinearGradient(0, h - bandH, 0, h);
    g.addColorStop(0, 'rgba(0,0,0,0)');
    g.addColorStop(1, 'rgba(0,0,0,0.72)');
    ctx.fillStyle = g;
    ctx.fillRect(0, h - bandH, w, bandH);

    ctx.font = `600 ${fontSize}px "Roboto Mono", "DejaVu Sans Mono", monospace`;
    ctx.textAlign = 'right';
    ctx.textBaseline = 'alphabetic';
    ctx.fillStyle = 'rgba(255,255,255,0.95)';
    const pad = Math.round(w * 0.02);
    const lineGap = Math.round(fontSize * 1.25);
    let y = h - pad;
    for (let i = stamp.lines.length - 1; i >= 0; i--) {
      ctx.fillText(stamp.lines[i], w - pad, y);
      y -= lineGap;
    }

    const out = canvas.toDataURL('image/jpeg', 0.82);
    let bytes = estimateBytes(out);
    // If still oversized at this scale, drop JPEG quality first.
    if (bytes > maxBytes) {
      const q = Math.max(0.35, (maxBytes / bytes) * 0.82);
      const out2 = canvas.toDataURL('image/jpeg', q);
      bytes = estimateBytes(out2);
      return { dataUrl: out2, bytes };
    }
    return { dataUrl: out, bytes };
  };

  let result = drawWithScale(1);
  // Shrink dimensions until the byte budget is met (cap at a tiny fraction so
  // pathological images still terminate).
  let scale = 1;
  while (result.bytes > maxBytes && scale > 0.18) {
    scale *= 0.8;
    result = drawWithScale(scale);
  }
  return result.dataUrl;
}

function loadImage(src: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = () => reject(new Error('image decode failed'));
    img.src = src;
  });
}

/* ---------------------------------------------------------------------------
 * signature (canvas → png)
 * ------------------------------------------------------------------------ */

export function signatureToDataUrl(canvas: HTMLCanvasElement): string {
  return canvas.toDataURL('image/png');
}

export async function saveSignature(dataUrl: string): Promise<CapturedFile> {
  const fileName = `sig_${Date.now()}.png`;
  const uri = await storeDataUrl(dataUrl, fileName);
  return { uri, fileName, mimeType: 'image/png', sizeBytes: estimateBytes(dataUrl), dataUrl };
}

/* ---------------------------------------------------------------------------
 * file picker (WebView <input type=file>)
 * ------------------------------------------------------------------------ */

export function pickFile(): Promise<CapturedFile> {
  return new Promise((resolve, reject) => {
    const input = document.createElement('input');
    input.type = 'file';
    input.style.display = 'none';
    document.body.appendChild(input);
    input.onchange = async () => {
      const file = input.files?.[0];
      input.remove();
      if (!file) {
        reject(new Error('No file selected.'));
        return;
      }
      try {
        const dataUrl = await blobToDataUrl(file);
        const safe = file.name.replace(/[^\w.\- ]+/g, '_');
        const fileName = `${Date.now()}_${safe}`;
        const uri = await storeDataUrl(dataUrl, fileName);
        resolve({
          uri,
          fileName: file.name,
          mimeType: file.type || 'application/octet-stream',
          sizeBytes: file.size,
          dataUrl,
        });
      } catch (e) {
        reject(e);
      }
    };
    input.click();
  });
}