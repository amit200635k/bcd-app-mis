import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'com.jsac_bcd_survey.app',
  appName: 'BCD Survey',
  webDir: 'dist',
  server: {
    cleartext: true,
  },
  android: {
    allowMixedContent: true,
  },
  plugins: {
    CapacitorHttp: {
      enabled: true,
    },
    SplashScreen: {
      launchAutoHide: true,
      backgroundColor: '#0d6efd',
      showSpinner: false,
    },
    Camera: {
      saveToGallery: false,
    },
  },
};

export default config;
