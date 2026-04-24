import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'path';
import { execSync } from 'child_process';

// 業界做法：用 git commit hash 作版本識別碼，而非 build 時間戳
// 好處：相同 commit 多次 build 結果相同，避免無謂的「新版本」通知
// docs-only commit 不重 build 前端（由 deploy.yml 控制），所以 hash 不會推到 server
const gitHash = (() => {
  try {
    return execSync('git rev-parse --short HEAD').toString().trim();
  } catch {
    return new Date().toISOString(); // fallback: CI 或無 git 環境
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
    __APP_BUILD_TIME__: JSON.stringify(gitHash),
  },
  plugins: [
    vue(),
    {
      name: 'emit-version-json',
      generateBundle() {
        this.emitFile({
          type: 'asset',
          fileName: 'version.json',
          source: JSON.stringify({ t: gitHash }),
        });
      },
    },
  ],
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
