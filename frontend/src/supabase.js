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
            options = { method: 'GET', headers: { ...authHeaders } };
        } else if (this._method === 'POST') {
            url = `${API_BASE}/${this._table}`;
            options = {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', ...authHeaders },
                body: JSON.stringify(this._body)
            };
        } else if (this._method === 'PUT') {
            const id = this._updateId || this._filters.id;
            url = `${API_BASE}/${this._table}/${id}`;
            options = {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', ...authHeaders },
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
            options = { method: 'DELETE', headers: { ...authHeaders } };
        }

        const resp = await fetch(url, options);
        const text = await resp.text();
        let json = {};
        if (text && text.trim()) {
            try {
                json = JSON.parse(text);
            } catch (e) {
                json = { error: { message: '回應格式錯誤' } };
            }
        }
        if (!resp.ok && !json.error) {
            json.error = { message: json.message || `Request failed (${resp.status})` };
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

    async signInWithPassword({ email, password }) {
        const resp = await fetch(`${API_BASE}/auth/login`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, password })
        });
        const text = await resp.text();
        let result = { data: null, error: { message: '登入失敗' } };
        try {
            if (text.trim().startsWith('<') || text.trim().startsWith('<?')) {
                result.error = { message: 'API 回傳錯誤格式，請檢查伺服器設定（document root 應指向 backend/public）' };
            } else {
                result = JSON.parse(text);
            }
        } catch {
            result.error = { message: resp.status === 401 ? '帳號或密碼錯誤。若尚未建立管理員，請先訪問 /api/create-admin' : '伺服器回應異常' };
        }
        if (resp.status === 401 && result.error) {
            result.error.message = '帳號或密碼錯誤。若尚未建立管理員，請先訪問 /api/create-admin';
        }
        if (result.data?.session) {
            this._session = result.data.session;
            localStorage.setItem('alltrue_session', JSON.stringify(this._session));
            this._notify('SIGNED_IN', this._session);
        }
        return result;
    },

    async signUp({ email, password, options }) {
        const username = options?.data?.username || '';
        const resp = await fetch(`${API_BASE}/auth/register`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, password, username })
        });
        const text = await resp.text();
        let result = { data: null, error: { message: '註冊失敗' } };
        try {
            if (text.trim().startsWith('<') || text.trim().startsWith('<?')) {
                result.error = { message: 'API 回傳錯誤格式，請檢查伺服器設定' };
            } else {
                result = JSON.parse(text);
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
