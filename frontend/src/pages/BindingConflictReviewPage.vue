<template>
  <div class="bcrp-page">
    <AtPageHeader
      title="衝突審查"
      description="同一學生綁定多個 LINE 帳號時在此審查，保留正確的那一側。"
      icon="gpp_maybe"
    >
      <template #meta>
        <span>待審衝突 <strong>{{ conflicts.length }}</strong> 筆</span>
      </template>
      <template #actions>
        <AtButton shape="rect" variant="ghost" icon="refresh" @click="refresh">重新整理</AtButton>
      </template>
    </AtPageHeader>

    <AtFilterBar label="衝突篩選">
      <div>
        <label for="bcrp-campus">分校</label>
        <select
          id="bcrp-campus"
          v-model="filters.campusId"
          :disabled="!isSuperAdmin && branchId != null"
          @change="load"
        >
          <option value="">全部（限權限）</option>
          <option v-for="b in allBranches" :key="b.id" :value="b.id">{{ b.name }}</option>
        </select>
      </div>
    </AtFilterBar>

    <AtSkeleton v-if="loading" rows="3" />
    <div v-else-if="loadError" class="bcrp-state">
      <AtInlineAlert tone="danger" title="無法載入衝突清單">
        <p>{{ loadError }}</p>
        <template #action>
          <AtButton shape="rect" size="sm" variant="ghost" @click="refresh">重試</AtButton>
        </template>
      </AtInlineAlert>
    </div>
    <AtEmpty
      v-else-if="conflicts.length === 0"
      icon="task_alt"
      title="無待審衝突"
      description="目前沒有同一學生綁定多個 LINE 帳號的衝突案件。"
    />

    <div v-else class="bcrp-table-wrap">
      <table class="bcrp-table">
        <thead>
          <tr>
            <th style="width:110px">學生</th>
            <th>兩方 LINE ID</th>
            <th style="width:90px">分校</th>
            <th style="width:110px">綁定時間</th>
            <th style="width:150px; text-align:right">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in conflicts" :key="c.id">
            <td>
              <div class="bcrp-student">
                <span class="bcrp-avatar">{{ (c.student_name || '?')[0] }}</span>
                <strong>{{ c.student_name || `#${c.student_id}` }}</strong>
              </div>
            </td>
            <td>
              <div class="bcrp-lines">
                <span
                  v-for="(b, i) in bindingSides(c)"
                  :key="b.id"
                  :class="['bcrp-line-chip', { 'bcrp-line-chip--keeper': b.keep }]"
                >
                  <code>{{ b.line_user_id_masked || '—' }}</code>
                  <AtButton
                    shape="rect"
                    size="sm"
                    :variant="b.keep ? 'secondary' : 'ghost'"
                    :disabled="submittingId === c.id"
                    @click="openResolveDialog(c, b)"
                  >
                    {{ b.keep ? '保留此側' : '保留此側' }}
                  </AtButton>
                </span>
              </div>
            </td>
            <td>{{ c.campus_name || `#${c.campus_id}` }}</td>
            <td class="bcrp-tabular">{{ formatDate(c.created_at || c.detected_at) }}</td>
            <td style="text-align:right">
              <AtButton
                shape="rect"
                size="sm"
                variant="ghost"
                :loading="submittingId === c.id"
                :disabled="submittingId === c.id"
                @click="openResolveDialog(c, null)"
              >
                選擇保留側
              </AtButton>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- 解決衝突確認 -->
    <AtDialog
      :open="Boolean(resolveTarget)"
      title="保留綁定並解除另一側"
      size="sm"
      panel-class="bcrp-dialog"
      @close="closeResolveDialog"
    >
      <p>
        學生 <strong>{{ resolveTarget?.conflict?.student_name || `#${resolveTarget?.conflict?.student_id}` }}</strong>
        綁定了 {{ resolveTarget ? bindingSides(resolveTarget.conflict).length : 0 }} 個 LINE 帳號。確認保留
        <strong>{{ resolveTarget?.binding?.line_user_id_masked || '此帳號' }}</strong>，並解除其他綁定嗎？
      </p>
      <p v-if="resolveError" class="bcrp-dialog-error">{{ resolveError }}</p>
      <template #actions>
        <AtButton shape="rect" variant="ghost" :disabled="submitting" @click="closeResolveDialog">取消</AtButton>
        <AtButton shape="rect" variant="danger" :loading="submitting" @click="confirmResolve">確認保留</AtButton>
      </template>
    </AtDialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { branches } from '../lib/useBranches';
import { fetchBindingConflicts, resolveBindingConflict } from '../lib/bindingsApi';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';
import AtButton from '../components/design-system/AtButton.vue';
import AtDialog from '../components/design-system/AtDialog.vue';
import AtFilterBar from '../components/design-system/AtFilterBar.vue';
import AtSkeleton from '../components/design-system/AtSkeleton.vue';
import AtEmpty from '../components/design-system/AtEmpty.vue';
import AtInlineAlert from '../components/design-system/AtInlineAlert.vue';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  userRole: { type: String, default: 'director' },
});

const isSuperAdmin = computed(() => props.userRole === 'super_admin');
const allBranches = computed(() => branches.value);

const loading = ref(false);
const loadError = ref('');
const conflicts = ref([]);
const filters = ref({ campusId: '' });
const resolveTarget = ref(null);
const submitting = ref(false);
const resolveError = ref('');
const submittingId = ref(null);

const activeCampusId = computed(() => {
  if (filters.value.campusId) return Number(filters.value.campusId);
  if (!isSuperAdmin.value && props.branchId != null && props.branchId !== '') return Number(props.branchId);
  return null;
});

function bindingSides(c) {
  const sides = Array.isArray(c.bindings) ? c.bindings : [];
  return sides.map((b, i) => ({ ...b, keep: i === 0 }));
}

function formatDate(s) {
  if (!s) return '—';
  try {
    const d = new Date(s);
    if (Number.isNaN(d.getTime())) return String(s);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  } catch { return String(s); }
}

async function load() {
  loading.value = true;
  loadError.value = '';
  try {
    const data = await fetchBindingConflicts({ campusId: activeCampusId.value });
    conflicts.value = Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
  } catch (e) {
    conflicts.value = [];
    loadError.value = (e?.status === 404 || e?.status === 500)
      ? `後端衝突 API 尚未上線（HTTP ${e.status}）。此頁依賴 W31 P2-2 端點，合併後即可使用。`
      : (e?.payload?.message || e?.message || '無法載入衝突清單');
  } finally {
    loading.value = false;
  }
}

function refresh() { load(); }

function openResolveDialog(conflict, binding) {
  const sides = bindingSides(conflict);
  const keep = binding || sides[0];
  if (!keep) return;
  resolveTarget.value = { conflict, binding: keep };
  resolveError.value = '';
}
function closeResolveDialog() {
  if (submitting.value) return;
  resolveTarget.value = null;
}
async function confirmResolve() {
  if (!resolveTarget.value) return;
  submitting.value = true;
  submittingId.value = resolveTarget.value.conflict.id;
  resolveError.value = '';
  try {
    await resolveBindingConflict(resolveTarget.value.conflict.id, resolveTarget.value.binding.id);
    conflicts.value = conflicts.value.filter((c) => c.id !== resolveTarget.value.conflict.id);
    closeResolveDialog();
  } catch (e) {
    resolveError.value = e?.payload?.message || e?.message || '解決衝突失敗，請稍後再試';
  } finally {
    submitting.value = false;
    submittingId.value = null;
  }
}

watch(() => props.branchId, () => load());
onMounted(load);
</script>

<style scoped>
.bcrp-page { max-width: 1180px; margin: 0 auto; }
.bcrp-state { padding: var(--ds-space-4) 0; }

.bcrp-table-wrap {
  background: var(--ds-surface-1);
  border: var(--ds-border-width) solid var(--ds-hairline);
  border-radius: var(--ds-radius-lg);
  overflow-x: auto;
}
.bcrp-table { width: 100%; border-collapse: collapse; font-size: var(--ds-font-size-base); }
.bcrp-table th {
  text-align: left; font-size: var(--ds-font-size-xs); font-weight: var(--ds-font-weight-semibold);
  text-transform: uppercase; letter-spacing: 0.04em; color: var(--ds-ink-mute);
  padding: var(--ds-space-3); border-bottom: var(--ds-border-width) solid var(--ds-hairline); white-space: nowrap;
}
.bcrp-table td { padding: var(--ds-space-3); border-bottom: var(--ds-border-width) solid var(--ds-hairline); color: var(--ds-ink); vertical-align: middle; }
.bcrp-table tbody tr:hover { background: var(--ds-canvas-soft); }

.bcrp-student { display: flex; align-items: center; gap: var(--ds-space-2); }
.bcrp-avatar {
  width: 28px; height: 28px; border-radius: 50%;
  display: inline-flex; align-items: center; justify-content: center;
  background: var(--ds-primary-wash); color: var(--ds-primary-deep);
  font-weight: var(--ds-font-weight-bold); font-size: var(--ds-font-size-sm); flex-shrink: 0;
}
.bcrp-lines { display: flex; flex-direction: column; gap: var(--ds-space-2); }
.bcrp-line-chip {
  display: flex; align-items: center; justify-content: space-between; gap: var(--ds-space-2);
  border: var(--ds-border-width) solid var(--ds-hairline); border-radius: var(--ds-radius-md);
  padding: 4px 8px; background: var(--ds-surface-0);
}
.bcrp-line-chip code { font-family: 'Courier New', monospace; font-size: var(--ds-font-size-sm); color: var(--ds-ink-secondary); }
.bcrp-tabular { font-variant-numeric: tabular-nums; color: var(--ds-ink-secondary); white-space: nowrap; }

.bcrp-overlay {
  position: fixed; inset: 0; z-index: var(--ds-z-modal);
  background: rgba(15, 23, 42, 0.45); display: grid; place-items: center; padding: var(--ds-space-4);
}
.bcrp-dialog {
  width: min(480px, 100%); background: var(--ds-canvas);
  border: var(--ds-border-width) solid var(--ds-hairline); border-radius: var(--ds-radius-lg);
  box-shadow: var(--ds-shadow-2); padding: var(--ds-space-5);
}
.bcrp-dialog h3 { margin: 0 0 var(--ds-space-3); font-size: var(--ds-font-size-lg); color: var(--ds-ink); }
.bcrp-dialog p { margin: 0 0 var(--ds-space-3); font-size: var(--ds-font-size-base); color: var(--ds-ink-secondary); line-height: var(--ds-line-base); }
.bcrp-dialog-actions { display: flex; justify-content: flex-end; gap: var(--ds-space-2); }
.bcrp-dialog-error { color: var(--ds-danger); font-size: var(--ds-font-size-sm); }
</style>
