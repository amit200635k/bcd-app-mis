import React, {useCallback, useMemo, useState} from 'react';
import {ActivityIndicator, Modal, StyleSheet, View} from 'react-native';
import {SafeAreaProvider} from 'react-native-safe-area-context';
import {AuthProvider, useAuth} from './src/context/AuthContext';
import {DataProvider} from './src/context/DataContext';
import LoginScreen from './src/screens/LoginScreen';
import HomeScreen from './src/screens/HomeScreen';
import FormFillScreen from './src/screens/FormFillScreen';
import SyncScreen from './src/screens/SyncScreen';
import {FormDefinition, PublishedForm} from './src/types/api';

function ModalOverlay({children}: {children: React.ReactNode}) {
  return (
    <Modal visible animationType="slide" onRequestClose={() => undefined}>
      {children}
    </Modal>
  );
}

function Root() {
  const {profile, bootstrapping} = useAuth();
  const [formDefinition, setFormDefinition] = useState<FormDefinition | null>(null);
  const [syncOpen, setSyncOpen] = useState(false);

  const openForm = useCallback(async (form: PublishedForm) => {
    setFormDefinition({
      form: {
        id: form.id,
        code: form.code,
        title: form.title,
        description: form.description,
        current_version: form.current_version,
        updated_at: form.updated_at,
      },
      version: form.version ?? form.current_version,
      sections: form.sections ?? [],
    });
  }, []);

  const content = useMemo(() => {
    if (bootstrapping) {
      return (
        <View style={styles.loader}>
          <ActivityIndicator size="large" color="#1f3a68" />
        </View>
      );
    }
    if (!profile) {
      return <LoginScreen />;
    }
    return (
      <View style={styles.flex}>
        <HomeScreen onOpenForm={openForm} onOpenSync={() => setSyncOpen(true)} />
        {formDefinition ? (
          <ModalOverlay>
            <FormFillScreen
              form={formDefinition}
              onClose={() => setFormDefinition(null)}
              onSubmitted={() => setFormDefinition(null)}
            />
          </ModalOverlay>
        ) : null}
        {syncOpen ? (
          <ModalOverlay>
            <SyncScreen onClose={() => setSyncOpen(false)} />
          </ModalOverlay>
        ) : null}
      </View>
    );
  }, [bootstrapping, profile, formDefinition, syncOpen, openForm]);

  return <View style={styles.flex}>{content}</View>;
}

function AppInner() {
  return (
    <AuthProvider>
      <DataProvider>
        <Root />
      </DataProvider>
    </AuthProvider>
  );
}

export default function App() {
  return (
    <SafeAreaProvider>
      <AppInner />
    </SafeAreaProvider>
  );
}

const styles = StyleSheet.create({
  flex: {flex: 1, backgroundColor: '#f4f6fb'},
  loader: {flex: 1, alignItems: 'center', justifyContent: 'center'},
});
