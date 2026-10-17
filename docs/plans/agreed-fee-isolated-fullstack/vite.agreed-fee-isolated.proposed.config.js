/**
 * DRAFT — install path: frontend/vite.agreed-fee-isolated.config.js
 * Dev-only. Proxies /api → Laravel :8000 so AgreedFeeCorrectionModal relative
 * fetch('/api/v1/...') stays same-origin. Not used by production build.
 *
 * Proxy target is allowlisted to precise loopback origins (not a lifenet blacklist).
 */
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const fixtureRoot = path.resolve(__dirname, 'e2e/fixtures/agreed-fee-isolated');

const LOOPBACK = new Set(['127.0.0.1', 'localhost']);

function assertLoopbackProxyTarget(raw, label) {
  let u;
  try {
    u = new URL(raw);
  } catch {
    throw new Error(`${label} not a URL: ${raw}`);
  }
  if (!LOOPBACK.has(u.hostname)) {
    throw new Error(`${label} host must be loopback, got ${u.hostname}`);
  }
  if (u.protocol !== 'http:') {
    throw new Error(`${label} must be http:// loopback`);
  }
  // Empty port / :80 normalize to origin without :8000 — refuse. Exact match only.
  if (u.origin !== 'http://127.0.0.1:8000' && u.origin !== 'http://localhost:8000') {
    throw new Error(`${label} origin must be http://127.0.0.1:8000 (or localhost:8000), got ${u.origin}`);
  }
  return u.origin;
}

const apiTarget = assertLoopbackProxyTarget(
  process.env.AGREED_FEE_ISOLATED_API_URL || 'http://127.0.0.1:8000',
  'AGREED_FEE_ISOLATED_API_URL',
);

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
        target: apiTarget,
        changeOrigin: true,
      },
      // Harness snapshot is on the Laravel router, not under /api
      '/__isolated': {
        target: apiTarget,
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
