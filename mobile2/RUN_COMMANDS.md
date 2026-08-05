# BCD Survey Mobile (mobile2) — Run & Build Commands

Offline-first dynamic survey app: **Capacitor 8 + TypeScript + Bootstrap 5 + SQLite**.
Package: `com.jsac_bcd_survey.app` · Android **minSdk 24 / targetSdk 36 / compileSdk 37**.

All paths relative to `mobile2/` unless stated.

---

## 1. Prerequisites (this machine)

| Tool | Location / version |
|---|---|
| Node | v22.9.0 (Capacitor 8 needs Node 22+) |
| **JDK 21** (Capacitor 8 / AGP 8.13) | `C:\Program Files\Eclipse Adoptium\jdk-21.0.12.8-hotspot` |
| Android SDK | `%LOCALAPPDATA%\Android\Sdk` (build-only; no AVDs are used) |
| **LDPlayer 9** (the only emulator) | `D:\LDPlayer\LDPlayer9\` — v9.5.31.0, instance 0 serial `emulator-5554` |
| Backend | XAMPP Apache on **port 81** → `http://localhost:81/bcd-app/api/v1` |

> ⚠️ **Two JDKs on this machine:** JDK 17 is for the old RN app (`mobile/`); **JDK 21 is required for mobile2 Gradle builds**. Set `JAVA_HOME` per build (see §4).

> ⚠️ **Emulator rule: LDPlayer only.** Never use the Android Studio AVD/emulator.

---

## 2. Install dependencies (first time)

```powershell
cd D:\Xampp\htdocs\bcd-app\mobile2
npm install
```

---

## 3. Web app — dev & build (Vite)

```powershell
# Dev server with live reload (port 8100)
npm run dev

# Typecheck only
npm run typecheck

# Production web build → dist/  (tsc --noEmit && vite build)
npm run build
```

Web assets are served **from `dist/`** inside the APK — always build before `cap sync`.

---

## 4. Android build (APK)

```powershell
cd D:\Xampp\htdocs\bcd-app\mobile2

# 1. Build web + copy into android/  (build + cap sync)
npm run sync

# 2. Build debug APK (JDK 21!)
$env:JAVA_HOME = 'C:\Program Files\Eclipse Adoptium\jdk-21.0.12.8-hotspot'
& .\android\gradlew.bat -p .\android assembleDebug
```

**APK output:** `android\app\build\outputs\apk\debug\app-debug.apk`

Release (after signing config is in place, see §8):

```powershell
$env:JAVA_HOME = 'C:\Program Files\Eclipse Adoptium\jdk-21.0.12.8-hotspot'
& .\android\gradlew.bat -p .\android assembleRelease
# → android\app\build\outputs\apk\release\app-release.apk
```

---

## 5. LDPlayer emulator

`$ld = "D:\LDPlayer\LDPlayer9"` (ldconsole.exe / adb.exe live here).

```powershell
# Start the instance (headed — the window shows the app)
& "$ld\ldconsole.exe" launch --index 0

# Stop / restart / reboot guest
& "$ld\ldconsole.exe" quit --index 0
& "$ld\ldconsole.exe" reboot --index 0
```

**Getting adb to see the device (critical):**

1. In the LDPlayer window: gear icon (right-edge toolbar) → **其他设置 (Other settings)** → **ADB调试 → 本地连接 (Local connection)**. Without this the adb bridge never starts.
2. Start LDPlayer's own adb server, then launch/relaunch the instance so the bridge registers:

```powershell
& "$ld\adb.exe" start-server
& "$ld\ldconsole.exe" quit --index 0; Start-Sleep 8
& "$ld\ldconsole.exe" launch --index 0
& "$ld\adb.exe" devices          # → "emulator-5554  device" (wait up to ~2 min after launch)
```

- Instance serials: `emulator-5554` (index 0), `5556` (1), `5558` (2), …
- `ldconsole adb --index 0` fails with "device not found" until the bridge registers — it is **not** a diagnostic of the emulator being down.
- Do **not** kill the adb server while LDPlayer runs (the bridge won't re-register until a full `quit` + `launch`).
- Guest networking is NAT-only (no host alias like `10.0.2.2`) — use `adb reverse` (below) for API access.

---

## 6. Install, API tunnel & launch

```powershell
$ld = "D:\LDPlayer\LDPlayer9"
$adb = "$ld\adb.exe"
$apk = "D:\Xampp\htdocs\bcd-app\mobile2\android\app\build\outputs\apk\debug\app-debug.apk"

# 1. Install
& $adb -s emulator-5554 install -r $apk

# 2. API tunnel: guest 127.0.0.1:8080 → host Apache :81
#    (ports <1024 cannot be bound device-side by adb reverse)
& $adb -s emulator-5554 reverse tcp:8080 tcp:81
& $adb -s emulator-5554 reverse --list        # → host-10 tcp:8080 tcp:81

# 3. Launch
& $adb -s emulator-5554 shell am start -n com.jsac_bcd_survey.app/.MainActivity
```

> After an `install -r` on LDPlayer, the first `am start` often races the package teardown ("failed to attach"). Just retry `am start` after a few seconds.

Re-run `adb reverse` after every adb restart (emulator reboot, `quit`+`launch`, machine reboot).

---

## 7. Debugging on device

```powershell
$adb = "D:\LDPlayer\LDPlayer9\adb.exe"

# JS console.log from the WebView (Capacitor tag):
& $adb -s emulator-5554 logcat -s "Capacitor/Console:I"

# All app output:
& $adb -s emulator-5554 logcat --pid=$(& $adb -s emulator-5554 shell pidof com.jsac_bcd_survey.app)

# Screenshot (use cmd /c — PowerShell redirection corrupts binary):
cmd /c "`"$adb`" -s emulator-5554 exec-out screencap -p > screen.png"
```

**WebView DevTools (CDP)** — the debug WebView exposes Chrome devtools; `uiautomator dump` is broken on LDPlayer, use this instead:

```powershell
$pid = & $adb -s emulator-5554 shell pidof com.jsac_bcd_survey.app
& $adb -s emulator-5554 forward tcp:9222 localabstract:webview_devtools_remote_$pid
# then open http://127.0.0.1:9222/json in a browser / drive via WebSocket
```

---

## 8. Backend reference (dev)

| Item | Value |
|---|---|
| Base URL (browser) | `http://localhost:81/bcd-app/api/v1` |
| Base URL (device, debug) | `http://127.0.0.1:8080/bcd-app/api/v1` (`src/js/config/index.ts`) — reachable via `adb reverse tcp:8080 tcp:81` |
| Health check | `curl http://localhost:81/bcd-app/api/v1/health` |
| Login (state admin) | `admin` / `Admin@12345` |
| Demo users | `dh_surveyor`, `rk_surveyor`, `jb_block`, `sk_district`, `pm_panchayat`, `vp_village` — all `Demo@123` |
| Main form | `GOVT_BUILDING_SURVEY` (published) |

**Why the app talks HTTP to `127.0.0.1:8080`:** LDPlayer's NAT exposes no host alias (10.0.2.2 times out, 172.16.1.1 is unroutable), so the API goes through the adb reverse tunnel. The WebView page origin is `https://localhost`, which would block plain `http://` fetches, so:

- `capacitor.config.ts` → `server.cleartext: true` + `android.allowMixedContent: true` (plus `android:usesCleartextTraffic="true"` in the manifest),
- `plugins.CapacitorHttp.enabled: true` — native HTTP bridge, sidesteps CORS entirely.

**Release builds must use an HTTPS endpoint** (cleartext/mixed content are dev-only) — replace `API_BASE_URL` in `src/js/config/index.ts`.

---

## 9. Full local workflow (typical iteration)

```powershell
# 1. Build web + sync to android
cd D:\Xampp\htdocs\bcd-app\mobile2
npm run sync

# 2. Build APK (JDK 21)
$env:JAVA_HOME = 'C:\Program Files\Eclipse Adoptium\jdk-21.0.12.8-hotspot'
& .\android\gradlew.bat -p .\android assembleDebug

# 3. Install + re-tunnel + launch (LDPlayer must be running)
$adb = "D:\LDPlayer\LDPlayer9\adb.exe"
& $adb -s emulator-5554 install -r android\app\build\outputs\apk\debug\app-debug.apk
& $adb -s emulator-5554 reverse tcp:8080 tcp:81
& $adb -s emulator-5554 shell am start -n com.jsac_bcd_survey.app/.MainActivity
```

---

## 10. Gotchas

- **JDK 21 only** for mobile2 Gradle builds — JDK 17 breaks AGP 8.13 (`invalid source release: 21`).
- LDPlayer: enable **ADB调试 → 本地连接** in the emulator settings or adb never sees `emulator-5554`.
- Never `adb kill-server` while LDPlayer runs — the bridge won't re-register; a full `quit` + `launch` is the fix.
- `adb reverse` cannot bind device ports < 1024 (port 81 → "Permission denied"); always use 8080 on the device side.
- `cap sync android` overwrites `android/app/src/main/assets/public` — do not hand-edit files there.
- Local file `android/local.properties` holds `sdk.dir` (gitignored); recreate after a fresh clone.
- Vite needs Node ≥ 22.12 (22.9 warns but works); if Vite 8 native bindings fail on a fresh install, `npm install vite@^7`.
