import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';
import { execSync } from 'child_process';

// version.json 顯示給使用者看 → 用「YYYY-MM-DD HH:mm」（台北時間）
// 同時保留 commit hash 供開發者追溯
const buildDate = (() => {
  const now = new Date();
  // UTC+8
  const tpe = new Date(now.getTime() + 8 * 60 * 60 * 1000);
  const pad = (n) => String(n).padStart(2, '0');
  return `${tpe.getUTCFullYear()}-${pad(tpe.getUTCMonth() + 1)}-${pad(tpe.getUTCDate())} ${pad(tpe.getUTCHours())}:${pad(tpe.getUTCMinutes())}`;
})();
const gitHash = (() => {
  try {
    return execSync('git rev-parse --short HEAD').toString().trim();
  } catch {
    return 'unknown';
  }
})();

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
    __APP_BUILD_TIME__: JSON.stringify(buildDate),
  },
  plugins: [
    vue(),
    {
      name: 'emit-version-json',
      generateBundle() {
        this.emitFile({
          type: 'asset',
          fileName: 'version.json',
          source: JSON.stringify({ t: buildDate, hash: gitHash }),
        });
      },
    },
  ],
  build: {
    outDir: 'dist_build',
    rollupOptions: {
      output: {
        manualChunks: {
          'vendor-vue':    ['vue'],
          'vendor-sentry': ['@sentry/vue'],
        },
      },
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
