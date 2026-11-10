<script setup>
// #4971: add 行政加給 for a part-time teacher who has no sessions this month (not in the table yet).
import { ref, computed, onMounted } from 'vue';
import ParttimeAdminBonusField from './ParttimeAdminBonusField.vue';
import { fetchPartTimeTeachers } from '../lib/parttimePayrollApi.js';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  month: { type: String, required: true },
  existingIds: { type: Array, default: () => [] },
});
const emit = defineEmits(['saved']);

const all = ref([]);
const picked = ref('');
const options = computed(() => all.value.filter((t) => !props.existingIds.includes(Number(t.id))));

onMounted(async () => {
  try { all.value = props.branchId ? await fetchPartTimeTeachers({ branchId: props.branchId }) : []; } catch { all.value = []; }
});
function saved() { picked.value = ''; emit('saved'); }
</script>

<template>
  <div v-if="options.length" class="ab-add" data-testid="ab-add">
    <label>新增行政加給（本月無授課的兼職老師）
      <select v-model="picked" data-testid="ab-add-select">
        <option value="">選擇老師</option>
        <option v-for="t in options" :key="t.id" :value="Number(t.id)">{{ t.username }}</option>
      </select>
    </label>
    <ParttimeAdminBonusField v-if="picked" :key="picked" :branch-id="branchId" :teacher-id="picked" :month="month" @saved="saved" />
  </div>
</template>

<style scoped>
.ab-add { margin: 12px 0; display: flex; flex-direction: column; gap: 8px; font-size: var(--ds-font-size-sm); }
.ab-add select { padding: 6px 8px; min-height: 44px; max-width: 100%; }
</style>
