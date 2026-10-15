function teacherIdOf(row) {
  const raw = row?.id ?? row?.teacher_id ?? row?.TeacherID;
  const n = Number(raw);
  return Number.isFinite(n) && n > 0 ? n : null;
}

function teacherNameOf(row) {
  return String(row?.username ?? row?.name ?? row?.Name ?? row?.teacher_name ?? '').trim();
}

export function isAssignableTeacher(teacher) {
  return teacher?.status == null || teacher.status === 'active';
}

/**
 * Options for the NEW-course teacher picker (UniversalClassScheduler create mode).
 * Deactivated teachers are excluded; edit pickers must keep using
 * buildEditTeacherOptions so an existing course still shows its historical teacher.
 */
export function buildCreateTeacherOptions(teachers = []) {
  return (Array.isArray(teachers) ? teachers : [])
    .filter(isAssignableTeacher)
    .map((teacher) => {
      const id = teacherIdOf(teacher);
      // Keep every source field (branch_ids / branch_id / email / status …): the
      // scheduler and TeacherAvailabilityPlanner read branch metadata for labels
      // and eligibility. Only id/name are normalized.
      return {
        ...teacher,
        id,
        name: teacher?.username || teacher?.name || teacher?.Name || `#${id ?? ''}`,
      };
    })
    .filter((teacher) => teacher.id);
}

/**
 * Seed value for the create picker's teacher. A calendar cell under a
 * deactivated / historical teacher column must not pre-select that teacher
 * (the backend would reject it with 「所選老師已無法指派」), so once the teacher
 * list has loaded any id that is not in the create options is cleared.
 *
 * While the teacher list is still loading (`loaded: false`) the seed is kept
 * as-is: an empty option list says nothing about the teacher, and clearing an
 * active id there could never be undone when the list arrives (#4157 review).
 * UniversalClassScheduler re-validates its form against the options when they
 * arrive, so a deactivated seed is still dropped after loading.
 */
export function resolveCreateTeacherId(teacherId, createOptions = [], { loaded = true } = {}) {
  const id = teacherIdOf({ id: teacherId });
  if (!id) return '';
  if (!loaded) return teacherId;
  const options = Array.isArray(createOptions) ? createOptions : [];
  return options.some((teacher) => teacherIdOf(teacher) === id) ? teacherId : '';
}

export function buildEditTeacherOptions(teachers = [], course = null) {
  const normalized = (Array.isArray(teachers) ? teachers : [])
    .map((teacher) => ({
      ...teacher,
      id: teacherIdOf(teacher),
      username: teacherNameOf(teacher) || `老師#${teacherIdOf(teacher) ?? ''}`,
    }))
    .filter((teacher) => teacher.id);

  const byId = new Map(normalized.map((teacher) => [String(teacher.id), teacher]));
  const currentId = teacherIdOf(course);
  if (!currentId || byId.has(String(currentId))) {
    return normalized;
  }

  return [
    {
      id: currentId,
      username: teacherNameOf(course) || `目前授課老師 #${currentId}`,
      branch_ids: [],
      is_current_course_teacher: true,
    },
    ...normalized,
  ];
}

export function shouldClearTeacherSelection(teacherId, teachers = [], { isEditing = false } = {}) {
  if (!teacherId || isEditing) return false;
  const id = String(teacherId);
  return !(Array.isArray(teachers) ? teachers : []).some((teacher) => String(teacherIdOf(teacher)) === id);
}
