<script>
export default {
  name: 'CourseManagerOverview',
  props: {
    course: { type: Object, required: true },
    studentName: { type: String, default: '' },
    subjectLabel: { type: String, default: '' },
    classTypeLabel: { type: String, default: '' },
    statusLabel: { type: String, default: '' },
    scheduleSummary: { type: String, default: '' },
    remainingLabel: { type: String, default: '' },
    nextSessionLabel: { type: String, default: '' },
    paymentLabel: { type: String, default: '' },
    needs: { type: Array, default: () => [] },
    canClose: { type: Boolean, default: false },
  },
  emits: ['action'],
  setup(_, { emit }) {
    const act = (name) => emit('action', { name });
    return { act };
  },
};
</script>

<template>
  <div class="cm-overview" data-testid="course-manager-overview">
    <section class="cm-card">
      <h3 class="cm-card__title">這門課</h3>
      <dl class="cm-facts">
        <div><dt>學生</dt><dd>{{ studentName || '—' }}</dd></div>
        <div><dt>科目</dt><dd>{{ subjectLabel || '—' }}</dd></div>
        <div><dt>狀態</dt><dd>{{ statusLabel || '—' }}</dd></div>
        <div><dt>班型</dt><dd>{{ classTypeLabel || '—' }}</dd></div>
        <div><dt>老師</dt><dd>{{ course.teacher_name || '待指派' }}</dd></div>
        <div><dt>教室</dt><dd>{{ course.room_name || '—' }}</dd></div>
        <div><dt>排課</dt><dd>{{ scheduleSummary || '未排定' }}</dd></div>
        <div><dt>堂次</dt><dd>{{ remainingLabel || '—' }}</dd></div>
        <div><dt>下一堂</dt><dd>{{ nextSessionLabel || '—' }}</dd></div>
        <div><dt>繳費</dt><dd>{{ paymentLabel || '—' }}</dd></div>
      </dl>
    </section>

    <section v-if="needs.length" class="cm-card cm-card--needs">
      <h3 class="cm-card__title">需要處理</h3>
      <ul class="cm-needs">
        <li v-for="n in needs" :key="n.id" class="cm-needs__item">
          <div>
            <strong>{{ n.title }}</strong>
            <p v-if="n.detail">{{ n.detail }}</p>
          </div>
          <button
            v-if="n.action"
            type="button"
            class="small primary"
            @click="act(n.action)"
          >{{ n.actionLabel || '前往' }}</button>
        </li>
      </ul>
    </section>

    <section class="cm-card">
      <h3 class="cm-card__title">課程狀態</h3>
      <p class="cm-lifecycle__now">目前：{{ statusLabel }}</p>
      <div class="cm-lifecycle__actions">
        <button
          v-if="course.status !== 'inactive'"
          type="button"
          class="small ghost"
          @click="act('pause')"
        >暫停課程</button>
        <button
          v-else
          type="button"
          class="small primary"
          @click="act('resume')"
        >恢復課程</button>
        <button
          v-if="canClose"
          type="button"
          class="small ghost"
          title="保留已上課與付款紀錄，停止後續排課與續課提醒"
          @click="act('close')"
        >結束課程</button>
      </div>
      <p class="cm-lifecycle__hint">結束後保留歷史上課與帳務紀錄，停止後續排課與續課提醒。</p>
    </section>

    <section class="cm-card cm-card--danger">
      <h3 class="cm-card__title">危險操作</h3>
      <p>刪除課程僅限符合既有安全條件的課程。</p>
      <button type="button" class="small danger" @click="act('delete')">刪除課程</button>
    </section>
  </div>
</template>

<style scoped>
.cm-overview { display: grid; gap: 14px; max-width: 760px; }
.cm-card {
  background: #fffdf9;
  border: 1px solid #e7e2da;
  border-radius: 10px;
  padding: 14px 16px;
}
.cm-card__title {
  margin: 0 0 10px;
  font-size: 0.95rem;
  font-weight: 650;
}
.cm-facts {
  margin: 0;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 10px 14px;
}
.cm-facts dt {
  font-size: 0.72rem;
  color: #78716c;
  margin-bottom: 2px;
}
.cm-facts dd { margin: 0; font-size: 0.92rem; }
.cm-needs { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.cm-needs__item {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: center;
  padding: 8px 0;
  border-top: 1px solid #efeae3;
}
.cm-needs__item:first-child { border-top: 0; padding-top: 0; }
.cm-needs__item p { margin: 2px 0 0; color: #57534e; font-size: 0.85rem; }
.cm-lifecycle__now { margin: 0 0 10px; }
.cm-lifecycle__actions { display: flex; flex-wrap: wrap; gap: 8px; }
.cm-lifecycle__hint { margin: 10px 0 0; color: #78716c; font-size: 0.82rem; }
.cm-card--danger { border-color: #e8c5c0; }
.cm-card--danger .cm-card__title { color: #9f2d2d; }
button.danger {
  border: 1px solid #c45c5c;
  background: #fff;
  color: #9f2d2d;
  border-radius: 6px;
  padding: 4px 10px;
  cursor: pointer;
}
</style>
