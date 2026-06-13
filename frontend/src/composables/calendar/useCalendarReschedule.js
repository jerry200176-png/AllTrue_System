import { ref, computed } from 'vue';
import {
  dayLabel,
  dayOfWeekFromDate,
  normalizeTimeTo30,
  computeEndTime,
} from '../../lib/calendarFormat.js';

/** #740 Step 7b2b：調課 modal + submit（拖曳 handler 留 SmartCalendar） */
export function useCalendarReschedule({
  supabase,
  branchId,
  showModal,
  modalForm,
  editingCourseId,
  loadCourses,
  getToken,
  allStudents,
  courses,
  exceptions,
  getSubjectLabel,
}) {
  const getStudentName = (sid) => {
    const s = allStudents.value.find((x) => x.id === sid);
    return s ? s.name : '—';
  };

  const showRescheduleModal = ref(false);
  const rescheduleForm = ref({
    student_id: '', subject: '', course_id: '',
    original_day: 1, original_start: '', original_end: '',
    new_date: '', new_day_of_week: 1, new_start: '', new_end: '',
    teacher_id: '', class_type: '', duration_hours: 2,
  });

  const onRescheduleNewStartChange = () => {
    rescheduleForm.value.new_start = normalizeTimeTo30(rescheduleForm.value.new_start);
    rescheduleForm.value.new_end = computeEndTime(rescheduleForm.value.new_start, rescheduleForm.value.duration_hours);
  };
  const computedRescheduleNewEnd = computed(() =>
    computeEndTime(rescheduleForm.value.new_start, rescheduleForm.value.duration_hours),
  );

  const openRescheduleModal = () => {
    const exactDate = modalForm.value.action_date || new Date().toISOString().split('T')[0];
    const newStart = normalizeTimeTo30(modalForm.value.start_time);
    const dur = modalForm.value.duration_hours || 2;
    rescheduleForm.value = {
      student_id: modalForm.value.student_id,
      subject: modalForm.value.subject,
      course_id: editingCourseId.value,
      original_day: modalForm.value.day_of_week,
      original_start: modalForm.value.start_time,
      original_end: modalForm.value.end_time,
      original_date: exactDate,
      new_date: exactDate,
      new_day_of_week: modalForm.value.day_of_week,
      new_start: newStart,
      new_end: computeEndTime(newStart, dur),
      teacher_id: modalForm.value.teacher_id,
      class_type: modalForm.value.class_type,
      duration_hours: dur,
    };
    showModal.value = false;
    showRescheduleModal.value = true;
  };

  const resolveTeacherIdForRescheduledSlot = (anchorId, courseId, fallbackTeacherId) => {
    if (anchorId == null || anchorId === '' || !courseId) {
      return fallbackTeacherId ?? null;
    }
    const subEx = exceptions.value.find((ex) =>
      ex.status === 'scheduled'
      && ex.original_schedule_id != null
      && String(ex.original_schedule_id) === String(anchorId)
      && String(ex.student_course_id) === String(courseId));
    if (subEx?.teacher_id == null || String(subEx.teacher_id).trim() === '') {
      return fallbackTeacherId ?? null;
    }
    const substituteTid = Number(subEx.teacher_id);
    const fbNum = fallbackTeacherId != null && fallbackTeacherId !== ''
      ? Number(fallbackTeacherId)
      : 0;
    const baseCourse = courses.value.find((c) => String(c.id) === String(courseId));
    const contractTid = baseCourse?.teacher_id != null ? Number(baseCourse.teacher_id) : 0;
    if (
      contractTid > 0
      && Number.isFinite(substituteTid)
      && substituteTid > 0
      && substituteTid !== contractTid
      && (fbNum === 0 || fbNum === contractTid)
    ) {
      return substituteTid;
    }
    return fallbackTeacherId ?? null;
  };

  const submitReschedule = async () => {
    if (!rescheduleForm.value.new_date) { alert('請選擇新日期'); return; }
    const newDayOfWeek = dayOfWeekFromDate(rescheduleForm.value.new_date);
    const bid = Number(branchId.value ?? branchId) || 0;
    if (!bid) { alert('請先選擇分校'); return; }

    const payload1 = {
      student_id: rescheduleForm.value.student_id,
      teacher_id: rescheduleForm.value.teacher_id || null,
      subject: rescheduleForm.value.subject,
      day_of_week: rescheduleForm.value.original_day,
      start_time: rescheduleForm.value.original_start,
      end_time: rescheduleForm.value.original_end,
      duration_hours: rescheduleForm.value.duration_hours,
      class_type: rescheduleForm.value.class_type,
      status: 'rescheduled',
      type: 'normal',
      deduction: 0,
      branch_id: bid,
      student_course_id: rescheduleForm.value.course_id,
      schedule_date: rescheduleForm.value.original_date,
    };

    const alreadyRescheduled = exceptions.value.some((ex) =>
      (ex.status === 'rescheduled' || ex.status === 'leave')
      && String(ex.student_course_id) === String(rescheduleForm.value.course_id)
      && ex.schedule_date === rescheduleForm.value.original_date,
    );
    let originalId = null;
    if (!alreadyRescheduled) {
      const res1 = await supabase.from('schedules').insert([payload1]);
      if (res1.error) {
        alert('調課失敗：' + (res1.error?.message || '無法寫入原堂次紀錄'));
        return;
      }
      const origList = Array.isArray(res1.data) ? res1.data : (res1.data ? [res1.data] : []);
      originalId = origList[0]?.id ?? null;
    } else {
      const existing = exceptions.value.find((ex) =>
        ex.status === 'rescheduled'
        && String(ex.student_course_id) === String(rescheduleForm.value.course_id)
        && ex.schedule_date === rescheduleForm.value.original_date,
      );
      originalId = existing?.id ?? null;
    }

    const newEnd = computeEndTime(rescheduleForm.value.new_start, rescheduleForm.value.duration_hours);

    const alreadySubstituted = originalId !== null && exceptions.value.some((ex) =>
      ex.status === 'scheduled'
      && ex.original_schedule_id != null
      && String(ex.original_schedule_id) === String(originalId)
      && String(ex.student_course_id) === String(rescheduleForm.value.course_id),
    );

    if (!alreadySubstituted) {
      const effectiveTid = resolveTeacherIdForRescheduledSlot(
        originalId,
        rescheduleForm.value.course_id,
        rescheduleForm.value.teacher_id,
      );
      const payload2 = {
        student_id: rescheduleForm.value.student_id,
        teacher_id: effectiveTid != null && effectiveTid !== '' ? effectiveTid : null,
        subject: rescheduleForm.value.subject,
        day_of_week: newDayOfWeek,
        start_time: normalizeTimeTo30(rescheduleForm.value.new_start),
        end_time: newEnd,
        duration_hours: rescheduleForm.value.duration_hours,
        class_type: rescheduleForm.value.class_type,
        status: 'scheduled',
        type: 'normal',
        deduction: 1,
        branch_id: bid,
        schedule_date: rescheduleForm.value.new_date,
        original_schedule_id: originalId,
        student_course_id: rescheduleForm.value.course_id,
      };

      const res2 = await supabase.from('schedules').insert([payload2]);
      if (res2.error) {
        if (originalId) {
          await supabase.from('schedules').delete().eq('id', originalId);
        }
        const errMsg = res2.error?.message || '無法寫入新堂次';
        const isConflict = res2.error?.conflicts?.length > 0 || String(errMsg).includes('已有') || String(errMsg).includes('上限');
        if (isConflict) {
          alert('調課失敗：目標時段已有其他學生（撞課），請換一個時段再試。\n\n詳細：' + errMsg);
        } else {
          alert('調課失敗：' + errMsg);
        }
        return;
      }
    }

    if (rescheduleForm.value.course_id) {
      try {
        const token = await getToken();
        const resched = await fetch('/api/v1/learning-records/reschedule-session', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
          body: JSON.stringify({
            student_class_id: rescheduleForm.value.course_id,
            old_date: rescheduleForm.value.original_date || null,
            old_start_time: rescheduleForm.value.original_start || undefined,
            new_date: rescheduleForm.value.new_date,
            start_time: normalizeTimeTo30(rescheduleForm.value.new_start),
            end_time: computeEndTime(rescheduleForm.value.new_start, rescheduleForm.value.duration_hours),
          }),
        });
        if (!resched.ok) {
          const err = await resched.json().catch(() => ({}));
          alert('調課失敗：' + (err.message || '找不到指定堂次，請確認日期與時間是否正確'));
          return;
        }
      } catch (e) {
        alert('調課失敗：' + (e?.message || '網路錯誤，請稍後再試'));
        return;
      }
    }

    showRescheduleModal.value = false;
    await loadCourses();
    alert('調課完成');
  };

  const rescheduleDisplay = computed(() => ({
    studentName: getStudentName(rescheduleForm.value.student_id),
    subjectLabel: getSubjectLabel(rescheduleForm.value.subject),
    originalSlot: `${dayLabel(rescheduleForm.value.original_day)} ${rescheduleForm.value.original_start}~${rescheduleForm.value.original_end}`,
  }));

  return {
    showRescheduleModal, rescheduleForm, rescheduleDisplay, computedRescheduleNewEnd,
    onRescheduleNewStartChange, openRescheduleModal, submitReschedule,
  };
}
