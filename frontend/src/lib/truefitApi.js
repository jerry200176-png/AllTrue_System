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
