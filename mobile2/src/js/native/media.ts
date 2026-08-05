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

async function storeDataUrl(dataUrl: string, fileName: string): Promise<string> {
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
  if (Capacitor.isNativePlatform() && photo.webPath) {
    const res = await fetch(photo.webPath);
    const blob = await res.blob();
    dataUrl = await blobToDataUrl(blob);
  } else {
    throw new Error('Camera photo could not be read.');
  }

  const fileName = `img_${Date.now()}.jpg`;
  const uri = await storeDataUrl(dataUrl, fileName);
  const mime = dataUrl.split(';')[0].split(':')[1] ?? 'image/jpeg';
  return { uri, fileName, mimeType: mime, sizeBytes: estimateBytes(dataUrl), dataUrl };
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