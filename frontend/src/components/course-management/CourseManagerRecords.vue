<script>
import { computed } from 'vue';

export default {
  name: 'CourseManagerRecords',
  props: {
    course: { type: Object, required: true },
    sessionUnits: { type: Array, default: () => [] },
    cancelledUnits: { type: Array, default: () => [] },
    formatSessionChipDate: { type: Function, required: true },
    getSessionStateLabel: { type: Function, required: true },
    sessionRowKey: { type: Function, required: true },
  },
  setup(props) {
    const completed = computed(() =>
      (props.sessionUnits || []).filter((u) => !u.isProjected && ['completed', 'attended', 'done'].includes(
        String(props.getSessionStateLabel(props.course, (u.date || '').slice(0, 10), u.id) || '').toLowerCase(),
      ) || (!u.isProjected && !u.isCancelled)),
    );
    // Prefer explicit completed labels when present; otherwise list materialized non-cancelled units as history candidates.
    const materialized = computed(() => (props.sessionUnits || []).filter((u) => !u.isProjected));
    return { completed, materialized };
  },
};
</script>

<template>
  <div class="cm-records" data-testid="course-manager-records">
    <section class="cm-card">
      <h3 class="cm-card__title">已建立堂次</h3>
      <p v-if="!materialized.length" class="cm-records__empty">尚無可列示的已建立堂次。</p>
      <ul v-else class="cm-records__list">
        <li v-for="u in materialized" :key="sessionRowKey(u)">
          <span>{{ formatSessionChipDate(u) }}</span>
          <span>{{ getSessionStateLabel(course, (u.date || '').slice(0, 10), u.id) || '已建立' }}</span>
        </li>
      </ul>
    </section>
    <section class="cm-card">
      <h3 class="cm-card__title">已取消／已調走</h3>
      <p v-if="!cancelledUnits.length" class="cm-records__empty">沒有已取消或已調走堂次。</p>
      <ul v-else class="cm-records__list">
        <li v-for="u in cancelledUnits" :key="'r-' + sessionRowKey(u)">
          <span>{{ formatSessionChipDate(u) }}</span>
          <span>已取消</span>
        </li>
      </ul>
    </section>
    <p class="cm-records__hint">僅顯示目前已有的堂次資料；不新建事件溯源後端。</p>
  </div>
</template>

<style scoped>
.cm-records { display: grid; gap: 14px; max-width: 760px; }
.cm-card {
  background: #fffdf9;
  border: 1px solid #e7e2da;
  border-radius: 10px;
  padding: 14px 16px;
}
.cm-card__title { margin: 0 0 10px; font-size: 0.95rem; font-weight: 650; }
.cm-records__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 6px;
}
.cm-records__list li {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-size: 0.9rem;
  padding: 4px 0;
  border-bottom: 1px solid #efeae3;
}
.cm-records__empty,
.cm-records__hint { margin: 0; color: #78716c; font-size: 0.85rem; }
</style>
