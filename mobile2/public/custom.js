// Runtime API config — edit this file and run `npm run sync` to apply.
// No Gradle rebuild needed.
window.__BCD_CONFIG__ = {
  API_BASE_URL: 'http://127.0.0.1:8080/bcd-app/api/v1',
  // LDPlayer:        adb reverse tcp:8080 tcp:81 → host Apache :81
  // Physical device: replace 127.0.0.1 with your machine's LAN IP
  // Release:         HTTPS endpoint required (cleartext disabled)
};
