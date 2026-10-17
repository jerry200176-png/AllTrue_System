/**
 * DRAFT — proposed path: frontend/vite.agreed-fee-isolated.config.js
 * Dev-only fixture. Proxies /api → Laravel :8000. Not used by production build.
 */
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';

const fixtureRoot = path.resolve(__dirname, '../../frontend/e2e/fixtures/agreed-fee-isolated');
// When still under docs/plans, paths are illustrative; after copy into frontend/,
// set root to e2e/fixtures/agreed-fee-isolated and publicDir to frontend/public.

export default defineConfig({
  root: fixtureRoot,
  base: '/',
  publicDir: path.resolve(__dirname, '../../frontend/public'),
  plugins: [vue()],
  server: {
    host: '127.0.0.1',
    port: 5179,
    strictPort: true,
    proxy: {
      '/api': {
        target: process.env.AGREED_FEE_ISOLATED_API_URL || 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  build: {
    target: 'esnext',
    rollupOptions: {
      input: path.resolve(fixtureRoot, 'agreed-fee-isolated.html'),
    },
  },
});
