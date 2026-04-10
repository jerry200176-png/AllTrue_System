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
const DEFAULT_BRANCHES = [
    { id: 1,  name: '內湖分校',     code: 'neihu' },
    { id: 2,  name: '東湖分校',     code: 'donghu' },
    { id: 3,  name: '大直分校',     code: 'dazhi' },
    { id: 4,  name: '汐止分校',     code: 'xizhi' },
    { id: 5,  name: '新店校區',     code: 'xindian_qu' },
    { id: 6,  name: '麗山校區',     code: 'lishan' },
    { id: 7,  name: '蘆洲分校',     code: 'luzhou' },
    { id: 8,  name: '敦南分校',     code: 'dunnan' },
    { id: 9,  name: '新店分校',     code: 'xindian' },
    { id: 10, name: '桃園分校',     code: 'taoyuan' },
    { id: 11, name: '新莊分校',     code: 'xinzhuang' },
    { id: 12, name: '石牌分校',     code: 'campus_12' },
    { id: 13, name: '新莊中平分校', code: 'xinzhuang_zhongping' },
    { id: 14, name: '三重分校',     code: 'sanchong' },
    { id: 15, name: '大安分校',     code: 'daan' },
    { id: 16, name: '木柵分校',     code: 'muzha' },
    { id: 17, name: '興隆分校',     code: 'xinglong' },
    { id: 18, name: '新竹分校',     code: 'hsinchu' },
    { id: 19, name: '天母分校',     code: 'tianmu' },
    { id: 20, name: '中壢分校',     code: 'zhongli' },
];
export const branches = ref([...DEFAULT_BRANCHES]);

function mergeWithDefaults(list) {
    const merged = new Map();
    for (const item of list) {
        if (!item || (!item.code && !item.name)) continue;
        const key = item.code || item.name;
        const id = Number(item.id);
        merged.set(key, {
            id: Number.isFinite(id) ? id : null,
            name: item.name || key,
            code: item.code || '',
        });
    }
    for (const item of DEFAULT_BRANCHES) {
        const key = item.code || item.name;
        if (!merged.has(key)) merged.set(key, item);
    }
    return Array.from(merged.values());
}

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
            // Prefer live API to avoid stale static branches.json.
            let data = await tryFetch(`${API_BASE}/branches`);
            if (!data) data = await tryFetch('/branches.json');
            if (data) {
                branches.value = mergeWithDefaults(data);
                _loaded = true; // only lock cache on success
            }
            // on failure: leave _loaded = false so next call can retry
        } catch (err) {
            console.warn('[useBranches]', err?.message || err);
        }
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
