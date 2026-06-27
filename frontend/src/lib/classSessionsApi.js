/**
 * Unified session presentation model — all API → UI transformation lives here.
 *
 * @typedef {object} SessionViewModel
 * @property {number|null} id
 * @property {'materialized'|'projected'} kind
 * @property {number} studentClassId
 * @property {string} date YYYY-MM-DD
 * @property {string} startTime HH:MM
 * @property {string} endTime HH:MM
 * @property {string} status
 * @property {boolean} isProjected
 * @property {number} [studentId]
 * @property {number} [teacherId]
 * @property {number} [branchId]
 * @property {boolean} [isContractException]
 * @property {number|null} [substituteTeacherId]
 * @property {number|null} [learningRecordId]
 * @property {string} [learningRecordStatus]
 * @property {boolean} [learningRecordBodyFilled]
 * @property {number|null} [learningRecordTeacherId]
 * @property {string|null} [attendanceSignInAt]
 * @property {string} [attendanceMemo]
 * @property {string} [recordedByName]
 * @property {string} [studentName]
 * @property {string} [teacherName]
 * @property {string|null} [note]
 * @property {number|null} [sessionCharge]
 * @property {number|null} [contractRate]
 * @property {number|null} [contractSessionDuration]
 * @property {string|null} [contractRateUnit]
 * @property {string} [subject]
 * @property {string} [subjectName]
 */

function hm(value) {
  return String(value || '').slice(0, 5);
}

function ymd(value) {
  return String(value || '').slice(0, 10);
}

export function sessionViewModelKey(vm) {
  if (vm?.id) return `id:${vm.id}`;
  return `${ymd(vm?.date)}|${hm(vm?.startTime)}`;
}

function slotKey(date, startTime) {
  return `${ymd(date)}|${hm(startTime)}`;
}

/** @param {Partial<SessionViewModel> & { kind?: string, isProjected?: boolean }} input */
export function createSessionViewModel(input) {
  const isProjected = input.isProjected ?? input.kind === 'projected';
  const id = input.id != null && Number(input.id) > 0 ? Number(input.id) : null;

  return {
    id,
    kind: isProjected ? 'projected' : 'materialized',
    studentClassId: Number(input.studentClassId || 0),
    date: ymd(input.date),
    startTime: hm(input.startTime),
    endTime: hm(input.endTime),
    status: String(input.status || (isProjected ? 'projected' : 'scheduled')),
    isProjected,
    studentId: input.studentId != null ? Number(input.studentId) : undefined,
    teacherId: input.teacherId != null ? Number(input.teacherId) : undefined,
    branchId: input.branchId != null ? Number(input.branchId) : undefined,
    isContractException: input.isContractException,
    substituteTeacherId: input.substituteTeacherId ?? undefined,
    learningRecordId: input.learningRecordId ?? undefined,
    learningRecordStatus: input.learningRecordStatus,
    learningRecordBodyFilled: input.learningRecordBodyFilled,
    learningRecordTeacherId: input.learningRecordTeacherId ?? undefined,
    attendanceSignInAt: input.attendanceSignInAt ?? undefined,
    attendanceMemo: input.attendanceMemo,
    recordedByName: input.recordedByName,
    studentName: input.studentName,
    teacherName: input.teacherName,
    note: input.note ?? undefined,
    sessionCharge: input.sessionCharge ?? undefined,
    contractRate: input.contractRate ?? undefined,
    contractSessionDuration: input.contractSessionDuration ?? undefined,
    contractRateUnit: input.contractRateUnit ?? undefined,
    subject: input.subject,
    subjectName: input.subjectName,
  };
}

/** Map API patch / write response fields to ViewModel overlay (partial updates). */
export function sessionViewModelPatchFromApi(raw) {
  if (!raw || raw.id == null) return null;
  const id = Number(raw.id);
  if (!id) return null;
  /** @type {Partial<SessionViewModel>} */
  const patch = { id };
  if (raw.session_date != null || raw.date != null) patch.date = ymd(raw.session_date ?? raw.date);
  if (raw.start_time != null || raw.startTime != null) patch.startTime = hm(raw.start_time ?? raw.startTime);
  if (raw.end_time != null || raw.endTime != null) patch.endTime = hm(raw.end_time ?? raw.endTime);
  if (raw.status != null) patch.status = String(raw.status);
  if (raw.teacher_id != null || raw.teacherId != null) patch.teacherId = Number(raw.teacher_id ?? raw.teacherId);
  if (raw.teacher_name != null || raw.teacherName != null) patch.teacherName = String(raw.teacher_name ?? raw.teacherName);
  if (raw.learning_record_status != null || raw.learningRecordStatus != null) {
    patch.learningRecordStatus = String(raw.learning_record_status ?? raw.learningRecordStatus);
  }
  if (raw.learning_record_id != null || raw.learningRecordId != null) {
    patch.learningRecordId = Number(raw.learning_record_id ?? raw.learningRecordId);
  }
  if (raw.note !== undefined) patch.note = raw.note;
  return patch;
}

/** @param {object} raw class-sessions index row */
export function sessionViewModelFromClassSessionsRow(raw) {
  if (!raw) return null;
  const id = Number(raw?.id || 0);
  const studentClassId = Number(raw?.student_class_id || raw?.StudentClassID || 0);
  const date = ymd(raw?.session_date || raw?.SessionDate);
  if (!id || !studentClassId || !date) return null;

  return createSessionViewModel({
    id,
    kind: 'materialized',
    studentClassId,
    date,
    startTime: raw?.start_time || raw?.StartTime,
    endTime: raw?.end_time || raw?.EndTime,
    status: raw?.status || raw?.Status || 'scheduled',
    isProjected: false,
    studentId: Number(raw?.student_id || raw?.StudentID || 0) || undefined,
    teacherId: Number(raw?.teacher_id || raw?.TeacherID || 0) || undefined,
    branchId: Number(raw?.branch_id || raw?.CampusID || 0) || undefined,
    isContractException: !!(raw?.is_contract_exception ?? raw?.IsContractException ?? false),
    substituteTeacherId: raw?.substitute_teacher_id != null ? Number(raw.substitute_teacher_id) : null,
    learningRecordId: raw?.learning_record_id != null ? Number(raw.learning_record_id) : null,
    learningRecordStatus: String(raw?.learning_record_status || 'missing'),
    learningRecordBodyFilled: !!raw?.learning_record_body_filled,
    learningRecordTeacherId: raw?.learning_record_teacher_id != null ? Number(raw.learning_record_teacher_id) : null,
    attendanceSignInAt: raw?.attendance_sign_in_at || null,
    attendanceMemo: String(raw?.attendance_memo || ''),
    recordedByName: String(raw?.recorded_by_name || ''),
    studentName: raw?.student_name || '',
    teacherName: raw?.teacher_name || '',
    note: raw?.note ?? null,
    sessionCharge: raw?.session_charge != null ? Number(raw.session_charge) : null,
    contractRate: raw?.contract_rate != null ? Number(raw.contract_rate) : null,
    contractSessionDuration: raw?.contract_session_duration != null ? Number(raw.contract_session_duration) : null,
    contractRateUnit: raw?.contract_rate_unit != null ? String(raw.contract_rate_unit) : null,
    subject: raw?.subject || '',
    subjectName: raw?.subject_name || raw?.subject || '',
  });
}

/** @param {object} raw session-dates materialized or projected slot */
export function sessionViewModelFromSessionDatesSlot(raw, fallbackClassId = 0) {
  if (!raw || typeof raw !== 'object') return null;
  const studentClassId = Number(raw?.student_class_id || raw?.studentClassId || fallbackClassId || 0);
  const date = ymd(raw?.session_date || raw?.date);
  if (!studentClassId || !date) return null;

  const isProjected = raw?.kind === 'projected'
    || raw?.status === 'projected'
    || raw?.isProjected === true
    || !raw?.id;

  if (isProjected && raw?.id) {
    return null;
  }

  if (!isProjected) {
    return sessionViewModelFromClassSessionsRow({
      ...raw,
      student_class_id: studentClassId,
      session_date: date,
    }) || createSessionViewModel({
      id: raw?.id,
      kind: 'materialized',
      studentClassId,
      date,
      startTime: raw?.start_time || raw?.startTime,
      endTime: raw?.end_time || raw?.endTime,
      status: raw?.status || 'scheduled',
      isProjected: false,
    });
  }

  return createSessionViewModel({
    id: null,
    kind: 'projected',
    studentClassId,
    date,
    startTime: raw?.start_time || raw?.startTime,
    endTime: raw?.end_time || raw?.endTime,
    status: 'projected',
    isProjected: true,
  });
}

/** @param {object} raw ensure-projected / patch payload */
export function sessionViewModelFromEnsureProjected(raw) {
  if (!raw) return null;
  return sessionViewModelFromClassSessionsRow({
    id: raw?.id,
    student_class_id: raw?.student_class_id,
    session_date: raw?.session_date,
    start_time: raw?.start_time,
    end_time: raw?.end_time,
    status: raw?.status,
    note: raw?.note,
    teacher_id: raw?.teacher_id,
    teacher_name: raw?.teacher_name,
    learning_record_status: raw?.learning_record_status,
  });
}

/** @param {SessionViewModel[]} rows */
export function sortSessionViewModels(rows) {
  return [...rows].sort((a, b) => {
    if (a.date !== b.date) return a.date.localeCompare(b.date);
    if (a.startTime !== b.startTime) return a.startTime.localeCompare(b.startTime);
    if (a.isProjected !== b.isProjected) return a.isProjected ? 1 : -1;
    return (a.id || 0) - (b.id || 0);
  });
}

/** @param {SessionViewModel[]} existing @param {SessionViewModel[]} incoming */
export function mergeSessionViewModels(existing = [], incoming = []) {
  const bySlot = new Map();

  for (const vm of [...existing, ...incoming]) {
    if (!vm?.date) continue;
    const key = slotKey(vm.date, vm.startTime);
    const prev = bySlot.get(key);
    if (!prev) {
      bySlot.set(key, vm);
      continue;
    }
    if (prev.isProjected && !vm.isProjected) {
      bySlot.set(key, { ...prev, ...vm, isProjected: false, kind: 'materialized', id: vm.id ?? prev.id });
      continue;
    }
    if (!prev.isProjected && !vm.isProjected) {
      bySlot.set(key, { ...prev, ...vm });
      continue;
    }
    if (prev.isProjected && vm.isProjected) {
      bySlot.set(key, vm);
    }
  }

  return sortSessionViewModels([...bySlot.values()]);
}

/** @param {Record<string, SessionViewModel[]>} base @param {Record<string, SessionViewModel[]>} patch */
export function mergeSessionsByCourse(base = {}, patch = {}) {
  const out = { ...base };
  for (const [key, rows] of Object.entries(patch)) {
    out[key] = mergeSessionViewModels(out[key] || [], rows || []);
  }
  return out;
}

function buildByClassFromItems(items) {
  /** @type {Record<string, SessionViewModel[]>} */
  const byClass = {};
  for (const vm of items) {
    const key = String(vm.studentClassId);
    if (!byClass[key]) byClass[key] = [];
    if (vm.id && byClass[key].some((r) => r.id === vm.id)) continue;
    if (!vm.id && byClass[key].some((r) => !r.id && r.date === vm.date && r.startTime === vm.startTime)) continue;
    byClass[key].push(vm);
  }
  Object.keys(byClass).forEach((key) => {
    byClass[key] = sortSessionViewModels(byClass[key]);
  });
  return byClass;
}

export function normalizeClassSessionsPayload(json) {
  const materializedRoot = json?.materialized ?? json;
  const projectedRoot = json?.projected ?? {};

  const rawItems = Array.isArray(materializedRoot?.data)
    ? materializedRoot.data
    : (Array.isArray(json?.data) ? json.data : (Array.isArray(json) ? json : []));

  /** @type {SessionViewModel[]} */
  const items = [];
  for (const raw of rawItems) {
    const vm = sessionViewModelFromClassSessionsRow(raw);
    if (vm) items.push(vm);
  }

  const rawProjected = projectedRoot?.by_class && typeof projectedRoot.by_class === 'object'
    ? projectedRoot.by_class
    : {};

  /** @type {SessionViewModel[]} */
  const projectedItems = [];
  Object.keys(rawProjected).forEach((key) => {
    const rows = Array.isArray(rawProjected[key]) ? rawProjected[key] : [];
    for (const raw of rows) {
      const vm = sessionViewModelFromSessionDatesSlot(raw, Number(key));
      if (vm?.isProjected) projectedItems.push(vm);
    }
  });

  const mergedItems = mergeSessionViewModels(items, projectedItems);
  const byClass = buildByClassFromItems(mergedItems);

  return { items: mergedItems, byClass };
}

export function normalizeSessionDatesPayload(json) {
  /** @type {Record<string, SessionViewModel[]>} */
  const byClass = {};

  Object.keys(json || {}).forEach((key) => {
    const entry = json[key];
    /** @type {SessionViewModel[]} */
    const rows = [];

    if (entry && typeof entry === 'object' && (Array.isArray(entry.materialized) || Array.isArray(entry.projected))) {
      for (const raw of entry.materialized || []) {
        const vm = sessionViewModelFromSessionDatesSlot(raw, Number(key));
        if (vm && !vm.isProjected) rows.push(vm);
      }
      for (const raw of entry.projected || []) {
        const vm = sessionViewModelFromSessionDatesSlot(raw, Number(key));
        if (vm?.isProjected) rows.push(vm);
      }
    } else if (Array.isArray(entry)) {
      for (const raw of entry) {
        if (typeof raw === 'string') {
          rows.push(createSessionViewModel({
            id: null,
            kind: 'projected',
            studentClassId: Number(key),
            date: raw,
            startTime: '00:00',
            endTime: '',
            status: 'projected',
            isProjected: true,
          }));
        }
      }
    }

    if (rows.length) {
      byClass[String(key)] = mergeSessionViewModels([], rows);
    }
  });

  return { byClass };
}

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
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
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
    merged.items = mergeSessionViewModels(merged.items, result.items);
    merged.byClass = mergeSessionsByCourse(merged.byClass, result.byClass);
  }

  return merged;
}

export async function fetchSessionDates({
  token,
  branchId,
  rangeStart,
  rangeEnd,
  courses = [],
} = {}) {
  const params = new URLSearchParams({ branch_id: String(branchId) });
  const res = await fetch(`/api/v1/student-classes/session-dates?${params.toString()}`, {
    method: 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: JSON.stringify({
      branch_id: Number(branchId),
      range_start: rangeStart,
      range_end: rangeEnd,
      courses,
    }),
  });

  if (!res.ok) {
    throw new Error(`session-dates fetch failed (${res.status})`);
  }

  const json = await res.json().catch(() => ({}));
  return normalizeSessionDatesPayload(json);
}

/** @param {SessionViewModel} vm @param {Partial<SessionViewModel>} patch */
export function patchSessionViewModel(vm, patch) {
  return createSessionViewModel({ ...vm, ...patch, id: patch.id ?? vm.id });
}

export function materializedSessionsOnly(rows = []) {
  return (rows || []).filter((vm) => !vm.isProjected);
}

export function sessionDatesFromViewModels(rows = []) {
  return [...new Set((rows || []).map((vm) => vm.date).filter(Boolean))].sort();
}
