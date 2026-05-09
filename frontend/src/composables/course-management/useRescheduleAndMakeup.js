import { ref, computed, watch } from 'vue';
import { getSubjectLabel } from '../../lib/constants';
import { trackAdoptionEvent } from '../../lib/adoptionTelemetry';

export function useRescheduleAndMakeup({
  supabase,
  branchId,
  computeEndTime,
  normalizeTo30Min,
  dayOfWeekFromDate,
  classSessionsByCourse,
  sessions,
  ensureCompletedSessionDatesLoaded,
  loadCourses,
  getCapacityForClassType,
}) {
  const showRescheduleModal = ref(false);
  const rescheduleCourse = ref(null);
  const rescheduleForm = ref({
    student_id: '', student_name: '', subject: '', teacher_id: '', class_type: 'one_on_one',
    duration_hours: 2, course_id: null,
    original_date: '', original_day: 1, original_start: '', original_end: '',
    new_date: '', new_start: '16:00',
  });

  const rescheduleSessionOptions = computed(() => {
    const c = rescheduleCourse.value;
    if (!c) return [];
    const cid = String(c?.id ?? '');
    const rows = classSessionsByCourse.value[cid];
    if (Array.isArray(rows) && rows.length > 0) {
      const options = [];
      rows.forEach((row, idx) => {
        const status = String(row?.status || '').toLowerCase();
        if (['completed', 'attended', 'late', 'excused', 'absent', 'cancelled', 'leave', 'leave_adjusted'].includes(status)) return;
        const date = String(row?.session_date || '').slice(0, 10);
        if (!date) return;
        const startTime = String(row?.start_time || '').slice(0, 5);
        options.push({
          date,
          index: idx + 1,
          session_id: row?.id || null,
          start_time: startTime || null,
          label: startTime ? `${date} ${startTime}` : date,
        });
      });
      return options;
    }
    const list = sessions(c);
    return list.map((date, i) => ({ date, index: i + 1, session_id: null, start_time: null, label: date }));
  });

  async function openReschedule(c) {
    await ensureCompletedSessionDatesLoaded(c);
    const list = sessions(c);
    if (!list || list.length === 0) {
      alert('此課程無可調課堂次（請確認開課日與排課設定）。');
      return;
    }
    rescheduleCourse.value = c;
    const first = list[0];
    rescheduleForm.value = {
      student_id: c.student_id,
      student_name: c.student_name || '—',
      subject: c.subject,
      teacher_id: c.teacher_id || null,
      class_type: c.class_type || 'one_on_one',
      duration_hours: c.duration_hours ?? 2,
      course_id: c.id,
      original_date: first,
      original_day: dayOfWeekFromDate(first),
      original_start: c.start_time || '16:00',
      original_end: c.end_time || computeEndTime(c.start_time || '16:00', c.duration_hours ?? 2),
      new_date: '',
      new_start: normalizeTo30Min(c.start_time || '16:00'),
    };
    showRescheduleModal.value = true;
  }

  watch(() => rescheduleForm.value.original_date, (date) => {
    if (!date) return;
    rescheduleForm.value.original_day = dayOfWeekFromDate(date);
  });

  function onRescheduleNewStartChange() {}

  async function submitReschedule() {
    const form = rescheduleForm.value;
    if (!form.new_date) return;
    const bid = Number(typeof branchId === 'object' ? branchId.value : branchId) || 0;
    if (!bid) { alert('請先選擇分校'); return; }
    const newEnd = computeEndTime(form.new_start, form.duration_hours);
    const newDayOfWeek = dayOfWeekFromDate(form.new_date);

    const payload1 = {
      student_id: form.student_id, teacher_id: form.teacher_id || null, subject: form.subject,
      day_of_week: form.original_day, start_time: form.original_start, end_time: form.original_end,
      duration_hours: form.duration_hours, class_type: form.class_type,
      status: 'rescheduled', type: 'normal', deduction: 0, branch_id: bid,
      student_course_id: form.course_id, schedule_date: form.original_date,
    };
    const payload2 = (originalId) => ({
      student_id: form.student_id, teacher_id: form.teacher_id || null, subject: form.subject,
      day_of_week: newDayOfWeek, start_time: normalizeTo30Min(form.new_start), end_time: newEnd,
      duration_hours: form.duration_hours, class_type: form.class_type,
      status: 'scheduled', type: 'normal', deduction: 1, branch_id: bid,
      schedule_date: form.new_date, original_schedule_id: originalId, student_course_id: form.course_id,
    });

    const impactLines = [
      `原堂次：${form.original_date} ${form.original_start}~${form.original_end}`,
      `新堂次：${form.new_date} ${normalizeTo30Min(form.new_start)}~${newEnd}`,
      '系統將建立「原堂改期」與「新堂排入」兩筆記錄，可於課程編修追溯',
    ].join('\n');
    if (!confirm(`調課影響預覽\n\n${impactLines}\n\n確認送出？`)) return;

    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (token) {
        let originalId = null;
        let createdNewRescheduled = false;
        const existingRes = await fetch(
          `/api/v1/schedules?branch_id=${bid}&student_course_id=${form.course_id}&schedule_date=${form.original_date}&status=rescheduled&__limit=1`,
          { credentials: 'include', headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } }
        );
        if (existingRes.ok) {
          const existingList = await existingRes.json();
          const arr = Array.isArray(existingList) ? existingList : existingList?.data ?? [];
          if (arr.length > 0 && arr[0].id) originalId = arr[0].id;
        }
        if (originalId == null) {
          const r1 = await fetch('/api/v1/schedules', {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
            body: JSON.stringify(payload1),
          });
          if (!r1.ok) { const err = await r1.json().catch(() => ({})); alert('調課失敗：' + (err.message || '無法寫入原堂次紀錄')); return; }
          const created = await r1.json();
          originalId = created?.id ?? null;
          createdNewRescheduled = true;
        }
        const r2 = await fetch('/api/v1/schedules', {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
          body: JSON.stringify(payload2(originalId)),
        });
        if (!r2.ok) {
          const err = await r2.json().catch(() => ({}));
          let errMsg = err.message || '無法寫入新堂次';
          if (r2.status === 409 && Array.isArray(err.conflicts) && err.conflicts.length > 0) {
            const details = err.conflicts[0]?.overlap_details;
            if (Array.isArray(details) && details.length > 0) {
              const names = details.map((d) => d.student_name || `#${d.student_id}`).join('、');
              errMsg += `\n衝突學生：${names}`;
            }
          }
          // FR-004: 補償刪除本次剛建立的 rescheduled 列，防止孤兒資料
          if (createdNewRescheduled && originalId) {
            fetch(`/api/v1/schedules/${originalId}`, {
              method: 'DELETE', credentials: 'include',
              headers: { Authorization: `Bearer ${token}` },
            }).catch(() => {});
          }
          alert('調課失敗：' + errMsg);
          return;
        }
        if (form.course_id) {
          await fetch('/api/v1/learning-records/reschedule-session', {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
            body: JSON.stringify({
              student_class_id: form.course_id, old_date: form.original_date || null,
              new_date: form.new_date, start_time: normalizeTo30Min(form.new_start), end_time: newEnd,
            }),
          }).catch(() => {});
        }
        showRescheduleModal.value = false;
        rescheduleCourse.value = null;
        alert('調課完成');
        trackAdoptionEvent('flow_submitted', bid, { flow: 'reschedule', source: 'course-modal' });
        loadCourses();
        return;
      }
    } catch (_) {}

    let originalId = null;
    const { data: existing } = await supabase
      .from('schedules').select('id')
      .eq('student_course_id', form.course_id)
      .eq('schedule_date', form.original_date)
      .eq('status', 'rescheduled').maybeSingle();
    if (existing?.id) {
      originalId = existing.id;
    } else {
      const { data: ins, error: e1 } = await supabase.from('schedules').insert([payload1]).select('id').single();
      if (e1) { alert('調課失敗：' + (e1.message || '無法寫入原堂次紀錄')); return; }
      originalId = ins?.id ?? null;
    }
    const { error: e2 } = await supabase.from('schedules').insert([payload2(originalId)]);
    if (e2) { alert('調課失敗：' + (e2.message || '無法寫入新堂次')); return; }
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (token && form.course_id) {
        await fetch('/api/v1/learning-records/reschedule-session', {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
          body: JSON.stringify({
            student_class_id: form.course_id, old_date: form.original_date || null,
            new_date: form.new_date, start_time: normalizeTo30Min(form.new_start), end_time: newEnd,
          }),
        }).catch(() => {});
      }
    } catch (_) {}
    showRescheduleModal.value = false;
    rescheduleCourse.value = null;
    alert('調課完成');
    trackAdoptionEvent('flow_submitted', bid, { flow: 'reschedule', source: 'course-modal-fallback' });
    loadCourses();
  }

  // Makeup slots
  const showMakeupSlotsModal = ref(false);
  const makeupLoading = ref(false);
  const makeupDateRange = ref(30);
  const availableMakeupSlots = ref([]);

  function timeToSlotIndex(timeStr) {
    const [h, m] = (timeStr || '00:00').split(':').map(Number);
    return h * 2 + Math.floor((m || 0) / 30);
  }
  function slotIndexToTime(idx) {
    const h = Math.floor(idx / 2);
    const m = (idx % 2) * 30;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
  }

  const makeupSlotsGrouped = computed(() => {
    const map = {};
    for (const s of availableMakeupSlots.value) {
      if (!map[s.date]) map[s.date] = { date: s.date, day_of_week: s.day_of_week, slots: [] };
      map[s.date].slots.push(s);
    }
    return Object.values(map).sort((a, b) => a.date.localeCompare(b.date));
  });

  async function fetchMakeupSlots() {
    const form = rescheduleForm.value;
    if (!form.teacher_id) { alert('此課程未指定老師，無法查詢空檔'); return; }
    const bid = Number(typeof branchId === 'object' ? branchId.value : branchId) || 0;
    if (!bid) { alert('請先選擇分校'); return; }

    makeupLoading.value = true;
    showMakeupSlotsModal.value = true;
    availableMakeupSlots.value = [];

    const now = new Date();
    now.setHours(12, 0, 0, 0);
    const startDate = new Date(now.getTime() + 86400000).toISOString().slice(0, 10);
    const endDate = new Date(now.getTime() + makeupDateRange.value * 86400000).toISOString().slice(0, 10);

    let teacherCourses = [];
    let schedExceptions = [];

    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (token) {
        const [cRes, sRes] = await Promise.all([
          fetch(`/api/v1/student-classes?${new URLSearchParams({ branch_id: String(bid), teacher_id: String(form.teacher_id), per_page: '1000' })}`, {
            credentials: 'include', headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
          }),
          // Pull by branch (not teacher_id) then filter by teacher course ids.
          // Some leave rows may have null/mismatched teacher_id depending on write path,
          // but student_course_id remains authoritative for occupancy release.
          fetch(`/api/v1/schedules?${new URLSearchParams({ branch_id: String(bid), per_page: '5000' })}`, {
            credentials: 'include', headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
          }),
        ]);
        if (cRes.ok) { const j = await cRes.json(); const a = Array.isArray(j) ? j : (j?.data ?? []); teacherCourses = Array.isArray(a) ? a : []; }
        if (sRes.ok) { const j = await sRes.json(); const a = Array.isArray(j) ? j : (j?.data ?? []); schedExceptions = Array.isArray(a) ? a : []; }
      }
    } catch (_) {}

    if (teacherCourses.length === 0) {
      const { data } = await supabase.from('student-classes').select('*').eq('branch_id', bid).eq('teacher_id', form.teacher_id);
      teacherCourses = data || [];
    }
    if (schedExceptions.length === 0) {
      const { data } = await supabase.from('schedules').select('*').eq('branch_id', bid);
      schedExceptions = data || [];
    }

    const teacherCourseIds = new Set(
      (teacherCourses || [])
        .map((c) => String(c?.id || ''))
        .filter(Boolean)
    );
    schedExceptions = (schedExceptions || []).filter((ex) => teacherCourseIds.has(String(ex?.student_course_id || '')));

    const leaveSet = new Set();
    const reschFromSet = new Set();
    const reschToList = [];
    for (const ex of schedExceptions) {
      const d = ex.schedule_date ? String(ex.schedule_date).slice(0, 10) : '';
      const cid = String(ex.student_course_id || '');
      if (ex.status === 'leave' || ex.status === 'leave_adjusted' || ex.status === 'excused') leaveSet.add(`${cid}_${d}`);
      else if (ex.status === 'rescheduled') reschFromSet.add(`${cid}_${d}`);
      else if (ex.status === 'scheduled' && ex.original_schedule_id) reschToList.push(ex);
    }

    const maxStudentsPerSlot = getCapacityForClassType(form.class_type || 'one_on_one');
    const occMap = {};
    const slotStudentsMap = {};

    function markOcc(date, st, et, studentName) {
      if (!st || !et) return;
      if (!occMap[date]) occMap[date] = {};
      if (!slotStudentsMap[date]) slotStudentsMap[date] = {};
      const s = timeToSlotIndex(st), e = timeToSlotIndex(et);
      for (let i = s; i < e; i++) {
        occMap[date][i] = (occMap[date][i] || 0) + 1;
        if (studentName) {
          if (!slotStudentsMap[date][i]) slotStudentsMap[date][i] = [];
          slotStudentsMap[date][i].push(studentName);
        }
      }
    }

    const dEnd = new Date(endDate + 'T12:00:00');
    let cursor = new Date(startDate + 'T12:00:00');
    while (cursor <= dEnd) {
      const ymd = cursor.toISOString().slice(0, 10);
      const dow = cursor.getDay() === 0 ? 7 : cursor.getDay();
      for (const c of teacherCourses) {
        const cDays = Array.isArray(c.days_of_week) && c.days_of_week.length
          ? c.days_of_week.map(Number) : (c.day_of_week ? [Number(c.day_of_week)] : []);
        if (!cDays.includes(dow)) continue;
        const cid = String(c.id || '');
        if (leaveSet.has(`${cid}_${ymd}`) || reschFromSet.has(`${cid}_${ymd}`)) continue;
        const fcd = c.first_class_date ? String(c.first_class_date).slice(0, 10) : null;
        if (fcd && ymd < fcd) continue;
        const st = c.start_time || '16:00';
        markOcc(ymd, st, c.end_time || computeEndTime(st, c.duration_hours || 2), c.student_name || '');
      }
      cursor.setDate(cursor.getDate() + 1);
    }

    for (const ex of reschToList) {
      const d = ex.schedule_date ? String(ex.schedule_date).slice(0, 10) : '';
      if (d >= startDate && d <= endDate) markOcc(d, ex.start_time, ex.end_time, ex.student_name || '');
    }

    const durSlots = Math.ceil((form.duration_hours || 2) * 2);
    const tStart = timeToSlotIndex('09:00');
    const tEnd = timeToSlotIndex('21:00');
    const result = [];

    cursor = new Date(startDate + 'T12:00:00');
    while (cursor <= dEnd) {
      const ymd = cursor.toISOString().slice(0, 10);
      const dow = cursor.getDay() === 0 ? 7 : cursor.getDay();
      const occ = occMap[ymd] || {};
      const stuMap = slotStudentsMap[ymd] || {};
      for (let i = tStart; i <= tEnd - durSlots; i++) {
        let available = true;
        let maxOcc = 0;
        for (let j = 0; j < durSlots; j++) {
          const cnt = occ[i + j] || 0;
          if (cnt >= maxStudentsPerSlot) { available = false; break; }
          if (cnt > maxOcc) maxOcc = cnt;
        }
        if (available) {
          const studentsSet = new Set();
          for (let j = 0; j < durSlots; j++) {
            for (const name of (stuMap[i + j] || [])) { if (name) studentsSet.add(name); }
          }
          result.push({
            date: ymd, start_time: slotIndexToTime(i), end_time: slotIndexToTime(i + durSlots),
            day_of_week: dow, currentStudentCount: maxOcc, capacity: maxStudentsPerSlot,
            existingStudents: [...studentsSet],
          });
        }
      }
      cursor.setDate(cursor.getDate() + 1);
    }

    availableMakeupSlots.value = result;
    makeupLoading.value = false;
  }

  function selectMakeupSlot(slot) {
    rescheduleForm.value.new_date = slot.date;
    rescheduleForm.value.new_start = slot.start_time;
    showMakeupSlotsModal.value = false;
  }

  return {
    showRescheduleModal,
    rescheduleCourse,
    rescheduleForm,
    rescheduleSessionOptions,
    openReschedule,
    onRescheduleNewStartChange,
    submitReschedule,
    showMakeupSlotsModal,
    makeupLoading,
    makeupDateRange,
    availableMakeupSlots,
    makeupSlotsGrouped,
    fetchMakeupSlots,
    selectMakeupSlot,
  };
}
