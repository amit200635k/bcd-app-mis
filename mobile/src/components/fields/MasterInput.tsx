import React, {useMemo, useState} from 'react';
import {Pressable, StyleSheet, Text} from 'react-native';
import {MasterData, SurveyField} from '../../types/api';
import FieldShell from './FieldShell';
import PickerModal from './PickerModal';

interface Props {
  field: SurveyField;
  masters: MasterData | null;
  value: {master_id?: number | null; name?: string | null} | null;
  onChange: (value: {master_id: number | null; name: string | null} | null) => void;
  editable?: boolean;
  error?: string | null;
}

export default function MasterInput({field, masters, value, onChange, editable = true, error}: Props) {
  const [open, setOpen] = useState(false);

  const groupId = field.master_group_id;
  const items = useMemo(() => {
    const active = masters?.items.filter((i) => i.group_id === groupId) ?? [];
    return active.map((i) => ({label: i.name, value: String(i.id)}));
  }, [masters, groupId]);

  const selectedLabel = items.find((i) => String(value?.master_id) === i.value)?.label ?? value?.name ?? null;

  return (
    <FieldShell label={field.label} help={field.help_text} error={error}>
      <Pressable
        style={({pressed}) => [styles.select, pressed && styles.selectPressed]}
        onPress={() => (editable ? setOpen(true) : undefined)}
        disabled={!editable}
        testID={`field-${field.field_key}`}>
        <Text style={[styles.text, !selectedLabel && styles.placeholder]} numberOfLines={1}>
          {selectedLabel ?? field.placeholder ?? (items.length ? 'Select…' : 'No items downloaded')}
        </Text>
        <Text style={styles.chevron}>▾</Text>
      </Pressable>
      <PickerModal
        visible={open}
        title={field.label}
        options={items}
        selected={value?.master_id != null ? String(value.master_id) : undefined}
        onSelect={(v) => {
          const item = items.find((i) => i.value === v);
          onChange({master_id: Number(v), name: item?.label ?? null});
        }}
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
  text: {fontSize: 15, color: '#1a2233', flex: 1, marginRight: 10},
  placeholder: {color: '#a6b0bf'},
  chevron: {color: '#6b7a90', fontSize: 14},
});
