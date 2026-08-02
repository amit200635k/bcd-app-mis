import React, {useMemo, useState} from 'react';
import {Pressable, StyleSheet, Text} from 'react-native';
import {SurveyField} from '../../types/api';
import FieldShell from './FieldShell';
import PickerModal from './PickerModal';

interface Props {
  field: SurveyField;
  value: string;
  onChange: (value: string) => void;
  editable?: boolean;
  error?: string | null;
}

export default function DropdownInput({field, value, onChange, editable = true, error}: Props) {
  const [open, setOpen] = useState(false);

  const options = useMemo(
    () => (field.options ?? []).map((o) => ({label: o.option_label, value: o.option_value})),
    [field.options],
  );

  const selectedLabel = options.find((o) => o.value === value)?.label;

  return (
    <FieldShell label={field.label} help={field.help_text} error={error}>
      <Pressable
        style={({pressed}) => [styles.select, pressed && styles.selectPressed]}
        onPress={() => (editable ? setOpen(true) : undefined)}
        disabled={!editable}
        testID={`field-${field.field_key}`}>
        <Text style={[styles.selectText, !selectedLabel && styles.placeholder]} numberOfLines={1}>
          {selectedLabel ?? field.placeholder ?? 'Select…'}
        </Text>
        <Text style={styles.chevron}>▾</Text>
      </Pressable>
      <PickerModal
        visible={open}
        title={field.label}
        options={options}
        selected={value}
        onSelect={onChange}
        onClose={() => setOpen(false)}
        disabled={!editable}
      />
    </FieldShell>
  );
}

const styles = StyleSheet.create({
  select: {
    backgroundColor: '#fff',
    borderRadius: 10,
    borderWidth: 1,
    borderColor: '#d7deeb',
    paddingHorizontal: 14,
    paddingVertical: 13,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  selectPressed: {borderColor: '#1f3a68'},
  selectText: {fontSize: 15, color: '#1a2233', flex: 1, marginRight: 10},
  placeholder: {color: '#a6b0bf'},
  chevron: {color: '#6b7a90', fontSize: 14},
});
