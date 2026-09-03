<template>
  <div class="bmp-page">
    <!-- Header -->
    <AtPageHeader
      title="LINE 綁定管理"
      description="檢視家長 LINE 綁定狀態、搜尋學生並解除綁定。資料依所選分校隔離。"
      icon="link"
    >
      <template #meta>
        <span>本分校 <strong>{{ statsLabel }}</strong></span>
        <span>列表 {{ list.length }} 筆</span>
      </template>
      <template #actions>
        <AtButton shape="rect" variant="ghost" icon="refresh" @click="refresh">重新整理</AtButton>
      </template>
    </AtPageHeader>

    <!-- 綁定率統計卡片 -->
    <div class="bmp-stats" data-guide="binding-stats">
      <AtMetric
        label="已綁定 LINE"
        :value="displayBoundCount"
        :delta="statsDelta"
        delta-tone="positive"
        accent="var(--ds-success)"
      />
      <AtMetric
        label="綁定率"
        :value="bindingRateLabel"
        :delta="stats ? `${stats.bound_count}/${stats.student_total}` : ''"
        delta-tone="neutral"
        accent="var(--ds-primary)"
      />
      <AtMetric
        label="未驗證綁定"
        :value="displayUnverifiedCount"
        delta="待家長完成驗證"
        delta-tone="neutral"
        accent="var(--ds-warning)"
      />
      <AtMetric
        label="總活躍學生"
        :value="displayStudentTotal"
        delta="含未綁定"
        delta-tone="neutral"
      />
    </div>

    <!-- 篩選列 -->
    <AtFilterBar label="綁定篩選">
      <div>
        <label for="bmp-search">搜尋學生姓名</label>
        <input
          id="bmp-search"
          v-model="filters.studentName"
          type="search"
          placeholder="輸入姓名…"
          @input="onSearchInput"
        />
      </div>
      <div>
        <label for="bmp-campus">分校</label>
        <select
          id="bmp-campus"
          v-model="filters.campusId"
          :disabled="!isSuperAdmin && branchId != null"
          @change="load"
        >
          <option value="">全部（限權限）</option>
          <option v-for="b in allBranches" :key="b.id" :value="b.id">{{ b.name }}</option>
        </select>
      </div>
      <div>
        <label for="bmp-status">狀態</label>
        <select id="bmp-status" v-model="filters.status" @change="load">
          <option value="">全部</option>
          <option value="verified">已驗證</option>
          <option value="unverified">未驗證</option>
        </select>
      </div>
    </AtFilterBar>

    <!-- 狀態區塊 -->
    <AtSkeleton v-if="loading" rows="4" />
    <div v-else-if="loadError" class="bmp-state">
      <AtInlineAlert tone="danger" title="無法載入綁定資料">
        <p>{{ loadError }}</p>
        <template #action>
          <AtButton shape="rect" size="sm" variant="ghost" @click="refresh">重試</AtButton>
        </template>
      </AtInlineAlert>
    </div>
    <AtEmpty
      v-else-if="list.length === 0"
      icon="link_off"
      title="沒有符合條件的綁定"
      description="調整搜尋或篩選條件後再試；尚未有任何學生綁定 LINE 時也會顯示此狀態。"
    />

    <!-- Desktop 表格 -->
    <div v-else class="bmp-table-wrap">
      <table class="bmp-table" data-guide="binding-table">
        <thead>
          <tr>
            <th style="width:110px">學生</th>
            <th>LINE ID</th>
            <th style="width:90px">分校</th>
            <th style="width:120px">綁定時間</th>
            <th style="width:100px">狀態</th>
            <th style="width:96px; text-align:right">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in list" :key="row.id">
            <td>
              <div class="bmp-student">
                <span class="bmp-avatar">{{ (row.student_name || '?')[0] }}</span>
                <strong>{{ row.student_name || `#${row.student_id}` }}</strong>
              </div>
            </td>
            <td>
              <code class="bmp-line-id">{{ row.line_user_id_masked || '—' }}</code>
            </td>
            <td>{{ row.campus_name || `#${row.campus_id}` }}</td>
            <td class="bmp-tabular">{{ formatDateTime(row.bound_at) }}</td>
            <td>
              <AtBadge :tone="isVerified(row) ? 'success' : 'warning'" :label="isVerified(row) ? '已驗證' : '未驗證'" />
            </td>
            <td style="text-align:right">
              <AtIconButton
                icon="link_off"
                :label="`解除 ${row.student_name || ''} 的綁定`"
                variant="danger"
                @click="openUnbindDialog(row)"
              />
            </td>
          </tr>
        </tbody>
      </table>

      <!-- 分頁 -->
      <div v-if="pagination.lastPage > 1" class="bmp-pagination">
        <AtButton shape="rect" size="sm" variant="ghost" :disabled="page <= 1" @click="changePage(page - 1)">上一頁</AtButton>
        <span class="bmp-page-info">第 {{ page }} / {{ pagination.lastPage }} 頁（共 {{ pagination.total }} 筆）</span>
        <AtButton shape="rect" size="sm" variant="ghost" :disabled="page >= pagination.lastPage" @click="changePage(page + 1)">下一頁</AtButton>
      </div>
    </div>

    <!-- 解除綁定二次確認 -->
    <AtDialog
      :open="Boolean(unbindTarget)"
      title="解除 LINE 綁定"
      size="sm"
      panel-class="bmp-dialog"
      @close="closeUnbindDialog"
    >
      <p>
        確定要解除學生 <strong>{{ unbindTarget?.student_name || `#${unbindTarget?.student_id}` }}</strong>
        的 LINE 綁定嗎？解除後家長將無法再收到該學生的學習通知連結。
      </p>
      <p v-if="unbindError" class="bmp-dialog-error">{{ unbindError }}</p>
      <template #actions>
        <AtButton shape="rect" variant="ghost" :disabled="unbinding" @click="closeUnbindDialog">取消</AtButton>
        <AtButton shape="rect" variant="danger" :loading="unbinding" @click="confirmUnbind">確認解除</AtButton>
      </template>
    </AtDialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { branches } from '../lib/useBranches';
import {
  fetchBindings,
  fetchBindingStats,
  unbindBinding,
} from '../lib/bindingsApi';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';
import AtButton from '../components/design-system/AtButton.vue';
import AtDialog from '../components/design-system/AtDialog.vue';
import AtMetric from '../components/design-system/AtMetric.vue';
import AtFilterBar from '../components/design-system/AtFilterBar.vue';
import AtSkeleton from '../components/design-system/AtSkeleton.vue';
import AtEmpty from '../components/design-system/AtEmpty.vue';
import AtInlineAlert from '../components/design-system/AtInlineAlert.vue';
import AtBadge from '../components/design-system/AtBadge.vue';
import AtIconButton from '../components/design-system/AtIconButton.vue';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  userRole: { type: String, default: 'director' },
  initialStudentName: { type: String, default: '' },
});
const emit = defineEmits(['clear-initial-student']);

const isSuperAdmin = computed(() => props.userRole === 'super_admin');
const allBranches = computed(() => branches.value);

const loading = ref(false);
const loadError = ref('');
const list = ref([]);
const stats = ref(null);
const page = ref(1);
const perPage = 20;
const pagination = ref({ lastPage: 1, total: 0 });
const unbindTarget = ref(null);
const unbinding = ref(false);
const unbindError = ref('');
const unbindTitleId = 'bmp-unbind-title';

const filters = ref({ studentName: '', campusId: '', status: '' });
let searchTimer = null;

const activeCampusId = computed(() => {
  if (filters.value.campusId) return Number(filters.value.campusId);
  if (!isSuperAdmin.value && props.branchId != null && props.branchId !== '') return Number(props.branchId);
  return null;
});

/* ── 統計卡顯示 ── */
const statsLabel = computed(() => {
  if (!stats.value) return '—';
  return `${stats.value.bound_count} / ${stats.value.student_total}`;
});
const displayBoundCount = computed(() => stats.value?.bound_count ?? '—');
const displayUnverifiedCount = computed(() => stats.value?.unverified_bound_count ?? '—');
const displayStudentTotal = computed(() => stats.value?.student_total ?? '—');
const bindingRateLabel = computed(() => {
  if (!stats.value || !stats.value.student_total) return '—';
  const rate = (stats.value.bound_count / stats.value.student_total) * 100;
  return `${rate.toFixed(1)}%`;
});
const statsDelta = computed(() => {
  if (!stats.value) return '';
  return `${stats.value.bound_count}/${stats.value.student_total}`;
});

function isVerified(row) {
  return Boolean(row.verified_at || row.status === 'verified');
}

function formatDateTime(s) {
  if (!s) return '—';
  try {
    const d = new Date(s);
    if (Number.isNaN(d.getTime())) return String(s);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  } catch { return String(s); }
}

/* ── 資料載入 ── */
async function loadStats() {
  try {
    stats.value = await fetchBindingStats({ campusId: activeCampusId.value });
  } catch { /* 統計卡失敗不阻擋列表 */ }
}

async function load() {
  loading.value = true;
  loadError.value = '';
  try {
    const data = await fetchBindings({
      campusId: activeCampusId.value,
      studentName: filters.value.studentName || undefined,
      page: page.value,
      perPage,
    });
    const rows = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
    list.value = rows;
    const meta = data?.meta || data?.pagination || {};
    pagination.value = {
      lastPage: Number(meta.last_page || data.last_page || 1),
      total: Number(meta.total || data.total || rows.length),
    };
    if (page.value > pagination.value.lastPage) {
      page.value = pagination.value.lastPage || 1;
    }
  } catch (e) {
    list.value = [];
    const msg = e?.payload?.message || e?.message || '無法載入綁定清單';
    loadError.value = (e?.status === 404 || e?.status === 500)
      ? `後端綁定 API 尚未上線（HTTP ${e.status}）。此頁依賴 W31 P1-2 端點，合併後即可使用。`
      : msg;
  } finally {
    loading.value = false;
  }
}

function refresh() { load(); loadStats(); }

function onSearchInput() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => { page.value = 1; load(); }, 350);
}

function changePage(p) {
  if (p < 1) return;
  page.value = p;
  load();
}

/* ── 解除綁定 ── */
function openUnbindDialog(row) {
  unbindTarget.value = row;
  unbindError.value = '';
}
function closeUnbindDialog() {
  if (unbinding.value) return;
  unbindTarget.value = null;
}
async function confirmUnbind() {
  if (!unbindTarget.value) return;
  unbinding.value = true;
  unbindError.value = '';
  try {
    await unbindBinding(unbindTarget.value.id);
    const removedId = unbindTarget.value.id;
    closeUnbindDialog();
    list.value = list.value.filter((r) => r.id !== removedId);
    if (list.value.length === 0) await load();
    loadStats();
  } catch (e) {
    unbindError.value = e?.payload?.message || e?.message || '解除綁定失敗，請稍後再試';
  } finally {
    unbinding.value = false;
  }
}

watch(() => props.branchId, () => {
  page.value = 1;
  refresh();
});

watch(
  () => props.initialStudentName,
  (name) => {
    const trimmed = String(name || '').trim();
    if (!trimmed) return;
    filters.value.studentName = trimmed.slice(0, 40);
    page.value = 1;
    load();
    emit('clear-initial-student');
  },
  { immediate: true },
);

onMounted(() => { load(); loadStats(); });
</script>

<style scoped>
.bmp-page {
  max-width: 1180px;
  margin: 0 auto;
}

.bmp-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: var(--ds-space-3);
  margin-bottom: var(--ds-space-3);
}

.bmp-state {
  padding: var(--ds-space-4) 0;
}

.bmp-table-wrap {
  background: var(--ds-surface-1);
  border: var(--ds-border-width) solid var(--ds-hairline);
  border-radius: var(--ds-radius-lg);
  overflow-x: auto;
}

.bmp-table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--ds-font-size-base);
}

.bmp-table th {
  text-align: left;
  font-size: var(--ds-font-size-xs);
  font-weight: var(--ds-font-weight-semibold);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--ds-ink-mute);
  padding: var(--ds-space-3);
  border-bottom: var(--ds-border-width) solid var(--ds-hairline);
  white-space: nowrap;
}

.bmp-table td {
  padding: var(--ds-space-3);
  border-bottom: var(--ds-border-width) solid var(--ds-hairline);
  color: var(--ds-ink);
  vertical-align: middle;
}

.bmp-table tbody tr:hover {
  background: var(--ds-canvas-soft);
}

.bmp-student {
  display: flex;
  align-items: center;
  gap: var(--ds-space-2);
}

.bmp-avatar {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: var(--ds-primary-wash);
  color: var(--ds-primary-deep);
  font-weight: var(--ds-font-weight-bold);
  font-size: var(--ds-font-size-sm);
  flex-shrink: 0;
}

.bmp-line-id {
  font-family: 'Courier New', monospace;
  font-size: var(--ds-font-size-sm);
  color: var(--ds-ink-secondary);
  background: var(--ds-surface-0);
  padding: 2px 6px;
  border-radius: var(--ds-radius-sm);
}

.bmp-tabular {
  font-variant-numeric: tabular-nums;
  color: var(--ds-ink-secondary);
  white-space: nowrap;
}

.bmp-pagination {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--ds-space-3);
  padding: var(--ds-space-3);
  flex-wrap: wrap;
}

.bmp-page-info {
  font-size: var(--ds-font-size-sm);
  color: var(--ds-ink-mute);
  font-variant-numeric: tabular-nums;
}

/* 解除綁定對話框 */
.bmp-overlay {
  position: fixed;
  inset: 0;
  z-index: var(--ds-z-modal);
  background: rgba(15, 23, 42, 0.45);
  display: grid;
  place-items: center;
  padding: var(--ds-space-4);
}

.bmp-dialog {
  width: min(460px, 100%);
  background: var(--ds-canvas);
  border: var(--ds-border-width) solid var(--ds-hairline);
  border-radius: var(--ds-radius-lg);
  box-shadow: var(--ds-shadow-2);
  padding: var(--ds-space-5);
}

.bmp-dialog h3 {
  margin: 0 0 var(--ds-space-3);
  font-size: var(--ds-font-size-lg);
  color: var(--ds-ink);
}

.bmp-dialog p {
  margin: 0 0 var(--ds-space-3);
  font-size: var(--ds-font-size-base);
  color: var(--ds-ink-secondary);
  line-height: var(--ds-line-base);
}

.bmp-dialog-actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--ds-space-2);
}

.bmp-dialog-error {
  color: var(--ds-danger);
  font-size: var(--ds-font-size-sm);
}

@media (max-width: 768px) {
  .bmp-stats {
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  }
}
</style>
