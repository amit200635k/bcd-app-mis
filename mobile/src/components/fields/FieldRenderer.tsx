import React from 'react';
import {StyleSheet, Text, View} from 'react-native';
import {FieldType, SurveyField} from '../../types/api';
import {useData} from '../../context/DataContext';
import TextFieldInput from './TextFieldInput';
import OptionInput from './OptionInput';
import DropdownInput from './DropdownInput';
import MasterInput from './MasterInput';
import CascadeInput, {CascadeValue} from './CascadeInput';
import GpsInput from './GpsInput';

export interface FieldValues {
  [key: string]: unknown;
}

interface Props {
  field: SurveyField;
  values: FieldValues;
  onChange: (key: string, value: unknown) => void;
  editable?: boolean;
  errors?: Record<string, string>;
}

const NO_INPUT: FieldType[] = ['heading'];

export default function FieldRenderer({field, values, onChange, editable = true, errors}: Props) {
  const {masters, locations} = useData();

  if (NO_INPUT.includes(field.type)) {
    return (
      <View style={styles.heading}>
        <Text style={styles.headingText}>{field.label}</Text>
      </View>
    );
  }

  const error = errors?.[field.field_key] ?? null;
  const value = values[field.field_key];
  const onChangeField = (v: unknown) => onChange(field.field_key, v);

  switch (field.type) {
    case 'textbox':
    case 'textarea':
    case 'number':
    case 'decimal':
    case 'date':
    case 'time':
    case 'auto_number':
      return (
        <TextFieldInput
          field={field}
          value={typeof value === 'string' ? value : ''}
          onChange={onChangeField}
          editable={editable}
          error={error}
        />
      );

    case 'dropdown':
      return (
        <DropdownInput
          field={field}
          value={typeof value === 'string' ? value : ''}
          onChange={onChangeField}
          editable={editable}
          error={error}
        />
      );

    case 'radio':
    case 'checkbox':
    case 'multi_select':
      return (
        <OptionInput
          field={field}
          value={typeof value === 'string' ? value : Array.isArray(value) ? value.join(',') : ''}
          onChange={onChangeField}
          editable={editable}
          error={error}
        />
      );

    case 'master':
      return (
        <MasterInput
          field={field}
          masters={masters}
          value={value as {master_id?: number | null; name?: string | null} | null}
          onChange={onChangeField}
          editable={editable}
          error={error}
        />
      );

    case 'location_cascade':
      return (
        <CascadeInput
          field={field}
          locations={locations}
          value={value as CascadeValue | null}
          onChange={onChangeField}
          editable={editable}
          error={error}
        />
      );

    case 'gps':
      return (
        <GpsInput
          field={field}
          value={value as {latitude: number; longitude: number; accuracy: number | null} | null}
          onChange={onChangeField}
          editable={editable}
          error={error}
        />
      );

    default:
      // photo/signature/file/barcode/qr are captured in a later milestone;
      // render a read-only note so the offline form remains fillable.
      return (
        <View style={styles.comingSoon}>
          <Text style={styles.comingSoonTitle}>{field.label}</Text>
          <Text style={styles.comingSoonText}>
            {field.type === 'photo' || field.type === 'signature' || field.type === 'file'
              ? 'Photo/file capture is not available offline yet.'
              : `${field.type} input is not available yet.`}
          </Text>
        </View>
      );
  }
}

const styles = StyleSheet.create({
  heading: {paddingVertical: 10, borderBottomWidth: 1, borderBottomColor: '#e3e8f2', marginBottom: 8},
  headingText: {fontSize: 17, fontWeight: '800', color: '#1f3a68'},
  comingSoon: {
    backgroundColor: '#f4f6fb',
    borderRadius: 10,
    padding: 14,
    marginBottom: 18,
    borderWidth: 1,
    borderColor: '#e3e8f2',
  },
  comingSoonTitle: {fontSize: 15, fontWeight: '600', color: '#1a2233'},
  comingSoonText: {fontSize: 13, color: '#8a94a6', marginTop: 4},
});
