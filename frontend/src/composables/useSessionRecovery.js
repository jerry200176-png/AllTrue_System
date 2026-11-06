import { ref } from 'vue';
import { authedFetch } from '../lib/authedFetch';

// Shared "undo a cancelled lesson" flow for SmartCalendar and CourseManagement.
// GET /class-sessions/{id}/recovery -> { available, audit_id, previous_status }
// POST /class-sessions/{id}/restore  { expected_audit_id, reason }
const LABELS = {
  scheduled: '排程中', attended: '已上課', completed: '已完成',
  late: '遲到', absent: '缺席', leave: '請假',
};

export const makeSessionRecovery = (loading = false) => ({
  loading, available: false, audit_id: null, previous_status: '',
  previousStatusLabel: '', reason: '主任誤取消', submitting: false,
});

export function useSessionRecovery({ fetcher = authedFetch } = {}) {
  const recovery = ref(makeSessionRecovery());

  const reset = () => { recovery.value = makeSessionRecovery(); };

  const load = async (sessionId) => {
    recovery.value = makeSessionRecovery(true);
    if (!sessionId) { recovery.value.loading = false; return; }
    try {
      const res = await fetcher('/api/v1/class-sessions/' + sessionId + '/recovery');
      const data = await res.json();
      recovery.value = {
        ...recovery.value,
        ...data,
        previousStatusLabel: LABELS[data.previous_status] || data.previous_status || '',
        loading: false,
      };
    } catch {
      recovery.value.loading = false;
    }
  };

  // Resolves with the API payload; rejects with the backend message unchanged.
  const restore = async (sessionId) => {
    const r = recovery.value;
    if (!sessionId || !r.available || r.submitting || !r.reason?.trim()) return null;
    r.submitting = true;
    try {
      const res = await fetcher('/api/v1/class-sessions/' + sessionId + '/restore', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ expected_audit_id: r.audit_id, reason: r.reason.trim() }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.message || 'HTTP ' + res.status);
      recovery.value = { ...recovery.value, available: false, submitting: false };
      return data;
    } catch (e) {
      r.submitting = false;
      throw e;
    }
  };

  return { recovery, reset, load, restore };
}
