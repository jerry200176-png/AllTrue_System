/**
 * Staff multi-role acting context (in-app #299 Phase B).
 * Client-stored acting_as is context only — server validates capability + campus scope.
 */

export const ACTING_AS_STORAGE_KEY = 'alltrue_acting_as';
export const ACTING_AS_HEADER = 'X-Acting-As';

const VALID = new Set(['director', 'teacher']);

export function normalizeActingAs(value) {
  if (value == null || value === '') return null;
  const v = String(value).trim().toLowerCase();
  return VALID.has(v) ? v : null;
}

export function readStoredActingAs(storage = globalThis.localStorage) {
  try {
    return normalizeActingAs(storage?.getItem?.(ACTING_AS_STORAGE_KEY));
  } catch {
    return null;
  }
}

export function writeStoredActingAs(value, storage = globalThis.localStorage) {
  const next = normalizeActingAs(value);
  try {
    if (!storage) return next;
    if (next == null) storage.removeItem(ACTING_AS_STORAGE_KEY);
    else storage.setItem(ACTING_AS_STORAGE_KEY, next);
  } catch {
    /* ignore quota / private mode */
  }
  return next;
}

/**
 * Prefer explicit dual-capability context; omit header when absent so shared APIs
 * can resolve without failing merely because acting_as is missing.
 */
export function actingAsHeaders(actingAs = readStoredActingAs()) {
  const normalized = normalizeActingAs(actingAs);
  return normalized ? { [ACTING_AS_HEADER]: normalized } : {};
}

export function canSwitchStaffMode(capabilities) {
  if (!Array.isArray(capabilities)) return false;
  const caps = new Set(capabilities.map((c) => String(c).toLowerCase()));
  return caps.has('director') && caps.has('teacher');
}
