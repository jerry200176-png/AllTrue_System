// Sentry integrated — DSN injected via VITE_SENTRY_DSN at build time (PR #61 #63 #64)
import { createApp } from 'vue';
import * as Sentry from '@sentry/vue';
import App from './App.vue';
import './styles.css';

// Deploy 後舊 chunk hash 失效時（vite:preloadError），自動 reload 一次讓使用者取得最新版
// Ref: https://vite.dev/guide/build#load-error-handling
window.addEventListener('vite:preloadError', (event) => {
  event.preventDefault();
  window.location.reload();
});

const app = createApp(App);

if (import.meta.env.VITE_SENTRY_DSN) {
  Sentry.init({
    app,
    dsn: import.meta.env.VITE_SENTRY_DSN,
    environment: import.meta.env.MODE,
    integrations: [
      Sentry.browserTracingIntegration(),
    ],
    tracesSampleRate: 0.1,
    // 過濾掉不重要的錯誤
    ignoreErrors: [
      'ResizeObserver loop',
      'Network request failed',
      'Load failed',
      // Deploy 後舊 tab 嘗試載入已被新 hash 取代的 chunk，會自動 reload，無需回報
      'Failed to fetch dynamically imported module',
      'Importing a module script failed',
    ],
    beforeSend(event) {
      // 不回報本地開發的錯誤
      if (import.meta.env.DEV) return null;
      return event;
    },
  });
}

app.mount('#app');
