import { isTrueFitHost } from './truefitHost.js';

export function parseTrueFitRoute(locationLike = null) {
  if (typeof window === 'undefined' && !locationLike) return null;

  const loc = locationLike || window.location;
  const hash = String(loc.hash || '');
  const params = new URLSearchParams(String(loc.search || ''));
  const hashPath = hash.split('?')[0];

  if (hashPath === '#/truefit' || hashPath === '#/truefit/') {
    return { view: 'workspace' };
  }

  const prepMatch = hashPath.match(/^#\/truefit\/prep\/(\d+)$/);
  if (prepMatch) {
    return { view: 'prep', classSessionId: Number(prepMatch[1]) };
  }

  const projectedPrepMatch = hashPath.match(/^#\/truefit\/prep\/c(\d+)-(\d{4})$/);
  if (projectedPrepMatch) {
    return {
      view: 'prep',
      studentClassId: Number(projectedPrepMatch[1]),
      projectedStartHm: projectedPrepMatch[2],
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
  if (sessionOrId && typeof sessionOrId === 'object') {
    if (sessionOrId.class_session_id) {
      return `#/truefit/prep/${Number(sessionOrId.class_session_id)}`;
    }
    const classId = Number(sessionOrId.student_class_id || 0);
    const start = String(sessionOrId.start_time || '').slice(0, 5).replace(':', '');
    return `#/truefit/prep/c${classId}-${start || '0000'}`;
  }

  return `#/truefit/prep/${Number(sessionOrId)}`;
}

export function buildTrueFitWorkspaceUrl() {
  return '#/truefit';
}

export function buildAdminReturnUrl() {
  return '/?app_page=teacher-home';
}
