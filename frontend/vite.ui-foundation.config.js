import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';

/** Dev server only — mounts real Vue pilot pages for Playwright. Not used by production build. */
export default defineConfig({
  root: path.resolve(__dirname, 'e2e/fixtures/ui-foundation'),
  base: '/',
  cacheDir: path.resolve(__dirname, '.vite_cache_ui_foundation'),
  // Root is the fixtures dir, so Vite would otherwise default publicDir to a
  // nonexistent local folder and silently 404 every asset under frontend/public/
  // (fonts, icons, manifest) — point it at the real one to match production serving.
  publicDir: path.resolve(__dirname, 'public'),
  plugins: [vue()],
  resolve: {
    alias: {
      'laravel-echo': path.resolve(__dirname, 'vendor-modules/laravel-echo'),
      'pusher-js': path.resolve(__dirname, 'vendor-modules/pusher-js'),
    },
  },
  server: {
    host: '127.0.0.1',
    port: 5177,
    strictPort: true,
  },
});
