import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

// 元件單元測試設定（#729）。
// 範圍刻意只含 design-system 共用元件 + 其 __tests__，
// 不與 src/lib/*.test.js（node:assert 純函式測試，由 node 直接跑）混在一起。
export default defineConfig({
  plugins: [vue()],
  test: {
    environment: 'jsdom',
    include: [
      'src/components/**/__tests__/**/*.test.js',
      'src/composables/**/__tests__/**/*.test.js',
    ],
    globals: false,
    coverage: {
      provider: 'v8',
      include: ['src/components/design-system/**/*.vue'],
      reporter: ['text-summary'],
      reportsDirectory: './coverage/unit',
    },
  },
});
