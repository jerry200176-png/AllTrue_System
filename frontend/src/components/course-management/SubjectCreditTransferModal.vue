<template>
  <div v-if="show" class="modal-overlay" @click.self="close">
    <div ref="panel" class="modal credit-dialog" role="dialog" aria-modal="true" aria-labelledby="credit-title" tabindex="-1" @keydown="keydown">
      <h3 id="credit-title">跨科轉用與紀錄</h3>
      <p data-testid="credit-student"><strong>學生：{{ studentName || '學生資料未完整載入，請關閉後重開' }}</strong></p>
      <p class="muted">{{ course.subject_name || '這科' }}的已付費剩餘堂數，轉到同一學生、同校區、同單價與堂長的另一科新批次。舊欠款不會抵銷，已上課與原收據保留。</p>
      <label for="credit-target">轉到哪一科（沿用該合約教師）</label>
      <select id="credit-target" v-model="form.target" :disabled="locked">
        <option value="">請選另一科合約</option>
        <option v-for="c in candidates" :key="c.id ?? c.ID" :value="c.id ?? c.ID">{{ creditCourseLabel(c) }}</option>
      </select>
      <p v-if="!loading && !candidates.length" class="muted">沒有可選的另一科合約。可先查看下方轉用紀錄；不同單價或堂長仍不能轉用。</p>
      <label for="credit-sessions">轉用堂數（正整數）</label>
      <input id="credit-sessions" v-model.number="form.sessions" type="number" min="1" max="10000" step="1" :disabled="locked" />
      <label for="credit-reason">轉用原因（必填）</label>
      <textarea id="credit-reason" v-model="form.reason" rows="2" maxlength="100" :disabled="locked" />
      <section v-if="quote" class="summary" data-testid="credit-preview" aria-label="本次轉用試算">
        <p>學生：{{ studentName || '學生資料未完整載入' }}</p>
        <p>已購 {{ quote.plan.purchased_sessions }} 堂，已用 {{ quote.plan.used_sessions }} 堂；保留排課 {{ quote.plan.reserved_sessions }} 堂，可轉 {{ quote.plan.available_sessions }} 堂。</p>
        <p>每堂 {{ quote.plan.lesson_minutes }} 分鐘，原約定單價 {{ quote.plan.unit_price }} 元。</p>
        <p><strong>這次轉 {{ quote.plan.moved_sessions }} 堂／{{ money(quote.plan.amount) }} 元</strong> → {{ creditCourseLabel(candidates.find(c => Number(c.id ?? c.ID) === Number(form.target))) }}</p>
        <p>目的科舊欠款：{{ quote.plan.destination_existing_outstanding == null ? '須另行核對，這次不變更' : `${money(quote.plan.destination_existing_outstanding)} 元，這次不變更` }}。新批次需另外排課。</p>
        <p v-if="!quote.execution_available" class="muted">目前只能試算，尚不能執行；請先完成資料結構更新或核對堂數。</p>
      </section>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <p v-if="notice" class="summary" role="status">{{ notice }}</p>
      <div class="actions">
        <button class="ghost" :disabled="locked || !valid || loading" @click="preview">試算轉用</button>
        <button v-if="!pending" class="primary" :disabled="!canConfirm" @click="confirm">確認跨科轉用</button>
        <button v-else class="primary" :disabled="busy || !identityReady" @click="retry">重試確認結果</button>
      </div>
      <section class="history" aria-labelledby="credit-history-title" :aria-busy="loading">
        <h4 id="credit-history-title">跨科轉用紀錄</h4>
        <p class="muted">只可還原尚未使用、未排課且沒有後續帳務變動的新批次；送出時會重新核對。</p>
        <p v-if="historyError" class="error" role="alert">{{ historyError }} <button class="ghost" :disabled="busy" @click="loadHistory(page)">重讀紀錄</button></p>
        <p v-else-if="loading" role="status">載入紀錄中…</p>
        <p v-else-if="!history.length" class="muted">尚無跨科轉用紀錄。</p>
        <ol>
          <li v-for="r in history" :key="r.id">
            <p>{{ label(r.source_course_id) }} → {{ label(r.destination_template_course_id) }}</p>
            <p><strong>{{ r.moved_sessions }} 堂／{{ money(r.amount) }} 元 · {{ r.reversed ? '已還原' : '已轉用' }}</strong></p>
            <p class="muted">{{ r.created_at }} {{ r.reason }}</p>
            <p v-if="r.reversed" class="muted">{{ r.reversed_at }} 還原原因：{{ r.reverse_reason }}</p>
            <button v-else class="ghost" :disabled="locked || !identityReady" @click="askReverse(r)">還原這筆轉用</button>
          </li>
        </ol>
        <div v-if="lastPage > 1" class="actions" aria-label="轉用紀錄分頁">
          <button class="ghost" :disabled="page <= 1 || locked" @click="loadHistory(page - 1)">上一頁</button>
          <span>{{ page }} / {{ lastPage }}</span>
          <button class="ghost" :disabled="page >= lastPage || locked" @click="loadHistory(page + 1)">下一頁</button>
        </div>
        <div v-if="reversing" class="summary" role="group" aria-label="還原確認">
          <p>確認還原{{ studentName }}的 {{ reversing.moved_sessions }} 堂／{{ money(reversing.amount) }} 元？款項會回到原來源科；轉用歷史保留。</p>
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
import { creditCourseLabel, useSubjectCreditTransfer } from '../../composables/course-management/useSubjectCreditTransfer.js';
const props = defineProps({ show: Boolean, course: { type: Object, default: () => ({}) } });
const emit = defineEmits(['close', 'changed']);
const { form, quote, error, notice, busy, loading, locked, studentName, identityReady, candidates, history, historyError, page, lastPage,
  reversing, reverseReason, pending, valid, canConfirm, label, loadHistory, preview, confirm, askReverse, confirmReverse, retry } = useSubjectCreditTransfer(props, () => emit('changed'));
const panel = ref(null);
let opener;
const money = n => Number(n).toLocaleString('zh-TW');
const close = () => { if (!busy.value) emit('close'); };
const keydown = e => { if (e.key === 'Tab') trapTab(e, panel.value); else if (e.key === 'Escape') close(); };
watch(reversing, async record => { if (record) { await nextTick(); panel.value?.querySelector('#credit-reverse-reason')?.focus(); } });
watch(() => props.show, async show => {
  if (show) { opener = document.activeElement; await nextTick(); (panel.value?.querySelector('select:not([disabled])') || panel.value)?.focus(); }
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
</style>
