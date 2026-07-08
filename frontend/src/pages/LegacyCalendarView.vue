<template>
  <!-- Legacy calendar path: same SmartCalendar with v1 feature set gated off -->
  <SmartCalendar v-bind="$attrs" :legacy-mode="true" />
</template>

<script setup>
import SmartCalendar from './SmartCalendar.vue';

defineOptions({ inheritAttrs: false });
</script>
