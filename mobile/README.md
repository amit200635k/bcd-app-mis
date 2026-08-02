# BCD Survey Mobile App

React Native (Android) client for the BCD survey platform. Implements the **offline-first loop**: download forms + masters + locations, fill a dynamic form offline (master / location_cascade / GPS / dropdown / conditional show+required fields), queue the record locally, then auto-upload it when connectivity returns and view the server's `/v1/sync/status`.

This is the first mobile milestone (core offline loop). Camera, signature, file upload, SQLite storage and background workers are deliberately out of scope for now.

## Prerequisites

- Node 18+ and npm.
- Android toolchain (JDK 17+, Android SDK with **API 37 platform only** — build tools 37.0.0, AGP 9.2, Gradle 9.4.1). Follow the [React Native environment setup](https://reactnative.dev/docs/set-up-your-environment) guide.
- The backend running at `http://localhost:81/bcd-app` (XAMPP, port 81). The app talks to the API under `/bcd-app/api/v1`.

## Toolchain

- React Native **0.87.0-rc.3** (first RN line whose Gradle plugin works with AGP 9). Its template ships `compileSdk 37` / `targetSdk 36` / build tools 37.0.0, AGP **9.2.1** (pinned by the RN Gradle plugin), Gradle wrapper **9.4.1**, and sets `android.builtInKotlin=false` + `android.newDsl=false` in `gradle.properties`.
- Only the API 37 platform and 37.0.0 build-tools are installed on this machine; the Gradle home keeps only the 9.4.1 wrapper distribution.

## Running

```sh
# 1. Install dependencies
npm install

# 2. Start Metro
npm start

# 3. In another terminal, build & launch on an Android emulator
npm run android
```

The Android emulator reaches your host machine via `10.0.2.2`, so the dev build talks to `http://10.0.2.2:81/bcd-app/api/v1` automatically (`src/config/index.ts`). On a physical device, point the device at your machine's LAN IP by editing `DEV_API_HOST` in `src/config/index.ts`.

Cleartext HTTP is enabled for debug builds only (`android/app/build.gradle` → `manifestPlaceholders.usesCleartextTraffic`); release builds require an HTTPS endpoint — replace the `survey.example.gov.in` placeholder in `src/config/index.ts`.

## Login

Use any portal user created by `database/seed_demo.php` (e.g. `admin` / `admin123`) — the app supports every role. The server issues an access + refresh token pair; tokens and the profile are stored in the device Keychain, and the device registers itself via `POST /v1/devices` on first login.

## Structure

- `src/config/index.ts` — API base URL + sync settings.
- `src/api/` — `client.ts` (fetch wrapper + single-flight token refresh), `endpoints.ts`, `cache.ts` (AsyncStorage offline store + pending-record queue + device id), `session.ts` (Keychain).
- `src/context/` — `AuthContext.tsx` (login/logout/bootstrap/device registration), `DataContext.tsx` (offline cache load, refresh, download, queue + auto-upload).
- `src/screens/` — `LoginScreen`, `HomeScreen` (forms list), `FormFillScreen` (dynamic form + client-side conditions/validation), `SyncScreen` (pending queue + `/v1/sync/status`).
- `src/components/fields/` — field renderers for text/number/date, radio/checkbox/multi-select, dropdown, master, location cascade, GPS (other field types show a "not available offline yet" note).
- `src/utils/` — `conditions.ts` (mirrors the server `ConditionEvaluator`), `validators.ts` (mirrors server validation rules).

## Checks

```sh
npx tsc --noEmit   # typecheck
npm run lint       # eslint
npm test           # jest (native modules are mocked in jest.setup.js)
```
