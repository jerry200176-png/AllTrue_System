<template>
  <div v-if="show" class="modal-overlay" @click.self="close">
    <div ref="panel" class="modal credit-dialog" role="dialog" aria-modal="true" aria-labelledby="credit-title" tabindex="-1" @keydown="keydown">
      <h3 id="credit-title">跨科轉用與紀錄</h3>
      <p data-testid="credit-student"><strong>學生：{{ studentName || '學生資料未完整載入，請關閉後重開' }}</strong></p>
      <fieldset class="choices" :disabled="locked || loading">
        <legend>這次要怎麼使用已付學費？</legend>
        <label><input id="credit-mode-new" v-model="mode" type="radio" value="new" :disabled="locked || loading" /> 轉到另一科的新批次</label>
        <label><input id="credit-mode-existing" v-model="mode" type="radio" value="existing" :disabled="locked || loading" /> 抵付已建立的課程，不再多收款</label>
      </fieldset>
      <template v-if="mode === 'new'">
      <p class="muted">{{ course.subject_name || '這科' }}的已付費剩餘堂數，轉到同一學生、同校區、同單價與堂長的另一科新批次。舊欠款不會抵銷，已上課與原收據保留。</p>
      <label for="credit-target">轉到哪一科（沿用該合約教師）</label>
      <select id="credit-target" v-model="form.target" :disabled="locked">
        <option value="">請選另一科合約</option>
        <option v-for="c in candidates" :key="c.id ?? c.ID" :value="c.id ?? c.ID">{{ creditCourseLabel(c) }}</option>
      </select>
      <p v-if="!loading && !candidates.length" class="muted">沒有可選的另一科合約。可先查看下方轉用紀錄；不同單價或堂長仍不能轉用。</p>
      <label for="credit-sessions">轉用堂數（正整數）</label>
      <input id="credit-sessions" v-model.number="form.sessions" type="number" min="1" max="10000" step="1" :disabled="locked" />
      </template>
      <fieldset v-else class="choices" :disabled="locked || loading">
        <legend>要抵付哪些已建立的課程？</legend>
        <p class="muted">限同一學生、同校區、同單價與堂長，且尚未開單或收款的課程。保留已上課，不新增目的課程。已改成免費或帳款不一致時須先核對。</p>
        <label v-for="c in candidates" :key="c.id ?? c.ID"><input :id="`credit-existing-${c.id ?? c.ID}`" v-model="form.targets" type="checkbox" :value="Number(c.id ?? c.ID)" :disabled="locked || loading" /> {{ creditCourseLabel(c) }} {{ creditCourseDetails(c) }}</label>
        <p v-if="!loading && !candidates.length" class="muted">沒有可選的另一科課程，請先確認這位學生的課程資料。</p>
      </fieldset>
      <label for="credit-reason">轉用原因（必填）</label>
      <textarea id="credit-reason" v-model="form.reason" rows="2" maxlength="100" :disabled="locked" />
      <section v-if="quote && mode === 'new'" class="summary" data-testid="credit-preview" aria-label="本次轉用試算">
        <p>學生：{{ studentName || '學生資料未完整載入' }}</p>
        <p>已購 {{ quote.plan.purchased_sessions }} 堂，已用 {{ quote.plan.used_sessions }} 堂；保留排課 {{ quote.plan.reserved_sessions }} 堂，可轉 {{ quote.plan.available_sessions }} 堂。</p>
        <p>每堂 {{ quote.plan.lesson_minutes }} 分鐘，原約定單價 {{ quote.plan.unit_price }} 元。</p>
        <p><strong>這次轉 {{ quote.plan.moved_sessions }} 堂／{{ money(quote.plan.amount) }} 元</strong> → {{ creditCourseLabel(candidates.find(c => Number(c.id ?? c.ID) === Number(form.target))) }}</p>
        <p>目的科舊欠款：{{ quote.plan.destination_existing_outstanding == null ? '須另行核對，這次不變更' : `${money(quote.plan.destination_existing_outstanding)} 元，這次不變更` }}。新批次需另外排課。</p>
        <p v-if="!quote.execution_available" class="muted">目前只能試算，尚不能執行；請先完成資料結構更新或核對堂數。</p>
      </section>
      <section v-else-if="quote" class="summary" data-testid="credit-preview" aria-label="本次抵付與排課影響">
        <p><strong>{{ studentName }} · {{ course.subject_name || '來源課程' }} {{ creditCourseDetails(course) }}</strong></p>
        <ul><li v-for="a in quote.plan.allocations" :key="a.id">{{ label(a.id, true) }}：{{ a.sessions }} 堂／{{ money(a.amount) }} 元（已上 {{ a.used }} 堂保留）</li></ul>
        <p><strong>本次抵付 {{ money(quote.plan.allocations.reduce((sum, a) => sum + a.amount, 0)) }} 元，不另外收款。</strong></p>
        <p>來源保留 {{ quote.plan.source_sessions }} 堂，已上 {{ quote.plan.source_used }} 堂，剩餘 {{ quote.plan.source_remaining }} 堂；保留學費 {{ money(quote.plan.source_amount) }} 元。</p>
        <template v-if="quote.released_lessons.length">
          <p><strong>抵付後需取消以下 {{ quote.released_lessons.length }} 堂未來排課：</strong></p>
          <ul><li v-for="s in quote.released_lessons" :key="s.id">{{ s.date }} {{ String(s.start_time).slice(0, 5) }}–{{ String(s.end_time).slice(0, 5) }}</li></ul>
          <label class="acknowledge"><input id="credit-release-confirm" v-model="releaseConfirmed" type="checkbox" :disabled="locked" /> 我已核對以上日期，同意釋出這些尚未上課的排課。</label>
        </template>
        <p v-else>不需取消任何已排定課程。</p>
        <p v-if="!quote.execution_available" class="muted">目前只能預覽，尚不能抵付；請稍後重新核對。</p>
      </section>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <p v-if="notice" class="summary" role="status">{{ notice }}</p>
      <div class="actions">
        <button class="ghost" :disabled="locked || !valid || loading" @click="preview">{{ mode === 'existing' ? '預覽抵付與排課影響' : '試算轉用' }}</button>
        <button v-if="!pending" class="primary" :disabled="!canConfirm" @click="confirm">{{ mode === 'existing' ? '確認抵付既有課程' : '確認跨科轉用' }}</button>
        <button v-else class="primary" :disabled="busy || !identityReady" @click="retry">重試確認結果</button>
      </div>
      <section class="history" aria-labelledby="credit-history-title" :aria-busy="loading">
        <h4 id="credit-history-title">{{ mode === 'existing' ? '既有課程整批抵付紀錄' : '跨科轉用紀錄' }}</h4>
        <p class="muted">{{ mode === 'existing' ? '須整批還原，帳務、上課或原時段已變動時會阻擋；不會抹除原收款與歷史。' : '只可還原尚未使用、未排課且沒有後續帳務變動的新批次；送出時會重新核對。' }}</p>
        <p v-if="historyError" class="error" role="alert">{{ historyError }} <button class="ghost" :disabled="busy" @click="loadHistory(page)">重讀紀錄</button></p>
        <p v-else-if="loading" role="status">載入紀錄中…</p>
        <p v-else-if="!history.length" class="muted">尚無跨科轉用紀錄。</p>
        <ol>
          <li v-for="r in history" :key="r.id">
            <p>{{ label(r.source_course_id, mode === 'existing') }} → {{ mode === 'existing' ? r.targets.map(t => label(t.id, true)).join('、') : label(r.destination_template_course_id) }}</p>
            <p><strong>{{ r.moved_sessions ?? r.sessions }} 堂／{{ money(r.amount) }} 元 · {{ r.reversed ? '已還原' : '已轉用' }}</strong></p>
            <p class="muted">{{ r.created_at }} {{ r.reason }}</p>
            <p v-if="r.reversed" class="muted">{{ r.reversed_at }} 還原原因：{{ r.reverse_reason }}</p>
            <p v-else-if="mode === 'new' && r.allocation_group_id" class="muted">這是整批抵付的一部分，請切換到「抵付已建立的課程」查看與還原整批。</p>
            <button v-else class="ghost" :disabled="locked || !identityReady" @click="askReverse(r)">{{ mode === 'existing' ? '還原整批抵付' : '還原這筆轉用' }}</button>
          </li>
        </ol>
        <div v-if="lastPage > 1" class="actions" aria-label="轉用紀錄分頁">
          <button class="ghost" :disabled="page <= 1 || locked" @click="loadHistory(page - 1)">上一頁</button>
          <span>{{ page }} / {{ lastPage }}</span>
          <button class="ghost" :disabled="page >= lastPage || locked" @click="loadHistory(page + 1)">下一頁</button>
        </div>
        <div v-if="reversing" class="summary" role="group" aria-label="還原確認">
          <p>確認還原{{ studentName }}的 {{ reversing.moved_sessions ?? reversing.sessions }} 堂／{{ money(reversing.amount) }} 元？款項會回到原來源科；轉用歷史保留。</p>
          <label for="credit-reverse-reason">還原原因（必填）</label>
          <textarea id="credit-reverse-reason" v-model="reverseReason" rows="2" maxlength="100" :disabled="locked" />
          <div class="actions">
            <button class="ghost" :disabled="locked" @click="reversing = null">保留轉用</button>
            <button class="primary" :disabled="locked || !identityReady || !reverseReason.trim()" @click="confirmReverse">確認還原</button>
          </div>
        </div>
      </section>
      <div class="actions"><button class="ghost" :disabled="busy" @click="close">關閉</button></div>
    </div>
  </div>
</template>
<script setup>
import { nextTick, ref, watch } from 'vue';
import { trapTab } from '../../lib/focusTrap.js';
import { creditCourseDetails, creditCourseLabel, useSubjectCreditTransfer } from '../../composables/course-management/useSubjectCreditTransfer.js';
const props = defineProps({ show: Boolean, course: { type: Object, default: () => ({}) } });
const emit = defineEmits(['close', 'changed']);
const { form, mode, releaseConfirmed, quote, error, notice, busy, loading, locked, studentName, identityReady, candidates, history, historyError, page, lastPage,
  reversing, reverseReason, pending, valid, canConfirm, label, loadHistory, preview, confirm, askReverse, confirmReverse, retry } = useSubjectCreditTransfer(props, () => emit('changed'));
const panel = ref(null);
let opener;
const money = n => Number(n).toLocaleString('zh-TW');
const close = () => { if (!busy.value) emit('close'); };
const keydown = e => { if (e.key === 'Tab') trapTab(e, panel.value); else if (e.key === 'Escape') close(); };
watch(reversing, async record => { if (record) { await nextTick(); panel.value?.querySelector('#credit-reverse-reason')?.focus(); } });
const focusStart = () => (panel.value?.querySelector('input[type="radio"]:checked:not(:disabled)') || panel.value)?.focus();
watch(loading, async value => { if (!value && props.show && document.activeElement === panel.value) { await nextTick(); focusStart(); } });
watch(() => props.show, async show => {
  if (show) { opener = document.activeElement; await nextTick(); focusStart(); }
  else { if (opener?.isConnected) opener.focus(); opener = null; }
});
</script>
<style scoped>
.credit-dialog { width: 100%; max-width: 580px; max-height: 90dvh; overflow-y: auto; overflow-wrap: anywhere; }
h3, h4 { margin: 0 0 var(--ds-space-2); font-weight: var(--ds-font-weight-bold); }
p { margin: var(--ds-space-2) 0; line-height: 1.6; }
.muted { color: var(--ds-ink-secondary); font-size: var(--ds-font-size-sm); }
label { display: block; margin: var(--ds-space-3) 0 var(--ds-space-2); font-weight: var(--ds-font-weight-bold); }
select, input, textarea { width: 100%; min-height: 44px; padding: var(--ds-space-2); border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-md); font: inherit; background: var(--ds-canvas); color: var(--ds-ink); }
.summary { margin-top: var(--ds-space-3); padding: var(--ds-space-3); background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-md); }
.error { padding: var(--ds-space-3); background: var(--ds-danger-wash); color: var(--ds-danger); }
.actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: var(--ds-space-2); margin-top: var(--ds-space-3); }
button { min-height: 44px; white-space: normal; }
.history { margin-top: var(--ds-space-5); border-top: 1px solid var(--ds-hairline); padding-top: var(--ds-space-4); }
ol { list-style: none; margin: 0; padding: 0; }
li { padding: var(--ds-space-3) 0; border-bottom: 1px solid var(--ds-hairline); }
.choices { margin: var(--ds-space-3) 0; padding: var(--ds-space-2); border: 1px solid var(--ds-hairline); }
.choices label, .acknowledge { display: flex; align-items: center; gap: var(--ds-space-2); min-height: 44px; font-size: inherit; font-weight: inherit; }
.choices input, .acknowledge input { width: 18px; min-height: 18px; flex: 0 0 18px; }
.summary { font-variant-numeric: tabular-nums; }
</style>
