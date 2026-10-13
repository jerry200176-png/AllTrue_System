<script setup>
import { computed } from 'vue';
import { AT_ICON_NAMES, AT_ICON_PATHS, AT_ICON_SIZES } from './atIcons.js';

const props = defineProps({
  name: {
    type: String,
    required: true,
    validator: (v) => AT_ICON_NAMES.includes(v),
  },
  size: {
    type: String,
    default: 'md',
    validator: (v) => AT_ICON_SIZES.includes(v),
  },
  // Decorative by default (aria-hidden). Pass a label only for a standalone
  // meaningful icon; icon-only buttons put aria-label on the button instead.
  label: { type: String, default: '' },
});

const paths = computed(() => AT_ICON_PATHS[props.name] || []);
const a11y = computed(() => (props.label
  ? { role: 'img', 'aria-label': props.label }
  : { 'aria-hidden': 'true', focusable: 'false' }));
</script>

<template>
  <svg
    :class="['at-icon', `at-icon--${size}`]"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    :data-icon="name"
    v-bind="a11y"
  >
    <path v-for="(d, i) in paths" :key="i" :d="d" />
  </svg>
</template>

<style scoped>
.at-icon {
  display: inline-block;
  flex-shrink: 0;
  width: 1em;
  height: 1em;
  vertical-align: -0.125em;
}
.at-icon--sm { font-size: var(--ds-font-size-base); }
.at-icon--md { font-size: var(--ds-font-size-lg); }
.at-icon--lg { font-size: var(--ds-font-size-2xl); }
</style>
