// Sentry integrated — DSN injected via VITE_SENTRY_DSN at build time (PR #61 #63 #64)
import { createApp } from 'vue';
import * as Sentry from '@sentry/vue';
import App from './App.vue';
import './styles.css';

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
    ],
    beforeSend(event) {
      // 不回報本地開發的錯誤
      if (import.meta.env.DEV) return null;
      return event;
    },
  });
}

app.mount('#app');
