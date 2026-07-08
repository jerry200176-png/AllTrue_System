<template>
  <SmartCalendar
    v-if="ready && useCalendarV2"
    v-bind="$attrs"
  />
  <LegacyCalendarView
    v-else-if="ready"
    v-bind="$attrs"
  />
  <div v-else class="calendar-loading-bar">載入行事曆…</div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { featureFlag } from '../lib/featureFlags.js';
import SmartCalendar from './SmartCalendar.vue';
import LegacyCalendarView from './LegacyCalendarView.vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  userRole: { type: String, default: '' },
  userId: { type: [Number, String], default: null },
});

const ready = ref(false);
const useCalendarV2 = ref(false);

onMounted(async () => {
  useCalendarV2.value = await featureFlag('calendar_v2');
  ready.value = true;
});
</script>

<style scoped>
.calendar-loading-bar {
  padding: 1rem;
  color: var(--ds-ink-mute, #666);
}
</style>
