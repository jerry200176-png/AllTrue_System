<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import AtButton from './design-system/AtButton.vue';
import AtEmpty from './design-system/AtEmpty.vue';
import AtInlineAlert from './design-system/AtInlineAlert.vue';
import AtSkeleton from './design-system/AtSkeleton.vue';

const props = defineProps({ branchId: { type: [Number, String], default: null } });
const emit = defineEmits(['ledger', 'navigate']);
const courses = ref([]);
const loading = ref(false);
const error = ref('');
const name = ref('');
const appliedName = ref('');
const page = ref(1);
const lastPage = ref(1);
const reviews = computed(() => courses.value.filter(course => course.monthly_payment?.review_required));
let requestVersion = 0;
let controller;
const money = value => value == null ? '待核對' : `NT$ ${Number(value).toLocaleString()}`;

async function reload() {
  const version = ++requestVersion;
  controller?.abort();
  controller = new AbortController();
  courses.value = [];
  error.value = '';
  loading.value = true;
  try {
    const token = JSON.parse(localStorage.getItem('alltrue_session') || 'null')?.access_token;
    if (!token) throw new Error('請先登入');
    const params = new URLSearchParams({ schedule_mode: 'date', status: 'active', per_page: '100', page: String(page.value) });
    if (props.branchId != null && props.branchId !== '') params.set('branch_id', String(props.branchId));
    if (appliedName.value) params.set('name', appliedName.value);
    const response = await fetch(`/api/v1/student-classes?${params}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` }, signal: controller.signal,
    });
    if (!response.ok) throw new Error(`月結核對資料載入失敗（${response.status}）`);
    const data = await response.json();
    if (version !== requestVersion) return;
    courses.value = data.data || [];
    lastPage.value = Math.max(1, Number(data.last_page) || 1);
  } catch (failure) {
    if (version === requestVersion && failure.name !== 'AbortError') error.value = failure.message;
  } finally {
    if (version === requestVersion) loading.value = false;
  }
}
function search() { appliedName.value = name.value.trim(); page.value = 1; reload(); }
function changePage(next) { page.value = next; reload(); }
watch(() => props.branchId, () => { page.value = 1; lastPage.value = 1; reload(); }, { immediate: true });
onBeforeUnmount(() => { requestVersion += 1; controller?.abort(); });
defineExpose({ reload, loading });
</script>

<template>
  <div class="monthly-review">
    <p>列出付款期間待確認的月結課程，包含已上課卻沒有對應帳單的月份。先核對，再更正合約與建立帳單。</p>
    <form class="monthly-review__search" @submit.prevent="search">
      <label>學生姓名 <input v-model="name" type="search" placeholder="搜尋學生" /></label>
      <AtButton type="submit" variant="secondary" size="sm" shape="rect">搜尋</AtButton>
    </form>
    <AtSkeleton v-if="loading" :rows="5" height="28px" />
    <AtInlineAlert v-else-if="error" tone="danger" title="無法載入月結待核對課程">{{ error }}<template #action><AtButton variant="secondary" size="sm" @click="reload">重新載入</AtButton></template></AtInlineAlert>
    <template v-else>
      <AtEmpty v-if="!reviews.length" icon="task_alt" title="本頁沒有待核對的月結課程" description="可搜尋學生，或查看下一頁；本頁結果不代表所有月份都有帳單。" />
      <div v-else class="monthly-review__table">
        <table aria-label="月結待核對課程">
          <thead><tr><th scope="col">學生／課程</th><th scope="col">合約期間／登錄收款</th><th scope="col">上課月份與缺少帳單的堂次</th><th scope="col">下一步</th></tr></thead>
          <tbody><tr v-for="course in reviews" :key="course.ID">
            <th scope="row">{{ course.student_name }}<small>{{ course.subject_name }} · {{ course.teacher_name }}</small></th>
            <td data-label="合約期間／登錄收款">{{ course.monthly_payment.contract_start }} ～ {{ course.monthly_payment.contract_end }}<small>系統登錄 {{ money(course.monthly_payment.registered_paid_amount) }}，實收待核對</small><small v-if="course.monthly_payment.periods?.some(period => period.amount_discrepancy)">帳單總額與堂次試算不同，需核對約定</small></td>
            <td data-label="上課月份與帳單"><div v-for="month in course.monthly_payment.session_review" :key="month.calendar_month">
              {{ month.calendar_month }} · 已上 {{ month.completed_sessions }} 堂 · 試算 {{ money(month.estimated_charge) }}
              <small v-if="month.uncovered_sessions">{{ month.uncovered_sessions }} 堂沒有對應帳單服務期間</small>
              <small v-if="month.outside_contract_sessions">{{ month.outside_contract_sessions }} 堂超出合約日期</small>
            </div><small>試算尚未成為應收帳單。</small></td>
            <td data-label="下一步"><AtButton variant="ghost" shape="rect" size="sm" @click="emit('ledger', { id: course.ID })">核對登錄收款</AtButton><AtButton variant="secondary" shape="rect" size="sm" @click="emit('navigate', { target: 'course-mgmt', studentId: course.StudentID, courseId: course.ID, studentName: course.student_name })">前往課程核對</AtButton></td>
          </tr></tbody>
        </table>
      </div>
      <div class="monthly-review__pages">
        <AtButton variant="ghost" shape="rect" size="sm" :disabled="page <= 1" @click="changePage(page - 1)">上一頁</AtButton>
        <span>第 {{ page }} / {{ lastPage }} 頁（月結課程）</span>
        <AtButton variant="ghost" shape="rect" size="sm" :disabled="page >= lastPage" @click="changePage(page + 1)">下一頁</AtButton>
      </div>
    </template>
  </div>
</template>

<style scoped>
.monthly-review { color: var(--ds-ink); }
.monthly-review__search, .monthly-review__pages { display: flex; align-items: center; gap: 12px; margin: 12px 0; flex-wrap: wrap; }
.monthly-review__search input { padding: 8px; border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-sm); background: var(--ds-surface-0, var(--ds-canvas)); color: var(--ds-ink); }
.monthly-review__table { overflow-x: auto; }
.monthly-review table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
.monthly-review th, .monthly-review td { text-align: left; vertical-align: top; padding: 12px; border-bottom: 1px solid var(--ds-hairline); }
.monthly-review small { display: block; color: var(--ds-ink-mute); font-weight: 400; margin-top: 4px; }
@media (max-width: 640px) {
  .monthly-review table, .monthly-review tbody, .monthly-review tr, .monthly-review td, .monthly-review th { display: block; }
  .monthly-review thead { display: none; }
  .monthly-review tbody { display: grid; gap: 12px; }
  .monthly-review tr { padding: 8px; border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-sm); }
  .monthly-review th, .monthly-review td { padding: 8px; border: 0; }
  .monthly-review td[data-label]::before { content: attr(data-label); display: block; font-size: 12px; font-weight: 600; color: var(--ds-ink-mute); margin-bottom: 4px; }
}
</style>
