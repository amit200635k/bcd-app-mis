import React, {useCallback} from 'react';
import {FlatList, Pressable, RefreshControl, StyleSheet, Text, View} from 'react-native';
import {SafeAreaView} from 'react-native-safe-area-context';
import {useData} from '../context/DataContext';
import {useAuth} from '../context/AuthContext';
import {PublishedForm} from '../types/api';

interface Props {
  onOpenForm: (form: PublishedForm) => void;
  onOpenSync: () => void;
}

export default function HomeScreen({onOpenForm, onOpenSync}: Props) {
  const {forms, pending, uploading, refresh, refreshing, loading, error, clearError, syncStatus} = useData();
  const {profile, logout} = useAuth();

  const renderForm = useCallback(
    ({item}: {item: PublishedForm}) => (
      <Pressable style={({pressed}) => [styles.card, pressed && styles.cardPressed]} onPress={() => onOpenForm(item)}>
        <View style={styles.cardRow}>
          <Text style={styles.cardTitle}>{item.title}</Text>
          <Text style={styles.cardVersion}>v{item.current_version}</Text>
        </View>
        {item.description ? <Text style={styles.cardDesc} numberOfLines={2}>{item.description}</Text> : null}
        <Text style={styles.cardMeta}>
          {item.sections?.length ?? 0} sections · {item.code}
        </Text>
      </Pressable>
    ),
    [onOpenForm],
  );

  return (
    <SafeAreaView style={styles.safe} edges={['top']}>
      <View style={styles.header}>
        <View style={styles.headerRow}>
          <View style={styles.headerText}>
            <Text style={styles.greeting}>Welcome{profile ? `, ${profile.full_name}` : ''}</Text>
            <Text style={styles.subGreeting}>Downloaded surveys</Text>
          </View>
          <Pressable onPress={logout} style={styles.logout}>
            <Text style={styles.logoutText}>Logout</Text>
          </Pressable>
        </View>
      </View>

      {(pending.length > 0 || uploading) && (
        <Pressable onPress={onOpenSync} style={styles.syncBanner}>
          <Text style={styles.syncBannerText}>
            {uploading ? 'Uploading offline records…' : `${pending.length} offline record${pending.length === 1 ? '' : 's'} pending sync`}
          </Text>
          <Text style={styles.syncBannerLink}>View</Text>
        </Pressable>
      )}

      {error ? (
        <View style={styles.errorBox}>
          <Text style={styles.errorText}>{error}</Text>
          <Pressable onPress={clearError}>
            <Text style={styles.errorDismiss}>Dismiss</Text>
          </Pressable>
        </View>
      ) : null}

      <FlatList
        data={forms}
        keyExtractor={(f) => String(f.id)}
        renderItem={renderForm}
        contentContainerStyle={styles.list}
        refreshControl={<RefreshControl refreshing={refreshing || loading} onRefresh={refresh} />}
        ListEmptyComponent={
          <View style={styles.empty}>
            <Text style={styles.emptyTitle}>
              {loading ? 'Downloading surveys…' : 'No surveys available'}
            </Text>
            <Text style={styles.emptyText}>
              Pull down to refresh. Survey forms assigned to your account will appear here and work offline.
            </Text>
          </View>
        }
      />

      <View style={styles.footer}>
        <Text style={styles.footerText}>
          Server sync: {syncStatus ? `${syncStatus.pending} pending` : 'unknown'}
        </Text>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: {flex: 1, backgroundColor: '#f4f6fb'},
  header: {paddingHorizontal: 20, paddingTop: 8, paddingBottom: 4},
  headerRow: {flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between'},
  headerText: {flex: 1},
  greeting: {fontSize: 22, fontWeight: '800', color: '#1f3a68'},
  subGreeting: {fontSize: 13, color: '#6b7a90', marginTop: 2},
  logout: {paddingHorizontal: 14, paddingVertical: 8, backgroundColor: '#eef1f7', borderRadius: 8},
  logoutText: {color: '#1f3a68', fontWeight: '600'},
  syncBanner: {
    marginHorizontal: 20,
    marginTop: 12,
    backgroundColor: '#fff3d6',
    borderColor: '#f2c94c',
    borderWidth: 1,
    borderRadius: 10,
    padding: 12,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  syncBannerText: {color: '#7a5b00', flex: 1, fontSize: 13},
  syncBannerLink: {color: '#1f3a68', fontWeight: '700', marginLeft: 10},
  errorBox: {
    marginHorizontal: 20,
    marginTop: 12,
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
  list: {padding: 20, paddingBottom: 8},
  card: {
    backgroundColor: '#fff',
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#e3e8f2',
    padding: 16,
    marginBottom: 12,
  },
  cardPressed: {opacity: 0.85},
  cardRow: {flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center'},
  cardTitle: {fontSize: 16, fontWeight: '700', color: '#1f3a68', flex: 1, marginRight: 8},
  cardVersion: {fontSize: 12, color: '#6b7a90', backgroundColor: '#eef1f7', paddingHorizontal: 8, paddingVertical: 3, borderRadius: 8},
  cardDesc: {fontSize: 13, color: '#4a5568', marginTop: 6},
  cardMeta: {fontSize: 12, color: '#9aa4b2', marginTop: 8},
  empty: {alignItems: 'center', paddingTop: 60},
  emptyTitle: {fontSize: 17, fontWeight: '700', color: '#4a5568'},
  emptyText: {fontSize: 13, color: '#8a94a6', textAlign: 'center', marginTop: 8, paddingHorizontal: 24},
  footer: {padding: 12, alignItems: 'center'},
  footerText: {fontSize: 12, color: '#9aa4b2'},
});
