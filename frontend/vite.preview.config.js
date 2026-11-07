import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';
import tailwindcss from '@tailwindcss/vite';

/** PR preview build (fake data, in-browser mocks). Separate from production: outputs dist-preview/. */
export default defineConfig({
  root: path.resolve(__dirname, 'preview'),
  base: '/',
  cacheDir: path.resolve(__dirname, '.vite_cache_preview'),
  publicDir: path.resolve(__dirname, 'public'),
  plugins: [
    {
      // Preview never contacts third-party hosts: drop remote font @import / <link> (local fonts remain).
      name: 'preview-strip-remote-assets',
      enforce: 'pre',
      transform(code, id) {
        if (!/\.(css|vue)(\?|$)/.test(id)) return null;
        const out = code.replace(/@import\s+url\(['"]?https?:[^)]*\)\s*;?/g, '').replace(/<link\b[^>]*href="https?:[^>]*>/g, '');
        return out === code ? null : out;
      },
    },
    vue(),
    tailwindcss(),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
      'laravel-echo': path.resolve(__dirname, 'vendor-modules/laravel-echo'),
      'pusher-js': path.resolve(__dirname, 'vendor-modules/pusher-js'),
    },
  },
  define: { __APP_BUILD_TIME__: JSON.stringify(new Date().toISOString()) },
  build: {
    target: 'esnext',
    outDir: path.resolve(__dirname, 'dist-preview'),
    emptyOutDir: true,
    rollupOptions: {
      input: path.resolve(__dirname, 'preview/index.html'),
    },
  },
});
