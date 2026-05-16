function teacherIdOf(row) {
  const raw = row?.id ?? row?.teacher_id ?? row?.TeacherID;
  const n = Number(raw);
  return Number.isFinite(n) && n > 0 ? n : null;
}

function teacherNameOf(row) {
  return String(row?.username ?? row?.name ?? row?.Name ?? row?.teacher_name ?? '').trim();
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
