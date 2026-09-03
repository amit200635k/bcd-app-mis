// Runtime API config — edit this file and run `npm run sync` to apply.
// No Gradle rebuild needed.
window.__BCD_CONFIG__ = {
  // Live server — works from anywhere (phone uses its own internet).
  API_BASE_URL: 'https://jswm.jharkhand.gov.in/bcdapp/api/v1',
  API_BASE_URL_Live: 'https://jswm.jharkhand.gov.in/bcdapp/api/v1',
  // Local (USB debug tunnel): adb reverse tcp:8080 tcp:81 → http://127.0.0.1:8080/bcd-app/api/v1
  // Local (same Wi-Fi):       http://<LAN-IP>:81/bcd-app/api/v1
  // Release:                  HTTPS endpoint required (cleartext disabled)
};
