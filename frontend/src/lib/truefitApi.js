/**
 * TrueFit API client (Slice 0 + Slice 1 Teacher Brief).
 */

function trueFitHeaders(token) {
  return {
    Accept: 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
  };
}

async function throwTrueFitError(res, fallback) {
  if (res.status === 404) {
    const err = new Error('TrueFit 尚未啟用');
    err.code = 'truefit_disabled';
    throw err;
  }
  if (res.status === 403) {
    const err = new Error('您目前無法使用 TrueFit 工作台');
    err.code = 'truefit_forbidden';
    throw err;
  }
  if (!res.ok) {
    throw new Error(`${fallback}（${res.status}）`);
  }
}

export async function fetchTrueFitTodaySessions({ token, branchId } = {}) {
  const params = new URLSearchParams();
  if (branchId) params.set('branch_id', String(branchId));

  const res = await fetch(`/api/v1/truefit/today-sessions?${params.toString()}`, {
    headers: trueFitHeaders(token),
  });
  await throwTrueFitError(res, 'TrueFit 載入失敗');
  return res.json();
}

/**
 * Aggregate stage-presence for visible workspace sessions (read-only).
 * Body: { sessions: [ { class_session_id } | { student_class_id, session_date, start_time } ] }
 * Contract: inaccessible/invalid refs are omitted (meta.contract=omit_inaccessible).
 */
export async function fetchTrueFitSessionProgress({ token, sessions } = {}) {
  const res = await fetch('/api/v1/truefit/session-progress', {
    method: 'POST',
    headers: {
      ...trueFitHeaders(token),
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ sessions: Array.isArray(sessions) ? sessions : [] }),
  });
  await throwTrueFitError(res, '堂次進度載入失敗');
  return res.json();
}

/** Build a session-progress request ref from a today-sessions row. */
export function trueFitSessionProgressRef(session) {
  if (!session || typeof session !== 'object') return null;
  const classSessionId = Number(session.class_session_id || 0);
  if (classSessionId > 0) {
    return { class_session_id: classSessionId };
  }
  const studentClassId = Number(session.student_class_id || 0);
  const sessionDate = String(session.session_date || '').slice(0, 10);
  const startTime = String(session.start_time || '').slice(0, 5);
  if (studentClassId <= 0 || !/^\d{4}-\d{2}-\d{2}$/.test(sessionDate) || !/^\d{2}:\d{2}$/.test(startTime)) {
    return null;
  }
  return {
    student_class_id: studentClassId,
    session_date: sessionDate,
    start_time: startTime,
  };
}

/** Map API row session_ref → stable progress lookup key (mirrors workspace list keys). */
export function trueFitProgressLookupKey(sessionOrRef) {
  if (!sessionOrRef || typeof sessionOrRef !== 'object') return '';
  const classSessionId = Number(sessionOrRef.class_session_id || 0);
  if (classSessionId > 0) return `m:${classSessionId}`;
  const studentClassId = Number(sessionOrRef.student_class_id || 0);
  const sessionDate = String(sessionOrRef.session_date || '').slice(0, 10);
  const startTime = String(sessionOrRef.start_time || '').slice(0, 5);
  return `p:${studentClassId}|${sessionDate}|${startTime}`;
}

export async function fetchTrueFitMaterialUnits({ token, subjectHint } = {}) {
  const params = new URLSearchParams();
  if (subjectHint) params.set('subject_hint', String(subjectHint));

  const res = await fetch(`/api/v1/truefit/material-units?${params.toString()}`, {
    headers: trueFitHeaders(token),
  });
  await throwTrueFitError(res, '教材單元載入失敗');
  return res.json();
}

export async function fetchTrueFitLessonPrep({ token, classSessionId, studentClassId, sessionDate, startTime } = {}) {
  const params = new URLSearchParams();
  if (classSessionId) {
    params.set('class_session_id', String(classSessionId));
  } else {
    if (studentClassId) params.set('student_class_id', String(studentClassId));
    if (sessionDate) params.set('session_date', String(sessionDate));
    if (startTime) params.set('start_time', String(startTime).slice(0, 5));
  }

  const res = await fetch(`/api/v1/truefit/lesson-preps?${params.toString()}`, {
    headers: trueFitHeaders(token),
  });
  await throwTrueFitError(res, '備課資料載入失敗');
  return res.json();
}

export async function generateTrueFitLessonPrep({
  token,
  classSessionId,
  studentClassId,
  sessionDate,
  startTime,
  materialUnitKey,
  subjectName,
} = {}) {
  const body = {
    material_unit_key: materialUnitKey,
  };
  if (classSessionId) {
    body.class_session_id = Number(classSessionId);
  } else {
    body.student_class_id = Number(studentClassId);
    body.session_date = String(sessionDate || '').slice(0, 10);
    body.start_time = String(startTime || '').slice(0, 5);
  }
  if (subjectName) body.subject_name = subjectName;

  const res = await fetch('/api/v1/truefit/lesson-preps/generate', {
    method: 'POST',
    headers: {
      ...trueFitHeaders(token),
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(body),
  });
  await throwTrueFitError(res, 'Teacher Brief 產生失敗');
  return res.json();
}

export async function fetchTrueFitObservation({ token, classSessionId, studentClassId, sessionDate, startTime } = {}) {
  const params = new URLSearchParams();
  if (classSessionId) {
    params.set('class_session_id', String(classSessionId));
  } else {
    if (studentClassId) params.set('student_class_id', String(studentClassId));
    if (sessionDate) params.set('session_date', String(sessionDate));
    if (startTime) params.set('start_time', String(startTime).slice(0, 5));
  }

  const res = await fetch(`/api/v1/truefit/observations?${params.toString()}`, {
    headers: trueFitHeaders(token),
  });
  await throwTrueFitError(res, '課堂觀察載入失敗');
  return res.json();
}

export async function upsertTrueFitObservation({
  token,
  classSessionId,
  studentClassId,
  sessionDate,
  startTime,
  observation,
} = {}) {
  const body = { observation };
  if (classSessionId) {
    body.class_session_id = Number(classSessionId);
  } else {
    body.student_class_id = Number(studentClassId);
    body.session_date = String(sessionDate || '').slice(0, 10);
    body.start_time = String(startTime || '').slice(0, 5);
  }

  const res = await fetch('/api/v1/truefit/observations', {
    method: 'POST',
    headers: {
      ...trueFitHeaders(token),
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(body),
  });
  await throwTrueFitError(res, '課堂觀察儲存失敗');
  return res.json();
}

export async function fetchTrueFitDiagnosis({ token, classSessionId, studentClassId, sessionDate, startTime } = {}) {
  const params = new URLSearchParams();
  if (classSessionId) {
    params.set('class_session_id', String(classSessionId));
  } else {
    if (studentClassId) params.set('student_class_id', String(studentClassId));
    if (sessionDate) params.set('session_date', String(sessionDate));
    if (startTime) params.set('start_time', String(startTime).slice(0, 5));
  }

  const res = await fetch(`/api/v1/truefit/diagnoses?${params.toString()}`, {
    headers: trueFitHeaders(token),
  });
  await throwTrueFitError(res, '錯誤診斷載入失敗');
  return res.json();
}

export async function upsertTrueFitDiagnosis({
  token,
  classSessionId,
  studentClassId,
  sessionDate,
  startTime,
  diagnosis,
} = {}) {
  const body = { diagnosis };
  if (classSessionId) {
    body.class_session_id = Number(classSessionId);
  } else {
    body.student_class_id = Number(studentClassId);
    body.session_date = String(sessionDate || '').slice(0, 10);
    body.start_time = String(startTime || '').slice(0, 5);
  }

  const res = await fetch('/api/v1/truefit/diagnoses', {
    method: 'POST',
    headers: {
      ...trueFitHeaders(token),
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(body),
  });
  await throwTrueFitError(res, '錯誤診斷儲存失敗');
  return res.json();
}

export async function fetchTrueFitRemediation({ token, classSessionId, studentClassId, sessionDate, startTime } = {}) {
  const params = new URLSearchParams();
  if (classSessionId) {
    params.set('class_session_id', String(classSessionId));
  } else {
    if (studentClassId) params.set('student_class_id', String(studentClassId));
    if (sessionDate) params.set('session_date', String(sessionDate));
    if (startTime) params.set('start_time', String(startTime).slice(0, 5));
  }
  const res = await fetch(`/api/v1/truefit/remediations?${params.toString()}`, {
    headers: trueFitHeaders(token),
  });
  await throwTrueFitError(res, '補救計畫載入失敗');
  return res.json();
}

export async function upsertTrueFitRemediation({
  token, classSessionId, studentClassId, sessionDate, startTime, remediation,
} = {}) {
  const body = { remediation };
  if (classSessionId) {
    body.class_session_id = Number(classSessionId);
  } else {
    body.student_class_id = Number(studentClassId);
    body.session_date = String(sessionDate || '').slice(0, 10);
    body.start_time = String(startTime || '').slice(0, 5);
  }
  const res = await fetch('/api/v1/truefit/remediations', {
    method: 'POST',
    headers: { ...trueFitHeaders(token), 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  await throwTrueFitError(res, '補救計畫儲存失敗');
  return res.json();
}

export async function fetchTrueFitMastery({ token, classSessionId, studentClassId, sessionDate, startTime } = {}) {
  const params = new URLSearchParams();
  if (classSessionId) {
    params.set('class_session_id', String(classSessionId));
  } else {
    if (studentClassId) params.set('student_class_id', String(studentClassId));
    if (sessionDate) params.set('session_date', String(sessionDate));
    if (startTime) params.set('start_time', String(startTime).slice(0, 5));
  }
  const res = await fetch(`/api/v1/truefit/mastery-evidence?${params.toString()}`, {
    headers: trueFitHeaders(token),
  });
  await throwTrueFitError(res, '精熟證據載入失敗');
  return res.json();
}

export async function upsertTrueFitMastery({
  token, classSessionId, studentClassId, sessionDate, startTime, mastery,
} = {}) {
  const body = { mastery };
  if (classSessionId) {
    body.class_session_id = Number(classSessionId);
  } else {
    body.student_class_id = Number(studentClassId);
    body.session_date = String(sessionDate || '').slice(0, 10);
    body.start_time = String(startTime || '').slice(0, 5);
  }
  const res = await fetch('/api/v1/truefit/mastery-evidence', {
    method: 'POST',
    headers: { ...trueFitHeaders(token), 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  await throwTrueFitError(res, '精熟證據儲存失敗');
  return res.json();
}
