/**
 * Dynamic branch (campus) store.
 *
 * Fetches the branch list from the backend API (`GET /api/v1/branches`)
 * and provides a reactive `branches` ref that all components can use
 * instead of the hardcoded BRANCHES constant.
 *
 * Usage:
 *   import { branches, loadBranches, getBranchName } from '../lib/useBranches';
 *
 * The store is a singleton — `loadBranches()` only fetches once and
 * caches the result. Call it on app startup (e.g. in App.vue onMounted).
 */
import { ref } from 'vue';

const API_BASE = '/api/v1';

/**
 * Reactive array of branch objects: { id: number, name: string, code: string }
 * `id` is the integer campus ID used by the backend.
 * `code` is the short string identifier (e.g. 'daan').
 */
const DEFAULT_BRANCHES = [{ id: 1, name: '大安分校', code: 'daan' }];
export const branches = ref([...DEFAULT_BRANCHES]);

let _loaded = false;
let _loading = null;

/**
 * Fetch branches from the API. Safe to call multiple times —
 * subsequent calls return the cached promise.
 */
export async function loadBranches() {
    if (_loaded) return branches.value;
    if (_loading) return _loading;

    _loading = (async () => {
        const tryFetch = async (url) => {
            const resp = await fetch(url, { cache: 'no-store' });
            const text = await resp.text();
            if (!resp.ok) return null;
            if (text.trim().startsWith('<') || text.trim().startsWith('<?')) return null;
            try {
                const data = JSON.parse(text);
                return Array.isArray(data) ? data : null;
            } catch {
                return null;
            }
        };
        try {
            let data = await tryFetch('/branches.json');
            if (!data) data = await tryFetch(`${API_BASE}/branches`);
            if (data) branches.value = data;
        } catch (err) {
            console.warn('[useBranches]', err?.message || err);
        }
        _loaded = true;
        _loading = null;
        return branches.value;
    })();

    return _loading;
}

/**
 * Look up the display name for a branch by its integer ID.
 * Returns the name if found, or a fallback string.
 */
export function getBranchName(branchId) {
    if (branchId === null || branchId === undefined) return '未設定';
    const b = branches.value.find(br => br.id === branchId || br.code === branchId);
    return b ? b.name : `Branch #${branchId}`;
}

/**
 * Get the default branch ID (first branch in the list).
 * Returns null if branches haven't loaded yet.
 */
export function getDefaultBranchId() {
    return branches.value.length > 0 ? branches.value[0].id : null;
}

/**
 * Fetch campuses for director/super_admin from authenticated API.
 * Use this after login so the branch list reflects assigned campuses (director)
 * or all campuses (super_admin). Returns the array; caller should set branches.value = result.
 */
export async function loadBranchesForDirector(token) {
    if (!token) return [];
    try {
        const resp = await fetch(`${API_BASE}/campuses`, {
            headers: { Authorization: `Bearer ${token}` },
        });
        if (!resp.ok) return [];
        const text = await resp.text();
        if (text.trim().startsWith('<') || text.trim().startsWith('<?')) return [];
        const data = JSON.parse(text);
        return Array.isArray(data) ? data : [];
    } catch (err) {
        console.error('[useBranches] Error fetching campuses:', err);
        return [];
    }
}
