/**
 * API client — drop-in replacement for Supabase client.
 * Uses the same .from().select().eq().insert() chain syntax
 * but sends requests to our Docker backend (Nginx + PHP-FPM) instead.
 *
 * In dev mode (Vite on port 5173), requests to /api are proxied to
 * the Docker backend by vite.config.js.
 * In production, /api resolves to the same origin (Nginx on port 80).
 */
const API_BASE = (import.meta.env.VITE_API_BASE || '/api') + '/v1';
const JSON_ACCEPT_HEADER = { Accept: 'application/json' };

const parseJsonOrNull = (raw) => {
    try {
        return JSON.parse(raw);
    } catch {
        return null;
    }
};

const extractBalancedJson = (text, start, openChar, closeChar) => {
    let depth = 0;
    let inString = false;
    let escaped = false;

    for (let i = start; i < text.length; i += 1) {
        const ch = text[i];

        if (inString) {
            if (escaped) {
                escaped = false;
            } else if (ch === '\\') {
                escaped = true;
            } else if (ch === '"') {
                inString = false;
            }
            continue;
        }

        if (ch === '"') {
            inString = true;
            continue;
        }

        if (ch === openChar) depth += 1;
        if (ch === closeChar) {
            depth -= 1;
            if (depth === 0) {
                return text.slice(start, i + 1);
            }
        }
    }

    return null;
};

const recoverJsonPayload = (rawText) => {
    const text = String(rawText || '').replace(/^\uFEFF/, '').trim();
    if (!text) return {};

    const parsedDirect = parseJsonOrNull(text);
    if (parsedDirect !== null) return parsedDirect;

    // Recover from PHP notices/warnings printed before/after JSON body.
    const candidates = [];
    const objStart = text.indexOf('{');
    if (objStart !== -1) {
        const objChunk = extractBalancedJson(text, objStart, '{', '}');
        if (objChunk) candidates.push(objChunk);
    }
    const arrStart = text.indexOf('[');
    if (arrStart !== -1) {
        const arrChunk = extractBalancedJson(text, arrStart, '[', ']');
        if (arrChunk) candidates.push(arrChunk);
    }

    for (const chunk of candidates) {
        const recovered = parseJsonOrNull(chunk);
        if (recovered !== null) {
            return recovered;
        }
    }

    return null;
};

const flattenValidationErrors = (errors) => {
    if (!errors || typeof errors !== 'object') return '';
    return Object.values(errors)
        .flatMap((value) => (Array.isArray(value) ? value : [value]))
        .map((value) => String(value || '').trim())
        .filter(Boolean)
        .join(' ');
};

const isGenericValidationMessage = (message) => {
    const text = String(message || '').trim();
    return text === 'The given data was invalid.' || text === 'The given data was invalid';
};

const forceSignOut = (message = '登入已過期，請重新登入') => {
    try {
        auth._session = null;
        localStorage.removeItem('alltrue_session');
        auth._notify('SIGNED_OUT', null);
    } catch {
        // ignore sign-out side effect errors
    }
    return { data: null, error: { message } };
};

// Query builder that mimics supabase.from('table').select(...).eq(...) etc.
class QueryBuilder {
    constructor(table) {
        this._table = table;
        this._filters = {};
        this._method = 'GET';
        this._body = null;
        this._single = false;
        this._order = '';
        this._updateId = null;
        this._deleteId = null;
    }

    select(_columns) {
        this._method = 'GET';
        return this;
    }

    eq(field, value) {
        if ((this._method === 'PUT' || this._method === 'DELETE') && field === 'id') {
            if (this._method === 'PUT') this._updateId = value;
            if (this._method === 'DELETE') this._deleteId = value;
        } else {
            this._filters[field] = value;
        }
        return this;
    }

    neq(field, value) {
        this._filters[`${field}__neq`] = value;
        return this;
    }

    ilike(field, value) {
        this._filters[`${field}__ilike`] = value;
        return this;
    }

    gte(field, value) {
        this._filters[`${field}__gte`] = value;
        return this;
    }

    lte(field, value) {
        this._filters[`${field}__lte`] = value;
        return this;
    }

    // .is(field, null) → filter for NULL values
    is(field, value) {
        if (value === null) {
            this._filters[`${field}__is`] = 'null';
        } else {
            this._filters[field] = value;
        }
        return this;
    }

    // .in(field, [values]) → filter where field IN list
    in(field, values) {
        this._filters[`${field}__in`] = Array.isArray(values) ? values.join(',') : values;
        return this;
    }

    // .or(expr) → pass-through as a raw filter param
    or(expr) {
        this._filters['__or'] = expr;
        return this;
    }

    // .not(field, op, value) → negation filter
    not(field, op, value) {
        this._filters[`${field}__not_${op}`] = value;
        return this;
    }

    // .limit(n) → restrict number of results
    limit(n) {
        this._filters['__limit'] = n;
        return this;
    }

    order(field) {
        this._order = field;
        return this;
    }

    single() {
        this._single = true;
        return this;
    }

    insert(data) {
        this._method = 'POST';
        this._body = Array.isArray(data) ? data : [data];
        return this;
    }

    update(data) {
        this._method = 'PUT';
        this._body = data;
        return this;
    }

    delete() {
        this._method = 'DELETE';
        return this;
    }

    async then(resolve, reject) {
        try {
            const result = await this._execute();
            resolve(result);
        } catch (err) {
            if (reject) reject(err);
            else resolve({ data: null, error: { message: err.message } });
        }
    }

    async _execute() {
        let url, options;

        // Read session token from localStorage and include in all requests
        const session = JSON.parse(localStorage.getItem('alltrue_session') || 'null');
        const token = session?.access_token;
        const authHeaders = token ? { 'Authorization': `Bearer ${token}` } : {};

        if (this._method === 'GET') {
            const params = new URLSearchParams(this._filters);
            if (this._single) params.set('single', 'true');
            if (this._order) params.set('order', this._order);
            url = `${API_BASE}/${this._table}?${params}`;
            options = { method: 'GET', headers: { ...JSON_ACCEPT_HEADER, ...authHeaders } };
        } else if (this._method === 'POST') {
            url = `${API_BASE}/${this._table}`;
            options = {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', ...JSON_ACCEPT_HEADER, ...authHeaders },
                body: JSON.stringify(this._body)
            };
        } else if (this._method === 'PUT') {
            const id = this._updateId || this._filters.id;
            url = `${API_BASE}/${this._table}/${id}`;
            options = {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', ...JSON_ACCEPT_HEADER, ...authHeaders },
                body: JSON.stringify(this._body)
            };
        } else if (this._method === 'DELETE') {
            const id = this._deleteId || this._filters.id;
            if (id) {
                // Delete by id
                url = `${API_BASE}/${this._table}/${id}`;
            } else {
                // Delete by filters (e.g. .delete().eq('teacher_id', xxx))
                const params = new URLSearchParams(this._filters);
                url = `${API_BASE}/${this._table}?${params}`;
            }
            options = { method: 'DELETE', headers: { ...JSON_ACCEPT_HEADER, ...authHeaders } };
        }

        const resp = await fetch(url, options);
        const text = await resp.text();
        const trimmed = String(text || '').replace(/^\uFEFF/, '').trim();
        let json = {};
        if (trimmed) {
            const recovered = recoverJsonPayload(trimmed);
            if (recovered !== null) {
                json = recovered;
            } else if (trimmed.startsWith('<')) {
                // HTML usually means auth/session/server proxy issue, do not treat as success.
                json = {
                    error: {
                        message: (resp.status === 401 || resp.status === 419)
                            ? '登入已過期，請重新登入'
                            : 'API 回傳非 JSON 內容，請重新登入後再試'
                    }
                };
            } else if (!resp.ok) {
                json = { error: { message: '回應格式錯誤' } };
            } else {
                // For successful writes with plain-text bodies, avoid false-negative errors.
                json = { message: trimmed };
            }
        }
        if (!resp.ok && !json.error) {
            json.error = { message: json.message || `Request failed (${resp.status})` };
        }

        const validationMessage = flattenValidationErrors(json?.errors);
        if (validationMessage) {
            const currentMessage = json?.error?.message || json?.message || '';
            if (!json.error) json.error = {};
            if (!currentMessage || isGenericValidationMessage(currentMessage)) {
                json.error.message = validationMessage;
            } else if (!currentMessage.includes(validationMessage)) {
                json.error.message = `${currentMessage} ${validationMessage}`.trim();
            }
        }

        if ((resp.status === 401 || resp.status === 419) && token) {
            return forceSignOut(json?.error?.message || '登入已過期，請重新登入');
        }

        return json;
    }
}

// Auth module
const auth = {
    _session: JSON.parse(localStorage.getItem('alltrue_session') || 'null'),
    _listeners: [],

    async getSession() {
        return { data: { session: this._session }, error: null };
    },

    async signInWithPassword({ account, email, password, role = null }) {
        const loginAccount = (account ?? email ?? '').trim();
        const resp = await fetch(`${API_BASE}/auth/login`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', ...JSON_ACCEPT_HEADER },
            body: JSON.stringify({
                account: loginAccount,
                password,
                ...(role ? { role } : {})
            })
        });
        const text = await resp.text();
        let json = {};
        let result = { data: null, error: { message: '登入失敗' } };
        try {
            if (text.trim().startsWith('<') || text.trim().startsWith('<?')) {
                result.error = { message: 'API 回傳錯誤格式，請檢查伺服器設定（document root 應指向 backend/public）' };
            } else {
                json = text ? JSON.parse(text) : {};
                result = json;
            }
        } catch {
            result.error = { message: resp.status === 401 ? '帳號或密碼錯誤' : '伺服器回應異常' };
        }

        if (!resp.ok) {
            const apiMessage = json?.message || result?.error?.message;
            if (resp.status === 429) {
                const retryAfter = Number(json?.retry_after_seconds ?? 0);
                result = {
                    data: null,
                    error: {
                        code: 'LOCKED',
                        message: apiMessage || '登入錯誤次數過多，請稍後再試',
                        retry_after_seconds: Number.isFinite(retryAfter) ? Math.max(0, Math.ceil(retryAfter)) : 0,
                        locked_until: json?.locked_until || null
                    }
                };
            } else if (resp.status === 401) {
                result = {
                    data: null,
                    error: {
                        message: '帳號或密碼錯誤'
                    }
                };
            } else {
                result = {
                    data: null,
                    error: {
                        message: apiMessage || `登入失敗 (${resp.status})`
                    }
                };
            }
        }

        if (result.data?.session) {
            this._session = result.data.session;
            localStorage.setItem('alltrue_session', JSON.stringify(this._session));
            this._notify('SIGNED_IN', this._session);
        }
        return result;
    },

    async forgotPasswordRequest({ account, role = null, note = '' }) {
        const resp = await fetch(`${API_BASE}/auth/forgot-password`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                account,
                ...(role ? { role } : {}),
                ...(note ? { note } : {})
            })
        });

        const text = await resp.text();
        let json = {};

        try {
            if (text.trim().startsWith('<') || text.trim().startsWith('<?')) {
                return { data: null, error: { message: 'API 回傳錯誤格式，請檢查伺服器設定' } };
            }
            json = text ? JSON.parse(text) : {};
        } catch {
            return { data: null, error: { message: '伺服器回應異常' } };
        }

        if (!resp.ok) {
            return {
                data: null,
                error: {
                    message: json?.message || `送出失敗 (${resp.status})`
                }
            };
        }

        return { data: json, error: null };
    },

    async signUp({ account, email, password, options }) {
        const name = options?.data?.name || options?.data?.username || '';
        const loginAccount = (account ?? email ?? '').trim();
        const role = options?.data?.role || 'teacher';
        const branchId = options?.data?.branch_id ?? null;
        const phone = options?.data?.phone ?? '';
        const multiBranches = options?.data?.multi_branches ?? [];
        const subjectIds = options?.data?.subject_ids ?? [];
        const subjectLevelScopes = options?.data?.subject_level_scopes ?? [];
        const resp = await fetch(`${API_BASE}/auth/register`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                account: loginAccount,
                password,
                name,
                role,
                branch_id: branchId,
                phone,
                multi_branches: multiBranches,
                subject_ids: subjectIds,
                subject_level_scopes: subjectLevelScopes
            })
        });
        const text = await resp.text();
        let result = { data: null, error: null };
        try {
            if (text.trim().startsWith('<') || text.trim().startsWith('<?')) {
                result.error = { message: 'API 回傳錯誤格式，請檢查伺服器設定' };
            } else {
                const json = text ? JSON.parse(text) : {};
                if (!resp.ok) {
                    const validationErrors = json?.errors
                        ? Object.values(json.errors).flat().join(' ')
                        : '';
                    result.error = {
                        message: validationErrors || json?.message || `註冊失敗 (${resp.status})`
                    };
                } else {
                    const rawData = json?.data && typeof json.data === 'object' ? json.data : {};
                    result.data = {
                        ...json,
                        ...rawData,
                        user: rawData.user || (json?.user_id ? { id: json.user_id, account: loginAccount, email: loginAccount, name } : null),
                        message: rawData.message || json?.message || '註冊成功',
                    };
                }
            }
        } catch {
            result.error = { message: '伺服器回應異常' };
        }
        if (result.data?.session) {
            this._session = result.data.session;
            localStorage.setItem('alltrue_session', JSON.stringify(this._session));
            this._notify('SIGNED_IN', this._session);
        }
        return result;
    },

    async signOut() {
        this._session = null;
        localStorage.removeItem('alltrue_session');
        this._notify('SIGNED_OUT', null);
        return { error: null };
    },

    onAuthStateChange(callback) {
        this._listeners.push(callback);
        // Return unsubscribe
        return { data: { subscription: { unsubscribe: () => { } } } };
    },

    _notify(event, session) {
        this._listeners.forEach(cb => cb(event, session));
    }
};

// Main export — same interface as createClient result
export const supabase = {
    from: (table) => new QueryBuilder(table),
    auth
};
