<template>
  <div class="bcrp-page tw:mx-auto tw:flex tw:max-w-5xl tw:flex-col tw:gap-4 tw:px-[calc(var(--ds-space-4)-10px)] tw:pb-24 tw:text-foreground tw:md:px-0 tw:md:pb-0" data-testid="binding-conflicts-page">
    <header class="tw:pb-2">
      <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-x-4 tw:gap-y-2">
        <h2 class="tw:flex tw:items-center tw:gap-2 tw:text-tpl-page tw:font-bold tw:tracking-tight">
          <ShieldAlert class="tw:size-6 tw:shrink-0 tw:text-tpl-secondary" :stroke-width="2" aria-hidden="true" />
          衝突審查
        </h2>
        <Button variant="outline" :class="rowButton" @click="refresh">
          <RefreshCw class="tw:size-4" :stroke-width="2" aria-hidden="true" />
          重新整理
        </Button>
      </div>
      <p class="tw:mt-2 tw:text-tpl-body tw:text-tpl-secondary tw:text-pretty">同一學生綁定多個 LINE 帳號時在此審查，保留正確的那一側。</p>
      <p class="tw:mt-1 tw:text-tpl-body tw:text-tpl-secondary" data-testid="binding-conflicts-count">待審衝突 <strong class="tw:text-foreground tw:tabular-nums">{{ conflicts.length }}</strong> 筆</p>
    </header>

    <div role="group" aria-label="衝突篩選" class="tw:flex tw:flex-col tw:gap-2 tw:sm:max-w-xs">
      <Label for="bcrp-campus" class="tw:text-tpl-body tw:font-semibold">分校</Label>
      <select
        id="bcrp-campus"
        v-model="filters.campusId"
        class="tw:h-12 tw:w-full tw:rounded-md tw:border tw:border-input tw:bg-background tw:px-3 tw:text-[length:var(--tpl-text-body)] tw:text-foreground tw:outline-none tw:focus-visible:border-ring tw:focus-visible:ring-[3px] tw:focus-visible:ring-ring/50 tw:disabled:cursor-not-allowed tw:disabled:bg-secondary"
        :disabled="!isSuperAdmin && branchId != null"
        @change="load"
      >
        <option value="">全部（限權限）</option>
        <option v-for="b in allBranches" :key="b.id" :value="b.id">{{ b.name }}</option>
      </select>
    </div>

    <div class="tw:flex tw:flex-col tw:gap-4" :aria-busy="loading ? 'true' : 'false'" data-testid="binding-conflicts-content">
      <div v-if="loading" class="tw:flex tw:flex-col tw:gap-3" role="status" aria-live="polite" data-testid="binding-conflicts-loading">
        <span class="tw:sr-only">載入中</span>
        <Skeleton v-for="n in 3" :key="n" class="tw:h-14 tw:w-full" />
      </div>
      <Alert v-else-if="loadError" tone="danger" title="無法載入衝突清單" class="tw:text-tpl-body">
        <p class="tw:text-[length:var(--tpl-text-body)]">{{ loadError }}</p>
        <Button variant="outline" :class="[rowButton, 'tw:mt-3 tw:text-foreground']" @click="refresh">重試</Button>
      </Alert>
      <Card v-else-if="conflicts.length === 0" class="tw:items-center tw:gap-2 tw:py-10 tw:text-center tw:shadow-none" data-testid="binding-conflicts-empty">
        <CircleCheck class="tw:size-8 tw:text-tpl-secondary" :stroke-width="1.75" aria-hidden="true" />
        <h3 class="tw:text-tpl-list tw:font-bold">無待審衝突</h3>
        <p class="tw:max-w-prose tw:px-5 tw:text-tpl-body tw:text-tpl-secondary">目前沒有同一學生綁定多個 LINE 帳號的衝突案件。</p>
      </Card>
      <template v-else>
        <Card class="bcrp-table-wrap tw:gap-0 tw:py-0 tw:shadow-none tw:max-[720px]:hidden">
          <Table class="bcrp-table tw:text-[length:var(--tpl-text-body)] tw:[&_td]:text-[length:var(--tpl-text-body)] tw:[&_th]:text-[length:var(--tpl-text-caption)]">
            <TableCaption class="tw:sr-only">待審綁定衝突清單</TableCaption>
            <TableHeader>
              <TableRow class="tw:hover:bg-transparent">
                <TableHead scope="col" :class="headCell">學生</TableHead>
                <TableHead scope="col" :class="headCell">兩方 LINE ID</TableHead>
                <TableHead scope="col" :class="headCell">分校</TableHead>
                <TableHead scope="col" :class="headCell">綁定時間</TableHead>
                <TableHead scope="col" :class="headCell">操作</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="c in conflicts" :key="c.id">
                <TableCell class="tw:px-4 tw:py-3 tw:whitespace-normal tw:[overflow-wrap:anywhere]"><strong>{{ studentLabel(c) }}</strong></TableCell>
                <TableCell class="tw:px-4 tw:py-3">
                  <ul class="tw:flex tw:list-none tw:flex-col tw:gap-2">
                    <li v-for="b in bindingSides(c)" :key="b.id" :class="lineChip">
                      <code :class="lineCode">{{ b.line_user_id_masked || '—' }}</code>
                      <Button variant="outline" :class="rowButton" :disabled="submittingId === c.id" :aria-label="sideLabel(c, b)" @click="openResolveDialog(c, b)">{{ sideAction }}</Button>
                    </li>
                  </ul>
                  <p v-if="resolveErrorId === c.id" :class="resolveReason" role="alert" data-testid="binding-conflicts-resolve-error">{{ resolveError }}</p>
                </TableCell>
                <TableCell class="tw:px-4 tw:py-3 tw:whitespace-normal">{{ campusNames(c) }}</TableCell>
                <TableCell class="tw:px-4 tw:py-3 tw:tabular-nums tw:text-tpl-secondary">{{ formatDate(c.bindings?.[0]?.bound_at) }}</TableCell>
                <TableCell class="tw:px-4 tw:py-2">
                  <Button variant="outline" :class="rowButton" :disabled="submittingId === c.id" :aria-label="`${rowAction}：${studentLabel(c)}`" @click="openResolveDialog(c, null)">{{ rowAction }}</Button>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </Card>
        <ul class="bcrp-mobile-list tw:flex tw:list-none tw:flex-col tw:gap-3 tw:not-max-[720px]:hidden" aria-label="待審綁定衝突清單">
          <li v-for="c in conflicts" :key="c.id">
            <Card class="bcrp-mobile-card tw:gap-0 tw:py-4 tw:shadow-none">
              <CardContent class="tw:flex tw:flex-col tw:gap-3 tw:px-4">
                <strong class="tw:min-w-0 tw:text-tpl-list tw:[overflow-wrap:anywhere]">{{ studentLabel(c) }}</strong>
                <ul class="tw:flex tw:list-none tw:flex-col tw:gap-2" aria-label="綁定的 LINE 帳號">
                  <li v-for="b in bindingSides(c)" :key="b.id" :class="lineChip">
                    <code :class="lineCode">{{ b.line_user_id_masked || '—' }}</code>
                    <Button variant="outline" :class="rowButton" :disabled="submittingId === c.id" :aria-label="sideLabel(c, b)" @click="openResolveDialog(c, b)">{{ sideAction }}</Button>
                  </li>
                </ul>
                <p v-if="resolveErrorId === c.id" :class="resolveReason" role="alert" data-testid="binding-conflicts-resolve-error">{{ resolveError }}</p>
                <dl class="tw:grid tw:gap-2 tw:text-tpl-body">
                  <div class="tw:grid tw:grid-cols-[5rem_minmax(0,1fr)] tw:gap-2">
                    <dt class="tw:font-semibold tw:text-tpl-secondary">分校</dt>
                    <dd class="tw:min-w-0 tw:[overflow-wrap:anywhere]">{{ campusNames(c) }}</dd>
                  </div>
                  <div class="tw:grid tw:grid-cols-[5rem_minmax(0,1fr)] tw:gap-2">
                    <dt class="tw:font-semibold tw:text-tpl-secondary">綁定時間</dt>
                    <dd class="tw:tabular-nums">{{ formatDate(c.bindings?.[0]?.bound_at) }}</dd>
                  </div>
                </dl>
                <Button variant="outline" :class="[rowButton, 'tw:w-full']" :disabled="submittingId === c.id" :aria-label="`${rowAction}：${studentLabel(c)}`" @click="openResolveDialog(c, null)">{{ rowAction }}</Button>
              </CardContent>
            </Card>
          </li>
        </ul>
      </template>
    </div>

    <!-- 解決衝突確認. A refused request closes the confirm; its reason shows on that student with role="alert". -->
    <ConfirmDialog
      :open="resolveOpen"
      :title="isSuperAdmin ? '保留綁定並解除另一側' : '解除此綁定'"
      :description="resolveMessage"
      :confirm-label="isSuperAdmin ? '確認保留' : '確認解除'"
      destructive
      :loading="submitting"
      @update:open="onResolveOpenChange"
      @confirm="confirmResolve"
    />
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, watch } from 'vue';
import { CircleCheck, RefreshCw, ShieldAlert } from 'lucide-vue-next';
import { ConfirmDialog } from '@/components/app-overlay';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { branches } from '../lib/useBranches';
import { fetchBindingConflicts, resolveBindingConflict } from '../lib/bindingsApi';

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
const resolveOpen = ref(false);
const submitting = ref(false);
const resolveError = ref('');
const resolveErrorId = ref(null);
const submittingId = ref(null);

const activeCampusId = computed(() => {
  if (filters.value.campusId) return Number(filters.value.campusId);
  if (!isSuperAdmin.value && props.branchId != null && props.branchId !== '') return Number(props.branchId);
  return null;
});

// Presentation only. Table heads override the global `th` rule; row buttons are 48px tall.
const headCell = 'tw:h-12 tw:px-4 tw:font-semibold tw:normal-case tw:text-tpl-secondary';
const rowButton = 'tw:h-12 tw:px-4 tw:text-[length:var(--tpl-text-body)] tw:font-semibold tw:shadow-none';
const lineChip = 'bcrp-line-chip tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2 tw:rounded-lg tw:border tw:border-border tw:bg-secondary tw:px-3 tw:py-2';
const resolveReason = 'tw:mt-2 tw:text-tpl-caption tw:font-semibold tw:text-destructive tw:[overflow-wrap:anywhere]';
const lineCode = 'tw:min-w-0 tw:font-mono tw:text-[length:var(--tpl-text-body)] tw:[overflow-wrap:anywhere]';
const sideAction = computed(() => (isSuperAdmin.value ? '保留此側' : '解除此綁定'));
const rowAction = computed(() => (isSuperAdmin.value ? '選擇保留側' : '選擇解除側'));
const studentLabel = (c) => c?.student_name || `#${c?.student_id}`;
const sideLabel = (c, b) => `${sideAction.value}：${studentLabel(c)} ${b.line_user_id_masked || '此帳號'}`;
// The same question as before, as one sentence.
const resolveMessage = computed(() => {
  const target = resolveTarget.value;
  if (!target) return '';
  const account = target.binding?.line_user_id_masked || '此帳號';
  return `學生 ${studentLabel(target.conflict)} 綁定了 ${bindingSides(target.conflict).length} 個 LINE 帳號。`
    + (isSuperAdmin.value ? `確認保留 ${account}，並解除其他綁定嗎？` : `確認解除 ${account} 的綁定嗎？`);
});

function bindingSides(c) {
  return Array.isArray(c.bindings) ? c.bindings : [];
}

function campusNames(c) {
  const names = (c.bindings || []).map((b) => b.campus_name || `#${b.campus_id}`);
  return [...new Set(names)].join('、');
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
  resolveErrorId.value = null;
  try {
    const data = await fetchBindingConflicts({ campusId: activeCampusId.value });
    conflicts.value = Array.isArray(data?.conflicts) ? data.conflicts : [];
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
  resolveErrorId.value = null;
  resolveOpen.value = true;
}
// The confirm button emits both a close request and `confirm` in the same click (order is the library's),
// so the close is decided one tick later: while the request runs the dialog stays open.
// The target is kept after closing, so the question does not empty during the close animation.
async function onResolveOpenChange(open) {
  await nextTick();
  if (submitting.value) return;
  resolveOpen.value = open;
}
async function confirmResolve() {
  // The target is kept after closing, so a click that lands during the close animation must not send again.
  if (submitting.value || !resolveOpen.value || !resolveTarget.value) return;
  submitting.value = true;
  submittingId.value = resolveTarget.value.conflict.id;
  resolveError.value = '';
  try {
    await resolveBindingConflict(
      resolveTarget.value.conflict.id,
      resolveTarget.value.binding.id,
      isSuperAdmin.value ? 'keep' : 'delete',
    );
    resolveOpen.value = false; // a close request is ignored while submitting (Codex P2 on #4891)
    await load(); // a delete may leave the conflict open, so re-read instead of dropping the row
  } catch (e) {
    // The reason is shown on the student's row (role="alert"): inside the open dialog it would not be announced.
    resolveError.value = e?.payload?.message || e?.message || '解決衝突失敗，請稍後再試';
    resolveErrorId.value = resolveTarget.value.conflict.id;
    resolveOpen.value = false;
  } finally {
    submitting.value = false;
    submittingId.value = null;
  }
}

watch(() => props.branchId, () => load());
onMounted(load);
</script>
