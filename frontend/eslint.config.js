import vuePlugin from 'eslint-plugin-vue';
import vueParser from 'vue-eslint-parser';
import globals from 'globals';

/**
 * Minimal, narrowly-scoped lint gate — added 2026-07-29 after a production P0
 * (CourseManagement.vue blank page): a composable's return object referenced
 * an undeclared identifier and threw ReferenceError on every mount. No
 * TypeScript, no ESLint, and vite build's transpile step doesn't catch
 * unresolved identifiers, so this shipped straight to production.
 *
 * Scope is intentionally just `no-undef`. (`no-unused-vars` was tried too,
 * but produces false positives on `<script setup>` bindings that are only
 * referenced from `<template>` — that needs eslint-plugin-vue's template
 * analysis wired in, which is a separate follow-up.) Full stylistic linting
 * is a separate, much larger effort — see docs/TECH_DEBT.md for the rollout
 * plan. Keeping this rule set to exactly the bug class that shipped means it
 * can be blocking now instead of advisory-forever.
 */
export default [
  {
    ignores: ['dist/**', 'dist_build/**', 'coverage/**', 'node_modules/**', '**/*.generated.js'],
  },
  {
    files: ['src/**/*.js', 'src/**/*.vue'],
    plugins: { vue: vuePlugin },
    languageOptions: {
      parser: vueParser,
      parserOptions: {
        ecmaVersion: 2022,
        sourceType: 'module',
        parser: undefined, // plain JS in <script>, no TS
      },
      globals: {
        ...globals.browser,
        ...globals.es2021,
        defineProps: 'readonly',
        defineEmits: 'readonly',
        defineExpose: 'readonly',
        defineOptions: 'readonly',
        defineModel: 'readonly',
        withDefaults: 'readonly',
        __APP_VERSION__: 'readonly',
        // Vite `define` build-time constants (vite.config.js)
        __APP_BUILD_TIME__: 'readonly',
      },
    },
    rules: {
      'no-undef': 'error',
    },
  },
  {
    // Plain-node test scripts (run via `node foo.test.js`, not vitest) and
    // vitest spec files that reach for Node/vitest globals.
    files: ['src/**/*.test.js', 'src/**/__tests__/**/*.js'],
    languageOptions: {
      globals: {
        ...globals.node,
        ...globals.browser,
      },
    },
    rules: {
      'no-undef': 'error',
    },
  },
];
