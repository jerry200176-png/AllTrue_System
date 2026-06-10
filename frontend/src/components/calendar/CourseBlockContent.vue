<!--
  CourseBlockContent（#740 Step 5 — Presentational，自 SmartCalendar.vue 剝離；去重日檢視＋週檢視兩處課程卡內容）
  multi-root fragment：外層 .course-block（含拖曳/點擊/inline style）仍由父層持有，本元件只渲染卡片「內容」。
  ⚠️ 解耦設計（Linear/Airbnb 實戰）：原本依賴祖先狀態的選擇器全部改為 class 旗標 prop 驅動：
    - .teacher-grid-compact .cb-*            → layout.compact  → .cbc-compact
    - .course-block:has(.rc-tag) .cb-student → 由 badges 推導 → .cbc-has-rc
    - .slot:has(.capacity-badge) .course-block:first-of-type .cb-student → layout.firstBadge → .cbc-badge-*
  rc-tag 系列在此自帶一份 scoped 樣式（父層 legend 仍保留自己那份，互不污染）。
  API 僅 3 props：course / badges / layout。
-->
<template>
  <div v-if="badges.teacherTag" class="cb-teacher-tag" :style="{ background: badges.teacherTag.color }">{{ badges.teacherTag.name }}</div>
  <div class="cb-student" :class="studentClass">{{ course.student_name }}</div>
  <div class="cb-detail" :class="{ 'cbc-compact': layout.compact }">{{ subjectLabel }}</div>
  <div class="cb-type" :class="{ 'cbc-compact': layout.compact }">{{ typeLabel }}</div>
  <span
    v-if="badges.rollCall"
    class="rc-tag"
    :class="['rc-' + badges.rollCall.kind, { 'cbc-compact': layout.compact }]"
  >{{ badges.rollCall.label }}</span>
  <span
    v-if="badges.evalMissing"
    class="rc-tag rc-eval-missing"
    :class="{ 'rc-tag-second': !!badges.rollCall, 'cbc-compact': layout.compact }"
  >{{ badges.evalMissing.label }}</span>
</template>

<script setup>
import { computed } from 'vue';
import { getSubjectLabel } from '../../lib/constants';
import { classTypeLabel } from '../../lib/calendarFormat.js';

const props = defineProps({
  course: { type: Object, required: true },
  // { rollCall: {kind,label}|null, evalMissing: {label}|null, teacherTag: {name,color}|null }
  badges: { type: Object, default: () => ({}) },
  // { compact: boolean, firstBadge: 'full' | 'compact' | null }
  layout: { type: Object, default: () => ({}) },
});

const subjectLabel = computed(() => getSubjectLabel(props.course.subject));
const typeLabel = computed(() => classTypeLabel(props.course.class_type));
const hasRc = computed(() => !!(props.badges.rollCall || props.badges.evalMissing));

const studentClass = computed(() => ({
  'cbc-compact': !!props.layout.compact,
  'cbc-has-rc': hasRc.value,
  'cbc-badge-full': props.layout.firstBadge === 'full',
  'cbc-badge-compact-pad': props.layout.firstBadge === 'compact',
}));
</script>

<style scoped>
.cb-teacher-tag {
  font-size: 10px;
  font-weight: 700;
  color: #fff;
  border-radius: 3px;
  padding: 1px 4px;
  margin-bottom: 1px;
  line-height: 1.3;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.cb-student {
  font-weight: 800;
  font-size: 14px;
  line-height: 1.2;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  display: block;
  letter-spacing: -0.2px;
  text-shadow: 0 1px 2px rgba(15, 23, 42, 0.18);
}
.cb-detail {
  font-size: 9px;
  line-height: 1.2;
  opacity: 0.9;
  margin-top: 1px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  display: block;
}
.cb-type {
  font-size: 9px;
  line-height: 1.2;
  opacity: 0.78;
  margin-top: 1px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  display: block;
}

/* rc-tag 系列（自帶一份，父層 legend 仍保留自己那份） */
.rc-tag {
  position: absolute;
  top: 2px;
  right: 3px;
  font-size: 9px;
  font-weight: 700;
  line-height: 1;
  padding: 1px 4px;
  border-radius: 4px;
  pointer-events: none;
}
.rc-done  { background: rgba(34,197,94,.85); color: #fff; }
.rc-missed { background: rgba(245,158,11,.9); color: #fff; }
.rc-leave { background: rgba(148,163,184,.75); color: #fff; }
.rc-cancelled { background: rgba(100,116,139,.6); color: #fff; font-size: 8px; }
.rc-eval-missing { background: rgba(239,68,68,.85); color: #fff; }
.rc-tag-second { top: auto; bottom: 2px; }

/* ── 解耦：原 .teacher-grid-compact .cb-* → .cbc-compact ── */
.cb-student.cbc-compact {
  font-size: 12px;
  line-height: 1.15;
}
.cb-detail.cbc-compact,
.cb-type.cbc-compact {
  font-size: 8px;
  margin-top: 1px;
}
/* 解耦：原 .teacher-grid-compact .rc-tag */
.rc-tag.cbc-compact {
  font-size: 8px;
  padding: 0 2px;
  right: 2px;
}
/* 解耦：原 .teacher-grid-compact .rc-tag.rc-tag-second（維持底部，避免縱向撐開） */
.rc-tag.rc-tag-second.cbc-compact {
  top: auto;
  bottom: 2px;
}

/* ── 解耦：原 .course-block:has(.rc-tag) .cb-student → .cbc-has-rc ── */
.cb-student.cbc-has-rc { padding-right: 18px; }
.cb-student.cbc-has-rc.cbc-compact { padding-right: 10px; }

/* ── 解耦：原 .slot:has(.capacity-badge) .course-block:first-of-type .cb-student → .cbc-badge-* ── */
.cb-student.cbc-badge-full { padding-left: 20px; }
.cb-student.cbc-badge-compact-pad { padding-left: 12px; }

/* ── RWD cb-*（非祖先耦合，原樣搬移） ── */
@media (max-width: 900px) {
  .cb-student { font-size: 12px; }
  .cb-detail, .cb-type { font-size: 8px; margin-top: 1px; }
}
@media (max-width: 768px) {
  .cb-student { font-size: 12px; }
  .cb-detail, .cb-type { font-size: 8px; }
}
@media (max-width: 640px) {
  .cb-student { font-size: 11px; line-height: 1.15; }
  .cb-detail, .cb-type { font-size: 7px; }
}
</style>
