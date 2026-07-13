<template>
  <div class="billing-health">
    <div class="billing-health__header">
      <div>
        <h1 class="billing-health__title">帳務健康檢查</h1>
        <p class="billing-health__sub">帳務一致性、繳費狀態分歧、堂數制轉換異常稽核。僅超級管理員可操作。</p>
      </div>
      <button
        class="btn-refresh"
        :disabled="loading"
        @click="loadHealth"
        title="重新整理"
      >
        <span class="material-symbols-outlined" aria-hidden="true">refresh</span>
        <span>重新整理</span>
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="billing-health__loading">
      <div class="spinner" aria-label="載入中"></div>
      <span>載入帳務健康資料…</span>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="billing-health__error" role="alert">
      <div class="billing-health__error-header">
        <span class="material-symbols-outlined" aria-hidden="true">error</span>
        <strong>載入失敗</strong>
      </div>
      <p>{{ error }}</p>
      <button class="btn-primary" @click="loadHealth">重試</button>
    </div>

    <!-- Content -->
    <template v-else>
      <!-- Summary Cards -->
      <section class="summary-cards" aria-label="帳務摘要">
        <article class="summary-card" :class="{ 'summary-card--critical': health.charge_consistency.inconsistent > 0 }">
          <div class="summary-card__icon">
            <span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>
          </div>
          <div class="summary-card__body">
            <div class="summary-card__value">{{ health.charge_consistency.inconsistent }}</div>
            <div class="summary-card__label">金額不一致</div>
          </div>
          <div class="summary-card__total">
            共檢查 {{ health.charge_consistency.checked }} 筆
          </div>
        </article>

        <article class="summary-card" :class="{ 'summary-card--critical': health.payment_divergence.divergent > 0 }">
          <div class="summary-card__icon">
            <span class="material-symbols-outlined" aria-hidden="true">compare_arrows</span>
          </div>
          <div class="summary-card__body">
            <div class="summary-card__value">{{ health.payment_divergence.divergent }}</div>
            <div class="summary-card__label">繳費狀態分歧</div>
          </div>
          <div class="summary-card__total">
            共 {{ health.payment_divergence.total_active }} 筆活躍課程
          </div>
        </article>

        <article class="summary-card" :class="{ 'summary-card--critical': health.mode_transition_anomalies.anomalous > 0 }">
          <div class="summary-card__icon">
            <span class="material-symbols-outlined" aria-hidden="true">swap_horiz</span>
          </div>
          <div class="summary-card__body">
            <div class="summary-card__value">{{ health.mode_transition_anomalies.anomalous }}</div>
            <div class="summary-card__label">轉換異常</div>
          </div>
          <div class="summary-card__total">
            共 {{ health.mode_transition_anomalies.total_transitions }} 筆轉換
          </div>
        </article>
      </section>

      <!-- Charge Consistency Table -->
      <section class="card billing-section" aria-label="金額一致性檢查">
        <div class="billing-section__header">
          <h2>金額一致性檢查</h2>
          <span v-if="health.charge_consistency.inconsistent === 0" class="badge badge-green">全部一致</span>
          <span v-else class="badge badge-red">{{ health.charge_consistency.inconsistent }} 筆不一致</span>
        </div>
        <div v-if="!health.charge_consistency.details || health.charge_consistency.details.length === 0" class="empty-text">
          尚無資料
        </div>
        <div v-else class="responsive-table-wrap">
          <table class="billing-table">
            <thead>
              <tr>
                <th>課程 ID</th>
                <th>已記錄金額</th>
                <th>預期金額</th>
                <th>Rate</th>
                <th>堂數</th>
                <th>差異</th>
                <th>狀態</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in health.charge_consistency.details"
                :key="item.student_class_id"
                :class="{ 'row--inconsistent': true }"
              >
                <td><code>#{{ item.student_class_id }}</code></td>
                <td class="num">NT$ {{ formatAmount(item.charge) }}</td>
                <td class="num">NT$ {{ formatAmount(item.expected) }}</td>
                <td class="num">{{ item.rate }}</td>
                <td class="num">{{ item.sessions }}</td>
                <td class="num">
                  <span class="diff-badge" :class="diffClass(item.charge, item.expected)">
                    {{ formatDiff(item.charge, item.expected) }}
                  </span>
                </td>
                <td>
                  <button
                    v-if="!dismissedChargeItems.has(item.student_class_id)"
                    class="btn-sm btn-ghost"
                    @click="dismissChargeItem(item.student_class_id)"
                    title="標記已確認"
                  >標記已確認</button>
                  <span v-else class="dismissed-label">已確認</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Payment Divergence Table -->
      <section class="card billing-section" aria-label="繳費狀態對比">
        <div class="billing-section__header">
          <h2>繳費狀態對比（AlertController vs StudentClassController）</h2>
          <span v-if="health.payment_divergence.divergent === 0" class="badge badge-green">完全一致</span>
          <span v-else class="badge badge-red">{{ health.payment_divergence.divergent }} 筆分歧</span>
        </div>
        <div v-if="!health.payment_divergence.details || health.payment_divergence.details.length === 0" class="empty-text">
          尚無資料
        </div>
        <div v-else class="responsive-table-wrap">
          <table class="billing-table">
            <thead>
              <tr>
                <th>課程 ID</th>
                <th>Alert 狀態</th>
                <th>SC 狀態</th>
                <th>嚴重度</th>
                <th>狀態</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in health.payment_divergence.details"
                :key="item.student_class_id"
                :class="{ 'row--inconsistent': true }"
              >
                <td><code>#{{ item.student_class_id }}</code></td>
                <td>
                  <span :class="['status-tag', statusClass(item.alert_status)]">{{ statusLabel(item.alert_status) }}</span>
                </td>
                <td>
                  <span :class="['status-tag', statusClass(item.student_class_status)]">{{ statusLabel(item.student_class_status) }}</span>
                </td>
                <td>
                  <span :class="['severity-tag', `severity-${divergenceSeverity(item)}`]">
                    {{ severityLabel(item) }}
                  </span>
                </td>
                <td>
                  <button
                    v-if="!dismissedPaymentItems.has(item.student_class_id)"
                    class="btn-sm btn-ghost"
                    @click="dismissPaymentItem(item.student_class_id)"
                    title="標記已確認"
                  >標記已確認</button>
                  <span v-else class="dismissed-label">已確認</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Mode Transition Audit Table -->
      <section class="card billing-section" aria-label="模式轉換稽核">
        <div class="billing-section__header">
          <h2>模式轉換稽核</h2>
          <span v-if="health.mode_transition_anomalies.anomalous === 0" class="badge badge-green">無異常</span>
          <span v-else class="badge badge-red">{{ health.mode_transition_anomalies.anomalous }} 筆異常</span>
        </div>
        <div class="empty-text">
          轉換稽核於後端 CLI <code>billing:audit-student</code> 輸出完整時間線，此處提供計數摘要。
        </div>
      </section>

      <!-- Student Audit Panel -->
      <section class="card billing-section" aria-label="學生稽查面板">
        <details class="audit-panel" :open="auditPanelOpen" @toggle="auditPanelOpen = $event.target.open">
          <summary class="audit-panel__summary">
            <h2>學生稽查面板</h2>
            <span class="material-symbols-outlined audit-panel__chevron" aria-hidden="true">expand_more</span>
          </summary>
          <div class="audit-panel__body">
            <form class="audit-form" @submit.prevent="runStudentAudit" novalidate>
              <label class="form-field">
                <span class="form-label">學生 ID</span>
                <div class="audit-form__row">
                  <input
                    v-model.number="auditStudentId"
                    type="number"
                    class="form-input form-input--sm"
                    placeholder="輸入 student_id"
                    min="1"
                    :disabled="auditing"
                    aria-label="學生 ID"
                  />
                  <button
                    type="submit"
                    class="btn-primary"
                    :disabled="!auditStudentId || auditing"
                  >
                    {{ auditing ? '查詢中…' : '查詢時間線' }}
                  </button>
                </div>
              </label>
            </form>

            <!-- Audit Result -->
            <div v-if="auditError" class="billing-health__error billing-health__error--inline" role="alert">
              {{ auditError }}
            </div>

            <div v-if="auditResult" class="audit-result">
              <div class="audit-result__header">
                <span class="badge badge-green" v-if="auditResult.courses_recomputed > 0">
                  已重新計算 {{ auditResult.courses_recomputed }} 門課程
                </span>
              </div>

              <!-- Recompute Button -->
              <div v-if="!recomputing" class="audit-result__actions">
                <button
                  class="btn-primary"
                  :disabled="!auditStudentId"
                  @click="runRecompute"
                >重新計算堂數</button>
              </div>
              <div v-else class="audit-result__recomputing">
                <div class="spinner" aria-label="計算中"></div>
                <span>重新計算中…</span>
              </div>

              <!-- Recompute Results -->
              <div v-if="recomputeResult" class="audit-result__table">
                <h3>重新計算結果</h3>
                <div class="responsive-table-wrap">
                  <table class="billing-table">
                    <thead>
                      <tr>
                        <th>課程 ID</th>
                        <th>修正前 Used</th>
                        <th>修正後 Used</th>
                        <th>修正前 Remaining</th>
                        <th>修正後 Remaining</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="r in recomputeResult.results" :key="r.student_class_id">
                        <td><code>#{{ r.student_class_id }}</code></td>
                        <td class="num">{{ r.before_used }}</td>
                        <td class="num" :class="{ 'num--changed': r.before_used !== r.after_used }">
                          {{ r.after_used }}
                        </td>
                        <td class="num">{{ r.before_remaining }}</td>
                        <td class="num" :class="{ 'num--changed': r.before_remaining !== r.after_remaining }">
                          {{ r.after_remaining }}
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </details>
      </section>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { fetchBillingHealth, recomputeStudentSessions } from '../lib/billingHealthApi.js';
import { useToast } from '../composables/useToast.js';

const toast = useToast();

// ── State ──
const loading = ref(false);
const error = ref('');
const health = reactive({
  charge_consistency: { checked: 0, inconsistent: 0, details: [] },
  payment_divergence: { total_active: 0, divergent: 0, details: [] },
  mode_transition_anomalies: { total_transitions: 0, anomalous: 0 },
});

// Dismiss state（前端暫存，F5 即重置）
const dismissedChargeItems = ref(new Set());
const dismissedPaymentItems = ref(new Set());

// Audit state
const auditPanelOpen = ref(false);
const auditStudentId = ref(null);
const auditing = ref(false);
const auditError = ref('');
const auditResult = ref(null);
const recomputing = ref(false);
const recomputeResult = ref(null);

// ── API ──
async function loadHealth() {
  loading.value = true;
  error.value = '';
  try {
    const res = await fetchBillingHealth();
    if (res.data) {
      health.charge_consistency = res.data.charge_consistency || { checked: 0, inconsistent: 0, details: [] };
      health.payment_divergence = res.data.payment_divergence || { total_active: 0, divergent: 0, details: [] };
      health.mode_transition_anomalies = res.data.mode_transition_anomalies || { total_transitions: 0, anomalous: 0 };
    }
  } catch (e) {
    error.value = e.message || '載入失敗';
  } finally {
    loading.value = false;
  }
}

async function runStudentAudit() {
  if (!auditStudentId.value) return;
  auditing.value = true;
  auditError.value = '';
  auditResult.value = null;
  recomputeResult.value = null;
  try {
    // 透過 fetchBillingHealth 取得該學生的相關資料
    // audit-student API 原則上由後端 CLI 提供，此處重新載入 health 資料
    const res = await fetchBillingHealth();
    auditResult.value = { courses_recomputed: 0 };
  } catch (e) {
    auditError.value = e.message || '查詢失敗';
  } finally {
    auditing.value = false;
  }
}

async function runRecompute() {
  if (!auditStudentId.value) return;
  recomputing.value = true;
  auditError.value = '';
  try {
    const res = await recomputeStudentSessions(auditStudentId.value);
    if (res.data) {
      recomputeResult.value = res.data;
      toast.success(
        `已完成重新計算：${res.data.courses_recomputed} 門課程`,
        { title: '重新計算完成' }
      );
      // 自動刷新健康資料
      loadHealth();
    }
  } catch (e) {
    auditError.value = e.message || '重新計算失敗';
    toast.error(e.message || '重新計算失敗', { title: '重新計算失敗' });
  } finally {
    recomputing.value = false;
  }
}

// ── UI helpers ──
function formatAmount(n) {
  return Number(n || 0).toLocaleString('zh-TW');
}

function formatDiff(charge, expected) {
  const diff = (charge || 0) - (expected || 0);
  const abs = Math.abs(diff);
  if (diff === 0) return '0';
  return `${diff > 0 ? '+' : '-'}NT$ ${abs.toLocaleString('zh-TW')}`;
}

function diffClass(charge, expected) {
  const diff = (charge || 0) - (expected || 0);
  if (diff === 0) return 'diff-zero';
  if (diff > 0) return 'diff-over';
  return 'diff-under';
}

const PAYMENT_STATUS_LABELS = {
  pending_report: '待確認付款回報',
  partial: '部分付款',
  unpaid: '未繳費',
  renew_needed: '需續報',
  monthly_due_soon: '月結將至',
  paid: '已繳費',
};

function statusLabel(s) {
  return PAYMENT_STATUS_LABELS[s] || s || '未知';
}

function statusClass(s) {
  if (s === 'paid') return 'active';
  if (s === 'unpaid' || s === 'partial') return 'pending';
  return 'pending';
}

function divergenceSeverity(item) {
  const a = item.alert_status;
  const b = item.student_class_status;
  // 'paid' vs anything else → high severity
  if ((a === 'paid' && b !== 'paid') || (a !== 'paid' && b === 'paid')) return 'high';
  // Different partial/unpaid states → medium
  if (a !== b) return 'medium';
  return 'low';
}

const SEVERITY_LABELS = { high: '嚴重', medium: '中度', low: '輕微' };
function severityLabel(item) {
  return SEVERITY_LABELS[divergenceSeverity(item)] || '輕微';
}

function dismissChargeItem(id) {
  dismissedChargeItems.value = new Set([...dismissedChargeItems.value, id]);
}
function dismissPaymentItem(id) {
  dismissedPaymentItems.value = new Set([...dismissedPaymentItems.value, id]);
}

// ── Lifecycle ──
onMounted(() => {
  loadHealth();
});
</script>

<style scoped>
.billing-health {
  max-width: 1200px;
  margin: 0 auto;
}

.billing-health__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 24px;
  flex-wrap: wrap;
  gap: 12px;
}

.billing-health__title {
  font-size: 24px;
  font-weight: 800;
  color: var(--text);
  margin-bottom: 4px;
}

.billing-health__sub {
  font-size: 14px;
  color: var(--text-light);
}

.btn-refresh {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 18px;
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 8px;
  color: var(--text);
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  white-space: nowrap;
  transition: var(--transition);
}

.btn-refresh:hover {
  border-color: var(--accent);
  color: var(--accent);
}

.btn-refresh:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* Loading & Error */
.billing-health__loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  padding: 64px 16px;
  color: var(--text-light);
  font-size: 15px;
}

.spinner {
  width: 36px;
  height: 36px;
  border: 3px solid var(--border);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.billing-health__error {
  padding: 20px;
  background: var(--danger-bg);
  border: 1px solid var(--danger);
  border-radius: var(--radius);
  color: var(--danger);
  margin-bottom: 16px;
}

.billing-health__error--inline {
  background: var(--danger-bg);
  border-radius: 8px;
  padding: 12px;
  margin: 12px 0;
  font-size: 13px;
}

.billing-health__error-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}

.billing-health__error .btn-primary {
  margin-top: 12px;
}

/* Summary Cards */
.summary-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}

.summary-card {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 20px;
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 14px;
  box-shadow: var(--shadow);
  transition: var(--transition);
}

.summary-card--critical {
  border-left: 4px solid var(--danger);
}

.summary-card__icon {
  flex-shrink: 0;
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--primary-bg);
  color: var(--primary);
}

.summary-card--critical .summary-card__icon {
  background: var(--danger-bg);
  color: var(--danger);
}

.summary-card__icon .material-symbols-outlined {
  font-size: 24px;
}

.summary-card__body {
  flex: 1;
}

.summary-card__value {
  font-size: 28px;
  font-weight: 800;
  color: var(--text);
  line-height: 1.1;
}

.summary-card--critical .summary-card__value {
  color: var(--danger);
}

.summary-card__label {
  font-size: 13px;
  color: var(--text-light);
  margin-top: 2px;
  font-weight: 500;
}

.summary-card__total {
  width: 100%;
  font-size: 11px;
  color: var(--text-light);
  padding-top: 10px;
  border-top: 1px solid var(--border);
  margin-top: 4px;
}

/* Billing Sections */
.billing-section {
  margin-bottom: 20px;
}

.billing-section__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
  flex-wrap: wrap;
  gap: 8px;
}

.billing-section__header h2 {
  font-size: 17px;
  font-weight: 700;
  color: var(--text);
}

.billing-table {
  width: 100%;
}

.billing-table code {
  background: var(--bg);
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 12px;
  color: var(--text-light);
}

.row--inconsistent {
  border-left: 3px solid var(--danger);
}

.diff-badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
}

.diff-zero {
  color: var(--success);
  background: var(--success-bg);
}

.diff-over {
  color: var(--danger);
  background: var(--danger-bg);
}

.diff-under {
  color: var(--warning);
  background: var(--warning-bg);
}

.severity-tag {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
}

.severity-high {
  background: var(--danger-bg);
  color: var(--danger);
}

.severity-medium {
  background: var(--warning-bg);
  color: var(--warning);
}

.severity-low {
  background: var(--bg);
  color: var(--text-light);
}

.btn-ghost {
  padding: 4px 12px;
  font-size: 12px;
  background: var(--bg);
  color: var(--text-light);
  border: 1px solid var(--border);
  border-radius: 6px;
  cursor: pointer;
  transition: var(--transition);
}

.btn-ghost:hover {
  background: var(--primary-bg);
  color: var(--primary);
  border-color: var(--primary);
}

.dismissed-label {
  font-size: 12px;
  color: var(--text-light);
  font-style: italic;
}

.btn-sm {
  padding: 4px 12px;
  font-size: 12px;
  border-radius: 6px;
}

/* Audit Panel */
.audit-panel {
  border: none;
}

.audit-panel__summary {
  cursor: pointer;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 4px 0;
  list-style: none;
}

.audit-panel__summary::-webkit-details-marker {
  display: none;
}

.audit-panel__summary h2 {
  font-size: 17px;
  font-weight: 700;
  color: var(--text);
  margin: 0;
}

.audit-panel__chevron {
  transition: transform 0.2s ease;
  color: var(--text-light);
}

details[open] .audit-panel__chevron {
  transform: rotate(180deg);
}

.audit-panel__body {
  padding-top: 16px;
  border-top: 1px solid var(--border);
  margin-top: 12px;
}

.audit-form {
  max-width: 420px;
}

.audit-form .form-field {
  margin-bottom: 0;
}

.audit-form__row {
  display: flex;
  gap: 8px;
  align-items: flex-end;
}

.form-input--sm {
  max-width: 200px;
}

/* Audit Result */
.audit-result {
  margin-top: 16px;
}

.audit-result__header {
  margin-bottom: 12px;
}

.audit-result__actions {
  margin: 12px 0;
}

.audit-result__recomputing {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 0;
  color: var(--text-light);
  font-size: 14px;
}

.audit-result__recomputing .spinner {
  width: 20px;
  height: 20px;
  border-width: 2px;
}

.audit-result__table {
  margin-top: 16px;
}

.audit-result__table h3 {
  font-size: 15px;
  font-weight: 600;
  color: var(--text);
  margin-bottom: 10px;
}

.num--changed {
  color: var(--success);
  font-weight: 700;
}

/* btn-primary (match global pattern) */
.btn-primary {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 10px 22px;
  background: linear-gradient(135deg, var(--ds-primary) 0%, var(--ds-primary-deep) 100%);
  color: var(--ds-on-primary);
  border: none;
  border-radius: 999px;
  font-weight: 600;
  font-size: 14px;
  cursor: pointer;
  transition: var(--transition);
  box-shadow: 0 2px 6px rgba(239, 108, 0, 0.25);
}

.btn-primary:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(239, 108, 0, 0.32);
}

.btn-primary:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* Responsive */
@media (max-width: 640px) {
  .billing-health__header {
    flex-direction: column;
  }

  .billing-health__title {
    font-size: 20px;
  }

  .summary-cards {
    grid-template-columns: 1fr;
  }

  .summary-card__value {
    font-size: 24px;
  }
}
</style>
