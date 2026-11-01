<template>
      <div class="modal-overlay lr-modal-overlay">
        <div class="lr-modal" style="max-width: 480px;">
          <div class="lr-modal-header">
            <h3>匯出學習評量圖</h3>
            <button type="button" class="ghost icon" @click="emit('close')">✕</button>
          </div>
          <div class="lr-form">
            <p style="color:var(--ds-ink-mute); font-size:13px; margin-bottom:16px;">
              選擇日期區間後，系統會依學生分組，每位學生產出一張評量報告圖片。
            </p>
            <div class="lr-form-grid" style="grid-template-columns:1fr 1fr; gap:12px;">
              <div class="form-group">
                <label>開始日期</label>
                <input v-model="exportForm.startDate" type="date" />
              </div>
              <div class="form-group">
                <label>結束日期</label>
                <input v-model="exportForm.endDate" type="date" />
              </div>
            </div>

            <div v-if="exportForm.status === 'loading'" class="export-progress">
              <div class="export-progress-bar">
                <div class="export-progress-fill" :style="{ width: exportProgressPct + '%' }"></div>
              </div>
              <p class="export-progress-text">
                正在匯出 {{ exportForm.progressCurrent }} ({{ exportForm.progressCompleted }}/{{ exportForm.progressTotal }})…
              </p>
            </div>

            <div v-if="exportForm.status === 'done'" class="export-done">
              <p v-if="exportForm.errorNames.length === 0">全部匯出完成！共 {{ exportForm.progressCompleted }} 位學生。</p>
              <p v-else>匯出完成，但 {{ exportForm.errorNames.join('、') }} 匯出失敗。</p>
            </div>

            <div v-if="exportForm.status === 'empty'" class="export-empty">
              <p>此日期區間內沒有評量資料。</p>
            </div>

            <div class="lr-form-actions" style="margin-top:16px;">
              <button type="button" class="ghost" @click="emit('close')">{{ exportForm.status === 'done' ? '關閉' : '取消' }}</button>
              <button
                v-if="exportForm.status !== 'loading'"
                type="button"
                class="primary"
                :disabled="!exportForm.startDate || !exportForm.endDate"
                @click="executeExport"
              >
                {{ exportForm.status === 'done' ? '重新匯出' : '開始匯出' }}
              </button>
            </div>
          </div>
        </div>
      </div>
</template>

<script setup>
// Export-cards modal of LearningRecordsPage. Mounted with v-if, so each open starts from a fresh form.
import { reactive, computed } from 'vue';
import { supabase } from '../../supabase';
import { exportStudentCards } from '../../lib/learningRecordExport';
import { formatLocalDate, localTodayYmd } from '../../lib/learningRecordDate';

const props = defineProps(['branchId']);
const emit = defineEmits(['close']);

const getToken = async () => {
  const { data: { session } } = await supabase.auth.getSession();
  return session?.access_token;
};

const monthStart = (() => {
  const d = new Date();
  d.setDate(1);
  return formatLocalDate(d);
})();

const exportForm = reactive({
  startDate: monthStart,
  endDate: localTodayYmd(),
  status: 'idle', // idle | loading | done | empty
  progressCompleted: 0,
  progressTotal: 0,
  progressCurrent: '',
  errorNames: [],
});

const exportProgressPct = computed(() => {
  if (exportForm.progressTotal <= 0) return 0;
  return Math.round((exportForm.progressCompleted / exportForm.progressTotal) * 100);
});

const executeExport = async () => {
  if (!exportForm.startDate || !exportForm.endDate) return;
  exportForm.status = 'loading';
  exportForm.progressCompleted = 0;
  exportForm.progressTotal = 0;
  exportForm.progressCurrent = '';
  exportForm.errorNames = [];

  try {
    const token = await getToken();
    if (!token) { exportForm.status = 'idle'; return; }

    const params = new URLSearchParams();
    if (props.branchId) params.set('branch_id', props.branchId);
    params.set('start_date', exportForm.startDate);
    params.set('end_date', exportForm.endDate);
    params.set('per_page', '200');
    params.set('status', 'approved');

    const res = await fetch(`/api/v1/learning-records?${params.toString()}`, {
      headers: { 'Authorization': `Bearer ${token}` },
    });

    if (!res.ok) {
      alert('查詢評量資料失敗');
      exportForm.status = 'idle';
      return;
    }

    const data = await res.json();
    const allRecords = data.data || [];

    if (allRecords.length === 0) {
      exportForm.status = 'empty';
      return;
    }

    // Group by student
    const groups = new Map();
    for (const rec of allRecords) {
      const sid = Number(rec.student_id || 0) || null;
      const sname = String(rec.student_name || '').trim() || '未命名學生';
      const key = sid ? `s-${sid}` : `n-${sname}`;
      if (!groups.has(key)) {
        groups.set(key, { key, student_name: sname, student_id: sid, records: [] });
      }
      groups.get(key).records.push(rec);
    }

    const grouped = Array.from(groups.values())
      .map((g) => {
        g.records.sort((a, b) => {
          const ad = String(a.SessionDate || '');
          const bd = String(b.SessionDate || '');
          return ad.localeCompare(bd) || String(a.StartTime || '').localeCompare(String(b.StartTime || ''));
        });
        return g;
      })
      .sort((a, b) => new Intl.Collator('zh-Hant').compare(a.student_name, b.student_name));

    exportForm.progressTotal = grouped.length;

    const dateRange = `${exportForm.startDate} ~ ${exportForm.endDate}`;

    const branchNames = { 1: '興隆校', 2: '新店校', 3: '大安校', 4: '木柵校' };
    const branchName = branchNames[Number(props.branchId)] || '台北全真一對一補習班';

    await exportStudentCards({
      groupedRecords: grouped,
      dateRange,
      branchName,
      onProgress: ({ completed, total, current, error }) => {
        exportForm.progressCompleted = completed;
        exportForm.progressTotal = total;
        exportForm.progressCurrent = current;
        if (error) exportForm.errorNames.push(current);
      },
    });

    exportForm.status = 'done';
  } catch (err) {
    console.error('Export error:', err);
    alert('匯出失敗：' + (err.message || '未知錯誤'));
    exportForm.status = 'idle';
  }
};
</script>

<style scoped>
/* ── Export Modal ── */
.export-progress {
  margin-top: 16px;
}

.export-progress-bar {
  height: 8px;
  background: var(--ds-canvas-soft);
  border-radius: 4px;
  overflow: hidden;
}

.export-progress-fill {
  height: 100%;
  background: var(--primary);
  border-radius: 4px;
  transition: width 0.3s ease;
}

.export-progress-text {
  font-size: 13px;
  color: var(--text-light);
  margin-top: 8px;
}

.export-done {
  margin-top: 16px;
  padding: 12px 16px;
  background: var(--ds-success-wash);
  border-radius: 8px;
  color: var(--ds-success);
  font-size: 14px;
}

.export-empty {
  margin-top: 16px;
  padding: 12px 16px;
  background: var(--ds-warning-wash);
  border-radius: 8px;
  color: var(--ds-danger);
  font-size: 14px;
}
</style>
