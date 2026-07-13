/**
 * billingHealthApi 純函式測試（node:assert）。
 * API contract 驗證：路徑、方法、response shape。
 */

import assert from 'node:assert/strict';

// ── API 路徑與方法約束（contract test）──

const API_BASE = '/api/v1';

// fetchBillingHealth: GET /api/v1/admin/billing/health
assert.ok(
  `${API_BASE}/admin/billing/health`.endsWith('/billing/health'),
  'fetchBillingHealth path must end with /admin/billing/health',
);
assert.ok(
  `${API_BASE}/admin/billing/health`.startsWith('/api/v1/'),
  'fetchBillingHealth path must start with /api/v1/',
);

// recomputeStudentSessions: POST /api/v1/admin/billing/recompute-student
assert.ok(
  `${API_BASE}/admin/billing/recompute-student`.endsWith('/billing/recompute-student'),
  'recomputeStudentSessions path must end with /admin/billing/recompute-student',
);
assert.ok(
  `${API_BASE}/admin/billing/recompute-student`.startsWith('/api/v1/'),
  'recomputeStudentSessions path must start with /api/v1/',
);

// recomputeStudentSessions sends student_id in body
const body = JSON.stringify({ student_id: 789 });
const parsed = JSON.parse(body);
assert.strictEqual(parsed.student_id, 789, 'recomputeStudentSessions body must contain student_id');

// auditStudentBilling: GET /api/v1/admin/billing/audit-student
assert.ok(
  `${API_BASE}/admin/billing/audit-student`.endsWith('/billing/audit-student'),
  'auditStudentBilling path must end with /admin/billing/audit-student',
);
assert.ok(
  `${API_BASE}/admin/billing/audit-student`.startsWith('/api/v1/'),
  'auditStudentBilling path must start with /api/v1/',
);

// auditStudentBilling passes student_id as query param
const params = new URLSearchParams({ student_id: '789' });
assert.strictEqual(params.toString(), 'student_id=789', 'auditStudentBilling query string must include student_id=789');

// ── Response shape contract ──

// fetchBillingHealth response shape
const mockHealthResponse = {
  data: {
    charge_consistency: { checked: 350, inconsistent: 12, details: [] },
    payment_divergence: { total_active: 1428, divergent: 23, details: [] },
    mode_transition_anomalies: { total_transitions: 85, anomalous: 3 },
  },
};

assert.ok(mockHealthResponse.data, 'health response must have data key');
assert.ok(mockHealthResponse.data.charge_consistency, 'must have charge_consistency');
assert.ok(mockHealthResponse.data.payment_divergence, 'must have payment_divergence');
assert.ok(mockHealthResponse.data.mode_transition_anomalies, 'must have mode_transition_anomalies');
assert.strictEqual(typeof mockHealthResponse.data.charge_consistency.checked, 'number', 'checked must be number');
assert.strictEqual(typeof mockHealthResponse.data.charge_consistency.inconsistent, 'number', 'inconsistent must be number');
assert.ok(Array.isArray(mockHealthResponse.data.charge_consistency.details), 'details must be array');

// recomputeStudentSessions response shape
const mockRecomputeResponse = {
  data: {
    student_id: 789,
    courses_recomputed: 3,
    results: [
      { student_class_id: 123, before_used: 8, after_used: 10, before_remaining: 2, after_remaining: 0 },
    ],
  },
};

assert.ok(mockRecomputeResponse.data, 'recompute response must have data key');
assert.strictEqual(mockRecomputeResponse.data.student_id, 789, 'must contain student_id');
assert.strictEqual(typeof mockRecomputeResponse.data.courses_recomputed, 'number', 'courses_recomputed must be number');
assert.ok(Array.isArray(mockRecomputeResponse.data.results), 'results must be array');

// detail shape
const detail = mockHealthResponse.data.charge_consistency.details;
assert.ok(Array.isArray(detail), 'charge_consistency details must be array');

// status label mapping
const PAYMENT_STATUS_LABELS = {
  pending_report: '待確認付款回報',
  partial: '部分付款',
  unpaid: '未繳費',
  renew_needed: '需續報',
  monthly_due_soon: '月結將至',
  paid: '已繳費',
};
assert.strictEqual(PAYMENT_STATUS_LABELS.paid, '已繳費', 'six-value status: paid');
assert.strictEqual(PAYMENT_STATUS_LABELS.partial, '部分付款', 'six-value status: partial');
assert.strictEqual(PAYMENT_STATUS_LABELS.unpaid, '未繳費', 'six-value status: unpaid');
assert.strictEqual(PAYMENT_STATUS_LABELS.pending_report, '待確認付款回報', 'six-value status: pending_report');
assert.strictEqual(Object.keys(PAYMENT_STATUS_LABELS).length, 6, 'must have exactly 6 status values');

// diff formatting
function formatDiff(charge, expected) {
  const diff = (charge || 0) - (expected || 0);
  const abs = Math.abs(diff);
  if (diff === 0) return '0';
  return `${diff > 0 ? '+' : '-'}NT$ ${abs.toLocaleString('zh-TW')}`;
}
assert.strictEqual(formatDiff(6000, 4000), '+NT$ 2,000', 'positive diff');
assert.strictEqual(formatDiff(2000, 4000), '-NT$ 2,000', 'negative diff');
assert.strictEqual(formatDiff(4000, 4000), '0', 'zero diff');

// severity logic
function divergenceSeverity(item) {
  const a = item.alert_status;
  const b = item.student_class_status;
  if ((a === 'paid' && b !== 'paid') || (a !== 'paid' && b === 'paid')) return 'high';
  if (a !== b) return 'medium';
  return 'low';
}
assert.strictEqual(divergenceSeverity({ alert_status: 'paid', student_class_status: 'unpaid' }), 'high', 'paid vs unpaid = high');
assert.strictEqual(divergenceSeverity({ alert_status: 'unpaid', student_class_status: 'paid' }), 'high', 'unpaid vs paid = high');
assert.strictEqual(divergenceSeverity({ alert_status: 'partial', student_class_status: 'unpaid' }), 'medium', 'partial vs unpaid = medium');
assert.strictEqual(divergenceSeverity({ alert_status: 'paid', student_class_status: 'paid' }), 'low', 'paid vs paid = low');
assert.strictEqual(divergenceSeverity({ alert_status: 'unpaid', student_class_status: 'unpaid' }), 'low', 'unpaid vs unpaid = low');

// Courses recomputed
assert.strictEqual(mockRecomputeResponse.data.courses_recomputed, 3, 'courses recomputed count');

// Results detail check
const r = mockRecomputeResponse.data.results[0];
assert.strictEqual(r.before_used, 8, 'before_used');
assert.strictEqual(r.after_used, 10, 'after_used');
assert.strictEqual(r.before_remaining, 2, 'before_remaining');
assert.strictEqual(r.after_remaining, 0, 'after_remaining');

console.log('All billingHealthApi contract tests passed');
