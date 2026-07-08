/**
 * Runtime feature flags — reads pdp_v3.runtime_flags from canonical ci-artifacts/pdp.json via API.
 */
import { supabase } from '../supabase.js';

let snapshot = null;
let loadedAt = 0;
const CACHE_TTL_MS = 60_000;

async function getAuthHeaders() {
  const { data: { session } } = await supabase.auth.getSession();
  const token = session?.access_token;
  return token ? { Authorization: `Bearer ${token}` } : {};
}

async function loadRuntimeFlags() {
  if (snapshot && Date.now() - loadedAt < CACHE_TTL_MS) {
    return snapshot;
  }
  try {
    const res = await fetch('/api/v1/policy/runtime-flags', { headers: await getAuthHeaders() });
    if (!res.ok) return {};
    const data = await res.json();
    snapshot = data.runtime_flags || {};
    loadedAt = Date.now();
    return snapshot;
  } catch {
    return {};
  }
}

/**
 * @param {string} flagKey
 * @returns {Promise<boolean>}
 */
export async function featureFlag(flagKey) {
  const flags = await loadRuntimeFlags();
  return Boolean(flags[flagKey]);
}

export function clearFeatureFlagCache() {
  snapshot = null;
  loadedAt = 0;
}
