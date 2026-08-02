/**
 * @format
 * Jest setup: mock the native modules our app uses so App can render in the
 * test environment without a device.
 */
/* eslint-env jest */

jest.mock('react-native-device-info', () => ({
  getDeviceName: jest.fn().mockResolvedValue('Jest Device'),
  getSystemName: jest.fn(() => 'android'),
  getSystemVersion: jest.fn(() => '14'),
  getVersion: jest.fn(() => '1.0.0'),
  getBuildNumber: jest.fn(() => '1'),
}));

jest.mock('@react-native-async-storage/async-storage', () =>
  require('@react-native-async-storage/async-storage/jest/async-storage-mock'),
);

const keychainStore: Record<string, {username: string; password: string}> = {};
jest.mock('react-native-keychain', () => ({
  setGenericPassword: jest.fn(async (username: string, password: string, options?: {service?: string}) => {
    const service = options?.service || 'default';
    keychainStore[service] = {username, password};
    return {service, storage: 'test'};
  }),
  getGenericPassword: jest.fn(async (options?: {service?: string}) => {
    const entry = keychainStore[options?.service || 'default'];
    return entry ? entry : false;
  }),
  resetGenericPassword: jest.fn(async (options?: {service?: string}) => {
    delete keychainStore[options?.service || 'default'];
    return true;
  }),
  ACCESSIBLE: {WHEN_UNLOCKED_THIS_DEVICE_ONLY: 'whenUnlockedThisDeviceOnly'},
}));

jest.mock('@react-native-community/geolocation', () => ({
  getCurrentPosition: jest.fn((success: (position: unknown) => void) =>
    success({coords: {latitude: 0, longitude: 0, accuracy: 5}, timestamp: Date.now()}),
  ),
  watchPosition: jest.fn(),
  clearWatch: jest.fn(),
}));
