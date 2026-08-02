import React, {useState} from 'react';
import {ActivityIndicator, KeyboardAvoidingView, Platform, Pressable, StyleSheet, Text, TextInput, View} from 'react-native';
import {SafeAreaView} from 'react-native-safe-area-context';
import {useAuth} from '../context/AuthContext';

export default function LoginScreen() {
  const {login, loggingIn, loginError} = useAuth();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [fieldError, setFieldError] = useState<string | null>(null);

  const submit = async () => {
    setFieldError(null);
    if (!username.trim() || !password) {
      setFieldError('Enter your username and password.');
      return;
    }
    try {
      await login(username.trim(), password);
    } catch {
      // Error is surfaced via loginError in context.
    }
  };

  return (
    <SafeAreaView style={styles.safe}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.flex}>
        <View style={styles.container}>
          <View style={styles.brand}>
            <Text style={styles.title}>BCD Survey</Text>
            <Text style={styles.subtitle}>Government building data collection</Text>
          </View>

          <TextInput
            style={styles.input}
            placeholder="Username"
            placeholderTextColor="#9aa4b2"
            autoCapitalize="none"
            autoCorrect={false}
            value={username}
            onChangeText={setUsername}
            editable={!loggingIn}
            testID="username"
          />
          <TextInput
            style={styles.input}
            placeholder="Password"
            placeholderTextColor="#9aa4b2"
            secureTextEntry
            value={password}
            onChangeText={setPassword}
            editable={!loggingIn}
            testID="password"
          />

          {(loginError || fieldError) && (
            <Text style={styles.error} testID="login-error">
              {fieldError ?? loginError}
            </Text>
          )}

          <Pressable
            style={({pressed}) => [styles.button, (pressed || loggingIn) && styles.buttonPressed]}
            onPress={submit}
            disabled={loggingIn}
            testID="login-button">
            {loggingIn ? (
              <ActivityIndicator color="#fff" />
            ) : (
              <Text style={styles.buttonText}>Sign In</Text>
            )}
          </Pressable>

          <Text style={styles.hint}>
            Use a surveyor account (e.g. rk_surveyor / Survey@123) or a hierarchy account (admin / Admin@12345).
          </Text>
        </View>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: {flex: 1, backgroundColor: '#f4f6fb'},
  flex: {flex: 1},
  container: {flex: 1, justifyContent: 'center', paddingHorizontal: 28},
  brand: {alignItems: 'center', marginBottom: 32},
  title: {fontSize: 30, fontWeight: '800', color: '#1f3a68'},
  subtitle: {fontSize: 14, color: '#6b7a90', marginTop: 4},
  input: {
    backgroundColor: '#fff',
    borderRadius: 10,
    borderWidth: 1,
    borderColor: '#d7deeb',
    paddingHorizontal: 16,
    paddingVertical: 14,
    fontSize: 16,
    color: '#1a2233',
    marginBottom: 14,
  },
  error: {color: '#c62828', marginBottom: 12, fontSize: 14},
  button: {
    backgroundColor: '#1f3a68',
    borderRadius: 10,
    paddingVertical: 15,
    alignItems: 'center',
  },
  buttonPressed: {opacity: 0.85},
  buttonText: {color: '#fff', fontSize: 17, fontWeight: '700'},
  hint: {color: '#8a94a6', fontSize: 12, textAlign: 'center', marginTop: 24},
});
