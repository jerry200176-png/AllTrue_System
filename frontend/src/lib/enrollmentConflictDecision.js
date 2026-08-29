/**
 * Enrollment conflict decision kinds used when force-creating after 409.
 * Keep in sync with EnrollmentService force_reason validation.
 */
export const ENROLLMENT_FORCE_REASONS = Object.freeze({
  CREATE_TRIAL: 'create_trial',
  RENEWAL_NEXT_TERM: 'renewal_next_term',
  INDEPENDENT_PARALLEL: 'independent_parallel',
});

export function isAuditedForceReason(reason) {
  return Object.values(ENROLLMENT_FORCE_REASONS).includes(String(reason || ''));
}

/**
 * Build force payload fields for createUniversalClassSchedule.
 * @param {{ force_reason: string, force_note?: string, existing_contract_ids?: Array<number|string> }} decision
 */
export function buildForceOverrideFields(decision) {
  const reason = String(decision?.force_reason || '').trim();
  const note = String(decision?.force_note || '').trim();
  const ids = Array.isArray(decision?.existing_contract_ids)
    ? decision.existing_contract_ids.map((x) => Number(x)).filter((n) => n > 0)
    : [];
  return {
    force: true,
    force_reason: reason,
    force_note: note || null,
    existing_contract_ids: ids,
  };
}

/**
 * Active-courses API returns `id`; scheduler 409 conflicts return `existing_course_id`.
 * Callers must accept both, and must not use strict `===` across string/number IDs.
 */
export function resolveConflictCourseId(conflict) {
  const raw = conflict?.existing_course_id ?? conflict?.id;
  const id = Number(raw);
  return Number.isFinite(id) && id > 0 ? id : null;
}

export function findCourseForPurchase(courses, conflict) {
  const id = resolveConflictCourseId(conflict);
  if (id == null) return null;
  const list = Array.isArray(courses) ? courses : [];
  return list.find((course) => Number(course?.id ?? course?.existing_course_id) === id) || null;
}

/**
 * StudentsList keys courses by `student.id`; some lookups previously used `_laravelId`.
 * Collect both so 「去加購」 still finds the listed course.
 */
export function collectStudentCourses(courseMap, student) {
  if (!courseMap || !student) return [];
  const seen = new Set();
  const out = [];
  for (const key of [student.id, student._laravelId]) {
    if (key == null || key === '') continue;
    const list = courseMap[key] || courseMap[String(key)];
    if (!Array.isArray(list)) continue;
    for (const course of list) {
      const id = Number(course?.id);
      const mark = Number.isFinite(id) && id > 0 ? `id:${id}` : `idx:${out.length}`;
      if (seen.has(mark)) continue;
      seen.add(mark);
      out.push(course);
    }
  }
  return out;
}

export function normalizeActiveCourseConflicts(courses) {
  return (Array.isArray(courses) ? courses : []).map((course) => ({
    ...course,
    existing_course_id: course?.existing_course_id ?? course?.id,
  }));
}
