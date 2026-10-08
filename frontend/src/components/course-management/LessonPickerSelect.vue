<template>
  <label v-if="picker && picker.options.length > 1" class="lps">
    <span class="lps__label">{{ picker.mode === 'substitute' ? '要代哪一堂？' : '要調哪一堂？' }}</span>
    <select class="lps__select" :value="picker.key" data-testid="lesson-picker" @change="$emit('pick', $event.target.value)">
      <option v-for="o in picker.options" :key="o.key" :value="o.key">{{ o.label }}</option>
    </select>
  </label>
</template>

<script setup>
// "Which lesson?" picker (Google Calendar "which event?"): the next 6 upcoming lessons, the first already chosen.
defineProps({ picker: { type: Object, default: null } });
defineEmits(['pick']);
</script>

<style scoped>
.lps { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 0 0 12px; font-weight: 600; }
.lps__select { min-height: 40px; max-width: 100%; font: inherit; font-weight: 400; }
</style>
