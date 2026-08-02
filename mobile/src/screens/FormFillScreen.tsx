import React, {useCallback, useMemo, useState} from 'react';
import {Alert, FlatList, Pressable, StyleSheet, Text, View} from 'react-native';
import {SafeAreaView} from 'react-native-safe-area-context';
import {FormDefinition, SurveyField, SurveySection} from '../types/api';
import FieldRenderer, {FieldValues} from '../components/fields/FieldRenderer';
import GpsInput from '../components/fields/GpsInput';
import {evaluateConditions, missingRequired} from '../utils/conditions';
import {normalizeAnswer, validateField} from '../utils/validators';
import {useData} from '../context/DataContext';
import {OfflineCache} from '../api/cache';

interface Props {
  form: FormDefinition;
  onClose: () => void;
  onSubmitted: () => void;
}

export default function FormFillScreen({form, onClose, onSubmitted}: Props) {
  const {queueRecord, pending} = useData();
  const [values, setValues] = useState<FieldValues>({});
  const [gps, setGps] = useState<{latitude: number; longitude: number; accuracy: number | null} | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);

  const sections: SurveySection[] = useMemo(() => form.sections ?? [], [form]);

  const evaluated = useMemo(() => evaluateConditions(sections, values), [sections, values]);

  const onChangeField = useCallback(
    (key: string, value: unknown) => {
      setValues(prev => ({...prev, [key]: value}));
      setErrors(prev => {
        if (!prev[key]) {
          return prev;
        }
        const next = {...prev};
        delete next[key];
        return next;
      });
    },
    [],
  );

  const onGpsCapture = useCallback((v: {latitude: number; longitude: number; accuracy: number | null}) => {
    setGps(v);
  }, []);

  const validateAll = useCallback(() => {
    const next: Record<string, string> = {};
    const requiredMissing = missingRequired(sections, values, evaluated);
    for (const [key, msg] of Object.entries(requiredMissing)) {
      next[key] = msg;
    }
    for (const section of sections) {
      for (const field of section.fields) {
        if (!evaluated.visible[field.field_key]) {
          continue;
        }
        const err = validateField(field, values[field.field_key]);
        if (err) {
          next[field.field_key] = err;
        }
      }
    }
    setErrors(next);
    return Object.keys(next).length === 0;
  }, [sections, values, evaluated]);

  const submit = useCallback(async () => {
    if (!validateAll()) {
      Alert.alert('Missing fields', 'Please review the highlighted fields before submitting.');
      return;
    }
    setSubmitting(true);
    try {
      const deviceId = await OfflineCache.getOrCreateDeviceId();
      const answers: FieldValues = {};
      for (const section of sections) {
        for (const field of section.fields) {
          if (!evaluated.visible[field.field_key]) {
            continue;
          }
          const raw = values[field.field_key];
          if (raw === undefined || raw === null || raw === '' || (Array.isArray(raw) && raw.length === 0)) {
            continue;
          }
          answers[field.field_key] = normalizeAnswer(field, raw);
        }
      }

      await queueRecord({
        localUuid: `bcd-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`,
        formId: form.form.id,
        formVersionId: form.version,
        status: 'submitted',
        answers,
        gps,
        deviceId,
      });
      Alert.alert('Record saved', 'Your response has been queued for upload.', [
        {text: 'OK', onPress: onSubmitted},
      ]);
    } catch (err) {
      Alert.alert('Could not save', err instanceof Error ? err.message : 'Something went wrong.');
    } finally {
      setSubmitting(false);
    }
  }, [validateAll, sections, values, evaluated, gps, form, queueRecord, onSubmitted]);

  const renderField = useCallback(
    ({item}: {item: SurveyField}) => {
      if (evaluated.visible[item.field_key] === false) {
        return null;
      }
      if (item.type === 'gps') {
        return <GpsFieldBridge value={gps} onChange={onGpsCapture} field={item} errors={errors} />;
      }
      return (
        <FieldRenderer
          field={item}
          values={values}
          onChange={onChangeField}
          errors={errors}
        />
      );
    },
    [evaluated, values, onChangeField, errors, gps, onGpsCapture],
  );

  const renderSection = useCallback(
    ({item}: {item: SurveySection}) => (
      <View style={styles.section}>
        <Text style={styles.sectionTitle}>{item.title}</Text>
        {item.description ? <Text style={styles.sectionDesc}>{item.description}</Text> : null}
        <View style={styles.fields}>
          {item.fields.map(f => (
            <React.Fragment key={f.id}>
              {renderField({item: f} as never)}
            </React.Fragment>
          ))}
        </View>
      </View>
    ),
    [renderField],
  );

  const totalVisible = useMemo(
    () => sections.reduce((acc, s) => acc + s.fields.filter(f => evaluated.visible[f.field_key] !== false).length, 0),
    [sections, evaluated],
  );

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
      <View style={styles.header}>
        <Pressable onPress={onClose} hitSlop={12} testID="close-form">
          <Text style={styles.headerClose}>✕</Text>
        </Pressable>
        <View style={styles.headerTextWrap}>
          <Text style={styles.headerTitle} numberOfLines={1}>{form.form.title}</Text>
          <Text style={styles.headerMeta}>
            v{form.version} · {totalVisible} field{totalVisible === 1 ? '' : 's'}
          </Text>
        </View>
        <Pressable style={[styles.submitBtn, submitting && styles.submitBtnDisabled]} onPress={submit} disabled={submitting} testID="submit-form">
          <Text style={styles.submitBtnText}>{submitting ? 'Saving…' : 'Submit'}</Text>
        </Pressable>
      </View>

      {pending.length > 0 ? (
        <Text style={styles.pendingNote}>Saved offline — {pending.length} record pending upload.</Text>
      ) : null}

      <FlatList
        data={sections}
        keyExtractor={s => String(s.id)}
        renderItem={renderSection}
        contentContainerStyle={styles.list}
      />
    </SafeAreaView>
  );
}

// Bridges the GPS field so its value lives at form level (submitted as gps[]).
function GpsFieldBridge(props: {
  field: SurveyField;
  value: {latitude: number; longitude: number; accuracy: number | null} | null;
  onChange: (v: {latitude: number; longitude: number; accuracy: number | null}) => void;
  errors: Record<string, string>;
}) {
  return <GpsFieldInner {...props} />;
}

function GpsFieldInner(props: {
  field: SurveyField;
  value: {latitude: number; longitude: number; accuracy: number | null} | null;
  onChange: (v: {latitude: number; longitude: number; accuracy: number | null}) => void;
  errors: Record<string, string>;
}) {
  return (
    <GpsInput
      field={props.field}
      value={props.value}
      onChange={props.onChange}
      editable
      error={props.errors[props.field.field_key] ?? null}
    />
  );
}

const styles = StyleSheet.create({
  safe: {flex: 1, backgroundColor: '#f4f6fb'},
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 16,
    paddingVertical: 12,
    backgroundColor: '#fff',
    borderBottomWidth: 1,
    borderBottomColor: '#e3e8f2',
  },
  headerClose: {fontSize: 20, color: '#1f3a68', paddingRight: 12},
  headerTextWrap: {flex: 1},
  headerTitle: {fontSize: 16, fontWeight: '800', color: '#1f3a68'},
  headerMeta: {fontSize: 12, color: '#8a94a6', marginTop: 1},
  submitBtn: {backgroundColor: '#1f3a68', borderRadius: 8, paddingHorizontal: 16, paddingVertical: 10},
  submitBtnDisabled: {opacity: 0.6},
  submitBtnText: {color: '#fff', fontWeight: '700', fontSize: 14},
  pendingNote: {backgroundColor: '#fff3d6', color: '#7a5b00', paddingHorizontal: 16, paddingVertical: 8, fontSize: 13},
  list: {padding: 16, paddingBottom: 40},
  section: {marginBottom: 20},
  sectionTitle: {fontSize: 18, fontWeight: '800', color: '#1f3a68', marginBottom: 2},
  sectionDesc: {fontSize: 13, color: '#6b7a90', marginBottom: 12},
  fields: {marginTop: 6},
});
