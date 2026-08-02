import React from 'react';
import {ActivityIndicator, FlatList, Pressable, StyleSheet, Text, View} from 'react-native';
import {SafeAreaView} from 'react-native-safe-area-context';
import {useData} from '../context/DataContext';

interface Props {
  onClose: () => void;
}

export default function SyncScreen({onClose}: Props) {
  const {pending, uploading, uploadNow, syncStatus, refresh, error, clearError} = useData();

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
      <View style={styles.header}>
        <Pressable onPress={onClose} hitSlop={12}>
          <Text style={styles.headerClose}>✕</Text>
        </Pressable>
        <Text style={styles.headerTitle}>Sync Status</Text>
        <Pressable onPress={refresh} hitSlop={12}>
          <Text style={styles.headerRefresh}>Refresh</Text>
        </Pressable>
      </View>

      <View style={styles.statusCard}>
        <Text style={styles.statusLabel}>Server sync queue</Text>
        <Text style={styles.statusValue}>{syncStatus?.pending ?? '—'} pending</Text>
        {(syncStatus?.by_status ?? []).map(item => (
          <Text key={item.status} style={styles.statusSub}>
            {item.status}: {item.c}
          </Text>
        ))}
      </View>

      {error ? (
        <View style={styles.errorBox}>
          <Text style={styles.errorText}>{error}</Text>
          <Pressable onPress={clearError}>
            <Text style={styles.errorDismiss}>Dismiss</Text>
          </Pressable>
        </View>
      ) : null}

      <View style={styles.sectionHeader}>
        <Text style={styles.sectionTitle}>Offline records on this device ({pending.length})</Text>
        <Pressable style={[styles.uploadBtn, uploading && styles.uploadBtnDisabled]} onPress={uploadNow} disabled={uploading}>
          {uploading ? <ActivityIndicator color="#fff" size="small" /> : <Text style={styles.uploadBtnText}>Upload Now</Text>}
        </Pressable>
      </View>

      <FlatList
        data={pending}
        keyExtractor={item => item.localUuid}
        contentContainerStyle={styles.list}
        ListEmptyComponent={
          <View style={styles.empty}>
            <Text style={styles.emptyText}>No offline records waiting. Everything is uploaded.</Text>
          </View>
        }
        renderItem={({item}) => (
          <View style={styles.record}>
            <View style={styles.recordRow}>
              <Text style={styles.recordLabel} numberOfLines={1}>{item.localUuid}</Text>
              <Text style={styles.recordBadge}>{item.status}</Text>
            </View>
            <Text style={styles.recordMeta}>
              form #{item.formId} · v{item.formVersionId} · attempts {item.attempts}
            </Text>
          </View>
        )}
      />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: {flex: 1, backgroundColor: '#f4f6fb'},
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 16,
    paddingVertical: 12,
    backgroundColor: '#fff',
    borderBottomWidth: 1,
    borderBottomColor: '#e3e8f2',
  },
  headerClose: {fontSize: 20, color: '#1f3a68'},
  headerTitle: {fontSize: 16, fontWeight: '800', color: '#1f3a68'},
  headerRefresh: {fontSize: 14, color: '#1f3a68', fontWeight: '600'},
  statusCard: {
    backgroundColor: '#fff',
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#e3e8f2',
    margin: 16,
    padding: 18,
  },
  statusLabel: {fontSize: 13, color: '#6b7a90'},
  statusValue: {fontSize: 30, fontWeight: '800', color: '#1f3a68', marginTop: 2},
  statusSub: {fontSize: 13, color: '#4a5568', marginTop: 2, textTransform: 'capitalize'},
  errorBox: {
    marginHorizontal: 16,
    marginBottom: 4,
    backgroundColor: '#fdecec',
    borderColor: '#e57373',
    borderWidth: 1,
    borderRadius: 10,
    padding: 12,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  errorText: {color: '#b3261e', flex: 1, fontSize: 13},
  errorDismiss: {color: '#1f3a68', fontWeight: '700', marginLeft: 10},
  sectionHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 16,
    marginBottom: 8,
  },
  sectionTitle: {fontSize: 15, fontWeight: '700', color: '#1a2233'},
  uploadBtn: {backgroundColor: '#1f3a68', borderRadius: 8, paddingHorizontal: 14, paddingVertical: 9},
  uploadBtnDisabled: {opacity: 0.6},
  uploadBtnText: {color: '#fff', fontWeight: '700', fontSize: 13},
  list: {paddingHorizontal: 16},
  record: {
    backgroundColor: '#fff',
    borderRadius: 10,
    borderWidth: 1,
    borderColor: '#e3e8f2',
    padding: 14,
    marginBottom: 10,
  },
  recordRow: {flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between'},
  recordLabel: {fontSize: 14, fontWeight: '600', color: '#1a2233', flex: 1, marginRight: 10},
  recordBadge: {fontSize: 11, color: '#7a5b00', backgroundColor: '#fff3d6', paddingHorizontal: 8, paddingVertical: 3, borderRadius: 8},
  recordMeta: {fontSize: 12, color: '#8a94a6', marginTop: 6},
  empty: {alignItems: 'center', paddingTop: 40},
  emptyText: {fontSize: 14, color: '#8a94a6'},
});
