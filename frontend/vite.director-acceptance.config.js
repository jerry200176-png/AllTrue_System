import app from './vite.config.js';
import { directorAcceptanceApi } from './e2e/fixtures/directorAcceptanceApi.js';

export default {
  ...app,
  plugins: [directorAcceptanceApi(), ...app.plugins],
  server: { host: '127.0.0.1', port: 5189, strictPort: true, proxy: {} },
};
