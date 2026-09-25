# BCD Survey — Mobile App Build Commands

Full command list for the Capacitor Android app (`mobile2/`): from a scratch checkout to a
signed release **AAB bundle** and **APK**.

| Item | Value |
|---|---|
| App project | `D:\Xampp\htdocs\bcd-app\mobile2` |
| App id | `com.jsac_bcd_survey.app` |
| App name | BCD Survey |
| Web output | `mobile2/dist/` (`webDir: 'dist'`) |
| JDK | `C:\Program Files\Eclipse Adoptium\jdk-21.0.12.8-hotspot` |
| Android SDK | `C:\Users\JSAC\AppData\Local\Android\Sdk` (set in `android/local.properties`) |
| adb | `D:\LDPlayer\LDPlayer9\adb.exe` |
| Physical device | `RZ8R91FACZA` (Samsung F12) |
| Live API base | `https://jswm.jharkhand.gov.in/bcdapp/api/v1` |
| Local API base (dev) | `http://127.0.0.1:8080/bcd-app/api/v1` (via `adb reverse tcp:8080 tcp:81`) |

---

## 0. Prerequisites / scratch setup

```powershell
cd D:\Xampp\htdocs\bcd-app\mobile2
npm install
npm run typecheck        # optional sanity check
```

First-time platform generation (only if `android/` is missing):

```powershell
npx cap add android
```

---

## 1. Web development (browser preview)

```powershell
cd D:\Xampp\htdocs\bcd-app\mobile2
npm run dev              # Vite dev server -> http://localhost:<port>
npm run build            # typecheck + Vite build -> dist/
```

---

## 2. Sync the web build into the Android project

The Android app ships the compiled web assets, so after every web-code change you MUST
re-sync before building the APK, otherwise the device gets a stale UI.

```powershell
npm run sync             # = npm run build && npx cap sync android
```

or step by step:

```powershell
npm run build
npx cap sync android
```

Verify the copied assets:

```powershell
Get-ChildItem android\app\src\main\assets\public
```

---

## 3. Debug run on a connected device / emulator

```powershell
set JAVA_HOME=C:\Program Files\Eclipse Adoptium\jdk-21.0.12.8-hotspot
cd D:\Xampp\htdocs\bcd-app\mobile2
npm run android          # = npm run sync && npx cap run android  (pick device in menu)
```

Manual debug install:

```powershell
D:\LDPlayer\LDPlayer9\adb.exe devices
D:\LDPlayer\LDPlayer9\adb.exe -s RZ8R91FACZA install -r android\app\build\outputs\apk\debug\app-debug.apk
```

Tunnel to the local XAMPP API (runs on port 81) while developing:

```powershell
D:\LDPlayer\LDPlayer9\adb.exe reverse tcp:8080 tcp:81
```

Set the API base in `mobile2/public/custom.js`:
- Live: `https://jswm.jharkhand.gov.in/bcdapp/api/v1`
- Local via tunnel: `http://127.0.0.1:8080/bcd-app/api/v1`

Open in Android Studio instead (optional):

```powershell
npx cap open android
```

---

## 4. Debug APK via Gradle

```powershell
cd D:\Xampp\htdocs\bcd-app\mobile2\android
set JAVA_HOME=C:\Program Files\Eclipse Adoptium\jdk-21.0.12.8-hotspot
.\gradlew.bat assembleDebug
```

Output: `app\build\outputs\apk\debug\app-debug.apk`

---

## 5. Release signing (one time per keystore)

`android/app/build.gradle` already reads `android/keystore.properties` and signs the
`release` build type automatically when it exists. The keystore file is NOT in the repo.

**a) Create the keystore** (matches the configured `storeFile` + `keyAlias`):

```powershell
cd D:\Xampp\htdocs\bcd-app\mobile2\android
keytool -genkeypair -v -keystore bcd-survey-release.keystore -alias bcd-survey ` -keyalg RSA -keysize 2048 -validity 10000 `   -storepass <STRONG_PASSWORD> -keypass <STRONG_PASSWORD> `  -dname "CN=BCD Survey, OU=IT, O=Jharkhand, L=Ranchi, S=JH, C=IN"`
```

or point `keystore.properties` at an existing `.jks` file.

**b) `android/keystore.properties`** (path is relative to `android/`):

```ini
storeFile=bcd-survey-release.keystore
storePassword=<STRONG_PASSWORD>
keyAlias=bcd-survey
keyPassword=<STRONG_PASSWORD>
```

**c) NEVER commit secrets.** Add to `.gitignore`:

```
mobile2/android/keystore.properties
mobile2/android/*.keystore
mobile2/android/*.jks
```

---

## 6. Signed release build (AAB + APK)

```powershell
cd D:\Xampp\htdocs\bcd-app\mobile2\android
set JAVA_HOME=C:\Program Files\Eclipse Adoptium\jdk-21.0.12.8-hotspot
.\gradlew.bat clean
.\gradlew.bat bundleRelease assembleRelease
```

Outputs:
- **AAB (Play Store):** `app\build\outputs\bundle\release\app-release.aab`
- **APK (direct install):** `app\build\outputs\apk\release\app-release.apk`

Verify the signature:

```powershell
& "$env:LOCALAPPDATA\Android\Sdk\build-tools\36.0.0\apksigner.bat" verify --print-certs app\build\outputs\apk\release\app-release.apk
```

---

## 7. Install the release APK + launch

```powershell
D:\LDPlayer\LDPlayer9\adb.exe -s RZ8R91FACZA install -r app\build\outputs\apk\release\app-release.apk
D:\LDPlayer\LDPlayer9\adb.exe -s RZ8R91FACZA shell am start -n com.jsac_bcd_survey.app/.MainActivity
D:\LDPlayer\LDPlayer9\adb.exe -s RZ8R91FACZA shell pidof com.jsac_bcd_survey.app
```

---

## 8. Version bump before each release

`mobile2/android/app/build.gradle`:

```gradle
versionCode 1      // increment every release
versionName "1.0"
```

---

## 9. Useful checks / troubleshooting

```powershell
npx cap doctor
npx cap ls
& "$env:LOCALAPPDATA\Android\Sdk\build-tools\36.0.0\aapt.exe" dump badging app\build\outputs\apk\release\app-release.apk | Select-Object -First 8
```

- `Could not find supported Gradle` / SDK errors: confirm `android/local.properties` has
  `sdk.dir` and `JAVA_HOME` is the JDK 21 path.
- Old UI on device after code changes: run `npm run sync` again, then rebuild the APK.
- Signing password prompts: Gradle reads them from `keystore.properties` (do not pass on CLI).