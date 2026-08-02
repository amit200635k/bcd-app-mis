import {Platform} from 'react-native';

const DEV_API_HOST = __DEV__ ? '10.0.2.2:81' : 'survey.example.gov.in';

export const API_BASE_URL = `http://${DEV_API_HOST}/bcd-app/api/v1`;

export const APP_INFO = {
  name: 'BCD Survey',
  platform: Platform.OS,
  version: '1.0.0',
};

export const SYNC = {
  /** Max attempts before a pending record upload is reported as failed. */
  maxUploadAttempts: 3,
};
