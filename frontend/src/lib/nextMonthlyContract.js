export async function loadNextMonthlyContract({ source, targetId, date, token, fetchImpl = fetch }) {
  if (!token || !Number.isInteger(Number(targetId)) || Number(targetId) <= 0) throw new Error('請重新選擇下一期合約');
  const response = await fetchImpl(`/api/v1/student-classes/${Number(targetId)}`, {
    credentials: 'include', headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  });
  if (!response.ok) throw new Error('下一期合約無法讀取，請重新整理後再試');
  const course = await response.json();
  const sourceStudentId = Number(source.StudentID ?? source.student_id);
  const start = String(course.StartDate ?? course.start_date ?? '').slice(0, 10);
  const end = String(course.EndDate ?? course.end_date ?? '').slice(0, 10);
  if (Number(course.ID ?? course.id) !== Number(targetId) || Number(course.StudentID ?? course.student_id) !== sourceStudentId
      || String(course.ScheduleMode) !== 'date' || Number(course.PackageID) > 0 || Number(course.Stop) > 0
      || (source.SubjectID != null && Number(course.SubjectID) !== Number(source.SubjectID))
      || (source.TeacherID != null && Number(course.TeacherID) !== Number(source.TeacherID))
      || !start || !end || date < start || date > end) {
    throw new Error('下一期合約的學生或期間不符，未新增堂次');
  }
  return { ...course, id: Number(targetId), payment_type: 'monthly', data_source: 'laravel',
    student_name: source.student_name, teacher_name: course.teacher_name || source.teacher_name, subject: course.subject_name || source.subject,
    end_date: end, start_date: start };
}
