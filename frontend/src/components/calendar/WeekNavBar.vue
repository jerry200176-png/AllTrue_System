<!--
  WeekNavBar（#740 Step 4d — Presentational，自 SmartCalendar.vue 剝離）
  單一職責：上週/下週切換 + 週次下拉選擇。v-model 綁定週次，prev/next emit 事件。
  Container/Presentational 分離：父持 displayWeek 與翻週邏輯，本元件純接 props + emit，無副作用。
-->
<template>
  <div class="week-nav">
    <button type="button" class="week-nav-btn" @click="$emit('prev')">‹ 上週</button>
    <select
      class="week-select"
      :value="modelValue"
      @change="$emit('update:modelValue', Number($event.target.value))"
    >
      <option :value="0">全部</option>
      <option v-for="w in weekOptions" :key="w.value" :value="w.value">{{ w.label }}</option>
    </select>
    <button type="button" class="week-nav-btn" @click="$emit('next')">下週 ›</button>
  </div>
</template>

<script setup>
defineProps({
  modelValue: { type: Number, default: 0 },
  weekOptions: { type: Array, default: () => [] }, // [{ value, label }]
});
defineEmits(['update:modelValue', 'prev', 'next']);
</script>

<style scoped>
.week-nav {
  display: flex;
  align-items: center;
  gap: 6px;
}
.week-nav-btn {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  padding: 4px 10px;
  height: 30px;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 20px;
  background: #fff;
  font-size: 12px;
  font-weight: 500;
  color: var(--text-color);
  cursor: pointer;
  white-space: nowrap;
  transition: background 0.2s, border-color 0.2s;
}
.week-nav-btn:hover {
  background: var(--bg-muted, #f0f1f3);
  border-color: var(--border, #cbd5e1);
}
.week-nav .week-select {
  min-width: 180px;
}
.week-select {
  padding: 8px 12px;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 8px;
  font-size: 14px;
  line-height: 1.4;
  background: #fff;
  color: var(--text-color);
  min-width: 96px;
}

@media (max-width: 768px) {
  .week-nav {
    flex-wrap: wrap;
    gap: 6px;
  }
}
@media (max-width: 640px) {
  .week-nav { flex-wrap: wrap; justify-content: center; gap: 4px; }
  .week-nav button { padding: 5px 10px; font-size: 11px; }
}
</style>
