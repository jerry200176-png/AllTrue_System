export function normalizeClassSessionsPayload(json) {
  const items = Array.isArray(json?.data)
    ? json.data
    : (Array.isArray(json) ? json : []);

  const byClass = {};
  for (const raw of items) {
    const item = {
      id: Number(raw?.id || 0),
      student_class_id: Number(raw?.student_class_id || raw?.StudentClassID || 0),
      student_id: Number(raw?.student_id || raw?.StudentID || 0),
      teacher_id: Number(raw?.teacher_id || raw?.TeacherID || 0),
      branch_id: Number(raw?.branch_id || raw?.CampusID || 0),
      session_date: String(raw?.session_date || raw?.SessionDate || '').slice(0, 10),
      start_time: String(raw?.start_time || raw?.StartTime || '').slice(0, 5),
      end_time: String(raw?.end_time || raw?.EndTime || '').slice(0, 5),
      status: String(raw?.status || raw?.Status || ''),
      is_contract_exception: !!(raw?.is_contract_exception ?? raw?.IsContractException ?? false),
      substitute_teacher_id: raw?.substitute_teacher_id != null ? Number(raw.substitute_teacher_id) : null,
      learning_record_id: raw?.learning_record_id != null ? Number(raw.learning_record_id) : null,
      learning_record_status: String(raw?.learning_record_status || 'missing'),
      learning_record_body_filled: !!raw?.learning_record_body_filled,
      learning_record_teacher_id: raw?.learning_record_teacher_id != null ? Number(raw.learning_record_teacher_id) : null,
      attendance_sign_in_at: raw?.attendance_sign_in_at || null,
      attendance_memo: String(raw?.attendance_memo || ''),
      recorded_by_name: String(raw?.recorded_by_name || ''),
      student_name: raw?.student_name || '',
      teacher_name: raw?.teacher_name || '',
      note: raw?.note || null,
    };

    if (!item.id || !item.student_class_id || !item.session_date) continue;
    const key = String(item.student_class_id);
    if (!byClass[key]) byClass[key] = [];
    if (!byClass[key].some((r) => r.id === item.id)) {
      byClass[key].push(item);
    }
  }

  Object.keys(byClass).forEach((key) => {
    byClass[key].sort((a, b) => {
      if (a.session_date !== b.session_date) return a.session_date.localeCompare(b.session_date);
      if (a.start_time !== b.start_time) return a.start_time.localeCompare(b.start_time);
      return a.id - b.id;
    });
  });

  return { items, byClass };
}

// 分批大小：提高到 200 避免 Sentry N+1 誤判（MySQL IN 子句可安全支援 200+ 筆 ID）
// 大多數分校課程數 < 200，因此通常只需一次請求
const CHUNK_SIZE = 200;

async function fetchClassSessionsSingle({
  token, branchId, studentClassId, studentClassIds,
  teacherId, studentId, start, end, perPage = 2000,
} = {}) {
  const params = new URLSearchParams();
  if (branchId) params.set('branch_id', String(branchId));
  if (studentClassId) params.set('student_class_id', String(studentClassId));
  if (Array.isArray(studentClassIds) && studentClassIds.length) {
    params.set('student_class_ids', studentClassIds.map((id) => Number(id)).filter((id) => id > 0).join(','));
  }
  if (teacherId) params.set('teacher_id', String(teacherId));
  if (studentId) params.set('student_id', String(studentId));
  if (start) params.set('start', String(start));
  if (end) params.set('end', String(end));
  params.set('per_page', String(perPage));

  const res = await fetch(`/api/v1/class-sessions?${params.toString()}`, {
    headers: {
      'Accept': 'application/json',
      ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
    },
  });

  if (!res.ok) {
    throw new Error(`class-sessions fetch failed (${res.status})`);
  }

  const json = await res.json().catch(() => ({}));
  return normalizeClassSessionsPayload(json);
}

export async function fetchClassSessions(opts = {}) {
  const ids = Array.isArray(opts.studentClassIds) ? opts.studentClassIds.map(Number).filter((id) => id > 0) : [];

  if (ids.length <= CHUNK_SIZE) {
    return fetchClassSessionsSingle(opts);
  }

  const merged = { items: [], byClass: {} };
  const chunks = [];
  for (let i = 0; i < ids.length; i += CHUNK_SIZE) {
    chunks.push(ids.slice(i, i + CHUNK_SIZE));
  }

  const results = await Promise.all(
    chunks.map((chunk) => fetchClassSessionsSingle({ ...opts, studentClassIds: chunk }))
  );

  for (const result of results) {
    merged.items.push(...result.items);
    for (const [key, rows] of Object.entries(result.byClass)) {
      if (!merged.byClass[key]) merged.byClass[key] = [];
      merged.byClass[key].push(...rows);
    }
  }

  for (const key of Object.keys(merged.byClass)) {
    merged.byClass[key].sort((a, b) => {
      if (a.session_date !== b.session_date) return a.session_date.localeCompare(b.session_date);
      if (a.start_time !== b.start_time) return a.start_time.localeCompare(b.start_time);
      return a.id - b.id;
    });
  }

  return merged;
}

