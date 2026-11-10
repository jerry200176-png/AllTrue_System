// @ts-check
/**
 * Local answers for the class-session reads that readOnlyAppGuard blocks (#5175).
 *
 * GET /api/v1/class-sessions and /api/v1/class-sessions/projection create ClassSession rows
 * when the window is one day (ClassSessionController::authorizeAndMaterializeClassSessionIndex:
 * the three autoMaterialize*ForRange methods return unless start === end). The director
 * dashboard sends exactly that one-day read on every login, so the guard aborts both paths.
 *
 * A production acceptance spec installs this responder after the guard. Every browser GET/HEAD
 * to the two paths is then answered in the browser and never sent:
 *  - the list path gets an empty list (no acceptance check reads it);
 *  - the projection path gets the rows the spec loaded itself, or an empty projection.
 * Anything else that matches (another method, an encoded spelling) falls back to the guard,
 * which aborts and records it.
 *
 * The spec may read the projection directly only with nonMaterializingProjectionPath(): a window
 * of two or more days, which the controller never materializes (locked by
 * ClassSessionsMultiDayReadDoesNotMaterializeTest).
 */
import { isKnownStatefulRead } from './readOnlyAppGuard.js';

export const CLASS_SESSION_LIST_PATH = '/api/v1/class-sessions';
export const CLASS_SESSION_PROJECTION_PATH = '/api/v1/class-sessions/projection';
const DEFAULT_LIST_PER_PAGE = 1000;

/** The value when it is a real calendar date written YYYY-MM-DD, else ''. */
export function strictYmd(value) {
  const text = typeof value === 'string' ? value : '';
  if (!/^\d{4}-\d{2}-\d{2}$/.test(text)) return '';
  const date = new Date(`${text}T00:00:00Z`);
  return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === text ? text : '';
}

/** True when both bounds are plain dates and end is after start: a window the controller never materializes. */
export function isNonMaterializingWindow(start, end) {
  const from = strictYmd(start);
  const to = strictYmd(end);
  return from !== '' && to !== '' && from < to;
}

/** Path and query for a direct projection read, or a thrown error when the read could materialize. */
export function nonMaterializingProjectionPath({ branchId, start, end }) {
  if (!Number.isSafeInteger(branchId) || branchId <= 0) {
    throw new Error('projection read needs a positive branch id');
  }
  if (!isNonMaterializingWindow(start, end)) {
    throw new Error('projection read needs a YYYY-MM-DD window that spans two or more days: '
      + 'a one-day window makes the server create class sessions');
  }
  const params = new URLSearchParams({ branch_id: String(branchId), start, end });
  return `${CLASS_SESSION_PROJECTION_PATH}?${params}`;
}

/** 'list' or 'projection' for a plain GET/HEAD to the app origin, else null (the guard decides). */
export function classSessionReadKind(appOrigin, method, url) {
  const verb = String(method || '').toUpperCase();
  if (!isKnownStatefulRead(appOrigin, verb, url)) return null;
  const path = new URL(String(url)).pathname;
  if (path === CLASS_SESSION_LIST_PATH) return 'list';
  if (path === CLASS_SESSION_PROJECTION_PATH) return 'projection';
  return null;
}

function rowDate(row) {
  return String(row?.session_date ?? row?.SessionDate ?? '').slice(0, 10);
}

function requestedBranch(searchParams) {
  return Number(searchParams.get('branch_id') || searchParams.get('campus_id') || 0);
}

/**
 * Validate what the spec loaded: { branchId, start, end, payload } where payload is the
 * projection response for exactly that campus and window.
 */
export function projectionSource({ branchId, start, end, payload }) {
  if (!Number.isSafeInteger(branchId) || branchId <= 0) throw new Error('projection source needs a positive branch id');
  if (!isNonMaterializingWindow(start, end)) throw new Error('projection source needs a multi-day window');
  if (payload?.api_kind !== 'projection' || payload?.completeness !== 'full' || !Array.isArray(payload?.data)) {
    throw new Error('projection source is not a complete projection response');
  }
  const byClass = payload.by_class && typeof payload.by_class === 'object' && !Array.isArray(payload.by_class)
    ? payload.by_class : {};
  return { branchId, start, end, rows: payload.data, byClass };
}

/** Rows of the source the request may see: same campus, inside both the request and the source window. */
function visibleRows(rows, searchParams, source) {
  const start = String(searchParams.get('start') || '').slice(0, 10);
  const end = String(searchParams.get('end') || '').slice(0, 10);
  return rows.filter((row) => {
    const date = rowDate(row);
    return date >= source.start && date <= source.end && (!start || date >= start) && (!end || date <= end);
  });
}

/** JSON body for one local answer. `source` comes from projectionSource(), or null for none. */
export function localClassSessionBody(kind, searchParams, source = null) {
  if (kind === 'list') {
    const perPage = Number(searchParams.get('per_page')) > 0 ? Number(searchParams.get('per_page')) : DEFAULT_LIST_PER_PAGE;
    const page = { data: [], by_class: {}, current_page: 1, last_page: 1, per_page: perPage, total: 0 };
    return { materialized: { ...page }, projected: { by_class: {} }, ...page };
  }
  if (kind !== 'projection') throw new Error(`unknown class-session read kind: ${kind}`);
  const served = source && requestedBranch(searchParams) === source.branchId;
  const data = served ? visibleRows(source.rows, searchParams, source) : [];
  const byClass = {};
  if (served) {
    for (const [classId, rows] of Object.entries(source.byClass)) {
      const kept = visibleRows(Array.isArray(rows) ? rows : [], searchParams, source);
      if (kept.length > 0) byClass[classId] = kept;
    }
  }
  // The calendar reads data and by_class only (classSessionsApi fetchClassSessionsProjection).
  return { api_kind: 'projection', completeness: 'full', total: data.length, data, by_class: byClass, projected: { by_class: {} } };
}

/**
 * Install after installReadOnlyAppGuard on the same context. Returns:
 *  - answered: one { kind, method, start, end, rows } per local answer (no row content);
 *  - serveProjection(source): rows for later projection reads (see projectionSource).
 */
export async function installLocalClassSessionReads(context, appOrigin) {
  const answered = [];
  let source = null;
  await context.route((url) => isKnownStatefulRead(appOrigin, 'GET', url), async (route) => {
    const request = route.request();
    const method = request.method().toUpperCase();
    const kind = classSessionReadKind(appOrigin, method, request.url());
    if (!kind) {
      await route.fallback();
      return;
    }
    const { searchParams } = new URL(request.url());
    const body = localClassSessionBody(kind, searchParams, source);
    answered.push({
      kind,
      method,
      start: String(searchParams.get('start') || '').slice(0, 10),
      end: String(searchParams.get('end') || '').slice(0, 10),
      rows: body.data.length,
    });
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: method === 'HEAD' ? '' : JSON.stringify(body),
    });
  });
  return {
    answered,
    serveProjection(next) {
      source = projectionSource(next);
    },
  };
}
