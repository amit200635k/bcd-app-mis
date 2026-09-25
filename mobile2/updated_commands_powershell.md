# Building Survey MIS Mobile — PowerShell Commands (Updated)

All commands for **mobile2/** (Capacitor 8 + TypeScript + Bootstrap 5 + SQLite).
Package: `com.jsac_bcd_survey.app` · minSdk 24 / targetSdk 36 / compileSdk 37.

> **Machine:** Windows, XAMPP Apache on port 81, LDPlayer 9 emulator, JDK 21 for Gradle.

---

## 1. Prerequisites & Paths

| Tool | Path / Version |
|---|---|
| Node | v22+ |
| JDK 21 (Gradle builds) | `C:\Program Files\Eclipse Adoptium\jdk-21.0.12.8-hotspot` |
| Android SDK | `%LOCALAPPDATA%\Android\Sdk` |
| LDPlayer 9 | `D:\LDPlayer\LDPlayer9\` (v9.5.31.0) |
| LDPlayer ADB | `D:\LDPlayer\LDPlayer9\adb.exe` |
| LDPlayer Console | `D:\LDPlayer\LDPlayer9\ldconsole.exe` |
| Backend | `http://localhost:81/bcd-app/api/v1` |
| APK debug | `mobile2\android\app\build\outputs\apk\debug\app-debug.apk` |
| APK release | `mobile2\android\app\build\outputs\apk\release\app-release.apk` |

> ⚠️ **JDK 21 is required** for mobile2 Gradle builds. JDK 17 breaks AGP 8.13.
> ⚠️ **Never use Android Studio AVD/emulator** — LDPlayer only.
> ⚠️ LDPlayer serial: `emulator-5554` (index 0).

---

## 2. LDPlayer Management

### Start emulator

```powershell
& "D:\LDPlayer\LDPlayer9\ldconsole.exe" launch --index 0
```

Opens the LDPlayer window. Wait ~30 seconds for boot.

### Stop emulator

```powershell
& "D:\LDPlayer\LDPlayer9\ldconsole.exe" quit --index 0
```

### Restart emulator (stop + start)

```powershell
& "D:\LDPlayer\LDPlayer9\ldconsole.exe" quit --index 0
Start-Sleep 8
& "D:\LDPlayer\LDPlayer9\ldconsole.exe" launch --index 0
```

### Check if emulator is running

```powershell
& "D:\LDPlayer\LDPlayer9\adb.exe" devices
```

Expected output:
```
List of devices attached
emulator-5554	device
```

If the device shows as `offline`, wait 30 seconds. If it doesn't appear, enable **ADB调试 → 本地连接** in LDPlayer settings (gear icon → Other settings).

### Start ADB server (if devices not visible)

```powershell
& "D:\LDPlayer\LDPlayer9\adb.exe" start-server
```

> ⚠️ **Never `adb kill-server` while LDPlayer runs** — the bridge won't re-register.

---

## 3. ADB Bridge & Tunnel

### Check current reverse tunnels

```powershell
& "D:\LDPlayer\LDPlayer9\adb.exe" -s emulator-5554 reverse --list
```

### Set up API tunnel (device 8080 → host 81)

```powershell
& "D:\LDPlayer\LDPlayer9\adb.exe" -s emulator-5554 reverse tcp:8080 tcp:81
```

Expected output after `--list`:
```
host-10 tcp:8080 tcp:81
```

> Ports < 1024 cannot be bound device-side by adb reverse, so we use 8080 on the device and tunnel to 81 on the host.

### Remove a specific tunnel

```powershell
& "D:\LDPlayer\LDPlayer9\adb.exe" -s emulator-5554 reverse --remove tcp:8080
```

### Remove all tunnels

```powershell
& "D:\LDPlayer\LDPlayer9\adb.exe" -s emulator-5554 reverse --remove-all
```

### Verify tunnel works (test health endpoint from device)

```powershell
& "D:\LDPlayer\LDPlayer9\adb.exe" -s emulator-5554 shell curl -s http://127.0.0.1:8080/bcd-app/api/v1/health
```

Expected:
```json
{"success":true,"status":"ok","app":"Building Survey MIS Platform","env":"development","time":"...","checks":{"database":true}}
```

---

## 4. Install & Launch APK

### Set variables (use once per session)

```powershell
$ld = "D:\LDPlayer\LDPlayer9"
$adb = "$ld\adb.exe"
$apk = "D:\Xampp\htdocs\bcd-app\mobile2\android\app\build\outputs\apk\debug\app-debug.apk"
```

### Install APK on device

```powershell
& $adb -s emulator-5554 install -r $apk
```

Output: `Success`

> `-r` = replace existing installation (keeps data).

### Launch app

```powershell
& $adb -s emulator-5554 shell am start -n com.jsac_bcd_survey.app/.MainActivity
```

> After `install -r`, the first `am start` may race the package teardown. Just retry after a few seconds.

### Force-stop app

```powershell
& $adb -s emulator-5554 shell am force-stop com.jsac_bcd_survey.app
```

### Full install + tunnel + launch (one-liner)

```powershell
$ld = "D:\LDPlayer\LDPlayer9"
$adb = "$ld\adb.exe"
& $adb -s emulator-5554 install -r "D:\Xampp\htdocs\bcd-app\mobile2\android\app\build\outputs\apk\debug\app-debug.apk"
& $adb -s emulator-5554 reverse tcp:8080 tcp:81
Start-Sleep 2
& $adb -s emulator-5554 shell am start -n com.jsac_bcd_survey.app/.MainActivity
```

---

## 5. Build Commands

### 5a. Install dependencies (first time only)

```powershell
cd D:\Xampp\htdocs\bcd-app\mobile2
npm install
```

### 5b. Typecheck only (no build)

```powershell
cd D:\Xampp\htdocs\bcd-app\mobile2
npm run typecheck
```

Output: nothing = clean. Errors = type issues to fix.

### 5c. Web build only (Vite → dist/)

```powershell
cd D:\Xampp\htdocs\bcd-app\mobile2
npm run build
```

This runs `tsc --noEmit && vite build`. Output: `dist/` folder with web assets.

### 5d. Cap sync only (copy dist → Android assets)

```powershell
cd D:\Xampp\htdocs\bcd-app\mobile2
npx cap sync android
```

Copies `dist/` contents into `android/app/src/main/assets/public/`.

### 5e. Full web build + cap sync (recommended)

```powershell
cd D:\Xampp\htdocs\bcd-app\mobile2
npm run sync
```

This runs `npm run build && cap sync android` — builds web and copies to Android.

### 5f. Build debug APK

```powershell
cd D:\Xampp\htdocs\bcd-app\mobile2
$env:JAVA_HOME = 'C:\Program Files\Eclipse Adoptium\jdk-21.0.12.8-hotspot'
& .\android\gradlew.bat -p .\android assembleDebug
```

Output: `android\app\build\outputs\apk\debug\app-debug.apk` (~52 MB)

### 5g. Build release APK (signed)

```powershell
cd D:\Xampp\htdocs\bcd-app\mobile2
$env:JAVA_HOME = 'C:\Program Files\Eclipse Adoptium\jdk-21.0.12.8-hotspot'
& .\android\gradlew.bat -p .\android assembleRelease
```

Output: `android\app\build\outputs\apk\release\app-release.apk` (~50 MB)
Requires `android/keystore.properties` + `android/app/bcd-survey-release.keystore` (gitignored).

### 5h. Full workflow: build + install + launch

```powershell
cd D:\Xampp\htdocs\bcd-app\mobile2

# 1. Web build + cap sync
npm run sync

# 2. Gradle debug build
$env:JAVA_HOME = 'C:\Program Files\Eclipse Adoptium\jdk-21.0.12.8-hotspot'
& .\android\gradlew.bat -p .\android assembleDebug

# 3. Install + tunnel + launch
$adb = "D:\LDPlayer\LDPlayer9\adb.exe"
& $adb -s emulator-5554 install -r android\app\build\outputs\apk\debug\app-debug.apk
& $adb -s emulator-5554 reverse tcp:8080 tcp:81
Start-Sleep 2
& $adb -s emulator-5554 shell am start -n com.jsac_bcd_survey.app/.MainActivity
```

---

## 6. Debugging on Device

### JS console.log (Capacitor WebView)

```powershell
& "D:\LDPlayer\LDPlayer9\adb.exe" -s emulator-5554 logcat -s "Capacitor/Console:I"
```

### All app output

```powershell
$adb = "D:\LDPlayer\LDPlayer9\adb.exe"
$appPid = & $adb -s emulator-5554 shell pidof com.jsac_bcd_survey.app
& $adb -s emulator-5554 logcat --pid=$appPid
```

### Screenshot (use cmd /c — PowerShell redirection corrupts binary)

```powershell
$adb = "D:\LDPlayer\LDPlayer9\adb.exe"
cmd /c "`"$adb`" -s emulator-5554 exec-out screencap -p > screen.png"
```

### WebView DevTools (CDP)

```powershell
$adb = "D:\LDPlayer\LDPlayer9\adb.exe"
$appPid = & $adb -s emulator-5554 shell pidof com.jsac_bcd_survey.app
& $adb -s emulator-5554 forward tcp:9222 localabstract:webview_devtools_remote_$appPid
```

Then open `http://127.0.0.1:9222/json` in a browser to see the page list. Drive via WebSocket.

### Check app package version

```powershell
& "D:\LDPlayer\LDPlayer9\adb.exe" -s emulator-5554 shell dumpsys package com.jsac_bcd_survey.app | Select-String "versionName"
```

---

## 7. Custom URL Config (No Rebuild)

### How it works

1. Edit `mobile2/public/custom.js` — change the API base URL
2. Run `npm run sync` — Vite copies `public/custom.js` to `dist/custom.js`, then `cap sync` copies it to Android assets
3. Reinstall the APK — new URL is live

**No Gradle rebuild needed.** The TypeScript bundle stays the same; only the static `custom.js` file changes.

### The custom.js file

Location: `mobile2/public/custom.js`

```javascript
// Runtime API config — edit this file and run npm run sync to apply.
// No Gradle rebuild needed.
window.__BCD_CONFIG__ = {
  API_BASE_URL: 'http://127.0.0.1:8080/bcd-app/api/v1',
  // LDPlayer: adb reverse tcp:8080 tcp:81 → host Apache :81
  // Physical device on same network: replace 127.0.0.1 with host LAN IP
  // Release: HTTPS endpoint required (cleartext disabled)
};
```

### Change the URL

```powershell
# 1. Edit the file (change API_BASE_URL to your desired URL)
notepad D:\Xampp\htdocs\bcd-app\mobile2\public\custom.js

# 2. Build web + sync to Android assets (NO Gradle rebuild)
cd D:\Xampp\htdocs\bcd-app\mobile2
npm run sync

# 3. Reinstall APK on device (keeps app data)
$adb = "D:\LDPlayer\LDPlayer9\adb.exe"
& $adb -s emulator-5554 install -r android\app\build\outputs\apk\debug\app-debug.apk

# 4. Restart app to pick up new config
& $adb -s emulator-5554 shell am force-stop com.jsac_bcd_survey.app
Start-Sleep 1
& $adb -s emulator-5554 shell am start -n com.jsac_bcd_survey.app/.MainActivity
```

### Why no Gradle rebuild?

- `public/custom.js` is a **static file**, not TypeScript
- Vite copies `public/` → `dist/` as-is (no bundling)
- `cap sync` copies `dist/` → `android/app/src/main/assets/public/`
- The APK's WebView loads `custom.js` at runtime before the app bundle
- Only the web assets change; the native Android shell stays the same

### Common URL configurations

| Scenario | API_BASE_URL |
|---|---|
| LDPlayer (adb reverse) | `http://127.0.0.1:8080/bcd-app/api/v1` |
| Physical device on same LAN | `http://192.168.x.x:81/bcd-app/api/v1` |
| Public server (release) | `https://your-server.com/api/v1` |

---

## 8. Quick Reference

| Task | Command |
|---|---|
| Start LDPlayer | `& "$ld\ldconsole.exe" launch --index 0` |
| Stop LDPlayer | `& "$ld\ldconsole.exe" quit --index 0` |
| Check devices | `& $adb devices` |
| Set up tunnel | `& $adb -s emulator-5554 reverse tcp:8080 tcp:81` |
| Install APK | `& $adb -s emulator-5554 install -r $apk` |
| Launch app | `& $adb -s emulator-5554 shell am start -n com.jsac_bcd_survey.app/.MainActivity` |
| Force-stop app | `& $adb -s emulator-5554 shell am force-stop com.jsac_bcd_survey.app` |
| Web build | `npm run build` |
| Cap sync | `npx cap sync android` |
| Full sync | `npm run sync` |
| Gradle debug | `$env:JAVA_HOME='...'; & .\android\gradlew.bat -p .\android assembleDebug` |
| Gradle release | Same with `assembleRelease` |
| JS console logs | `& $adb -s emulator-5554 logcat -s "Capacitor/Console:I"` |
| Screenshot | `cmd /c "$adb -s emulator-5554 exec-out screencap -p > screen.png"` |
| Change API URL | Edit `public/custom.js` → `npm run sync` → reinstall APK |
| Health check | `Invoke-WebRequest http://localhost:81/bcd-app/api/v1/health` |

---

## 9. Gotchas

- **`$pid` is read-only in PowerShell** — use `$appPid` or another variable name when capturing the app PID from `adb shell pidof`.
- **JDK 21 only** for mobile2 Gradle builds — JDK 17 breaks AGP 8.13 (`invalid source release: 21`).
- LDPlayer: enable **ADB调试 → 本地连接** in the emulator settings or adb never sees `emulator-5554`.
- Never `adb kill-server` while LDPlayer runs — the bridge won't re-register; a full `quit` + `launch` is the fix.
- `adb reverse` cannot bind device ports < 1024 (port 81 → "Permission denied"); always use 8080 on the device side.
- `cap sync android` overwrites `android/app/src/main/assets/public` — do not hand-edit files there.
- `npm run sync` = `npm run build && cap sync android` (web build + copy to Android).
- After `install -r` on LDPlayer, the first `am start` may race the package teardown — retry after a few seconds.
