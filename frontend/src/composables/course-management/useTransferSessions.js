import { computed, ref } from 'vue';
import { authedFetch, getAccessToken } from '../../lib/authedFetch';
import { getSubjectLabel as getSubjectText } from '../../lib/constants';
import { buildTransferableSessionOption } from '../../lib/sessionTransferEligibility';

// Transfer sessions (轉移堂次 / 轉課) flow for CourseManagement: modal state, the
// same-student/same-subject target-course lookup, and the transfer-sessions /
// recover-transfer-sessions request with its error copy. The page supplies
// `allSessionUnits` (a course's session rows), `goToBilling`, `reload` (list refresh)
// and `notify` (toast). Which courses may open it stays in the page.
function normalizedCourseValue(value) {
  return String(value ?? '').trim().toLowerCase();
}

function sameCourseSubject(source, target) {
  const sourceValues = [source?.subject, source?.subject_name].filter(Boolean).map(normalizedCourseValue);
  const targetValues = [target?.subject, target?.subject_name].filter(Boolean).map(normalizedCourseValue);
  if (sourceValues.length === 0 || targetValues.length === 0) return true;
  return sourceValues.some((value) => targetValues.includes(value))
    || sourceValues.some((value) => getSubjectText(value) && targetValues.includes(normalizedCourseValue(getSubjectText(value))));
}

function sameCourseStudent(source, target) {
  const sourceId = source?.student_id ?? source?.StudentID;
  const targetId = target?.student_id ?? target?.StudentID;
  if (sourceId != null && targetId != null && String(sourceId) !== String(targetId)) return false;
  const sourceName = normalizedCourseValue(source?.student_name);
  const targetName = normalizedCourseValue(target?.student_name);
  return !sourceName || !targetName || sourceName === targetName;
}

export function useTransferSessions({ allSessionUnits, goToBilling, reload, notify }) {
  const showModal = ref(false);
  const course = ref(null);
  const submitting = ref(false);
  const error = ref('');
  const nextActions = ref([]);
  const targetCourses = ref([]);
  const targetCoursesLoading = ref(false);
  let targetCoursesRequest = 0;

  const sessionOptions = computed(() => {
    const c = course.value;
    if (!c) return [];
    return allSessionUnits(c)
      .map(buildTransferableSessionOption)
      .filter(Boolean);
  });

  function open(c) {
    course.value = c;
    error.value = '';
    nextActions.value = [];
    targetCourses.value = [];
    showModal.value = true;
    if (String(c?.schedule_mode ?? c?.ScheduleMode ?? '').toLowerCase() !== 'date') {
      loadTargetCourses(c);
    }
  }

  function openBillingNextStep() {
    const c = course.value;
    showModal.value = false;
    if (c) goToBilling(c);
  }

  async function loadTargetCourses(sourceCourse) {
    const requestId = ++targetCoursesRequest;
    targetCoursesLoading.value = true;
    try {
      const token = await getAccessToken();
      if (!token || !sourceCourse) return;
      const params = new URLSearchParams({
        per_page: '100',
        page: '1',
      });
      const studentId = Number(sourceCourse?.student_id ?? sourceCourse?.StudentID);
      if (Number.isInteger(studentId) && studentId > 0) {
        // The API applies the caller's campus/teacher scope. Query by the
        // canonical student identity instead of branch + display name, which
        // can hide a valid target course when room/campus metadata differs.
        params.set('student_id', String(studentId));
      } else if (sourceCourse.student_name) {
        params.set('name', String(sourceCourse.student_name));
      }
      const res = await authedFetch(`/api/v1/student-classes?${params}`, {
        credentials: 'include',
        headers: { Accept: 'application/json' },
      }, token);
      if (!res.ok) return;
      const json = await res.json().catch(() => ({}));
      const list = json?.data ?? json;
      const rows = Array.isArray(list) ? list : (list?.data ?? []);
      const candidates = rows
        .map((course) => ({
          ...course,
          id: Number(course?.id ?? course?.ID),
          student_name: course?.student_name ?? course?.student?.name ?? '',
          subject_name: course?.subject_name ?? '',
          teacher_name: course?.teacher_name ?? course?.teacher?.name ?? course?.teacher?.username ?? '',
          start_date: course?.start_date ?? course?.StartDate ?? '',
          remaining_sessions: course?.remaining_sessions ?? course?.RemainingSessions ?? 0,
          start_time: course?.start_time ?? course?.time ?? '',
          end_time: course?.end_time ?? '',
        }))
        .filter((course) => Number.isFinite(course.id) && course.id > 0)
        .filter((course) => course.id !== Number(sourceCourse.id))
        .filter((course) => sameCourseStudent(sourceCourse, course))
        .filter((course) => sameCourseSubject(sourceCourse, course));
      if (requestId === targetCoursesRequest) targetCourses.value = candidates;
    } catch {
      // The manual ID fallback remains available if the lookup endpoint is unavailable.
    } finally {
      if (requestId === targetCoursesRequest) targetCoursesLoading.value = false;
    }
  }

  async function submit({ targetCourseId, sessionIds, reason }) {
    const c = course.value;
    if (!c || sessionIds.length === 0) return;
    submitting.value = true;
    error.value = '';
    nextActions.value = [];
    try {
      const token = await getAccessToken();
      if (!token) { error.value = '請重新登入後再試'; return; }
      const hasRecovery = sessionOptions.value.some(
        (session) => sessionIds.includes(Number(session.id)) && session.recoverableCancelled
      );
      const endpoint = hasRecovery ? 'recover-transfer-sessions' : 'transfer-sessions';
      const res = await authedFetch(`/api/v1/student-classes/${c.id}/${endpoint}`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({
          session_ids: sessionIds,
          target_student_class_id: targetCourseId,
          ...(hasRecovery ? { reason } : {}),
        }),
      }, token);
      const json = await res.json().catch(() => ({}));
      if (!res.ok) {
        nextActions.value = Array.isArray(json?.next_actions) ? json.next_actions : [];
        const details = json?.errors ? Object.values(json.errors || {}).flat().join(' ') : '';
        const conflict = json?.conflict_session_id
          ? `衝突堂次 #${json.conflict_session_id}`
          : json?.conflict_schedule_id
            ? `衝突預排 #${json.conflict_schedule_id}`
            : '';
        error.value = [details, json?.message, conflict].filter(Boolean).join(' ') || '轉移失敗';
        return;
      }
      showModal.value = false;
      notify({
        title: '已轉移堂次紀錄',
        description: json?.message || `已轉移 ${sessionIds.length} 堂到課程 #${targetCourseId}`,
        variant: 'success',
        durationMs: 7000,
      });
      await reload();
    } catch (e) {
      error.value = '轉移失敗：' + (e?.message || '請稍後再試');
    } finally {
      submitting.value = false;
    }
  }

  return {
    showModal, course, submitting, error, nextActions, targetCourses, targetCoursesLoading,
    sessionOptions, open, openBillingNextStep, submit,
  };
}
