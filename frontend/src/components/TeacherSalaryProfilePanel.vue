<template>
  <section v-if="canManage" class="salary-profile" aria-labelledby="salary-profile-title" :inert="needsPin">
    <h3 id="salary-profile-title">既有本薪設定與更正</h3>
    <p class="hint">同一位老師的本薪供各校區薪資試算共用。需要停用先前金額時，可明確填 0 並選生效日；原設定保留，已鎖定月份仍受原流程保護。</p>
    <label for="salary-teacher">老師</label>
    <select id="salary-teacher" v-model="form.teacher" :disabled="busy">
      <option value="">請選擇老師</option>
      <option v-for="t in teachers" :key="t.teacher_id" :value="t.teacher_id">{{ t.teacher_name }}</option>
    </select>
    <p v-if="selected">{{ selected.teacher_name }}目前報表本薪：<strong>{{ currentSalary == null ? '尚待確認' : `${money(currentSalary)} 元` }}</strong></p>
    <p v-if="pending">待總部核准：{{ money(pending.base_salary) }} 元，自 {{ pending.effective_from }} 起。</p>
    <button v-if="isHq && pending" type="button" class="ghost" :disabled="busy || uncertain || !pendingScopeReady" @click="reviewApproval">核准這筆本薪</button>
    <p v-if="isHq && pending && !pendingScopeReady" class="hint">請切換至這筆設定所屬校區，或重新載入完整範圍資料後再核准。</p>
    <label for="salary-amount">更正後本薪（可填 0，不能留空）</label>
    <input id="salary-amount" v-model="form.amount" type="number" min="0" max="99999999.99" step="0.01" :disabled="busy || uncertain" />
    <label for="salary-effective">生效日</label>
    <input id="salary-effective" v-model="form.date" type="date" :disabled="busy || uncertain" />
    <label v-if="globalScope" class="ack"><input id="salary-global" v-model="form.globalAck" type="checkbox" :disabled="busy || uncertain" />我確認由總部受理這筆未指定校區的共用本薪設定。</label>
    <p v-if="!hasCampus && !globalScope" class="hint">請先選擇校區再更正本薪。</p>
    <button type="button" class="ghost" :disabled="busy || uncertain || !valid" @click="review">核對本薪更正</button>
    <div v-if="proposal" class="confirmation" data-testid="salary-confirm" role="group" aria-label="本薪更正確認">
      <p><strong>{{ proposal.name }} · {{ money(proposal.amount) }} 元</strong>，自 {{ proposal.date }} 起，供這位老師所有校區的薪資試算共用。</p>
      <p>受理校區：{{ proposal.global ? '總部共用設定' : '目前校區' }}。</p>
      <p>{{ proposal.kind === 'approve' || isHq ? '總部確認後核准此設定。' : '送出後待總部核准，核准前不會進入薪資。' }}</p>
      <button type="button" class="primary" :disabled="busy || uncertain" @click="perform">{{ proposal.kind === 'approve' ? '確認核准本薪' : isHq ? '儲存並核准本薪' : '送總部核准' }}</button>
    </div>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-if="message" class="notice" role="status">{{ message }}</p>
  </section>
  <PinLockModal v-if="needsPin" :token="pinToken" @unlocked="pinComplete" @skip="pinSkipped" @dismiss="pinSkipped" />
</template>
<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useToast } from '../composables/useToast';
import { ymdInTaipei } from '../lib/sessionDates';
import PinLockModal from './PinLockModal.vue';
import { getAccessToken } from '../lib/authedFetch';
import { approveTeacherSalaryProfile, saveTeacherSalaryProfile } from '../lib/teacherEligibilityApi';
const props = defineProps({ teachers: { type: Array, default: () => [] }, branchId: { type: [Number, String], default: null }, start: { type: String, default: '' }, userRole: { type: String, default: '' } });
const emit = defineEmits(['changed']);
const toast = useToast();
const form = reactive({ teacher: '', amount: '', date: props.start, globalAck: false });
const proposal = ref(null), busy = ref(false), uncertain = ref(false), error = ref(''), message = ref(''), needsPin = ref(false), pinToken = ref('');
const approved = reactive(new Set());
const canManage = computed(() => ['director', 'super_admin'].includes(props.userRole));
const isHq = computed(() => props.userRole === 'super_admin');
const hasCampus = computed(() => Number.isSafeInteger(Number(props.branchId)) && Number(props.branchId) > 0);
const globalScope = computed(() => isHq.value && (props.branchId == null || props.branchId === ''));
const selected = computed(() => props.teachers.find(t => Number(t.teacher_id) === Number(form.teacher)));
const identityReady = computed(() => Number.isSafeInteger(Number(selected.value?.teacher_id)) && Number(selected.value?.teacher_id) > 0 && !!selected.value?.teacher_name?.trim());
const currentSalary = computed(() => selected.value?.settlement?.base_salary ?? null);
const pending = computed(() => {
  const p = selected.value?.pending_salary;
  return p?.id && p.manual_multiplier_pct == null && !approved.has(Number(p.id)) ? p : null;
});
// An omitted legacy scope must never be interpreted as an all-campus profile.
const pendingScopeReady = computed(() => identityReady.value && pending.value && Object.hasOwn(pending.value, 'branch_id')
  && (pending.value.branch_id === null || (hasCampus.value && Number(pending.value.branch_id) === Number(props.branchId))));
const valid = computed(() => identityReady.value && /^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(String(form.amount))
  && Number(form.amount) <= 99999999.99 && /^\d{4}-\d{2}-\d{2}$/.test(form.date)
  && Number.isFinite(Date.parse(form.date)) && ymdInTaipei(new Date(form.date)) === form.date
  && (hasCampus.value || (globalScope.value && form.globalAck)));
const money = n => Number(n).toLocaleString('zh-TW', { maximumFractionDigits: 2 });
watch(() => [form.teacher, form.amount, form.date, form.globalAck, props.branchId, props.userRole], () => { proposal.value = null; });
watch(() => props.start, value => { proposal.value = null; if (!busy.value) form.date = value; });
function review() {
  if (!valid.value || busy.value || uncertain.value) return;
  proposal.value = { kind: 'save', name: selected.value.teacher_name, amount: Number(form.amount), date: form.date, global: globalScope.value,
    body: { teacher_id: Number(selected.value.teacher_id), base_salary: Number(form.amount), effective_from: form.date, ...(hasCampus.value ? { branch_id: Number(props.branchId) } : {}) } };
  error.value = ''; message.value = '';
}
function reviewApproval() {
  if (!isHq.value || !pendingScopeReady.value || busy.value || uncertain.value) return;
  proposal.value = { kind: 'approve', id: Number(pending.value.id), teacher: Number(selected.value.teacher_id), name: selected.value.teacher_name,
    amount: Number(pending.value.base_salary), date: pending.value.effective_from, global: pending.value.branch_id === null };
  error.value = ''; message.value = '';
}
async function perform() {
  const p = proposal.value;
  if (!p || busy.value || uncertain.value || !identityReady.value || !canManage.value) return;
  busy.value = true; error.value = ''; message.value = '';
  try {
    const r = p.kind === 'approve' ? await approveTeacherSalaryProfile(p.id) : await saveTeacherSalaryProfile(p.body);
    if (!r?.id || !['approved', 'pending'].includes(r.status) || Number(r.teacher_id) !== (p.teacher ?? p.body.teacher_id)
      || Number(r.base_salary) !== p.amount || String(r.effective_from).slice(0, 10) !== p.date
      || (p.kind === 'approve' && (Number(r.id) !== p.id || r.status !== 'approved'))) throw new Error('回覆不完整');
    if (p.kind === 'approve') approved.add(p.id);
    proposal.value = null; form.amount = '';
    message.value = r.status === 'approved' ? '本薪已核准，原設定紀錄保留。' : '本薪已送總部核准，尚未生效。';
    // The real payroll page remounts this panel while refreshing; its existing global toast survives.
    toast.success(message.value, { duration: 8000 });
    emit('changed');
  } catch (e) {
    proposal.value = null;
    if (e.code === 'pin_required' && e.status === 423) {
      pinToken.value = await getAccessToken().catch(() => null) || '';
      if (pinToken.value) needsPin.value = true;
      error.value = e.message;
    } else if (e.status == null || e.status >= 500) {
      uncertain.value = true;
      error.value = `${e.message || '連線失敗'}。尚未確認結果，請重新載入頁面並核對本薪與待核准資料，勿直接重送。`;
    } else error.value = e.message || '本薪操作失敗，請核對欄位與月份。';
  } finally { busy.value = false; }
}
function pinComplete() { needsPin.value = false; pinToken.value = ''; error.value = ''; message.value = '已解鎖，請重新核對並送出。'; }
function pinSkipped() { needsPin.value = false; pinToken.value = ''; error.value = '未完成 PIN 驗證，本薪未送出。'; }
</script>
<style scoped>
.salary-profile { margin-top: var(--ds-space-5); padding: var(--ds-space-4); border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-md); background: var(--ds-canvas); overflow-wrap: anywhere; }
h3 { margin: 0 0 var(--ds-space-2); font-weight: var(--ds-font-weight-bold); }
p { margin: var(--ds-space-2) 0; line-height: 1.6; }
.hint { color: var(--ds-ink-secondary); font-size: var(--ds-font-size-sm); }
label { display: block; margin: var(--ds-space-3) 0 var(--ds-space-2); font-weight: var(--ds-font-weight-bold); }
select, input { width: 100%; min-height: 44px; padding: var(--ds-space-2); border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-md); background: var(--ds-canvas); color: var(--ds-ink); font: inherit; }
button { min-height: 44px; margin-top: var(--ds-space-2); white-space: normal; }
.ack { display: flex; gap: var(--ds-space-2); align-items: center; }
.ack input { width: 20px; flex-shrink: 0; }
.confirmation, .notice { margin-top: var(--ds-space-3); padding: var(--ds-space-3); background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-md); }
.error { padding: var(--ds-space-3); background: var(--ds-danger-wash); color: var(--ds-danger); }
</style>
