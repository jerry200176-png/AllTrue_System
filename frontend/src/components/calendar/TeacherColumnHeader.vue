<!--
  TeacherColumnHeader（#740 Step 4a — Presentational，自 SmartCalendar.vue 剝離）
  單一職責：渲染日檢視老師欄表頭（頭像 + 姓名 + 教室）。純接 props、無副作用。
  CSS 連同 base / compact / RWD 三斷點忠實搬移；compact 由祖先選擇器改為 prop 驅動。
-->
<template>
  <div class="teacher-col-header" :class="{ 'tch-compact': compact }" :style="{ borderTopColor: color }">
    <div class="teacher-col-avatar" :style="{ background: color }">{{ initial }}</div>
    <div class="teacher-col-info">
      <div class="teacher-col-name">{{ name }}</div>
      <div v-if="room" class="teacher-col-room">{{ room }}</div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  name: { type: String, default: '' },
  room: { type: String, default: '' },
  color: { type: String, default: '#90A4AE' },
  compact: { type: Boolean, default: false },
});

const initial = computed(() => (props.name ? props.name.charAt(0) : ''));
</script>

<style scoped>
.teacher-col-header {
  height: 64px;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  background: var(--bg-muted, var(--ds-canvas-soft));
  border-bottom: 1px solid var(--border-color, var(--ds-canvas-soft));
  border-top: 3px solid transparent;
  position: sticky;
  top: 0;
  z-index: 10;
  box-shadow: 0 1px 0 rgba(15, 23, 42, 0.06);
}
.teacher-col-avatar {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--ds-canvas);
  font-weight: 700;
  font-size: 14px;
  flex-shrink: 0;
}
.teacher-col-info { min-width: 0; flex: 1; }
.teacher-col-name {
  font-size: 13px;
  font-weight: 700;
  line-height: 1.3;
  color: var(--text-color, var(--ds-ink));
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.teacher-col-room {
  font-size: 10px;
  line-height: 1.3;
  color: var(--text-light, var(--ds-ink-mute));
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* compact 變體：原為祖先選擇器 .teacher-grid-compact .teacher-col-header，改為 prop 驅動 */
.teacher-col-header.tch-compact {
  height: 56px;
  padding: 6px 6px;
  gap: 5px;
}
.teacher-col-header.tch-compact .teacher-col-avatar {
  width: 24px;
  height: 24px;
  font-size: 11px;
  border-radius: 6px;
}
.teacher-col-header.tch-compact .teacher-col-name { font-size: 12px; }
.teacher-col-header.tch-compact .teacher-col-room { font-size: 9px; }

@media (max-width: 900px) {
  .teacher-col-header { height: 56px; padding: 6px; gap: 4px; overflow: hidden; }
  .teacher-col-avatar { width: 26px; height: 26px; font-size: 11px; border-radius: 6px; }
  .teacher-col-name { font-size: 11px; }
  .teacher-col-room { font-size: 9px; }
}
@media (max-width: 768px) {
  .teacher-col-header { padding: 6px 6px; gap: 4px; height: 56px; }
  .teacher-col-avatar { width: 26px; height: 26px; font-size: 12px; }
  .teacher-col-name { font-size: 11px; }
  .teacher-col-room { font-size: 9px; }
}
@media (max-width: 640px) {
  .teacher-col-header { height: 48px; padding: 4px; }
  .teacher-col-avatar { width: 22px; height: 22px; font-size: 10px; border-radius: 6px; }
  .teacher-col-name { font-size: 10px; }
  .teacher-col-room { font-size: 8px; }
}
</style>
