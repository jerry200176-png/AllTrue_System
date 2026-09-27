import { isTrueFitHost } from './truefitHost.js';
import { localTodayYmd } from './teacherLoginStreak.js';

function splitHash(hash) {
  const raw = String(hash || '');
  const q = raw.indexOf('?');
  if (q === -1) return { path: raw, params: new URLSearchParams() };
  return {
    path: raw.slice(0, q),
    params: new URLSearchParams(raw.slice(q + 1)),
  };
}

function normalizeSessionDate(value) {
  const ymd = String(value || '').slice(0, 10);
  return /^\d{4}-\d{2}-\d{2}$/.test(ymd) ? ymd : null;
}

function withSessionDate(baseHash, sessionDate) {
  const ymd = normalizeSessionDate(sessionDate);
  if (!ymd) return baseHash;
  return `${baseHash}?d=${ymd}`;
}

export function parseTrueFitRoute(locationLike = null) {
  if (typeof window === 'undefined' && !locationLike) return null;

  const loc = locationLike || window.location;
  const { path: hashPath, params: hashParams } = splitHash(loc.hash || '');
  const params = new URLSearchParams(String(loc.search || ''));
  const sessionDate = normalizeSessionDate(hashParams.get('d'));

  if (hashPath === '#/truefit' || hashPath === '#/truefit/') {
    return { view: 'workspace' };
  }

  if (hashPath === '#/truefit/paper-fixture') return { view: 'paper-fixture' };

  const prepMatch = hashPath.match(/^#\/truefit\/(prep|observe|diagnose|remediate|mastery)\/(\d+)$/);
  if (prepMatch) {
    return {
      view: prepMatch[1],
      classSessionId: Number(prepMatch[2]),
      ...(sessionDate ? { sessionDate } : {}),
    };
  }

  const projectedPrepMatch = hashPath.match(/^#\/truefit\/(prep|observe|diagnose|remediate|mastery)\/c(\d+)-(\d{4})$/);
  if (projectedPrepMatch) {
    return {
      view: projectedPrepMatch[1],
      studentClassId: Number(projectedPrepMatch[2]),
      projectedStartHm: projectedPrepMatch[3],
      ...(sessionDate ? { sessionDate } : {}),
    };
  }

  if (params.get('truefit') === '1') {
    return { view: 'workspace' };
  }

  if (isTrueFitHost(loc.hostname)) {
    return { view: 'workspace' };
  }

  return null;
}

export function buildTrueFitPrepUrl(sessionOrId) {
  return buildTrueFitSessionViewUrl('prep', sessionOrId);
}

export function buildTrueFitObserveUrl(sessionOrId) {
  return buildTrueFitSessionViewUrl('observe', sessionOrId);
}

export function buildTrueFitDiagnoseUrl(sessionOrId) {
  return buildTrueFitSessionViewUrl('diagnose', sessionOrId);
}

export function buildTrueFitRemediateUrl(sessionOrId) {
  return buildTrueFitSessionViewUrl('remediate', sessionOrId);
}

export function buildTrueFitMasteryUrl(sessionOrId) {
  return buildTrueFitSessionViewUrl('mastery', sessionOrId);
}

function buildTrueFitSessionViewUrl(view, sessionOrId) {
  const allowed = ['prep', 'observe', 'diagnose', 'remediate', 'mastery'];
  const prefix = allowed.includes(view) ? view : 'prep';
  if (sessionOrId && typeof sessionOrId === 'object') {
    const sessionDate = sessionOrId.session_date || sessionOrId.sessionDate || null;
    if (sessionOrId.class_session_id) {
      return withSessionDate(
        `#/truefit/${prefix}/${Number(sessionOrId.class_session_id)}`,
        sessionDate,
      );
    }
    const classId = Number(sessionOrId.student_class_id || 0);
    const start = String(sessionOrId.start_time || '').slice(0, 5).replace(':', '');
    return withSessionDate(
      `#/truefit/${prefix}/c${classId}-${start || '0000'}`,
      sessionDate,
    );
  }

  return `#/truefit/${prefix}/${Number(sessionOrId)}`;
}

export function buildTrueFitWorkspaceUrl() {
  return '#/truefit';
}

export function buildAdminReturnUrl() {
  return '/?app_page=teacher-home';
}

/**
 * Seed a minimal session object from a parsed prep/observe route.
 * Prefer explicit route sessionDate; never invent UTC ISO "today".
 */
export function seedSessionFromPrepRoute(route, { fallbackDate = null } = {}) {
  if (!route || !['prep','observe','diagnose','remediate','mastery'].includes(route.view)) return null;
  const sessionDate = normalizeSessionDate(route.sessionDate)
    || normalizeSessionDate(fallbackDate)
    || localTodayYmd();

  if (route.classSessionId) {
    return {
      class_session_id: route.classSessionId,
      session_date: sessionDate,
    };
  }

  if (route.studentClassId) {
    const hm = String(route.projectedStartHm || '0000');
    return {
      class_session_id: null,
      student_class_id: route.studentClassId,
      start_time: `${hm.slice(0, 2)}:${hm.slice(2, 4)}`,
      session_date: sessionDate,
    };
  }

  return null;
}

/**
 * Match a today-sessions row to a seeded prep session for label enrichment.
 */
export function matchTodaySession(seed, sessions = []) {
  if (!seed || !Array.isArray(sessions) || !sessions.length) return null;
  const seedDate = normalizeSessionDate(seed.session_date);

  if (seed.class_session_id) {
    return sessions.find((row) => Number(row?.class_session_id) === Number(seed.class_session_id)) || null;
  }

  const seedClassId = Number(seed.student_class_id || 0);
  const seedStart = String(seed.start_time || '').slice(0, 5);
  return sessions.find((row) => {
    if (Number(row?.class_session_id || 0)) return false;
    if (Number(row?.student_class_id || 0) !== seedClassId) return false;
    if (String(row?.start_time || '').slice(0, 5) !== seedStart) return false;
    if (seedDate && normalizeSessionDate(row?.session_date) && normalizeSessionDate(row.session_date) !== seedDate) {
      return false;
    }
    return true;
  }) || null;
}
