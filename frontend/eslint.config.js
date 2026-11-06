import vuePlugin from 'eslint-plugin-vue';
import vueParser from 'vue-eslint-parser';
import globals from 'globals';
import vueA11y from 'eslint-plugin-vuejs-accessibility';

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
    plugins: { vue: vuePlugin, 'vuejs-accessibility': vueA11y },
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
      // #4991 P12: toISOString().slice(0, 10) is the UTC day, so "today" is
      // yesterday before 08:00 Taipei. Use ymdInTaipei() from lib/sessionDates.js.
      'no-restricted-syntax': ['error',
        {
          selector: "CallExpression[callee.property.name=/^(slice|substring|substr)$/][callee.object.callee.property.name='toISOString'][arguments.0.value=0][arguments.1.value=10]",
          message: 'toISOString().slice(0, 10) is the UTC date (yesterday before 08:00 Taipei). Use ymdInTaipei() from @/lib/sessionDates.js; if this really formats a UTC date, add an eslint-disable with a reason.',
        },
        {
          selector: "CallExpression[callee.property.name='split'][callee.object.callee.property.name='toISOString'][arguments.0.value='T']",
          message: 'toISOString().split(\'T\') is the UTC date (yesterday before 08:00 Taipei). Use ymdInTaipei() from @/lib/sessionDates.js.',
        },
      ],
      // Undeclared emits hide a child's events from the unhandled-emit guard
      // (scripts/ci/check-unhandled-emits.mjs). 0 violations at enable time.
      'vue/require-explicit-emits': 'error',
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
  {
    // Every form control needs an accessible name (label/for, aria-label…).
    // TODO: scoped to already-fixed pages; 207 violations remain in other pages
    // (count when enabled repo-wide on 2026-10-03). Widen `files` as pages are fixed.
    files: ['src/pages/AttendancePage.vue', 'src/pages/ProfileCenterPage.vue'],
    plugins: { 'vuejs-accessibility': vueA11y },
    languageOptions: { parser: vueParser },
    rules: { 'vuejs-accessibility/form-control-has-label': 'error' },
  },
];
