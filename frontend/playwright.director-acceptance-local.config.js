import acceptance from './playwright.director-acceptance.config.js';

// Dedicated synthetic runtime, never consumes existing credential values.
process.env.SMOKE_BASE_URL = 'http://127.0.0.1:5189';
process.env.SMOKE_DIRECTOR_USER = 'synthetic-director';
process.env.SMOKE_DIRECTOR_PASS = 'synthetic-only';
export default {
  ...acceptance,
  use: { ...acceptance.use, baseURL: process.env.SMOKE_BASE_URL,
    launchOptions: { args: ['--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1, EXCLUDE localhost'] } },
  webServer: { command: 'npx vite --config vite.director-acceptance.config.js',
    url: 'http://127.0.0.1:5189', reuseExistingServer: false, timeout: 60_000,
    env: { VITE_SENTRY_DSN: '' } },
};
