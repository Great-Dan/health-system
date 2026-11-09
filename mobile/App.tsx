import AsyncStorage from '@react-native-async-storage/async-storage';
import { StatusBar } from 'expo-status-bar';
import { useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  BackHandler,
  KeyboardAvoidingView,
  Linking,
  Modal,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';
import { WebView, type WebViewNavigation } from 'react-native-webview';

const DEFAULT_SERVER_URL = process.env.EXPO_PUBLIC_WEB_APP_URL?.trim() ?? '';
const SAVED_SERVER_URL_KEY = 'secure-health.server-url';

function getHttpOrigin(value: string): string | null {
  try {
    const parsed = new URL(value.trim());
    return parsed.protocol === 'http:' || parsed.protocol === 'https:'
      ? parsed.origin
      : null;
  } catch {
    return null;
  }
}

export default function App() {
  const webViewRef = useRef<WebView>(null);
  const [serverUrl, setServerUrl] = useState(DEFAULT_SERVER_URL);
  const [draftServerUrl, setDraftServerUrl] = useState(DEFAULT_SERVER_URL);
  const [isReady, setIsReady] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [canGoBack, setCanGoBack] = useState(false);
  const [isSettingsVisible, setIsSettingsVisible] = useState(!DEFAULT_SERVER_URL);
  const [loadError, setLoadError] = useState('');
  const [settingsError, setSettingsError] = useState('');
  const [notice, setNotice] = useState('');

  useEffect(() => {
    let mounted = true;

    AsyncStorage.getItem(SAVED_SERVER_URL_KEY)
      .then((savedUrl) => {
        if (!mounted) return;
        if (savedUrl) {
          setServerUrl(savedUrl);
          setDraftServerUrl(savedUrl);
          setIsSettingsVisible(false);
        }
      })
      .catch(() => {
        if (mounted) setNotice('Saved server settings could not be loaded.');
      })
      .finally(() => {
        if (mounted) setIsReady(true);
      });

    return () => {
      mounted = false;
    };
  }, []);

  useEffect(() => {
    if (Platform.OS !== 'android') return;

    const subscription = BackHandler.addEventListener('hardwareBackPress', () => {
      if (canGoBack && webViewRef.current) {
        webViewRef.current.goBack();
        return true;
      }
      return false;
    });

    return () => subscription.remove();
  }, [canGoBack]);

  const saveServerUrl = async () => {
    const nextUrl = draftServerUrl.trim();
    const nextOrigin = getHttpOrigin(nextUrl);

    if (!nextOrigin || !nextUrl.startsWith(nextOrigin)) {
      setSettingsError('Enter a valid http:// or https:// address.');
      return;
    }

    try {
      await AsyncStorage.setItem(SAVED_SERVER_URL_KEY, nextUrl);
      setServerUrl(nextUrl);
      setLoadError('');
      setNotice('');
      setSettingsError('');
      setIsLoading(true);
      setIsSettingsVisible(false);
    } catch {
      setSettingsError('The server address could not be saved on this device.');
    }
  };

  const handleNavigation = (request: { url: string }) => {
    if (request.url.startsWith('mailto:')) {
      Linking.openURL(request.url).catch(() => {
        setNotice('No email app is available for this reminder.');
      });
      return false;
    }

    const appOrigin = getHttpOrigin(serverUrl);
    const nextOrigin = getHttpOrigin(request.url);

    if (appOrigin && nextOrigin === appOrigin) return true;

    setNotice('This link is outside the configured health-system server.');
    return false;
  };

  const handleNavigationChange = (navigation: WebViewNavigation) => {
    setCanGoBack(navigation.canGoBack);
  };

  const refreshPage = () => {
    setLoadError('');
    setNotice('');
    if (webViewRef.current) {
      webViewRef.current.reload();
    } else {
      setIsLoading(true);
    }
  };

  const openServerSettings = () => {
    setDraftServerUrl(serverUrl);
    setSettingsError('');
    setIsSettingsVisible(true);
  };

  const statusLabel = loadError
    ? 'NEEDS ATTENTION'
    : isLoading
      ? 'CONNECTING'
      : 'CONNECTED';

  return (
    <SafeAreaProvider>
      <SafeAreaView style={styles.safeArea} edges={['top', 'bottom']}>
        <StatusBar style="light" />

        <View style={styles.header}>
          <View style={styles.brandRow}>
            <View style={styles.brandMark}>
              <Text style={styles.brandMarkText}>SH</Text>
            </View>
            <View style={styles.brandCopy}>
              <Text style={styles.brandEyebrow}>SECURE HEALTH</Text>
              <Text style={styles.brandTitle}>Patient system</Text>
            </View>
          </View>

          <View style={styles.headerActions}>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Go back in the health system"
              disabled={!canGoBack}
              onPress={() => webViewRef.current?.goBack()}
              style={({ pressed }) => [
                styles.headerButton,
                !canGoBack && styles.headerButtonDisabled,
                pressed && canGoBack && styles.headerButtonPressed,
              ]}
            >
              <Text style={styles.headerButtonText}>Back</Text>
            </Pressable>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Reload the health system"
              onPress={refreshPage}
              style={({ pressed }) => [styles.headerButton, pressed && styles.headerButtonPressed]}
            >
              <Text style={styles.headerButtonText}>Reload</Text>
            </Pressable>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Configure the server address"
              onPress={openServerSettings}
              style={({ pressed }) => [styles.serverButton, pressed && styles.serverButtonPressed]}
            >
              <Text style={styles.serverButtonText}>Server</Text>
            </Pressable>
          </View>
        </View>

        <View style={styles.connectionStrip}>
          <View style={[styles.statusDot, loadError ? styles.statusDotError : isLoading ? styles.statusDotLoading : styles.statusDotConnected]} />
          <Text style={styles.connectionLabel}>{statusLabel}</Text>
          {isLoading && <ActivityIndicator size="small" color="#377c70" />}
        </View>

        {notice !== '' && (
          <Pressable style={styles.notice} onPress={() => setNotice('')}>
            <Text style={styles.noticeText}>{notice}</Text>
            <Text style={styles.noticeDismiss}>Dismiss</Text>
          </Pressable>
        )}

        <View style={styles.webContainer}>
          {isReady && serverUrl !== '' && loadError === '' ? (
            <WebView
              key={serverUrl}
              ref={webViewRef}
              source={{ uri: serverUrl }}
              style={styles.webView}
              originWhitelist={['http://*', 'https://*']}
              javaScriptEnabled
              domStorageEnabled
              sharedCookiesEnabled
              thirdPartyCookiesEnabled
              allowsBackForwardNavigationGestures
              setSupportMultipleWindows={false}
              onShouldStartLoadWithRequest={handleNavigation}
              onNavigationStateChange={handleNavigationChange}
              onLoadStart={() => {
                setIsLoading(true);
                setLoadError('');
              }}
              onLoadEnd={() => setIsLoading(false)}
              onError={({ nativeEvent }) => {
                setIsLoading(false);
                setLoadError(nativeEvent.description || 'The server could not be reached.');
              }}
              onHttpError={({ nativeEvent }) => {
                if (nativeEvent.statusCode >= 500) {
                  setLoadError(`The server returned an error (${nativeEvent.statusCode}).`);
                }
              }}
              renderLoading={() => (
                <View style={styles.loadingOverlay}>
                  <ActivityIndicator size="large" color="#27776b" />
                  <Text style={styles.loadingText}>Connecting to your health system</Text>
                </View>
              )}
              startInLoadingState
            />
          ) : (
            <View style={styles.emptyState}>
              {loadError !== '' ? (
                <>
                  <View style={styles.errorMark}><Text style={styles.errorMarkText}>!</Text></View>
                  <Text style={styles.emptyTitle}>Couldn’t reach the server</Text>
                  <Text style={styles.emptyBody}>{loadError}</Text>
                  <Text style={styles.emptyHint}>
                    Check that XAMPP is running and this phone can reach the computer over the same Wi-Fi.
                  </Text>
                  <Pressable style={styles.primaryButton} onPress={refreshPage}>
                    <Text style={styles.primaryButtonText}>Try again</Text>
                  </Pressable>
                  <Pressable style={styles.textButton} onPress={openServerSettings}>
                    <Text style={styles.textButtonText}>Change server address</Text>
                  </Pressable>
                </>
              ) : isReady ? (
                <>
                  <View style={styles.errorMark}><Text style={styles.errorMarkText}>SH</Text></View>
                  <Text style={styles.emptyTitle}>Connect your server</Text>
                  <Text style={styles.emptyBody}>
                    Enter the address of the PHP system running on your computer.
                  </Text>
                  <Pressable style={styles.primaryButton} onPress={openServerSettings}>
                    <Text style={styles.primaryButtonText}>Set server address</Text>
                  </Pressable>
                </>
              ) : (
                <ActivityIndicator size="large" color="#27776b" />
              )}
            </View>
          )}
        </View>

        <View style={styles.footer}>
          <Text style={styles.footerText}>Secure Health · Mobile access</Text>
          <Text style={styles.footerText}>Web session protected by your account sign-in</Text>
        </View>

        <Modal
          visible={isSettingsVisible}
          transparent
          animationType="fade"
          onRequestClose={() => setIsSettingsVisible(false)}
        >
          <KeyboardAvoidingView
            style={styles.modalBackdrop}
            behavior={Platform.OS === 'ios' ? 'padding' : undefined}
          >
            <View style={styles.settingsCard}>
              <Text style={styles.settingsEyebrow}>CONNECTION</Text>
              <Text style={styles.settingsTitle}>Server address</Text>
              <Text style={styles.settingsBody}>
                Use your computer’s LAN IP and include the PHP login path. Don’t enter localhost here.
              </Text>

              <TextInput
                autoCapitalize="none"
                autoCorrect={false}
                keyboardType="url"
                onChangeText={setDraftServerUrl}
                placeholder="http://192.168.1.20/secure_health_system/login.php"
                placeholderTextColor="#788884"
                style={styles.urlInput}
                value={draftServerUrl}
              />

              {settingsError !== '' && <Text style={styles.settingsError}>{settingsError}</Text>}

              <Pressable style={styles.primaryButton} onPress={saveServerUrl}>
                <Text style={styles.primaryButtonText}>Save and connect</Text>
              </Pressable>
              {serverUrl !== '' && (
                <Pressable style={styles.textButton} onPress={() => setIsSettingsVisible(false)}>
                  <Text style={styles.textButtonText}>Cancel</Text>
                </Pressable>
              )}
            </View>
          </KeyboardAvoidingView>
        </Modal>
      </SafeAreaView>
    </SafeAreaProvider>
  );
}

const styles = StyleSheet.create({
  safeArea: {
    flex: 1,
    backgroundColor: '#f2f5f3',
  },
  header: {
    minHeight: 76,
    paddingHorizontal: 18,
    paddingVertical: 12,
    backgroundColor: '#153d3a',
    borderBottomWidth: 3,
    borderBottomColor: '#d5ea70',
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 10,
  },
  brandRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    flexShrink: 1,
  },
  brandMark: {
    width: 42,
    height: 42,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 12,
    backgroundColor: '#d5ea70',
  },
  brandMarkText: {
    color: '#153d3a',
    fontWeight: '800',
    fontSize: 13,
  },
  brandCopy: {
    flexShrink: 1,
  },
  brandEyebrow: {
    color: '#d5ea70',
    fontWeight: '700',
    fontSize: 9,
  },
  brandTitle: {
    color: '#ffffff',
    fontWeight: '700',
    fontSize: 14,
  },
  headerActions: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
  },
  headerButton: {
    minHeight: 36,
    paddingHorizontal: 9,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.28)',
    borderRadius: 8,
  },
  headerButtonDisabled: {
    opacity: 0.4,
  },
  headerButtonPressed: {
    backgroundColor: 'rgba(255,255,255,0.12)',
  },
  headerButtonText: {
    color: '#eff5f1',
    fontWeight: '600',
    fontSize: 11,
  },
  serverButton: {
    minHeight: 36,
    paddingHorizontal: 10,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 8,
    backgroundColor: '#d5ea70',
  },
  serverButtonPressed: {
    backgroundColor: '#c4db5a',
  },
  serverButtonText: {
    color: '#153d3a',
    fontSize: 11,
    fontWeight: '800',
  },
  connectionStrip: {
    height: 34,
    paddingHorizontal: 18,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    backgroundColor: '#e7eeea',
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: '#d6e0dc',
  },
  statusDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
  },
  statusDotConnected: {
    backgroundColor: '#2a836c',
  },
  statusDotLoading: {
    backgroundColor: '#bc882c',
  },
  statusDotError: {
    backgroundColor: '#a63d35',
  },
  connectionLabel: {
    color: '#526963',
    fontSize: 9,
    fontWeight: '800',
    letterSpacing: 0.7,
    flex: 1,
  },
  notice: {
    paddingHorizontal: 16,
    paddingVertical: 9,
    backgroundColor: '#fff5d8',
    flexDirection: 'row',
    justifyContent: 'space-between',
    gap: 12,
  },
  noticeText: {
    color: '#634e18',
    fontSize: 12,
    flex: 1,
  },
  noticeDismiss: {
    color: '#634e18',
    fontWeight: '700',
    fontSize: 11,
  },
  webContainer: {
    flex: 1,
    backgroundColor: '#f2f5f3',
  },
  webView: {
    flex: 1,
    backgroundColor: '#f2f5f3',
  },
  loadingOverlay: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 12,
    backgroundColor: '#f2f5f3',
  },
  loadingText: {
    color: '#647775',
    fontSize: 13,
  },
  emptyState: {
    flex: 1,
    padding: 28,
    alignItems: 'center',
    justifyContent: 'center',
  },
  errorMark: {
    width: 56,
    height: 56,
    marginBottom: 16,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 17,
    backgroundColor: '#d5ea70',
  },
  errorMarkText: {
    color: '#153d3a',
    fontSize: 18,
    fontWeight: '800',
  },
  emptyTitle: {
    marginBottom: 8,
    color: '#153d3a',
    fontSize: 21,
    fontWeight: '700',
    textAlign: 'center',
  },
  emptyBody: {
    maxWidth: 330,
    color: '#526963',
    fontSize: 14,
    lineHeight: 21,
    textAlign: 'center',
  },
  emptyHint: {
    maxWidth: 340,
    marginTop: 10,
    color: '#71837d',
    fontSize: 12,
    lineHeight: 18,
    textAlign: 'center',
  },
  primaryButton: {
    width: '100%',
    minHeight: 48,
    marginTop: 18,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: 10,
    backgroundColor: '#1d5149',
  },
  primaryButtonText: {
    color: '#ffffff',
    fontSize: 14,
    fontWeight: '700',
  },
  textButton: {
    minHeight: 42,
    marginTop: 8,
    paddingHorizontal: 12,
    alignItems: 'center',
    justifyContent: 'center',
  },
  textButtonText: {
    color: '#27776b',
    fontSize: 13,
    fontWeight: '700',
  },
  footer: {
    minHeight: 30,
    paddingHorizontal: 14,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 8,
    backgroundColor: '#e7eeea',
  },
  footerText: {
    color: '#667973',
    fontSize: 9,
    flexShrink: 1,
  },
  modalBackdrop: {
    flex: 1,
    padding: 20,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(13, 34, 31, 0.58)',
  },
  settingsCard: {
    width: '100%',
    maxWidth: 440,
    padding: 24,
    borderWidth: 1,
    borderColor: '#d8e2de',
    borderRadius: 16,
    backgroundColor: '#fffefd',
  },
  settingsEyebrow: {
    marginBottom: 6,
    color: '#27776b',
    fontSize: 10,
    fontWeight: '800',
    letterSpacing: 0.6,
  },
  settingsTitle: {
    marginBottom: 8,
    color: '#153d3a',
    fontSize: 23,
    fontWeight: '700',
  },
  settingsBody: {
    marginBottom: 14,
    color: '#647775',
    fontSize: 13,
    lineHeight: 19,
  },
  urlInput: {
    minHeight: 48,
    paddingHorizontal: 12,
    borderWidth: 1,
    borderColor: '#c8d7ce',
    borderRadius: 9,
    color: '#1b3030',
    fontSize: 13,
  },
  settingsError: {
    marginTop: 8,
    color: '#a63d35',
    fontSize: 12,
  },
});
