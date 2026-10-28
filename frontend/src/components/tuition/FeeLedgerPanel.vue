<script setup>
import { computed, ref, watch, onMounted } from 'vue';
import AtButton from '../design-system/AtButton.vue';
import AtDialog from '../design-system/AtDialog.vue';
import AtEmpty from '../design-system/AtEmpty.vue';
import AtInlineAlert from '../design-system/AtInlineAlert.vue';
import { authedFetch, getAccessToken } from '../../lib/authedFetch';
import { useToast } from '../../composables/useToast';

// In-app #439: 代收代付 — book / material / sundry fees collected for others, and what was paid out.
// Its own ledger (/api/v1/fee-ledger): never a tuition bill, so course paid state and 學收 stay unchanged.
const props = defineProps({
  branchId: { type: [Number, String], default: null },
  students: { type: Array, default: () => [] },
});
const toast = useToast();

const money = (v) => 'NT$ ' + Number(v || 0).toLocaleString('zh-TW');
const pad = (n) => String(n).padStart(2, '0');
const today = () => { const d = new Date(); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; };
const STATUS = { unpaid: '未收', paid: '已收', voided: '已作廢', active: '已付出' };

const campusId = computed(() => (props.branchId == null || props.branchId === '' ? null : Number(props.branchId)));
const month = ref(today().slice(0, 7));
const unpaidOnly = ref(false);
const loading = ref(false);
const error = ref('');
const emptyLedger = () => ({ charges: [], payouts: [], summary: { rows: [], total: {} }, categories: {}, methods: {} });
const ledger = ref(emptyLedger());
let loadSeq = 0; // only the newest request may write; a slow reply for an old campus/filter is dropped
let loadedCampus = null;

async function api(path, body) {
  const token = await getAccessToken();
  if (!token) throw new Error('請重新登入');
  const init = body ? { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : { headers: { Accept: 'application/json' } };
  const res = await authedFetch(`/api/v1/${path}`, init, token);
  const json = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(json?.message || `操作失敗（${res.status}）`);
  return json;
}

async function load() {
  const seq = ++loadSeq;
  const campus = campusId.value;
  if (campus !== loadedCampus) { ledger.value = emptyLedger(); loadedCampus = campus; } // never show campus A's rows under B
  if (campus == null) return;
  loading.value = true;
  error.value = '';
  try {
    const q = new URLSearchParams({ campus_id: String(campus) });
    if (unpaidOnly.value) q.set('scope', 'unpaid'); else q.set('month', month.value);
    const data = await api(`fee-ledger?${q}`);
    if (seq === loadSeq) ledger.value = data;
  } catch (e) {
    if (seq === loadSeq) error.value = e.message || '載入失敗';
  } finally {
    if (seq === loadSeq) loading.value = false;
  }
}

// One dialog at a time: { kind: 'charge' | 'payout' | 'pay' | 'void-charge' | 'void-payout', row? }
const dialog = ref(null);
const form = ref({});
const saving = ref(false);
const studentQuery = ref('');
const picked = ref([]);
const shownStudents = computed(() => props.students.filter((s) => !studentQuery.value || String(s.name).includes(studentQuery.value.trim())));
const shownPicked = computed(() => shownStudents.value.filter((s) => picked.value.includes(s.id)).length);
const allShown = computed(() => shownStudents.value.length > 0 && shownPicked.value === shownStudents.value.length);
function toggleShown(on) {
  const ids = shownStudents.value.map((s) => s.id);
  picked.value = on ? [...new Set([...picked.value, ...ids])] : picked.value.filter((id) => !ids.includes(id));
}

function open(kind, row = null) {
  const date = today();
  form.value = { category: 'books', title: '', amount: null, date, method: 'cash', reason: '' };
  if (kind === 'charge') { picked.value = []; studentQuery.value = ''; }
  dialog.value = { kind, row };
}

const DIALOG_TITLES = { charge: '新增代收款', payout: '記一筆代付', pay: '登記收款', 'void-charge': '作廢代收款', 'void-payout': '作廢代付' };
const canSave = computed(() => {
  const f = form.value;
  const k = dialog.value?.kind;
  if (k === 'charge') return picked.value.length > 0 && f.title.trim() && Number(f.amount) > 0;
  if (k === 'payout') return f.title.trim() && Number(f.amount) > 0 && f.date;
  if (k === 'pay') return Boolean(f.date && f.method);
  return Boolean(f.reason.trim());
});

async function save() {
  const { kind, row } = dialog.value;
  const f = form.value;
  saving.value = true;
  try {
    if (kind === 'charge') {
      const out = await api('fee-charges', { campus_id: campusId.value, student_ids: picked.value, category: f.category, title: f.title, amount: Number(f.amount), charged_on: f.date });
      toast.success(`已建立 ${out.charges.length} 筆代收款`);
    } else if (kind === 'payout') {
      await api('fee-payouts', { campus_id: campusId.value, category: f.category, title: f.title, amount: Number(f.amount), paid_on: f.date });
      toast.success('已記下這筆代付');
    } else if (kind === 'pay') {
      await api(`fee-charges/${row.id}/pay`, { paid_on: f.date, method: f.method });
      toast.success(`已收 ${row.student_name} ${money(row.amount)}`);
    } else {
      await api(`${kind === 'void-charge' ? 'fee-charges' : 'fee-payouts'}/${row.id}/void`, { reason: f.reason });
      toast.success('已作廢');
    }
    dialog.value = null;
    await load();
  } catch (e) {
    toast.error(e.message || '操作失敗，請稍後再試');
  } finally {
    saving.value = false;
  }
}

watch([campusId, month, unpaidOnly], load);
onMounted(load);
defineExpose({ reload: load, loading });
</script>

<template>
  <div class="flp">
    <AtEmpty v-if="campusId == null" icon="storefront" title="請先選擇分校" description="代收代付是每間分校各自記帳，選好分校後就會顯示。" />
    <template v-else>
      <div class="flp__bar">
        <label class="flp__field">月份 <input v-model="month" type="month" :disabled="unpaidOnly" /></label>
        <label class="flp__check"><input v-model="unpaidOnly" type="checkbox" /> 只看還沒收的（所有月份）</label>
        <span class="flp__grow" />
        <AtButton variant="primary" shape="rect" icon="add" @click="open('charge')">新增代收款</AtButton>
        <AtButton variant="secondary" shape="rect" icon="local_shipping" @click="open('payout')">記一筆代付</AtButton>
      </div>
      <p class="flp__hint">書籍費、教材費、雜費是幫別人代收的錢，不是學費：不會出現在學費帳單、學收和營收裡。</p>

      <AtInlineAlert v-if="error" tone="danger" title="載入失敗">
        {{ error }}
        <template #action><AtButton variant="secondary" size="sm" shape="rect" @click="load">重新載入</AtButton></template>
      </AtInlineAlert>
      <p v-else-if="loading && !ledger.charges.length && !ledger.payouts.length">載入中…</p>

      <template v-else>
        <section v-if="!unpaidOnly && ledger.summary.rows.length" class="flp__sec" aria-label="本月對帳">
          <h3>{{ month }} 對帳</h3>
          <table class="tc-table">
            <thead><tr><th>類別</th><th>應收</th><th>已收</th><th>未收</th><th>已付出</th><th>結餘</th></tr></thead>
            <tbody>
              <tr v-for="r in ledger.summary.rows" :key="r.category">
                <td>{{ r.label }}</td><td>{{ money(r.charged) }}</td><td>{{ money(r.collected) }}</td>
                <td>{{ money(r.outstanding) }}</td><td>{{ money(r.paid_out) }}</td>
                <td :class="{ 'flp__neg': r.balance < 0 }">{{ money(r.balance) }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr><th>合計</th><th>{{ money(ledger.summary.total.charged) }}</th><th>{{ money(ledger.summary.total.collected) }}</th>
                <th>{{ money(ledger.summary.total.outstanding) }}</th><th>{{ money(ledger.summary.total.paid_out) }}</th>
                <th>{{ money(ledger.summary.total.balance) }}</th></tr>
            </tfoot>
          </table>
          <p class="flp__hint">結餘＝已收－已付出。負數表示先墊錢了。</p>
        </section>

        <section class="flp__sec" aria-label="代收款">
          <h3>代收款（{{ ledger.charges.length }}）</h3>
          <AtEmpty v-if="!ledger.charges.length" icon="menu_book" :title="unpaidOnly ? '沒有還沒收的代收款' : '這個月還沒有代收款'" description="按「新增代收款」可以一次幫整班學生記一筆書籍費。" />
          <table v-else class="tc-table">
            <thead><tr><th>學生</th><th>類別</th><th>項目</th><th>金額</th><th>日期</th><th>狀態</th><th></th></tr></thead>
            <tbody>
              <tr v-for="c in ledger.charges" :key="c.id" :class="{ 'flp__void': c.status === 'voided' }">
                <td>{{ c.student_name }}</td><td>{{ c.category_label }}</td><td>{{ c.title }}</td><td>{{ money(c.amount) }}</td>
                <td>{{ c.charged_on }}</td>
                <td>{{ STATUS[c.status] }}<template v-if="c.status === 'paid'">（{{ c.paid_on }} {{ ledger.methods[c.paid_method] || '' }}）</template></td>
                <td class="flp__actions">
                  <AtButton v-if="c.status === 'unpaid'" variant="primary" size="sm" shape="rect" @click="open('pay', c)">收款</AtButton>
                  <AtButton v-if="c.status !== 'voided'" variant="ghost" size="sm" shape="rect" @click="open('void-charge', c)">作廢</AtButton>
                </td>
              </tr>
            </tbody>
          </table>
        </section>

        <section v-if="!unpaidOnly" class="flp__sec" aria-label="代付">
          <h3>代付（{{ ledger.payouts.length }}）</h3>
          <AtEmpty v-if="!ledger.payouts.length" icon="local_shipping" title="這個月還沒有代付" description="付給書商或廠商的錢，記在這裡就能和代收款對帳。" />
          <table v-else class="tc-table">
            <thead><tr><th>類別</th><th>項目</th><th>金額</th><th>付款日</th><th>狀態</th><th></th></tr></thead>
            <tbody>
              <tr v-for="p in ledger.payouts" :key="p.id" :class="{ 'flp__void': p.status === 'voided' }">
                <td>{{ p.category_label }}</td><td>{{ p.title }}</td><td>{{ money(p.amount) }}</td><td>{{ p.paid_on }}</td>
                <td>{{ STATUS[p.status] }}</td>
                <td><AtButton v-if="p.status !== 'voided'" variant="ghost" size="sm" shape="rect" @click="open('void-payout', p)">作廢</AtButton></td>
              </tr>
            </tbody>
          </table>
        </section>
      </template>
    </template>

    <AtDialog :open="Boolean(dialog)" :title="dialog ? DIALOG_TITLES[dialog.kind] : ''" @close="!saving && (dialog = null)">
      <form v-if="dialog" class="flp__form" @submit.prevent="canSave && !saving && save()">
        <template v-if="dialog.kind === 'charge' || dialog.kind === 'payout'">
          <label class="flp__field">類別
            <select v-model="form.category"><option v-for="(label, key) in ledger.categories" :key="key" :value="key">{{ label }}</option></select>
          </label>
          <label class="flp__field">項目名稱 <input v-model="form.title" type="text" maxlength="60" :placeholder="dialog.kind === 'charge' ? '例如：國二數學講義' : '例如：書商 10 月書款'" /></label>
          <label class="flp__field">{{ dialog.kind === 'charge' ? '每位學生金額' : '金額' }} <input v-model="form.amount" type="number" min="1" step="1" inputmode="numeric" /></label>
          <label class="flp__field">{{ dialog.kind === 'charge' ? '日期' : '付款日' }} <input v-model="form.date" type="date" :max="today()" /></label>
        </template>

        <fieldset v-if="dialog.kind === 'charge'" class="flp__students">
          <legend>要收的學生（已選 {{ picked.length }} 位）</legend>
          <input v-model="studentQuery" type="search" placeholder="搜尋學生姓名…" aria-label="搜尋學生" />
          <label class="flp__check">
            <input type="checkbox" :checked="allShown" :indeterminate.prop="shownPicked > 0 && !allShown" :disabled="!shownStudents.length" @change="toggleShown($event.target.checked)" />
            全選{{ studentQuery ? '搜尋結果' : '' }}（{{ shownStudents.length }} 位）
          </label>
          <div class="flp__list">
            <label v-for="s in shownStudents" :key="s.id" class="flp__check"><input v-model="picked" type="checkbox" :value="s.id" /> {{ s.name }}</label>
          </div>
          <p v-if="picked.length && Number(form.amount) > 0" class="flp__hint">會建立 {{ picked.length }} 筆，共 {{ money(picked.length * Number(form.amount)) }}。</p>
        </fieldset>

        <template v-if="dialog.kind === 'pay'">
          <p>{{ dialog.row.student_name }}・{{ dialog.row.title }}・{{ money(dialog.row.amount) }}</p>
          <label class="flp__field">收款日 <input v-model="form.date" type="date" :max="today()" /></label>
          <label class="flp__field">付款方式
            <select v-model="form.method"><option v-for="(label, key) in ledger.methods" :key="key" :value="key">{{ label }}</option></select>
          </label>
        </template>

        <template v-if="dialog.kind === 'void-charge' || dialog.kind === 'void-payout'">
          <p>{{ dialog.row.student_name ? dialog.row.student_name + '・' : '' }}{{ dialog.row.title }}・{{ money(dialog.row.amount) }}</p>
          <p class="flp__hint">作廢後不會再算進對帳，紀錄會保留。</p>
          <label class="flp__field">原因 <input v-model="form.reason" type="text" maxlength="100" placeholder="例如：登記錯學生" /></label>
        </template>
      </form>
      <template #actions>
        <AtButton variant="secondary" shape="rect" :disabled="saving" @click="dialog = null">取消</AtButton>
        <AtButton variant="primary" shape="rect" :loading="saving" :disabled="!canSave" @click="save">確定</AtButton>
      </template>
    </AtDialog>
  </div>
</template>

<style scoped>
.flp__bar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-bottom: 8px; }
.flp__grow { flex: 1; }
.flp__sec { margin: 16px 0 24px; overflow-x: auto; }
.flp__hint { color: var(--ds-text-secondary); font-size: 13px; margin: 4px 0; }
.flp__neg { color: var(--ds-danger); }
.flp__void td { color: var(--ds-text-secondary); text-decoration: line-through; }
.flp__void td:last-child { text-decoration: none; }
.flp__actions { display: flex; gap: 6px; }
.flp__form { display: flex; flex-direction: column; gap: 12px; }
.flp__field { display: flex; flex-direction: column; gap: 4px; font-size: 14px; }
.flp__check { display: flex; gap: 6px; align-items: center; font-size: 14px; }
.flp__students { border: 1px solid var(--ds-border); border-radius: 8px; padding: 8px 12px; display: flex; flex-direction: column; gap: 8px; }
.flp__list { max-height: 240px; overflow-y: auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 4px; }
</style>
