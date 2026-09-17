<script>
/**
 * COURSE_MANAGER_V1 shell — one selected course, tabbed domains.
 * Emits existing handler names; parent retains API authority.
 */
import { computed, watch } from 'vue';
import CourseManagerOverview from './CourseManagerOverview.vue';
import CourseManagerSessions from './CourseManagerSessions.vue';
import CourseManagerBilling from './CourseManagerBilling.vue';
import CourseManagerRecords from './CourseManagerRecords.vue';

const TABS = [
  { id: 'overview', label: '總覽' },
  { id: 'sessions', label: '排課與堂次' },
  { id: 'settings', label: '課程設定' },
  { id: 'billing', label: '帳務與合約' },
  { id: 'records', label: '紀錄' },
];

export default {
  name: 'CourseManager',
  components: {
    CourseManagerOverview,
    CourseManagerSessions,
    CourseManagerBilling,
    CourseManagerRecords,
  },
  props: {
    course: { type: Object, required: true },
    tab: { type: String, default: 'overview' },
    studentName: { type: String, default: '' },
    subjectLabel: { type: String, default: '' },
    classTypeLabel: { type: String, default: '' },
    statusLabel: { type: String, default: '' },
    scheduleSummary: { type: String, default: '' },
    remainingLabel: { type: String, default: '' },
    nextSessionLabel: { type: String, default: '' },
    paymentLabel: { type: String, default: '' },
    overviewNeeds: { type: Array, default: () => [] },
    sessionUnits: { type: Array, default: () => [] },
    cancelledUnits: { type: Array, default: () => [] },
    pendingMakeups: { type: Array, default: () => [] },
    calendarEnabled: { type: Boolean, default: false },
    createEnabled: { type: Boolean, default: false },
    showCancelled: { type: Boolean, default: false },
    showSessionNotes: { type: Boolean, default: false },
    sessionLoadFailed: { type: Boolean, default: false },
    planningStatus: { type: Object, default: null },
    canQuickAdd: { type: Boolean, default: false },
    canClose: { type: Boolean, default: false },
    isSessionMode: { type: Boolean, default: false },
    isMonthlyMode: { type: Boolean, default: false },
    isManualOccurrence: { type: Boolean, default: false },
    purchaseLabel: { type: String, default: '加購堂數' },
    paymentNoticeAvailable: { type: Boolean, default: false },
    canPackagePreview: { type: Boolean, default: false },
    formatSessionChipDate: { type: Function, required: true },
    getSessionStateClass: { type: Function, required: true },
    getSessionStateLabel: { type: Function, required: true },
    getSessionNumber: { type: Function, required: true },
    sessionRowKey: { type: Function, required: true },
    isUserNote: { type: Function, required: true },
    formatMakeupDate: { type: Function, required: true },
  },
  emits: [
    'close',
    'update:tab',
    'action',
    'open-session',
    'create-day',
    'toggle-cancelled',
    'toggle-notes',
  ],
  setup(props, { emit }) {
    const tabs = TABS;
    const activeTab = computed({
      get: () => props.tab,
      set: (v) => emit('update:tab', v),
    });

    function close() {
      emit('close');
    }

    function onAction(evt) {
      if (typeof evt === 'string') {
        emit('action', { name: evt });
        return;
      }
      emit('action', evt && typeof evt === 'object' ? evt : { name: evt });
    }

    function onKeydown(e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        close();
      }
    }

    watch(
      () => props.course?.id,
      () => {
        // Parent resets ephemeral tab/state; keep shell honest when course identity changes.
      },
    );

    return {
      tabs,
      activeTab,
      close,
      onAction,
      onKeydown,
    };
  },
};
</script>

<template>
  <div
    class="cm-workspace"
    role="dialog"
    aria-modal="true"
    :aria-label="`管理課程：${subjectLabel || '課程'}`"
    data-testid="course-manager"
    @keydown="onKeydown"
  >
    <div class="cm-workspace__scrim" @click="close" />
    <div class="cm-workspace__panel">
      <header class="cm-workspace__header">
        <button type="button" class="cm-workspace__back" data-testid="course-manager-close" @click="close">
          <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
          課程管理
        </button>
        <div class="cm-workspace__identity">
          <div class="cm-workspace__title-row">
            <h2 class="cm-workspace__title">
              <span v-if="studentName">{{ studentName }}</span>
              <span v-if="studentName" class="cm-workspace__dot">·</span>
              {{ subjectLabel }}
            </h2>
            <span class="cm-workspace__status" :data-status="course.status">{{ statusLabel }}</span>
          </div>
          <p class="cm-workspace__meta">
            {{ course.teacher_name || '待指派' }}
            <span class="cm-workspace__dot">·</span>
            {{ classTypeLabel }}
            <template v-if="course.room_name">
              <span class="cm-workspace__dot">·</span>
              {{ course.room_name }}
            </template>
          </p>
          <p class="cm-workspace__kpis">
            <span>{{ remainingLabel }}</span>
            <span v-if="nextSessionLabel">下堂 {{ nextSessionLabel }}</span>
            <span>{{ paymentLabel }}</span>
            <span v-if="scheduleSummary">{{ scheduleSummary }}</span>
          </p>
        </div>
      </header>

      <nav class="cm-workspace__tabs" aria-label="課程管理分區">
        <button
          v-for="t in tabs"
          :key="t.id"
          type="button"
          class="cm-workspace__tab"
          :class="{ 'is-active': activeTab === t.id }"
          :data-testid="`course-manager-tab-${t.id}`"
          :aria-selected="activeTab === t.id"
          @click="activeTab = t.id"
        >{{ t.label }}</button>
      </nav>

      <div class="cm-workspace__body">
        <CourseManagerOverview
          v-if="activeTab === 'overview'"
          :course="course"
          :student-name="studentName"
          :subject-label="subjectLabel"
          :class-type-label="classTypeLabel"
          :status-label="statusLabel"
          :schedule-summary="scheduleSummary"
          :remaining-label="remainingLabel"
          :next-session-label="nextSessionLabel"
          :payment-label="paymentLabel"
          :needs="overviewNeeds"
          :can-close="canClose"
          @action="onAction"
        />

        <CourseManagerSessions
          v-else-if="activeTab === 'sessions'"
          :course="course"
          :session-units="sessionUnits"
          :cancelled-units="cancelledUnits"
          :pending-makeups="pendingMakeups"
          :calendar-enabled="calendarEnabled"
          :create-enabled="createEnabled"
          :show-cancelled="showCancelled"
          :show-session-notes="showSessionNotes"
          :session-load-failed="sessionLoadFailed"
          :planning-status="planningStatus"
          :can-quick-add="canQuickAdd"
          :is-session-mode="isSessionMode"
          :is-monthly-mode="isMonthlyMode"
          :is-manual-occurrence="isManualOccurrence"
          :format-session-chip-date="formatSessionChipDate"
          :get-session-state-class="getSessionStateClass"
          :get-session-state-label="getSessionStateLabel"
          :get-session-number="getSessionNumber"
          :session-row-key="sessionRowKey"
          :is-user-note="isUserNote"
          :format-makeup-date="formatMakeupDate"
          @action="onAction"
          @open-session="(payload) => $emit('open-session', payload)"
          @create-day="(payload) => $emit('create-day', payload)"
          @toggle-cancelled="$emit('toggle-cancelled')"
          @toggle-notes="$emit('toggle-notes')"
        />

        <div v-else-if="activeTab === 'settings'" class="cm-settings" data-testid="course-manager-settings">
          <slot name="settings" />
        </div>

        <CourseManagerBilling
          v-else-if="activeTab === 'billing'"
          :course="course"
          :remaining-label="remainingLabel"
          :payment-label="paymentLabel"
          :purchase-label="purchaseLabel"
          :payment-notice-available="paymentNoticeAvailable"
          :can-package-preview="canPackagePreview"
          :is-session-mode="isSessionMode"
          @action="onAction"
        />

        <CourseManagerRecords
          v-else-if="activeTab === 'records'"
          :course="course"
          :session-units="sessionUnits"
          :cancelled-units="cancelledUnits"
          :format-session-chip-date="formatSessionChipDate"
          :get-session-state-label="getSessionStateLabel"
          :session-row-key="sessionRowKey"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.cm-workspace {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: flex;
  justify-content: flex-end;
}
.cm-workspace__scrim {
  position: absolute;
  inset: 0;
  background: rgba(18, 22, 28, 0.45);
}
.cm-workspace__panel {
  position: relative;
  display: flex;
  flex-direction: column;
  width: min(1120px, 100vw);
  height: 100%;
  background: #f7f5f1;
  color: #1c1917;
  box-shadow: -12px 0 40px rgba(28, 25, 23, 0.18);
}
.cm-workspace__header {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 14px 20px 10px;
  border-bottom: 1px solid #e7e2da;
  background: #fffdf9;
}
.cm-workspace__back {
  align-self: flex-start;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  border: 0;
  background: transparent;
  color: #57534e;
  font-size: 0.875rem;
  cursor: pointer;
  padding: 2px 0;
}
.cm-workspace__back:hover { color: #1c1917; }
.cm-workspace__title-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.cm-workspace__title {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 650;
  letter-spacing: -0.01em;
}
.cm-workspace__dot { opacity: 0.45; margin: 0 0.15em; }
.cm-workspace__status {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 0.75rem;
  background: #e7f0e8;
  color: #2f5d3a;
}
.cm-workspace__status[data-status="inactive"] {
  background: #f3ead8;
  color: #7a5b1e;
}
.cm-workspace__meta,
.cm-workspace__kpis {
  margin: 0;
  color: #57534e;
  font-size: 0.875rem;
  display: flex;
  flex-wrap: wrap;
  gap: 6px 14px;
}
.cm-workspace__tabs {
  display: flex;
  gap: 2px;
  padding: 0 12px;
  border-bottom: 1px solid #e7e2da;
  background: #fffdf9;
  overflow-x: auto;
}
.cm-workspace__tab {
  border: 0;
  background: transparent;
  padding: 12px 14px;
  font-size: 0.9rem;
  color: #57534e;
  cursor: pointer;
  border-bottom: 2px solid transparent;
  white-space: nowrap;
}
.cm-workspace__tab.is-active {
  color: #1c1917;
  border-bottom-color: #2f5d3a;
  font-weight: 600;
}
.cm-workspace__body {
  flex: 1;
  overflow: auto;
  padding: 16px 20px 28px;
}
.cm-settings { max-width: 760px; }

@media (max-width: 720px) {
  .cm-workspace__panel { width: 100vw; }
  .cm-workspace__body { padding: 12px 14px 24px; }
}
</style>
