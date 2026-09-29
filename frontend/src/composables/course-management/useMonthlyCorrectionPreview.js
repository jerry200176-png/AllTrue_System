import { ref } from 'vue';
import { supabase } from '../../supabase';

export function useMonthlyCorrectionPreview() {
  const show = ref(false);
  const loading = ref(false);
  const form = ref({});
  const preview = ref(null);
  const error = ref('');
  const candidates = ref([]);
  let version = 0;
  let courseId = 0;

  function open(course) {
    version += 1;
    courseId = Number(course.id ?? course.ID);
    const periods = course.monthly_payment?.periods || [];
    const oldPeriod = periods.find((period) => period.payment_status === 'paid') || periods[0];
    form.value = {
      source_start: String(course.StartDate ?? course.start_date ?? '').slice(0, 10),
      source_end: oldPeriod?.period_end || '',
      target_start: periods.find((period) => period.period_start > (oldPeriod?.period_end || ''))?.period_start || '',
      target_end: String(course.EndDate ?? course.end_date ?? '').slice(0, 10),
      target_course_id: null, source_charge: Number(course.Charge ?? course.charge ?? 0), target_charge: null,
      payment_evidence_reference: null,
    };
    preview.value = null;
    error.value = '';
    loading.value = false;
    show.value = true;
    candidates.value = [];
    loadCandidates(course, courseId, version);
  }
  async function loadCandidates(course, requestedCourseId, openVersion) {
    try {
      const { data: { session } } = await supabase.auth.getSession();
      if (!session?.access_token) return;
      const studentId = Number(course.StudentID ?? course.student_id);
      const response = await fetch(`/api/v1/student-classes?student_id=${studentId}&per_page=1000`, {
        credentials: 'include', headers: { Accept: 'application/json', Authorization: `Bearer ${session.access_token}` },
      });
      if (!response.ok) throw new Error('下一期合約讀取失敗，請重新開啟預覽');
      const data = await response.json();
      if (!show.value || courseId !== requestedCourseId || version !== openVersion) return;
      candidates.value = (data.data || []).filter((row) => Number(row.ID ?? row.id) !== requestedCourseId
        && row.ScheduleMode === 'date' && Number(row.SubjectID) === Number(course.SubjectID)
        && Number(row.StudentID) === studentId && !Number(row.PackageID));
    } catch (failure) {
      if (show.value && courseId === requestedCourseId) error.value = failure.message;
    }
  }
  function invalidate() { version += 1; preview.value = null; loading.value = false; }
  function close() { invalidate(); show.value = false; }
  async function check() {
    const requestVersion = ++version;
    const input = { ...form.value };
    preview.value = null;
    error.value = '';
    loading.value = true;
    try {
      const { data: { session } } = await supabase.auth.getSession();
      if (!session?.access_token) throw new Error('請先登入');
      const response = await fetch(`/api/v1/student-classes/${courseId}/monthly-contract-correction/preview`, {
        method: 'POST', credentials: 'include',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${session.access_token}` },
        body: JSON.stringify(input),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || '無法預覽更正，資料未變更');
      if (requestVersion === version) preview.value = data;
    } catch (failure) {
      if (requestVersion === version) error.value = failure.message || '預覽失敗';
    } finally {
      if (requestVersion === version) loading.value = false;
    }
  }
  return { show, candidates, form, preview, error, loading, open, close, check, invalidate };
}
