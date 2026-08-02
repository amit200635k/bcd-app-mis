import React, {useMemo, useState} from 'react';
import {Pressable, StyleSheet, Text} from 'react-native';
import {LocationHierarchy, SurveyField} from '../../types/api';
import FieldShell from './FieldShell';
import PickerModal from './PickerModal';

export interface CascadeValue {
  district_id?: number | null;
  district_name?: string | null;
  block_id?: number | null;
  block_name?: string | null;
  panchayat_id?: number | null;
  panchayat_name?: string | null;
  village_id?: number | null;
  village_name?: string | null;
}

interface Props {
  field: SurveyField;
  locations: LocationHierarchy | null;
  value: CascadeValue | null;
  onChange: (value: CascadeValue | null) => void;
  editable?: boolean;
  error?: string | null;
}

const LEVELS = [
  {key: 'district', parent: 'district_id', label: 'District'},
  {key: 'block', parent: 'block_id', label: 'Block'},
  {key: 'panchayat', parent: 'panchayat_id', label: 'Panchayat'},
  {key: 'village', parent: 'village_id', label: 'Village'},
] as const;

export default function CascadeInput({field, locations, value, onChange, editable = true, error}: Props) {
  const [openLevel, setOpenLevel] = useState<(typeof LEVELS)[number]['key'] | null>(null);

  const selectedIds = useMemo(
    () => ({
      district: value?.district_id ?? null,
      block: value?.block_id ?? null,
      panchayat: value?.panchayat_id ?? null,
      village: value?.village_id ?? null,
    }),
    [value],
  );

  const optionsFor = (level: (typeof LEVELS)[number]['key']) => {
    const loc = locations;
    if (!loc) {
      return [];
    }
    if (level === 'district') {
      return loc.districts.map((d) => ({label: d.name, value: String(d.id)}));
    }
    if (level === 'block') {
      const parent = selectedIds.district;
      return loc.blocks.filter((b) => parent == null || b.district_id === parent).map((b) => ({label: b.name, value: String(b.id)}));
    }
    if (level === 'panchayat') {
      const parent = selectedIds.block;
      return loc.panchayats.filter((p) => parent == null || p.block_id === parent).map((p) => ({label: p.name, value: String(p.id)}));
    }
    const parent = selectedIds.panchayat;
    return loc.villages.filter((v) => parent == null || v.panchayat_id === parent).map((v) => ({label: v.name, value: String(v.id)}));
  };

  const select = (level: (typeof LEVELS)[number]['key'], id: number | null, name: string | null) => {
    const next: CascadeValue = {...(value ?? {})};
    const clearBelow = level === 'district';
    if (level === 'district') {
      next.district_id = id;
      next.district_name = name;
      if (clearBelow) {
        next.block_id = next.panchayat_id = next.village_id = null;
        next.block_name = next.panchayat_name = next.village_name = null;
      }
    } else if (level === 'block') {
      next.block_id = id;
      next.block_name = name;
      next.panchayat_id = next.village_id = null;
      next.panchayat_name = next.village_name = null;
    } else if (level === 'panchayat') {
      next.panchayat_id = id;
      next.panchayat_name = name;
      next.village_id = null;
      next.village_name = null;
    } else {
      next.village_id = id;
      next.village_name = name;
    }
    onChange(next);
  };

  const summary = [value?.district_name, value?.block_name, value?.panchayat_name, value?.village_name]
    .filter(Boolean)
    .join(' / ');

  return (
    <FieldShell label={field.label} help={field.help_text} error={error}>
      {LEVELS.map((level) => {
        const currentId = selectedIds[level.key];
        const currentName =
          value?.[`${level.key}_name` as keyof CascadeValue] ?? optionsFor(level.key).find((o) => o.value === String(currentId))?.label;
        return (
          <Pressable
            key={level.key}
            style={({pressed}) => [styles.select, pressed && styles.selectPressed]}
            onPress={() => (editable ? setOpenLevel(level.key) : undefined)}
            disabled={!editable}
            testID={`field-${field.field_key}-${level.key}`}>
            <Text style={[styles.levelLabel, currentId == null && styles.placeholder]}>
              {currentName ? String(currentName) : `Select ${level.label}…`}
            </Text>
            <Text style={styles.chevron}>▾</Text>
          </Pressable>
        );
      })}
      {summary ? <Text style={styles.summary}>{summary}</Text> : null}
      {openLevel ? (
        <PickerModal
          visible
          title={field.label}
          options={optionsFor(openLevel)}
          selected={selectedIds[openLevel] != null ? String(selectedIds[openLevel]) : undefined}
          onSelect={(v) => {
            const item = optionsFor(openLevel).find((o) => o.value === v);
            select(openLevel, Number(v), item?.label ?? null);
          }}
          onClose={() => setOpenLevel(null)}
          disabled={!editable}
        />
      ) : null}
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
    paddingVertical: 12,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 8,
  },
  selectPressed: {borderColor: '#1f3a68'},
  levelLabel: {fontSize: 15, color: '#1a2233', flex: 1, marginRight: 10},
  placeholder: {color: '#a6b0bf'},
  chevron: {color: '#6b7a90', fontSize: 14},
  summary: {fontSize: 12, color: '#6b7a90', marginTop: 4},
});
