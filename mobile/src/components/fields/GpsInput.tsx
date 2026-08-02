import React, {useState} from 'react';
import {Alert, PermissionsAndroid, Platform, Pressable, StyleSheet, Text} from 'react-native';
import Geolocation from '@react-native-community/geolocation';
import {SurveyField} from '../../types/api';
import FieldShell from './FieldShell';

interface Props {
  field: SurveyField;
  value: {latitude: number; longitude: number; accuracy: number | null} | null;
  onChange: (value: {latitude: number; longitude: number; accuracy: number | null}) => void;
  editable?: boolean;
  error?: string | null;
}

async function ensurePermission(): Promise<boolean> {
  if (Platform.OS !== 'android') {
    return true;
  }
  try {
    const granted = await PermissionsAndroid.requestMultiple([
      PermissionsAndroid.PERMISSIONS.ACCESS_FINE_LOCATION,
      PermissionsAndroid.PERMISSIONS.ACCESS_COARSE_LOCATION,
    ]);
    return (
      granted[PermissionsAndroid.PERMISSIONS.ACCESS_FINE_LOCATION] === PermissionsAndroid.RESULTS.GRANTED ||
      granted[PermissionsAndroid.PERMISSIONS.ACCESS_COARSE_LOCATION] === PermissionsAndroid.RESULTS.GRANTED
    );
  } catch {
    return false;
  }
}

export default function GpsInput({field, value, onChange, editable = true, error}: Props) {
  const [capturing, setCapturing] = useState(false);

  const capture = async () => {
    if (!editable) {
      return;
    }
    if (!(await ensurePermission())) {
      Alert.alert('Permission needed', 'Location access is required to capture GPS.');
      return;
    }
    setCapturing(true);
    Geolocation.getCurrentPosition(
      pos => {
        setCapturing(false);
        onChange({
          latitude: pos.coords.latitude,
          longitude: pos.coords.longitude,
          accuracy: pos.coords.accuracy ?? null,
        });
      },
      err => {
        setCapturing(false);
        Alert.alert('GPS error', err.message ?? 'Could not get your location.');
      },
      {enableHighAccuracy: true, timeout: 20000, maximumAge: 10000},
    );
  };

  const text = value ? `${value.latitude.toFixed(6)}, ${value.longitude.toFixed(6)}` : null;

  return (
    <FieldShell label={field.label} help={field.help_text} error={error}>
      <Pressable
        style={({pressed}) => [styles.capture, pressed && styles.capturePressed]}
        onPress={capture}
        disabled={!editable || capturing}
        testID={`field-${field.field_key}`}>
        <Text style={styles.captureText}>{capturing ? 'Capturing…' : value ? text : 'Tap to capture GPS'}</Text>
      </Pressable>
      {value ? <Text style={styles.meta}>Accuracy ±{value.accuracy ?? 0}m</Text> : null}
    </FieldShell>
  );
}

const styles = StyleSheet.create({
  capture: {
    backgroundColor: '#eef3fb',
    borderRadius: 10,
    borderWidth: 1,
    borderColor: '#1f3a68',
    paddingVertical: 14,
    alignItems: 'center',
  },
  capturePressed: {opacity: 0.8},
  captureText: {color: '#1f3a68', fontSize: 15, fontWeight: '600'},
  meta: {fontSize: 12, color: '#6b7a90', marginTop: 4},
});
