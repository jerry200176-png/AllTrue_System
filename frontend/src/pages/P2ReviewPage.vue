<template>
  <div class="p2r-page">
    <header class="page-header p2r-header">
      <div>
        <h2>重複排課審核</h2>
        <p class="page-desc">學生同時存在兩筆有效契約，需主任逐案決定保留哪一份。</p>
      </div>
      <div class="p2r-header-btns">
        <button class="primary" type="button" @click="refresh">重新整理</button>
      </div>
    </header>

    <section class="p2r-sop-card" aria-label="審核說明">
      <h3>審核說明</h3>
      <ol>
        <li>每組顯示學生同時持有的兩筆契約，請檢視課程詳情後決定保留哪一筆。</li>
        <li>點擊「保留此契約」按鈕，系統會自動取消另一筆契約的剩餘堂次。</li>
        <li>請填寫決策原因以供日後稽核追溯。</li>
      </ol>
    </section>

    <section class="p2r-list-wrap">
      <div v-if="!hasBranch" class="p2r-state p2r-state-empty">
        <span class="material-symbols-outlined p2r-empty-icon p2r-empty-icon-info" aria-hidden="true">location_city</span>
        <div class="p2r-empty-title">請先選擇要管理的分校</div>
        <div class="p2r-empty-sub">重複排課審核屬於各分校的內部資料，請於上方切換分校後再查看。</div>
      </div>
      <div v-else-if="loading" class="p2r-state p2r-state-loading">
        <div class="p2r-spinner" aria-hidden="true"></div>
        <span>載入中…</span>
      </div>
      <div v-else-if="errorMsg" class="p2r-state p2r-state-error" role="alert">
        <span class="material-symbols-outlined" aria-hidden="true">error</span>
        <span>{{ errorMsg }}</span>
        <button class="ghost xs" type="button" @click="refresh">重試</button>
      </div>
      <div v-else-if="groups.length === 0" class="p2r-state p2r-state-empty">
        <span class="material-symbols-outlined p2r-empty-icon" aria-hidden="true">task_alt</span>
        <div class="p2r-empty-title">目前沒有待審核的重複排課</div>
        <div class="p2r-empty-sub">所有重複群組皆已審核完畢，或系統尚未偵測到重複契約。</div>
      </div>

      <table v-else class="p2r-table p2r-desktop">
        <thead>
          <tr>
            <th style="width:100px">學生</th>
            <th>契約 A</th>
            <th>契約 B</th>
            <th style="width:140px">重複時段</th>
            <th style="width:220px; text-align:right">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="group in groups" :key="group.group_id || group.id">
            <td>
              <div class="p2r-student-name">{{ group.student_name || '—' }}</div>
            </td>
            <td>
              <ContractCell
                v-if="group.student_classes?.[0]"
                :sc="group.student_classes[0]"
                :is-decided="group.decided_sc_id === group.student_classes[0].id"
              />
              <span v-else class="p2r-na">—</span>
            </td>
            <td>
              <ContractCell
                v-if="group.student_classes?.[1]"
                :sc="group.student_classes[1]"
                :is-decided="group.decided_sc_id === group.student_classes[1].id"
              />
              <span v-else class="p2r-na">—</span>
            </td>
            <td>
              <div v-if="group.duplicate_dates?.length" class="p2r-dup-dates">
                <div v-for="(d, i) in group.duplicate_dates" :key="i">{{ d }}</div>
              </div>
              <div v-if="group.duplicate_time_slots?.length" class="p2r-dup-slots">
                <span v-for="(slot, i) in group.duplicate_time_slots" :key="i" class="p2r-dup-slot">{{ slot }}</span>
              </div>
              <span v-if="!group.duplicate_dates?.length && !group.duplicate_time_slots?.length" class="p2r-na">—</span>
            </td>
            <td style="text-align:right">
              <div class="p2r-actions">
                <button
                  v-if="!group.decided_sc_id"
                  v-for="sc in group.student_classes"
                  :key="sc.id"
                  class="primary xs"
                  type="button"
                  :disabled="busyGroupId === (group.group_id || group.id)"
                  @click="openDecideDialog(group, sc)"
                >
                  保留契約 #{{ sc.id }}
                </button>
                <span v-else class="p2r-decided-badge">
                  <span class="material-symbols-outlined" aria-hidden="true" style="font-size:14px">check_circle</span>
                  已保留 #{{ group.decided_sc_id }}
                </span>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-if="hasBranch && groups.length > 0" class="p2r-mobile">
        <article v-for="group in groups" :key="'m-' + (group.group_id || group.id)" class="p2r-mcard">
          <header class="p2r-mcard-head">
            <span class="p2r-student-name">{{ group.student_name || '—' }}</span>
            <span v-if="group.decided_sc_id" class="p2r-decided-badge">
              <span class="material-symbols-outlined" aria-hidden="true" style="font-size:14px">check_circle</span>
              已保留 #{{ group.decided_sc_id }}
            </span>
          </header>
          <div class="p2r-mcard-contracts">
            <div v-for="sc in group.student_classes" :key="sc.id" class="p2r-mcard-contract">
              <ContractCell :sc="sc" :is-decided="group.decided_sc_id === sc.id" />
              <button
                v-if="!group.decided_sc_id"
                class="primary xs p2r-mcard-btn"
                type="button"
                :disabled="busyGroupId === (group.group_id || group.id)"
                @click="openDecideDialog(group, sc)"
              >
                保留契約 #{{ sc.id }}
              </button>
            </div>
          </div>
          <div v-if="group.duplicate_dates?.length || group.duplicate_time_slots?.length" class="p2r-mcard-dups">
            <div class="p2r-mcard-label">重複時段</div>
            <div v-if="group.duplicate_dates?.length" class="p2r-dup-dates">
              <span v-for="(d, i) in group.duplicate_dates" :key="i">{{ d }}</span>
            </div>
            <div v-if="group.duplicate_time_slots?.length" class="p2r-dup-slots">
              <span v-for="(slot, i) in group.duplicate_time_slots" :key="i" class="p2r-dup-slot">{{ slot }}</span>
            </div>
          </div>
        </article>
      </div>
    </section>

    <Teleport to="body">
      <Transition name="p2r-modal">
        <div v-if="dialog.open" class="p2r-modal-layer" @click.self="closeDialog">
          <div class="p2r-modal-card" role="dialog" aria-modal="true" :aria-label="'審核決策 - ' + dialog.studentName">
            <div class="p2r-modal-head">
              <h3>保留契約決策</h3>
              <button type="button" class="p2r-modal-close" @click="closeDialog" aria-label="關閉">
                <span class="material-symbols-outlined">close</span>
              </button>
            </div>
            <div class="p2r-modal-body">
              <p class="p2r-modal-desc">
                學生 <strong>{{ dialog.studentName }}</strong> 同時持有兩筆契約，您選擇保留：
              </p>
              <div class="p2r-modal-contract">
                <ContractCell :sc="dialog.selectedSc" :highlight="true" />
              </div>
              <p class="p2r-modal-warn">
                送出後，另一筆契約的剩餘堂次將被取消，此操作無法復原。
              </p>
              <label class="p2r-modal-label" :for="'p2r-reason-' + dialog.groupId">
                決策原因 <span class="p2r-required" aria-hidden="true">*</span>
                <span class="p2r-reason-hint">（至少 5 字）</span>
              </label>
              <textarea
                :id="'p2r-reason-' + dialog.groupId"
                class="p2r-textarea"
                rows="3"
                maxlength="300"
                v-model="dialog.reason"
                placeholder="例：新契約為 2026-07 主任核定、家長要求更換老師…"
              ></textarea>
              <div v-if="dialog.error" class="p2r-dialog-error" role="alert">{{ dialog.error }}</div>
            </div>
            <div class="p2r-modal-foot">
              <button class="ghost" type="button" @click="closeDialog">取消</button>
              <button
                class="primary"
                type="button"
                :disabled="!canSubmit || dialog.submitting"
                @click="submitDecision"
              >
                {{ dialog.submitting ? '送出中…' : '確認保留' }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <Teleport to="body">
      <Transition name="p2r-toast">
        <div v-if="toast.visible" class="p2r-toast" :class="'p2r-toast-' + toast.tone" role="status">
          <span class="material-symbols-outlined" aria-hidden="true">
            {{ toast.tone === 'success' ? 'check_circle' : (toast.tone === 'error' ? 'error' : 'info') }}
          </span>
          <span>{{ toast.text }}</span>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch, h, defineComponent } from 'vue';
import { fetchP2ReviewGroups, submitP2ReviewDecision } from '../lib/duplicateSessionReviewApi';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
});

const groups = ref([]);
const loading = ref(false);
const errorMsg = ref('');
const busyGroupId = ref(null);

const dialog = reactive({
  open: false,
  groupId: null,
  studentName: '',
  selectedSc: null,
  reason: '',
  error: '',
  submitting: false,
});

const toast = reactive({ visible: false, text: '', tone: 'success' });
let toastTimer = null;

const hasBranch = computed(() => {
  const v = props.branchId;
  return v !== null && v !== undefined && v !== '' && Number(v) > 0;
});

const canSubmit = computed(() => {
  return (dialog.reason || '').trim().length >= 5 && !dialog.submitting;
});

function showToast(text, tone = 'success') {
  if (toastTimer) clearTimeout(toastTimer);
  toast.text = text;
  toast.tone = tone;
  toast.visible = true;
  toastTimer = setTimeout(() => { toast.visible = false; }, 3500);
}

function openDecideDialog(group, sc) {
  dialog.open = true;
  dialog.groupId = group.group_id || group.id;
  dialog.studentName = group.student_name || '';
  dialog.selectedSc = sc;
  dialog.reason = '';
  dialog.error = '';
  dialog.submitting = false;
}

function closeDialog() {
  if (dialog.submitting) return;
  dialog.open = false;
  dialog.selectedSc = null;
}

function onKeyDown(e) {
  if (e.key === 'Escape' && dialog.open) closeDialog();
}

async function submitDecision() {
  const reason = (dialog.reason || '').trim();
  if (reason.length < 5) return;
  if (!dialog.selectedSc?.id) return;
  dialog.error = '';
  dialog.submitting = true;
  busyGroupId.value = dialog.groupId;
  try {
    await submitP2ReviewDecision(dialog.groupId, {
      keep_student_class_id: dialog.selectedSc.id,
      reason,
    });
    showToast('審核決策已送出，系統已自動取消另一筆契約的剩餘堂次。');
    dialog.open = false;
    groups.value = groups.value.filter(
      g => (g.group_id || g.id) !== dialog.groupId
    );
  } catch (e) {
    if (e.status === 409) {
      dialog.error = '此重複群組已被其他主任審核，請重新整理頁面。';
    } else if (e.status === 401 || e.status === 403) {
      dialog.error = '您沒有執行此操作的權限。';
    } else {
      dialog.error = e?.message || '送出失敗，請稍後再試。';
    }
  } finally {
    dialog.submitting = false;
    busyGroupId.value = null;
  }
}

async function load() {
  if (!hasBranch.value) {
    groups.value = [];
    loading.value = false;
    errorMsg.value = '';
    return;
  }
  loading.value = true;
  errorMsg.value = '';
  try {
    const data = await fetchP2ReviewGroups({ branchId: props.branchId });
    groups.value = Array.isArray(data?.groups) ? data.groups : (Array.isArray(data) ? data : []);
  } catch (e) {
    errorMsg.value = e?.payload?.error || e?.message || '載入失敗，請重試';
    groups.value = [];
  } finally {
    loading.value = false;
  }
}

function refresh() { load(); }

watch(() => props.branchId, () => { load(); });
onMounted(() => {
  load();
  document.addEventListener('keydown', onKeyDown);
});
onBeforeUnmount(() => {
  if (toastTimer) clearTimeout(toastTimer);
  document.removeEventListener('keydown', onKeyDown);
});

const ContractCell = defineComponent({
  name: 'ContractCell',
  props: {
    sc: { type: Object, required: true },
    isDecided: { type: Boolean, default: false },
    highlight: { type: Boolean, default: false },
  },
  setup(p) {
    return () => {
      const sc = p.sc;
      if (!sc) return h('span', { class: 'p2r-na' }, '—');
      return h('div', {
        class: ['p2r-contract-cell', {
          'p2r-contract-decided': p.isDecided,
          'p2r-contract-highlight': p.highlight,
        }],
      }, [
        h('div', { class: 'p2r-cc-row' }, [
          h('span', { class: 'p2r-cc-label' }, '老師'),
          h('span', { class: 'p2r-cc-value' }, sc.teacher_name || '—'),
        ]),
        h('div', { class: 'p2r-cc-row' }, [
          h('span', { class: 'p2r-cc-label' }, '科目'),
          h('span', { class: 'p2r-cc-value' }, sc.subject || '—'),
        ]),
        h('div', { class: 'p2r-cc-row' }, [
          h('span', { class: 'p2r-cc-label' }, '總堂數'),
          h('span', { class: 'p2r-cc-value p2r-cc-num' }, sc.session_count ?? '—'),
        ]),
        h('div', { class: 'p2r-cc-row' }, [
          h('span', { class: 'p2r-cc-label' }, '剩餘'),
          (sc.remaining_sessions ?? 999) <= 3
            ? h('span', { class: 'p2r-cc-value p2r-cc-num p2r-cc-low' }, [
                h('span', { class: 'material-symbols-outlined p2r-cc-warn-icon', 'aria-hidden': 'true' }, 'warning'),
                ' ' + (sc.remaining_sessions ?? '—'),
              ])
            : h('span', { class: 'p2r-cc-value p2r-cc-num' }, sc.remaining_sessions ?? '—'),
        ]),
        h('div', { class: 'p2r-cc-row' }, [
          h('span', { class: 'p2r-cc-label' }, '開課日'),
          h('span', { class: 'p2r-cc-value' }, sc.start_date || '—'),
        ]),
        p.isDecided ? h('div', { class: 'p2r-cc-badge' }, '✓ 已保留') : null,
      ]);
    };
  },
});
</script>

<style scoped>
.p2r-page { max-width: 1200px; margin: 0 auto; }

.p2r-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 12px;
}
.p2r-header-btns { display: flex; gap: 8px; }

.p2r-sop-card {
  margin-bottom: 12px;
  border: 1px solid var(--border);
  background: var(--canvas-soft, #f6f9fc);
  border-radius: 8px;
  padding: 12px 16px;
}
.p2r-sop-card h3 {
  margin: 0 0 8px;
  font-size: 13px;
  color: var(--primary);
}
.p2r-sop-card ol { margin: 0; padding-left: 18px; }
.p2r-sop-card li {
  margin-bottom: 6px;
  font-size: 12px;
  color: var(--text);
  line-height: 1.5;
}

.p2r-list-wrap {
  background: var(--card-bg, #fff);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 12px;
}

.p2r-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 48px 16px;
  color: var(--text-light);
  text-align: center;
}
.p2r-state-error { color: var(--danger); }
.p2r-empty-icon { font-size: 48px; color: var(--success); }
.p2r-empty-icon-info { color: var(--primary) !important; }
.p2r-empty-title { font-size: 16px; font-weight: 700; color: var(--text); }
.p2r-empty-sub { font-size: 13px; color: var(--text-light); }
.p2r-spinner {
  width: 24px;
  height: 24px;
  border: 3px solid rgba(0,0,0,0.08);
  border-top-color: var(--primary);
  border-radius: 50%;
  animation: p2r-spin 0.9s linear infinite;
}
@keyframes p2r-spin { to { transform: rotate(360deg); } }

.p2r-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.p2r-table th, .p2r-table td {
  padding: 8px 8px;
  border-bottom: 1px solid var(--border);
  text-align: left;
  vertical-align: top;
}
.p2r-table th {
  background: var(--canvas-soft, #f6f9fc);
  font-weight: 600;
  color: var(--text-light);
  font-size: 12px;
}
.p2r-student-name { font-weight: 700; color: var(--text); }
.p2r-na { color: var(--text-light); font-size: 12px; }

.p2r-contract-cell {
  background: var(--canvas-soft, #f6f9fc);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 8px 10px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.p2r-contract-highlight {
  border-color: var(--primary);
  background: var(--primary-wash, #FFF8E1);
}
.p2r-contract-decided {
  border-color: var(--success);
  background: rgba(26, 130, 69, 0.06);
  opacity: 0.7;
}
.p2r-cc-row { display: flex; gap: 6px; font-size: 12px; }
.p2r-cc-label { color: var(--text-light); min-width: 40px; flex-shrink: 0; }
.p2r-cc-value { color: var(--text); }
.p2r-cc-num { font-variant-numeric: tabular-nums; font-weight: 600; }
.p2r-cc-low { color: var(--danger); display: inline-flex; align-items: center; gap: 2px; }
.p2r-cc-warn-icon { font-size: 14px; color: var(--danger); }
.p2r-cc-badge { font-size: 12px; font-weight: 700; color: var(--success); margin-top: 2px; }

.p2r-dup-dates { display: flex; flex-wrap: wrap; gap: 4px; font-size: 12px; color: var(--text); }
.p2r-dup-slots { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
.p2r-dup-slot {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
  background: var(--warning-soft, #fffbeb);
  color: var(--warning);
  border: 1px solid var(--warning-border, #fde68a);
}

.p2r-actions { display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap; }
.p2r-decided-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  font-weight: 600;
  color: var(--success);
}

.p2r-modal-layer {
  position: fixed;
  inset: 0;
  z-index: 10050;
  background: var(--ds-overlay, rgba(15, 23, 42, 0.45));
  display: grid;
  place-items: center;
  padding: 16px;
}
.p2r-modal-card {
  width: min(520px, 100%);
  border-radius: 12px;
  background: var(--modal-bg, var(--card-bg, #fff));
  border: 1px solid var(--border);
  box-shadow: 0 22px 48px rgba(15, 23, 42, 0.28);
  display: flex;
  flex-direction: column;
  max-height: 90vh;
  overflow-y: auto;
}
.p2r-modal-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px 0;
}
.p2r-modal-head h3 { margin: 0; font-size: 18px; color: var(--text); }
.p2r-modal-close {
  background: transparent;
  border: 0;
  cursor: pointer;
  color: var(--text-light);
  padding: 4px;
  border-radius: 8px;
  min-height: 36px;
  min-width: 36px;
  display: grid;
  place-items: center;
}
.p2r-modal-close:hover { background: var(--canvas-soft, #f6f9fc); }
.p2r-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 12px; }
.p2r-modal-desc { margin: 0; font-size: 14px; color: var(--text); }
.p2r-modal-contract { margin: 4px 0; }
.p2r-modal-warn {
  margin: 0;
  font-size: 13px;
  color: var(--danger);
  padding: 8px 12px;
  background: rgba(225, 29, 72, 0.06);
  border: 1px solid rgba(225, 29, 72, 0.2);
  border-radius: 8px;
}
.p2r-modal-label { font-size: 13px; color: var(--text); font-weight: 600; }
.p2r-reason-hint { font-weight: 400; color: var(--text-light); }
.p2r-required { color: var(--danger); }
.p2r-textarea {
  width: 100%;
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 13px;
  min-height: 80px;
  resize: vertical;
  box-sizing: border-box;
  background: var(--input-bg, #fff);
  color: inherit;
  font-family: inherit;
}
.p2r-textarea:focus {
  outline: none;
  border-color: var(--primary);
  box-shadow: 0 0 0 3px rgba(239, 108, 0, 0.15);
}
.p2r-dialog-error {
  color: var(--danger);
  background: rgba(225, 29, 72, 0.08);
  border: 1px solid rgba(225, 29, 72, 0.2);
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 13px;
}
.p2r-modal-foot {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding: 16px 20px;
}

/* 按鈕 hover/active 態 */
.p2r-page button:not(:disabled):not(.p2r-modal-close):hover { opacity: 0.85; }
.p2r-page button:not(:disabled):not(.p2r-modal-close):active { scale: 0.97; }

.p2r-modal-enter-active, .p2r-modal-leave-active { transition: opacity 0.2s ease; }
.p2r-modal-enter-active .p2r-modal-card,
.p2r-modal-leave-active .p2r-modal-card { transition: transform 0.2s ease, opacity 0.2s ease; }
.p2r-modal-enter-from, .p2r-modal-leave-to { opacity: 0; }
.p2r-modal-enter-from .p2r-modal-card { opacity: 0; transform: translateY(12px) scale(0.97); }
.p2r-modal-leave-to .p2r-modal-card { opacity: 0; transform: translateY(8px) scale(0.98); }

.p2r-mobile { display: none; }
.p2r-mcard {
  background: var(--card-bg, #fff);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 12px;
  margin-bottom: 10px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.p2r-mcard-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.p2r-mcard-contracts { display: flex; flex-direction: column; gap: 8px; }
.p2r-mcard-contract { display: flex; flex-direction: column; gap: 8px; }
.p2r-mcard-btn { align-self: flex-end; }
.p2r-mcard-dups {
  padding: 8px 10px;
  background: var(--canvas-soft, #f6f9fc);
  border-radius: 8px;
}
.p2r-mcard-label { font-size: 12px; color: var(--text-light); font-weight: 600; margin-bottom: 4px; }

@media (max-width: 768px) {
  .p2r-desktop { display: none; }
  .p2r-mobile { display: block; }
}

.p2r-toast {
  position: fixed;
  top: 24px;
  right: 24px;
  z-index: 10060;
  background: var(--success);
  color: #fff;
  padding: 12px 18px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  box-shadow: 0 6px 16px rgba(15, 23, 42, 0.2);
  max-width: min(420px, 90vw);
}
.p2r-toast-error { background: var(--danger); }
.p2r-toast-info { background: var(--primary); }
.p2r-toast-enter-active, .p2r-toast-leave-active { transition: all 200ms ease; }
.p2r-toast-enter-from, .p2r-toast-leave-to { opacity: 0; transform: translateY(-8px); }
@media (max-width: 480px) {
  .p2r-toast { top: 12px; right: 12px; left: 12px; }
}
</style>
