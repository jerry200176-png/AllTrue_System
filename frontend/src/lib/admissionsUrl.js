/**
 * Public admissions URL construction and branch context parsing.
 * Preserves public route contract without leaking tokens, JWTs, or director identity.
 */
export function buildPublicAdmissionsUrl({ origin = '', pathname = '', branchId = null } = {}) {
  const base = `${origin}${pathname}#/admissions`;
  if (branchId === null || branchId === undefined || branchId === '') return base;
  const cleanId = String(branchId).trim();
  return cleanId ? `${base}?branch=${encodeURIComponent(cleanId)}` : base;
}

export function parsePublicAdmissionsContext({ hash = '', search = '', propBranchId = null } = {}) {
  if (propBranchId !== null && propBranchId !== undefined && propBranchId !== '') {
    const raw = String(propBranchId).trim();
    return (Number.isFinite(Number(raw)) && Number(raw) > 0) ? Number(raw) : (raw || null);
  }
  const inspectQuery = (qs) => {
    if (!qs) return null;
    const clean = qs.startsWith('?') ? qs.slice(1) : qs;
    const p = new URLSearchParams(clean);
    const val = (p.get('branch') || p.get('campus_id') || '').trim();
    return val ? ((Number.isFinite(Number(val)) && Number(val) > 0) ? Number(val) : val) : null;
  };
  if (hash && hash.includes('?')) {
    const res = inspectQuery(hash.slice(hash.indexOf('?') + 1));
    if (res) return res;
  }
  return inspectQuery(search);
}

export function matchPresetCampus(branches = [], targetBranch = null) {
  if (!targetBranch || !Array.isArray(branches) || !branches.length) return null;
  const numTarget = (Number.isFinite(Number(targetBranch)) && Number(targetBranch) > 0) ? Number(targetBranch) : null;
  const strTarget = String(targetBranch).trim().toLowerCase();
  return branches.find(b => {
    if (!b) return false;
    if (numTarget !== null && Number(b.id) === numTarget) return true;
    if (String(b.id).trim() === strTarget) return true;
    return Boolean(b.code && String(b.code).trim().toLowerCase() === strTarget);
  }) || null;
}
