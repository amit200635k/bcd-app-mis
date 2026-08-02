import React from 'react';
import {Pressable, StyleSheet, Text, View} from 'react-native';
import {SurveyField} from '../../types/api';
import FieldShell from './FieldShell';

interface Props {
  field: SurveyField;
  value: string;
  onChange: (value: string) => void;
  editable?: boolean;
  error?: string | null;
}

export default function OptionInput({field, value, onChange, editable = true, error}: Props) {
  const isRadio = field.type === 'radio';
  const isCheckbox = field.type === 'checkbox';

  if (isCheckbox) {
    const selected = value === '1' || value === 'true';
    return (
      <FieldShell label={field.label} help={field.help_text} error={error}>
        <Pressable
          style={[styles.checkboxRow, selected && styles.checkboxActive]}
          onPress={() => (editable ? onChange(selected ? '0' : '1') : undefined)}
          disabled={!editable}
          testID={`field-${field.field_key}`}>
          <View style={[styles.checkbox, selected && styles.checkboxChecked]}>
            {selected ? <Text style={styles.checkMark}>✓</Text> : null}
          </View>
          <Text style={styles.checkboxLabel}>{field.placeholder || 'Yes'}</Text>
        </Pressable>
      </FieldShell>
    );
  }

  const multiple = field.type === 'multi_select';
  const selectedSet = multiple
    ? new Set((value ? String(value).split(',') : []).map((s) => s.trim()).filter(Boolean))
    : new Set(value ? [value] : []);

  const toggle = (optionValue: string) => {
    if (!editable) {
      return;
    }
    if (multiple) {
      const next = new Set(selectedSet);
      if (next.has(optionValue)) {
        next.delete(optionValue);
      } else {
        next.add(optionValue);
      }
      onChange(Array.from(next).join(','));
    } else {
      onChange(selectedSet.has(optionValue) ? '' : optionValue);
    }
  };

  return (
    <FieldShell label={field.label} help={field.help_text} error={error}>
      <View style={styles.options}>
        {(field.options ?? []).map((opt) => {
          const selected = selectedSet.has(opt.option_value);
          return (
            <Pressable
              key={opt.option_value}
              style={[styles.option, selected && styles.optionActive]}
              onPress={() => toggle(opt.option_value)}
              disabled={!editable}
              testID={`field-${field.field_key}-${opt.option_value}`}>
              <View style={[isRadio ? styles.radio : styles.check, selected && styles.checked]}>
                {selected ? <Text style={styles.dot}>{isRadio ? '●' : '✓'}</Text> : null}
              </View>
              <Text style={[styles.optionLabel, selected && styles.optionLabelActive]}>{opt.option_label}</Text>
            </Pressable>
          );
        })}
      </View>
    </FieldShell>
  );
}

const styles = StyleSheet.create({
  options: {gap: 8},
  option: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#fff',
    borderRadius: 10,
    borderWidth: 1,
    borderColor: '#d7deeb',
    paddingHorizontal: 14,
    paddingVertical: 12,
  },
  optionActive: {borderColor: '#1f3a68', backgroundColor: '#eef3fb'},
  radio: {
    width: 20,
    height: 20,
    borderRadius: 10,
    borderWidth: 2,
    borderColor: '#a6b0bf',
    marginRight: 12,
    alignItems: 'center',
    justifyContent: 'center',
  },
  check: {
    width: 20,
    height: 20,
    borderRadius: 5,
    borderWidth: 2,
    borderColor: '#a6b0bf',
    marginRight: 12,
    alignItems: 'center',
    justifyContent: 'center',
  },
  checked: {borderColor: '#1f3a68', backgroundColor: '#1f3a68'},
  dot: {color: '#fff', fontSize: 12, lineHeight: 14},
  optionLabel: {fontSize: 15, color: '#1a2233'},
  optionLabelActive: {color: '#1f3a68', fontWeight: '600'},
  checkboxRow: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#fff',
    borderRadius: 10,
    borderWidth: 1,
    borderColor: '#d7deeb',
    padding: 14,
  },
  checkboxActive: {borderColor: '#1f3a68', backgroundColor: '#eef3fb'},
  checkbox: {
    width: 22,
    height: 22,
    borderRadius: 6,
    borderWidth: 2,
    borderColor: '#a6b0bf',
    marginRight: 12,
    alignItems: 'center',
    justifyContent: 'center',
  },
  checkboxChecked: {borderColor: '#1f3a68', backgroundColor: '#1f3a68'},
  checkMark: {color: '#fff', fontSize: 13, lineHeight: 15},
  checkboxLabel: {fontSize: 15, color: '#1a2233'},
});
