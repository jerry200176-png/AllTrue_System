/**
 * Public admissions URL construction and branch context parsing.
 * Preserves public route contract without leaking tokens, JWTs, or director identity.
 */

export function buildPublicAdmissionsUrl({
  origin = '',
  pathname = '',
  branchId = null,
} = {}) {
  const base = `${origin}${pathname}#/admissions`;
  if (branchId === null || branchId === undefined || branchId === '') {
    return base;
  }
  const cleanId = String(branchId).trim();
  if (!cleanId) return base;
  return `${base}?branch=${encodeURIComponent(cleanId)}`;
}

export function parsePublicAdmissionsContext({
  hash = '',
  search = '',
  propBranchId = null,
} = {}) {
  if (propBranchId !== null && propBranchId !== undefined && propBranchId !== '') {
    const rawProp = String(propBranchId).trim();
    if (rawProp) {
      const num = Number(rawProp);
      return Number.isFinite(num) && num > 0 ? num : rawProp;
    }
  }

  // 1. Inspect hash query: e.g. #/admissions?branch=2 or #/admissions?campus_id=2
  if (typeof hash === 'string' && hash.includes('?')) {
    const hashQuery = hash.slice(hash.indexOf('?') + 1);
    const params = new URLSearchParams(hashQuery);
    const candidate = params.get('branch') || params.get('campus_id');
    if (candidate) {
      const trimmed = candidate.trim();
      const num = Number(trimmed);
      return Number.isFinite(num) && num > 0 ? num : trimmed;
    }
  }

  // 2. Inspect search query: e.g. ?branch=2#/admissions
  if (typeof search === 'string' && search) {
    const cleanSearch = search.startsWith('?') ? search.slice(1) : search;
    const params = new URLSearchParams(cleanSearch);
    const candidate = params.get('branch') || params.get('campus_id');
    if (candidate) {
      const trimmed = candidate.trim();
      const num = Number(trimmed);
      return Number.isFinite(num) && num > 0 ? num : trimmed;
    }
  }

  return null;
}

export function matchPresetCampus(branches = [], targetBranch = null) {
  if (!targetBranch || !Array.isArray(branches) || branches.length === 0) {
    return null;
  }

  const isNumeric = Number.isFinite(Number(targetBranch)) && Number(targetBranch) > 0;
  const numTarget = isNumeric ? Number(targetBranch) : null;
  const strTarget = String(targetBranch).trim().toLowerCase();

  // Match by id (number or string) or by campus code
  const matched = branches.find(b => {
    if (!b) return false;
    if (numTarget !== null && Number(b.id) === numTarget) return true;
    if (String(b.id).trim() === strTarget) return true;
    if (b.code && String(b.code).trim().toLowerCase() === strTarget) return true;
    return false;
  });

  return matched || null;
}
