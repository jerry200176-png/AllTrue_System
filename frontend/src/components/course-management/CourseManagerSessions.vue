<script>
import CourseSessionCalendar from './CourseSessionCalendar.vue';

export default {
  name: 'CourseManagerSessions',
  components: { CourseSessionCalendar },
  props: {
    course: { type: Object, required: true },
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
    isSessionMode: { type: Boolean, default: false },
    isMonthlyMode: { type: Boolean, default: false },
    isManualOccurrence: { type: Boolean, default: false },
    formatSessionChipDate: { type: Function, required: true },
    getSessionStateClass: { type: Function, required: true },
    getSessionStateLabel: { type: Function, required: true },
    getSessionNumber: { type: Function, required: true },
    sessionRowKey: { type: Function, required: true },
    isUserNote: { type: Function, required: true },
    formatMakeupDate: { type: Function, required: true },
  },
  emits: ['action', 'open-session', 'create-day', 'toggle-cancelled', 'toggle-notes'],
  setup(props, { emit }) {
    const act = (name) => emit('action', { name });
    const scheduleLabel = () => {
      if (props.isManualOccurrence) return '＋新增下一堂';
      if (props.isMonthlyMode) return '排月結';
      return '排課';
    };
    return { act, scheduleLabel };
  },
};
</script>

<template>
  <div class="cm-sessions" data-testid="course-manager-sessions">
    <div class="cm-sessions__toolbar">
      <button
        v-if="isSessionMode || isMonthlyMode"
        type="button"
        class="small primary"
        @click="act(isManualOccurrence || isMonthlyMode || !isSessionMode ? 'manual-session' : 'manual-session')"
      >{{ scheduleLabel() }}</button>
      <button
        v-if="isSessionMode && canQuickAdd"
        type="button"
        class="small ghost"
        @click="act('quick-add')"
      >補課 / 補登</button>
      <button
        v-else-if="isMonthlyMode"
        type="button"
        class="small ghost"
        @click="act('monthly-session')"
      >新增月結堂次</button>
      <button type="button" class="small ghost" @click="$emit('toggle-notes')">
        {{ showSessionNotes ? '備註 ▲' : '備註 ▼' }}
      </button>
      <button
        v-if="cancelledUnits.length > 0"
        type="button"
        class="small ghost"
        @click="$emit('toggle-cancelled')"
      >
        {{ showCancelled ? '已調走／取消 ▲' : `含 ${cancelledUnits.length} 堂已調走／取消 ▼` }}
      </button>
    </div>

    <p v-if="sessionLoadFailed || planningStatus" class="cm-sessions__status" role="status">
      <strong>{{ sessionLoadFailed ? '堂次載入失敗' : planningStatus?.title }}</strong>
      — {{ sessionLoadFailed ? '目前無法確認最新堂次狀態。' : planningStatus?.message }}
      <button
        v-if="sessionLoadFailed"
        type="button"
        class="small primary"
        @click="act('retry-sessions')"
      >重新載入</button>
      <button
        v-else-if="['quick_add','arrange_makeup'].includes(planningStatus?.action) && canQuickAdd"
        type="button"
        class="small primary"
        @click="act(planningStatus?.action === 'arrange_makeup' ? 'quick-add' : 'quick-add')"
      >{{ planningStatus?.action === 'arrange_makeup' ? '安排補課' : '補排堂次' }}</button>
    </p>

    <CourseSessionCalendar
      v-if="calendarEnabled"
      :course="course"
      :sessions="sessionUnits"
      :create-enabled="createEnabled"
      @create-day="(payload) => $emit('create-day', payload)"
      @quick-add="act('quick-add')"
    />
    <p v-else class="cm-sessions__hint">行事曆功能尚未啟用；仍可使用下方堂次列表。</p>

    <div v-if="sessionUnits.length" class="dates-chip-grid">
      <button
        v-for="u in sessionUnits"
        :key="sessionRowKey(u)"
        type="button"
        :class="[
          'date-chip',
          'date-chip-clickable',
          u.isProjected ? 'date-chip--projected' : 'date-chip--materialized',
          getSessionStateClass(course, (u.date || '').slice(0, 10), u.id),
        ]"
        @click="$emit('open-session', { unit: u, date: (u.date || '').slice(0, 10), id: u.id })"
      >
        <template v-if="getSessionNumber(course, (u.date || '').slice(0, 10), u.id)">
          <span class="chip-seq">第{{ getSessionNumber(course, (u.date || '').slice(0, 10), u.id) }}堂</span>
        </template>
        <span class="chip-date">{{ formatSessionChipDate(u) }}</span>
        <template v-if="u.isProjected"><span class="chip-state chip-state--projected">預排</span></template>
        <template v-else-if="getSessionStateLabel(course, (u.date || '').slice(0, 10), u.id)">
          <span class="chip-state">{{ getSessionStateLabel(course, (u.date || '').slice(0, 10), u.id) }}</span>
        </template>
        <template v-if="showSessionNotes && isUserNote(u.note)">
          <span class="chip-note-text">{{ u.note }}</span>
        </template>
      </button>
    </div>
    <p v-else class="cm-sessions__hint">尚無可顯示堂次（請確認排課設定）。</p>

    <div v-if="showCancelled && cancelledUnits.length" class="dates-chip-grid cancelled-sessions-grid">
      <span
        v-for="u in cancelledUnits"
        :key="'cx-' + sessionRowKey(u)"
        class="date-chip cancelled"
      >
        <span class="chip-date">{{ formatSessionChipDate(u) }}</span>
        <span class="chip-state">已取消</span>
      </span>
    </div>

    <div v-if="pendingMakeups.length" class="pending-makeups-panel">
      <strong>待補課（{{ pendingMakeups.length }} 堂）</strong>
      <div
        v-for="ms in pendingMakeups"
        :key="ms.id"
        class="pending-makeup-row"
      >
        <span>{{ formatMakeupDate(ms) }}</span>
        <button type="button" class="small pending-makeup-cancel" @click="$emit('action', { name: 'cancel-makeup', payload: ms })">取消補課</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cm-sessions { display: grid; gap: 12px; }
.cm-sessions__toolbar { display: flex; flex-wrap: wrap; gap: 8px; }
.cm-sessions__status,
.cm-sessions__hint { margin: 0; color: #57534e; font-size: 0.875rem; }
.pending-makeups-panel {
  border: 1px solid #e7e2da;
  border-radius: 8px;
  padding: 10px 12px;
  background: #fffdf9;
}
.pending-makeup-row {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  padding: 6px 0;
  border-top: 1px solid #efeae3;
}
</style>
