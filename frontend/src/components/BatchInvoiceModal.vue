<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal batch-modal">
      <div class="batch-header">
        <h3>批次開單</h3>
        <button class="ghost icon-btn" @click="$emit('close')" title="關閉">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>

      <!-- Step indicator -->
      <div class="batch-steps">
        <div class="batch-step" :class="{ active: step === 1, done: step > 1 }">
          <span class="batch-step-num">1</span>
          <span class="batch-step-label">選擇範圍</span>
        </div>
        <div class="batch-step-line" :class="{ done: step > 1 }"></div>
        <div class="batch-step" :class="{ active: step === 2, done: step > 2 }">
          <span class="batch-step-num">2</span>
          <span class="batch-step-label">預覽確認</span>
        </div>
        <div class="batch-step-line" :class="{ done: step > 2 }"></div>
        <div class="batch-step" :class="{ active: step === 3, done: step > 3 }">
          <span class="batch-step-num">3</span>
          <span class="batch-step-label">開單結果</span>
        </div>
      </div>

      <!-- Error banner -->
      <div v-if="error" class="batch-error">
        <span class="material-symbols-outlined">error</span>
        {{ error }}
        <button class="ghost" @click="error = ''" style="margin-left:auto">✕</button>
      </div>

      <!-- ====== Step 1: Scope Selection ====== -->
      <div v-if="step === 1" class="batch-step-body">
        <div class="batch-form">
          <label class="batch-field">
            分校
            <select v-model="form.campus_id" @change="onCampusChange">
              <option :value="null">全部（權限內）</option>
              <option v-for="c in campuses" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </label>

          <label class="batch-field">
            選擇班級（可多選）
            <div class="batch-class-list" v-if="classes.length">
              <label
                v-for="c in classes"
                :key="c.id"
                class="batch-class-item"
                :class="{ selected: form.class_ids.includes(c.id) }"
              >
                <input
                  type="checkbox"
                  :value="c.id"
                  v-model="form.class_ids"
                  class="batch-class-check"
                />
                <span class="batch-class-info">
                  <strong>{{ c.name || '未命名班級' }}</strong>
                  <small>{{ c.student_count || 0 }} 位學生 · {{ c.subject || '—' }}</small>
                </span>
              </label>
            </div>
            <p v-else-if="classesLoaded" class="batch-hint">暫無班級資料</p>
            <p v-else class="batch-hint">請先選擇分校</p>
          </label>

          <label class="batch-field">
            統一繳費期限
            <input type="date" v-model="form.due_date" :min="today" />
          </label>

          <label class="batch-field">
            備註（選填）
            <textarea
              v-model="form.note"
              rows="2"
              placeholder="將顯示於所有帳單上…"
              maxlength="500"
            ></textarea>
          </label>
        </div>

        <div class="batch-step-actions">
          <button class="ghost" @click="$emit('close')">取消</button>
          <button
            class="primary"
            @click="loadPreview"
            :disabled="!form.class_ids.length || !form.due_date || previewLoading"
          >
            <span v-if="previewLoading" class="material-symbols-outlined spin">progress_activity</span>
            <span v-else class="material-symbols-outlined">visibility</span>
            預覽
          </button>
        </div>
      </div>

      <!-- ====== Step 2: Preview ====== -->
      <div v-if="step === 2" class="batch-step-body">
        <div class="batch-preview-summary">
          <span>共 {{ previewRows.length }} 筆</span>
          <span v-if="warningCount" class="batch-warn-count">
            <span class="material-symbols-outlined" style="font-size:14px">warning</span>
            {{ warningCount }} 筆警告
          </span>
          <span class="batch-preview-total">
            預計總金額：{{ formatCurrency(previewTotalAmount) }}
          </span>
        </div>

        <div class="batch-table-wrap" v-if="previewRows.length">
          <table class="batch-table">
            <thead>
              <tr>
                <th style="width:40px">
                  <input
                    type="checkbox"
                    :checked="allSelected"
                    @change="toggleAll"
                    title="全選/取消全選"
                  />
                </th>
                <th>學生</th>
                <th>班級</th>
                <th>模式</th>
                <th class="batch-col-amount">建議金額</th>
                <th class="batch-col-amount">調整金額</th>
                <th>狀態</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(row, i) in previewRows"
                :key="row.student_class_id"
                :class="{ 'batch-row-warning': row.warning, 'batch-row-deselected': !row.selected }"
              >
                <td>
                  <input type="checkbox" v-model="row.selected" />
                </td>
                <td class="batch-cell-name">{{ row.student_name }}</td>
                <td>{{ row.class_name }}</td>
                <td>
                  <span class="batch-mode-tag">{{ row.schedule_mode === 'date' ? '月結' : '堂數' }}</span>
                </td>
                <td class="batch-col-amount">{{ formatCurrency(row.suggested_amount) }}</td>
                <td class="batch-col-amount">
                  <input
                    v-model.number="row.adjusted_amount"
                    type="number"
                    class="batch-amount-input"
                    :class="{ modified: row.adjusted_amount !== row.suggested_amount }"
                    min="0"
                    step="1"
                    @focus="$event.target.select()"
                  />
                </td>
                <td>
                  <span v-if="row.warning" class="batch-warning-tag" :title="row.warning">
                    <span class="material-symbols-outlined" style="font-size:12px">warning</span>
                    {{ row.warning }}
                  </span>
                  <span v-else class="batch-ok-tag">✓</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="!previewRows.length" class="batch-empty">
          <span class="material-symbols-outlined" style="font-size:40px;color:var(--text-light)">inbox</span>
          <p>所選範圍內無可開單的學生</p>
        </div>

        <div class="batch-step-actions">
          <button class="ghost" @click="step = 1">← 返回修改</button>
          <button
            class="primary"
            @click="executeBatch"
            :disabled="!selectedRows.length || batchLoading"
          >
            <span v-if="batchLoading" class="material-symbols-outlined spin">progress_activity</span>
            <span v-else class="material-symbols-outlined">receipt_long</span>
            確認開單（{{ selectedRows.length }} 筆）
          </button>
        </div>
      </div>

      <!-- ====== Step 3: Results ====== -->
      <div v-if="step === 3" class="batch-step-body">
        <div class="batch-result-summary">
          <div class="batch-result-card batch-result-success">
            <span class="batch-result-num">{{ batchResult.success_count }}</span>
            <span class="batch-result-label">成功</span>
          </div>
          <div class="batch-result-card" :class="batchResult.failed_count ? 'batch-result-fail' : 'batch-result-neutral'">
            <span class="batch-result-num">{{ batchResult.failed_count }}</span>
            <span class="batch-result-label">失敗</span>
          </div>
        </div>

        <!-- Failure details -->
        <div v-if="batchResult.failures && batchResult.failures.length" class="batch-failures">
          <h4>失敗原因</h4>
          <ul>
            <li v-for="(f, i) in batchResult.failures" :key="i">
              <span v-if="f.student_name"><strong>{{ f.student_name }}</strong>：</span>
              {{ f.reason }}
            </li>
          </ul>
        </div>

        <div class="batch-step-actions">
          <button class="ghost" @click="step = 1; batchResult = null">開新批次</button>
          <button class="primary" @click="$emit('close'); $emit('batchCompleted')">完成</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue';

const props = defineProps({
  show: Boolean,
  branchId: { type: [Number, String], default: null },
});
const emit = defineEmits(['close', 'batchCompleted']);

const step = ref(1);
const error = ref('');
const previewLoading = ref(false);
const batchLoading = ref(false);

// Step 1 form
const today = new Date().toISOString().slice(0, 10);
const form = reactive({
  campus_id: null,
  class_ids: [],
  due_date: '',
  note: '',
});
form.due_date = today;

// Campuses & Classes
const campuses = ref([]);
const classes = ref([]);
const classesLoaded = ref(false);

// Preview data
const previewRows = ref([]);
const batchResult = ref(null);

function getToken() {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
  return session?.access_token;
}

function formatCurrency(v) {
  if (v == null || isNaN(v)) return '—';
  return 'NT$ ' + Number(v).toLocaleString('zh-TW');
}

const allSelected = computed(() =>
  previewRows.value.length > 0 && previewRows.value.every(r => r.selected)
);
const warningCount = computed(() => previewRows.value.filter(r => r.warning).length);
const previewTotalAmount = computed(() =>
  previewRows.value
    .filter(r => r.selected)
    .reduce((s, r) => s + (r.adjusted_amount ?? r.suggested_amount ?? 0), 0)
);
const selectedRows = computed(() => previewRows.value.filter(r => r.selected));

function toggleAll() {
  const newVal = !allSelected.value;
  previewRows.value.forEach(r => { if (!r.warning) r.selected = newVal; });
}

// ─── Load campuses ───
async function loadCampuses() {
  try {
    const token = getToken();
    const params = new URLSearchParams();
    if (props.branchId != null && props.branchId !== '') params.set('branch_id', String(Number(props.branchId)));
    const resp = await fetch(`/api/v1/campuses?${params}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (resp.ok) {
      const json = await resp.json();
      campuses.value = Array.isArray(json?.data) ? json.data : Array.isArray(json) ? json : [];
    }
  } catch { /* non-critical */ }
}

// ─── Load classes for selected campus ───
async function onCampusChange() {
  classes.value = [];
  classesLoaded.value = false;
  form.class_ids = [];
  if (!form.campus_id) return;
  try {
    const token = getToken();
    const resp = await fetch(`/api/v1/student-classes?campus_id=${form.campus_id}&status=active&per_page=200`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (resp.ok) {
      const json = await resp.json();
      classes.value = Array.isArray(json?.data) ? json.data : Array.isArray(json) ? json : [];
    }
  } catch { /* non-critical */ }
  classesLoaded.value = true;
}

// ─── Step 1 → 2: Load preview ───
async function loadPreview() {
  if (!form.class_ids.length || !form.due_date) return;
  previewLoading.value = true;
  error.value = '';
  try {
    const token = getToken();
    const body = {
      class_ids: form.class_ids,
      due_date: form.due_date,
    };
    if (form.campus_id) body.campus_id = form.campus_id;
    if (form.note.trim()) body.note = form.note.trim();

    const resp = await fetch('/api/v1/invoices/batch-preview', {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify(body),
    });
    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      throw new Error(err.message || `請求失敗（${resp.status}）`);
    }
    const json = await resp.json();
    const rows = (json.rows || []).map(r => ({
      ...r,
      selected: !r.warning,
      adjusted_amount: r.suggested_amount ?? 0,
    }));
    previewRows.value = rows;
    step.value = 2;
  } catch (e) {
    error.value = e.message || '載入預覽失敗';
  } finally {
    previewLoading.value = false;
  }
}

// ─── Step 2 → 3: Execute batch ───
async function executeBatch() {
  const toCreate = selectedRows.value;
  if (!toCreate.length) return;
  batchLoading.value = true;
  error.value = '';
  try {
    const token = getToken();
    const body = {
      class_ids: form.class_ids,
      due_date: form.due_date,
      rows: toCreate.map(r => ({
        student_class_id: r.student_class_id,
        amount: r.adjusted_amount ?? r.suggested_amount,
      })),
    };
    if (form.campus_id) body.campus_id = form.campus_id;
    if (form.note.trim()) body.note = form.note.trim();

    const resp = await fetch('/api/v1/invoices/batch', {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify(body),
    });
    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      throw new Error(err.message || `開單失敗（${resp.status}）`);
    }
    batchResult.value = await resp.json();
    step.value = 3;
  } catch (e) {
    error.value = e.message || '批次開單失敗';
  } finally {
    batchLoading.value = false;
  }
}

// ─── Reset on open ───
watch(() => props.show, (v) => {
  if (v) {
    step.value = 1;
    error.value = '';
    previewRows.value = [];
    batchResult.value = null;
    form.class_ids = [];
    form.due_date = today;
    form.note = '';
    if (!campuses.value.length) loadCampuses();
  }
});
</script>

<style scoped>
.modal-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.4);
  display: flex; align-items: center; justify-content: center;
  z-index: 10000; padding: 16px;
}
.modal {
  background: var(--card-bg); border-radius: 14px; padding: 24px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}
.batch-modal { width: 100%; max-width: 780px; max-height: 92vh; overflow-y: auto; }
.batch-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.batch-header h3 { margin: 0; font-size: 18px; }
.icon-btn { border: none; background: none; cursor: pointer; padding: 4px; border-radius: 8px; color: var(--text-light); }
.icon-btn:hover { background: var(--bg); }

/* Steps */
.batch-steps {
  display: flex; align-items: center; justify-content: center;
  gap: 0; margin-bottom: 24px;
}
.batch-step {
  display: flex; align-items: center; gap: 8px;
  padding: 6px 12px; border-radius: 20px;
  font-size: 13px; font-weight: 500; color: var(--text-light);
}
.batch-step.active { background: var(--primary-light, rgba(37,99,235,0.08)); color: var(--primary); font-weight: 700; }
.batch-step.done { color: var(--ds-success); }
.batch-step-num {
  width: 24px; height: 24px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 700;
  border: 2px solid var(--text-light); color: var(--text-light);
}
.batch-step.active .batch-step-num { border-color: var(--primary); background: var(--primary); color: var(--ds-on-primary); }
.batch-step.done .batch-step-num { border-color: var(--ds-success); background: var(--ds-success); color: #fff; }
.batch-step-line {
  width: 40px; height: 2px; background: var(--border);
}
.batch-step-line.done { background: var(--ds-success); }

.batch-step-body { display: flex; flex-direction: column; gap: 16px; }
.batch-error {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px; background: var(--ds-danger-wash); color: var(--ds-danger);
  border-radius: 8px; font-size: 13px; margin-bottom: 12px;
}

/* Form */
.batch-form { display: flex; flex-direction: column; gap: 14px; }
.batch-field { display: flex; flex-direction: column; gap: 5px; font-size: 13px; font-weight: 500; }
.batch-field input, .batch-field select, .batch-field textarea {
  min-height: 38px; border: 1px solid var(--border); border-radius: 8px;
  padding: 8px 10px; background: var(--bg); color: var(--text); font: inherit; font-size: 13px;
}
.batch-field textarea { resize: vertical; min-height: 56px; }
.batch-hint { color: var(--text-light); font-size: 12px; margin: 0; }

.batch-class-list {
  border: 1px solid var(--border); border-radius: 8px;
  max-height: 220px; overflow-y: auto;
}
.batch-class-item {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 12px; border-bottom: 1px solid var(--border);
  cursor: pointer; font-weight: 400; font-size: 13px; transition: background 0.1s;
}
.batch-class-item:last-child { border-bottom: none; }
.batch-class-item:hover { background: var(--bg); }
.batch-class-item.selected { background: var(--primary-light, rgba(37,99,235,0.06)); }
.batch-class-check { margin: 0; }
.batch-class-info { display: flex; flex-direction: column; gap: 2px; }
.batch-class-info strong { font-weight: 500; }
.batch-class-info small { color: var(--text-light); font-size: 11px; }

.batch-step-actions {
  display: flex; justify-content: flex-end; gap: 8px;
  margin-top: 8px; padding-top: 16px; border-top: 1px solid var(--border);
}

/* Preview */
.batch-preview-summary {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 14px; background: var(--bg); border-radius: 8px;
  font-size: 13px; flex-wrap: wrap;
}
.batch-warn-count { color: var(--ds-warning); display: flex; align-items: center; gap: 4px; font-weight: 500; }
.batch-preview-total { margin-left: auto; font-weight: 600; }

.batch-table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 8px; }
.batch-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.batch-table thead th {
  text-align: left; padding: 9px 10px; background: var(--bg);
  font-size: 11px; font-weight: 600; color: var(--text-light);
  border-bottom: 1px solid var(--border); white-space: nowrap;
  text-transform: uppercase; letter-spacing: 0.03em;
}
.batch-table td { padding: 8px 10px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.batch-table tbody tr:last-child td { border-bottom: none; }
.batch-cell-name { font-weight: 500; }
.batch-col-amount { width: 110px; text-align: right; font-variant-numeric: tabular-nums; }
.batch-row-warning { background: var(--ds-warning-wash); }
.batch-row-deselected { opacity: 0.45; }
.batch-mode-tag {
  display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;
  background: var(--ds-canvas-soft); color: var(--ds-ink-mute);
}
.batch-amount-input {
  width: 90px; text-align: right; padding: 4px 8px;
  border: 1px solid var(--border); border-radius: 6px;
  font: inherit; font-size: 13px; font-variant-numeric: tabular-nums;
  background: var(--card-bg); color: var(--text);
}
.batch-amount-input.modified { border-color: var(--ds-warning); background: var(--ds-warning-wash); }
.batch-warning-tag {
  display: inline-flex; align-items: center; gap: 3px;
  padding: 2px 7px; border-radius: 4px; font-size: 11px; font-weight: 600;
  background: var(--ds-warning-wash); color: var(--ds-warning); white-space: nowrap; cursor: help;
}
.batch-ok-tag { color: var(--ds-success); font-weight: 700; }
.batch-empty {
  text-align: center; padding: 40px 0; color: var(--text-light);
  display: flex; flex-direction: column; align-items: center; gap: 8px;
}

/* Results */
.batch-result-summary { display: flex; gap: 12px; justify-content: center; }
.batch-result-card {
  display: flex; flex-direction: column; align-items: center; gap: 4px;
  padding: 20px 32px; border-radius: 12px; border: 1px solid var(--border);
  min-width: 120px;
}
.batch-result-num { font-size: 36px; font-weight: 700; font-variant-numeric: tabular-nums; }
.batch-result-label { font-size: 13px; color: var(--text-light); }
.batch-result-success { background: var(--ds-success-wash); border-color: var(--ds-hairline); }
.batch-result-success .batch-result-num { color: var(--ds-success); }
.batch-result-fail { background: var(--ds-danger-wash); border-color: var(--ds-hairline); }
.batch-result-fail .batch-result-num { color: var(--ds-danger); }
.batch-result-neutral { background: var(--bg); }
.batch-failures { margin-top: 8px; }
.batch-failures h4 { margin: 0 0 8px; font-size: 14px; color: var(--ds-danger); }
.batch-failures ul { margin: 0; padding-left: 20px; font-size: 13px; color: var(--text); line-height: 1.8; }

/* Shared buttons */
.primary, .ghost {
  display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px;
  border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer;
  font-family: inherit; transition: all 0.15s; border: 1px solid var(--border);
}
.primary { background: var(--primary); border-color: var(--primary); color: var(--ds-on-primary); }
.primary:hover:not(:disabled) { opacity: 0.9; }
.primary:disabled { opacity: 0.5; cursor: not-allowed; }
.ghost { background: transparent; color: var(--text); }
.ghost:hover:not(:disabled) { background: var(--bg); }
.spin { animation: rotate 1s linear infinite; }
@keyframes rotate { to { transform: rotate(360deg); } }

@media (max-width: 640px) {
  .batch-modal { max-width: 100%; padding: 16px; }
  .batch-step-label { display: none; }
  .batch-step-line { width: 24px; }
}
</style>
