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
 * Fallback ids 1–8 align with `CampusController::listPublic` error payload / branches.json;
 * live API overwrites with real `Campus.id` values.
 */
/** IDs match the actual `Campus.id` values in the MySQL database. */
export const DEFAULT_BRANCHES = [
    { id: 17, name: '興隆分校', code: 'xinglong' },
    { id: 9,  name: '新店分校', code: 'xindian'  },
    { id: 15, name: '大安分校', code: 'daan'      },
    { id: 16, name: '木柵分校', code: 'muzha'     },
    { id: 2,  name: '東湖分校', code: 'donghu'    },
    { id: 3,  name: '大直分校', code: 'dazhi'     },
    { id: 4,  name: '汐止分校', code: 'xizhi'     },
    { id: 1,  name: '內湖分校', code: 'neihu'     },
    { id: 12, name: '石牌分校', code: 'shipai'    },
    { id: 22, name: '敦化分校', code: 'dunhua'    },
    { id: 7,  name: '蘆洲分校', code: 'luzhou'    },
    { id: 23, name: '大同分校', code: 'datong'    },
    // 以下 id 以線上 daan /api/v1/branches 為準，僅供離線後備；實際仍以 API 為主
    { id: 11, name: '新莊分校', code: 'xinzhuang' },
    { id: 24, name: '中平分校', code: 'zhongping' },
];
export const branches = ref([...DEFAULT_BRANCHES]);

/**
 * Old UI shipped 20 placeholder rows (ids 1–20) in branches.json / defaults.
 * `localStorage.app_branch` may still use those ids — map to name, then resolve to real campus id.
 */
const LEGACY_DEFAULT_ID_TO_NAME = {
    1: '內湖分校',
    2: '東湖分校',
    3: '大直分校',
    4: '汐止分校',
    5: '新店校區',
    6: '麗山校區',
    7: '蘆洲分校',
    8: '敦南分校',
    9: '新店分校',
    10: '桃園分校',
    11: '新莊分校',
    12: '石牌分校',
    13: '新莊中平分校',
    14: '三重分校',
    15: '大安分校',
    16: '木柵分校',
    17: '興隆分校',
    18: '新竹分校',
    19: '天母分校',
    20: '中壢分校',
};

/**
 * Pick a campus id from `localStorage.app_branch` (numeric id or code string)
 * against the current branch list; map legacy default ids by campus name.
 */
export function resolveSavedBranchChoice(savedRaw, list) {
    if (!list || list.length === 0) return null;
    const s = savedRaw != null ? String(savedRaw).trim() : '';
    if (!s) return list[0].id;

    const savedInt = parseInt(s, 10);
    if (Number.isFinite(savedInt)) {
        const byId = list.find((b) => b.id === savedInt);
        if (byId) return byId.id;
        const legacyName = LEGACY_DEFAULT_ID_TO_NAME[savedInt];
        if (legacyName) {
            const byName = list.find((br) => String(br.name).trim() === legacyName);
            if (byName) return byName.id;
        }
    }

    const byCode = list.find((b) => b.code === s);
    if (byCode) return byCode.id;

    return list[0].id;
}

/**
 * 正規化 API 回傳的分校列表，完全信任 API 內容。
 * DEFAULT_BRANCHES 僅在 API 回傳空陣列或失敗時作為離線後備，不做名稱白名單過濾。
 * 新增分校只需改後端 Campus table + CampusController::BRANCH_NAMES，前端自動反映。
 */
function mergeWithDefaults(list) {
    if (!list || list.length === 0) {
        return [...DEFAULT_BRANCHES];
    }
    const seen = new Set();
    const mapped = [];
    for (const item of list) {
        if (!item) continue;
        const name = String(item.name || '').trim();
        if (!name) continue;
        const id = Number(item.id);
        if (!Number.isFinite(id) || id <= 0) continue;
        if (seen.has(id)) continue;
        seen.add(id);
        mapped.push({ id, name, code: String(item.code || '').trim() });
    }
    return mapped.length > 0 ? mapped : [...DEFAULT_BRANCHES];
}

/** For pages that fetch `/api/v1/branches` directly (e.g. DirectorRegister). */
export function officialBranchesFromApi(list) {
    return mergeWithDefaults(Array.isArray(list) ? list : []);
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
