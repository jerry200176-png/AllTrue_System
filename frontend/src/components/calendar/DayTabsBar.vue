<!--
  DayTabsBar（#740 Step 4b — Presentational，自 SmartCalendar.vue 剝離）
  單一職責：渲染日檢視「週一～週日」分頁列，點擊時 emit('select', idx)。
  Container/Presentational 分離：父層持有 selectedDayIdx 與資料，本元件純接 props + emit，無副作用。
  CSS（base + 768px RWD）忠實搬移；.active 為自身狀態，改由 activeIdx 比對驅動。
-->
<template>
  <div class="day-tabs-bar">
    <button
      v-for="(tab, idx) in tabs"
      :key="idx"
      type="button"
      :class="['day-tab', { active: activeIdx === idx }]"
      @click="$emit('select', idx)"
    >
      <span class="day-tab-name">{{ tab.name }}</span>
      <span class="day-tab-date">{{ tab.dateLabel }}</span>
      <span v-if="tab.count > 0" class="day-tab-badge">{{ tab.count }}</span>
    </button>
  </div>
</template>

<script setup>
defineProps({
  tabs: { type: Array, default: () => [] },
  activeIdx: { type: Number, default: -1 },
});
defineEmits(['select']);
</script>

<style scoped>
.day-tabs-bar {
  display: flex;
  gap: 2px;
  padding: 12px 16px 0;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}
.day-tab {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  padding: 10px 16px 8px;
  border: none;
  border-radius: 10px 10px 0 0;
  background: var(--bg-muted, #f0f1f3);
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  color: var(--text-light, #64748b);
  transition: background 0.2s, color 0.2s;
  position: relative;
  min-width: 72px;
  flex-shrink: 0;
}
.day-tab:hover { background: #e8e9ec; color: var(--text-color, #1a1a1a); }
.day-tab.active {
  background: #fff;
  color: var(--primary, #2563eb);
  box-shadow: 0 -2px 0 var(--primary, #2563eb) inset;
}
.day-tab-name { font-size: 13px; font-weight: 700; line-height: 1.3; }
.day-tab-date { font-size: 11px; font-weight: 400; opacity: 0.8; line-height: 1.2; }
.day-tab-badge {
  position: absolute;
  top: 4px;
  right: 4px;
  min-width: 18px;
  height: 18px;
  border-radius: 9px;
  background: var(--primary, #2563eb);
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 4px;
  line-height: 1;
}
.day-tab.active .day-tab-badge { background: #1d4ed8; }

@media (max-width: 768px) {
  .day-tabs-bar {
    padding: 8px 8px 0;
    gap: 1px;
  }
  .day-tab {
    min-width: 56px;
    padding: 8px 8px 6px;
    font-size: 11px;
  }
  .day-tab-name { font-size: 11px; }
  .day-tab-date { font-size: 9px; }
}
@media (max-width: 640px) {
  .day-tabs-bar { padding: 6px 4px 0; }
  .day-tab { min-width: 44px; padding: 6px 4px 4px; }
  .day-tab-name { font-size: 10px; }
  .day-tab-date { font-size: 8px; }
  .day-tab-badge { min-width: 14px; height: 14px; font-size: 8px; }
}
</style>
