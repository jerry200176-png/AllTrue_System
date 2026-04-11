import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';

const buildTimeIso = new Date().toISOString();

export default defineConfig({
  base: './',
  cacheDir: './.vite_cache',
  resolve: {
    alias: {
      'laravel-echo': path.resolve(__dirname, 'vendor-modules/laravel-echo'),
      'pusher-js': path.resolve(__dirname, 'vendor-modules/pusher-js'),
    },
  },
  define: {
    __APP_BUILD_TIME__: JSON.stringify(buildTimeIso),
  },
  plugins: [vue()],
  build: {
    outDir: 'dist_build',
    rollupOptions: {
      // Removed custom output names to prevent asset overlapping (like index.png overriding logo.png)
    },
  },
  server: {
    host: true,   // 允許其他裝置透過 IP 連線
    port: 5173,
    proxy: {
      // 開發模式：proxy 到 Laravel (php artisan serve --port=8000)
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
        secure: false
      }
    }
  }
});
