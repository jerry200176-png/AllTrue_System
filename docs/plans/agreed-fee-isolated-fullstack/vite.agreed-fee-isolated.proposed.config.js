/**
 * DRAFT — install path: frontend/vite.agreed-fee-isolated.config.js
 * Dev-only. Proxies /api → Laravel :8000 so AgreedFeeCorrectionModal relative
 * fetch('/api/v1/...') stays same-origin. Not used by production build.
 */
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const fixtureRoot = path.resolve(__dirname, 'e2e/fixtures/agreed-fee-isolated');

export default defineConfig({
  root: fixtureRoot,
  base: '/',
  publicDir: path.resolve(__dirname, 'public'),
  cacheDir: path.resolve(__dirname, '.vite_cache_agreed_fee_isolated'),
  plugins: [vue()],
  resolve: {
    alias: {
      'laravel-echo': path.resolve(__dirname, 'vendor-modules/laravel-echo'),
      'pusher-js': path.resolve(__dirname, 'vendor-modules/pusher-js'),
    },
  },
  server: {
    host: '127.0.0.1',
    port: 5179,
    strictPort: true,
    proxy: {
      '/api': {
        target: process.env.AGREED_FEE_ISOLATED_API_URL || 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
      // Harness snapshot is on the Laravel router, not under /api
      '/__isolated': {
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
