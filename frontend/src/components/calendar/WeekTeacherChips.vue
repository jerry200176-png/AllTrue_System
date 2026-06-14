<!--
  WeekTeacherChips（#740 Step 4c — Presentational，自 SmartCalendar.vue 剝離）
  單一職責：渲染老師多選篩選 chips（含「全清除」），點擊 emit('toggle', id) / emit('clear')。
  Container/Presentational 分離：父持選取狀態與資料，本元件純接 props + emit，無副作用。
  active 由 selectedIds 比對驅動；active 配色由父層預算好放進 teacher.color。
-->
<template>
  <div class="week-teacher-chips" role="group" aria-label="篩選老師（可多選）">
    <span class="week-teacher-chips-label">老師</span>
    <div class="week-teacher-chips-scroll">
      <button
        v-for="t in teachers"
        :key="t.id"
        type="button"
        :aria-pressed="isActive(t.id)"
        :class="['week-teacher-chip', { active: isActive(t.id) }]"
        :style="isActive(t.id) ? { background: t.color, borderColor: t.color, color: 'var(--ds-canvas)' } : {}"
        @click="$emit('toggle', t.id)"
      >{{ t.username }}</button>
    </div>
    <button
      v-if="selectedIds.length > 0"
      type="button"
      class="week-teacher-chip-clear"
      @click="$emit('clear')"
    >全清除</button>
  </div>
</template>

<script setup>
const props = defineProps({
  teachers: { type: Array, default: () => [] },    // [{ id, username, color }]
  selectedIds: { type: Array, default: () => [] }, // string[]（已選老師 id，字串化）
});
defineEmits(['toggle', 'clear']);

const isActive = (id) => props.selectedIds.includes(String(id));
</script>

<style scoped>
.week-teacher-chips {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.week-teacher-chips-label {
  flex-shrink: 0;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-light, var(--ds-ink-mute));
}
.week-teacher-chips-scroll {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
  min-width: 0;
  max-height: 5.5rem;
  overflow-y: auto;
  padding: 2px 0;
}
.week-teacher-chip {
  padding: 6px 12px;
  border: 1px solid var(--border-color, var(--ds-canvas-soft));
  border-radius: 999px;
  font-size: 13px;
  font-weight: 600;
  line-height: 1.3;
  color: var(--text-color, var(--ds-ink));
  background: var(--ds-canvas);
  cursor: pointer;
  transition: background 0.15s, border-color 0.15s, color 0.15s;
}
.week-teacher-chip:hover {
  background: var(--bg-muted, var(--ds-canvas-soft));
  border-color: var(--ds-canvas-soft);
}
.week-teacher-chip.active {
  color: var(--ds-canvas);
}
.week-teacher-chip-clear {
  flex-shrink: 0;
  padding: 4px 10px;
  border: 1px dashed var(--border-color, var(--ds-canvas-soft));
  border-radius: 999px;
  font-size: 12px;
  color: var(--text-light, var(--ds-ink-mute));
  background: transparent;
  cursor: pointer;
  transition: color 0.15s, border-color 0.15s;
}
.week-teacher-chip-clear:hover {
  color: var(--ds-danger);
  border-color: var(--ds-danger);
}
</style>
