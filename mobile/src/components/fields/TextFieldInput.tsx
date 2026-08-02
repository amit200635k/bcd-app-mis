import React, {useMemo, useState} from 'react';
import {KeyboardTypeOptions, StyleSheet, Text, TextInput, View} from 'react-native';
import {SurveyField} from '../../types/api';
import FieldShell from './FieldShell';

interface Props {
  field: SurveyField;
  value: string;
  onChange: (value: string) => void;
  editable?: boolean;
  error?: string | null;
}

function keyboardFor(field: SurveyField): KeyboardTypeOptions {
  if (field.type === 'number' || field.type === 'decimal') {
    return 'decimal-pad';
  }
  if (field.type === 'date' || field.type === 'time') {
    return 'numbers-and-punctuation';
  }
  return 'default';
}

export default function TextFieldInput({field, value, onChange, editable = true, error}: Props) {
  const [focused, setFocused] = useState(false);

  const placeholder = useMemo(
    () =>
      field.placeholder ??
      (field.type === 'number'
        ? 'Enter a number'
        : field.type === 'date'
          ? 'YYYY-MM-DD'
          : field.type === 'time'
            ? 'HH:MM'
            : 'Enter value'),
    [field],
  );

  const multiline = field.type === 'textarea';
  const showAsDate = field.type === 'date';

  return (
    <FieldShell label={field.label} help={field.help_text} error={error}>
      {showAsDate ? (
        <TextInput
          style={[styles.input, focused && styles.focused]}
          value={value}
          onChangeText={onChange}
          placeholder={placeholder}
          placeholderTextColor="#a6b0bf"
          keyboardType="numbers-and-punctuation"
          editable={editable}
          onFocus={() => setFocused(true)}
          onBlur={() => setFocused(false)}
          maxLength={10}
          autoCorrect={false}
          testID={`field-${field.field_key}`}
        />
      ) : (
        <TextInput
          style={[styles.input, multiline && styles.multiline, focused && styles.focused]}
          value={value}
          onChangeText={onChange}
          placeholder={placeholder}
          placeholderTextColor="#a6b0bf"
          keyboardType={keyboardFor(field)}
          editable={editable}
          multiline={multiline}
          onFocus={() => setFocused(true)}
          onBlur={() => setFocused(false)}
          autoCorrect={false}
          testID={`field-${field.field_key}`}
        />
      )}
      <View style={styles.metaRow}>
        <Text style={styles.metaHint}>
          {field.type === 'date' ? 'Format: YYYY-MM-DD' : field.type === 'time' ? 'Format: HH:MM (24h)' : ''}
        </Text>
        {field.is_mandatory === 1 ? <Text style={styles.required}>Required</Text> : null}
      </View>
    </FieldShell>
  );
}

const styles = StyleSheet.create({
  input: {
    backgroundColor: '#fff',
    borderRadius: 10,
    borderWidth: 1,
    borderColor: '#d7deeb',
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 16,
    color: '#1a2233',
  },
  multiline: {minHeight: 96, textAlignVertical: 'top'},
  focused: {borderColor: '#1f3a68', borderWidth: 2},
  metaRow: {flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 4},
  metaHint: {fontSize: 11, color: '#9aa4b2'},
  required: {fontSize: 11, color: '#c62828', fontWeight: '600'},
});
