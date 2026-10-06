<script setup>
import { computed, ref, watch, onMounted } from 'vue';
import AtButton from '../design-system/AtButton.vue';
import AtDialog from '../design-system/AtDialog.vue';
import AtEmpty from '../design-system/AtEmpty.vue';
import AtInlineAlert from '../design-system/AtInlineAlert.vue';
import { authedFetch, getAccessToken } from '../../lib/authedFetch';
import { useMonthlyRenewal } from '../../composables/course-management/useMonthlyRenewal';

// 本月待開帳單: system proposes next-period bills, director confirms.
// Confirm reuses the existing renew-monthly request (useMonthlyRenewal.submit).
const props = defineProps({ branchId: { type: [Number, String], default: null } });

const money = (v) => (v == null || isNaN(v) ? '—' : 'NT$ ' + Number(v).toLocaleString('zh-TW'));
const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const todayYmd = () => ymd(new Date());
const monthOf = (offset) => {
  const d = new Date();
  return ymd(new Date(d.getFullYear(), d.getMonth() + offset, 1)).slice(0, 7);
};

const monthOffset = ref(0);
const loading = ref(false);
const error = ref('');
const items = ref([]);
const selected = ref([]);
const confirmOpen = ref(false);
const running = ref(false);
const results = ref({}); // student_class_id -> { ok, message }

const { submit } = useMonthlyRenewal({
  form: ref({}), warnings: ref([]), previewRequestId: ref(0),
  isModalOpen: () => false, currentCourseId: () => null,
});

const ready = computed(() => items.value.filter((r) => r.status === 'ready'));
const blocked = computed(() => items.value.filter((r) => r.status === 'blocked'));
const lapsed = computed(() => items.value.filter((r) => r.status === 'lapsed_no_lessons'));
const isPast = (r) => !r.proposed_end_date || r.proposed_end_date <= todayYmd();
const pickedRows = computed(() => ready.value.filter((r) => selected.value.includes(r.student_class_id) && !isPast(r)));
const pickedTotal = computed(() => pickedRows.value.reduce((s, r) => s + Number(r.amount || 0), 0));
const allSelected = computed(() => ready.value.length > 0 && ready.value.every((r) => selected.value.includes(r.student_class_id)));

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const token = await getAccessToken();
    if (!token) throw new Error('請重新登入');
    const params = new URLSearchParams({ month: monthOf(monthOffset.value) });
    if (props.branchId != null && props.branchId !== '') params.set('branch_id', String(props.branchId));
    const res = await authedFetch(`/api/v1/accounting/monthly-drafts?${params}`, { headers: { Accept: 'application/json' } }, token);
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json?.message || `載入失敗（${res.status}）`);
    items.value = json.data || [];
    selected.value = [];
  } catch (e) {
    error.value = e.message || '載入失敗';
  } finally {
    loading.value = false;
  }
}

function toggleAll(checked) {
  selected.value = checked ? ready.value.filter((r) => !isPast(r)).map((r) => r.student_class_id) : [];
}

async function confirmRows(rows) {
  running.value = true;
  for (const r of rows) {
    try {
      const out = await submit({ id: r.student_class_id }, r.proposed_end_date);
      results.value[r.student_class_id] = out.status === 'ok'
        ? { ok: true, message: '已開立' }
        : { ok: false, message: out.message || '請重新登入後再試' };
    } catch {
      results.value[r.student_class_id] = { ok: false, message: '連線失敗，請稍後再試' };
    }
  }
  running.value = false;
  confirmOpen.value = false;
  const failed = { ...results.value };
  await load();
  results.value = failed; // keep per-row outcome visible after refresh
}

watch([monthOffset, () => props.branchId], load);
onMounted(load);
defineExpose({ reload: load, loading });
</script>

<template>
  <div class="mdp">
    <div class="mdp__bar">
      <div class="mdp__months" role="group" aria-label="月份">
        <AtButton :variant="monthOffset === 0 ? 'primary' : 'secondary'" shape="rect" size="sm" @click="monthOffset = 0">本月</AtButton>
        <AtButton :variant="monthOffset === 1 ? 'primary' : 'secondary'" shape="rect" size="sm" @click="monthOffset = 1">下個月</AtButton>
      </div>
      <p class="mdp__hint">系統已算好新一期，確認金額和期間沒問題再開立。</p>
    </div>

    <AtInlineAlert v-if="error" tone="danger" title="載入失敗">
      {{ error }}
      <template #action><AtButton variant="secondary" size="sm" shape="rect" @click="load">重新載入</AtButton></template>
    </AtInlineAlert>
    <p v-else-if="loading && !items.length">載入中…</p>
    <AtEmpty v-else-if="!items.length" icon="check_circle" title="目前沒有待處理的帳單" description="這個月份沒有需要開立或補開的月繳帳單。" />

    <template v-else>
      <section v-if="ready.length" class="mdp__sec" aria-label="可開立">
        <h3>可開立（{{ ready.length }}）</h3>
        <table class="tc-table">
          <thead>
            <tr>
              <th><input type="checkbox" aria-label="全選" :checked="allSelected" @change="toggleAll($event.target.checked)" /></th>
              <th>學生</th><th>科目</th><th>新一期期間</th><th>堂數</th><th>金額</th><th>繳費期限</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in ready" :key="r.student_class_id">
              <td><input v-model="selected" type="checkbox" :value="r.student_class_id" :disabled="isPast(r)" :aria-label="`選取 ${r.student_name}`" /></td>
              <td>{{ r.student_name }}</td>
              <td>{{ r.subject }}</td>
              <td>{{ r.proposed_start_date }} ~ {{ r.proposed_end_date }}</td>
              <td>{{ r.period_sessions }}</td>
              <td>{{ money(r.amount) }}</td>
              <td>{{ r.due_date || '—' }}</td>
              <td>
                <span v-if="results[r.student_class_id]" :class="results[r.student_class_id].ok ? 'mdp__ok' : 'mdp__fail'" role="status">{{ results[r.student_class_id].message }}</span>
                <template v-else-if="isPast(r)"><span class="mdp__hint">新一期結束日已過，無法在此開立</span></template>
                <AtButton v-else variant="secondary" size="sm" shape="rect" :disabled="running" @click="confirmRows([r])">確認開立</AtButton>
              </td>
            </tr>
          </tbody>
        </table>
        <AtButton variant="primary" shape="rect" :disabled="!pickedRows.length || running" :loading="running" @click="confirmOpen = true">
          確認開立 {{ pickedRows.length }} 筆（共 {{ money(pickedTotal) }}）
        </AtButton>
      </section>

      <section v-if="blocked.length" class="mdp__sec" aria-label="需補開">
        <h3>需補開（{{ blocked.length }}）</h3>
        <p class="mdp__hint">請聯絡總部補開。</p>
        <ul>
          <li v-for="r in blocked" :key="r.student_class_id">{{ r.student_name }}　{{ r.subject }}：{{ r.blocker_message }}</li>
        </ul>
      </section>

      <section v-if="lapsed.length" class="mdp__sec" aria-label="已到期沒有上課">
        <h3>已到期、沒有上課（{{ lapsed.length }}）</h3>
        <p class="mdp__hint">若已不上課，請到課程管理結案。</p>
        <ul>
          <li v-for="r in lapsed" :key="r.student_class_id">{{ r.student_name }}　{{ r.subject }}（到 {{ r.current_end_date }}）</li>
        </ul>
      </section>
    </template>

    <AtDialog :open="confirmOpen" title="確認開立帳單" @close="!running && (confirmOpen = false)">
      <p>即將開立 {{ pickedRows.length }} 筆帳單，共 {{ money(pickedTotal) }}。</p>
      <template #actions>
        <AtButton variant="secondary" shape="rect" :disabled="running" @click="confirmOpen = false">取消</AtButton>
        <AtButton variant="primary" shape="rect" :loading="running" @click="confirmRows(pickedRows)">確定開立</AtButton>
      </template>
    </AtDialog>
  </div>
</template>

<style scoped>
.mdp__bar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-bottom: 16px; }
.mdp__months { display: flex; gap: 8px; }
.mdp__sec { margin-bottom: 24px; overflow-x: auto; }
.mdp__hint { color: var(--at-text-muted, #666); font-size: 13px; margin: 0; }
.mdp__ok { color: #1a7f37; }
.mdp__fail { color: #c62828; }
</style>
