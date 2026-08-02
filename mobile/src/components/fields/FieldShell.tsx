import React from 'react';
import {StyleSheet, Text, View} from 'react-native';

interface Props {
  label: string;
  help?: string | null;
  error?: string | null;
  children: React.ReactNode;
}

export default function FieldShell({label, help, error, children}: Props) {
  return (
    <View style={styles.wrap}>
      <Text style={styles.label}>{label}</Text>
      {help ? <Text style={styles.help}>{help}</Text> : null}
      {children}
      {error ? <Text style={styles.error}>{error}</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {marginBottom: 18},
  label: {fontSize: 15, fontWeight: '600', color: '#1a2233', marginBottom: 6},
  help: {fontSize: 12, color: '#8a94a6', marginBottom: 6},
  error: {color: '#c62828', fontSize: 13, marginTop: 6},
});
