/**
 * Learning Record Draft Manager
 *
 * Persists teacher draft form data in localStorage so that partially-filled
 * evaluation forms survive page navigation and can be resumed later.
 *
 * Key format: lr_draft_v1_{teacherId}_{classSessionId}
 *   - For new records without classSessionId: lr_draft_v1_{teacherId}_new_{studentClassId}_{sessionDate}
 *
 * Each draft stores only the editable content fields (no identity / PII beyond
 * what is needed to display the draft list). The backend LearningRecord table
 * remains the single source of truth.
 */

const DRAFT_VERSION = 1;
const KEY_PREFIX = `lr_draft_v${DRAFT_VERSION}_`;
const EXPIRY_MS = 7 * 24 * 60 * 60 * 1000; // 7 days
const MAX_DRAFTS_PER_TEACHER = 50;

const CONTENT_FIELDS = [
  'HomeworkStatus',
  'QuizScore',
  'Progress',
  'NextHomework',
  'NextWeekTestScope',
  'Performance',
  'Comment',
];

function buildKey(teacherId, classSessionId, fallback = null) {
  if (classSessionId && Number(classSessionId) > 0) {
    return `${KEY_PREFIX}${teacherId}_${classSessionId}`;
  }
  if (fallback) {
    const { studentClassId, sessionDate } = fallback;
    return `${KEY_PREFIX}${teacherId}_new_${studentClassId || 0}_${sessionDate || ''}`;
  }
  return null;
}

function isExpired(draft) {
  if (!draft || !draft._savedAt) return true;
  return Date.now() - draft._savedAt > EXPIRY_MS;
}

function allDraftKeys() {
  const keys = [];
  for (let i = 0; i < localStorage.length; i++) {
    const k = localStorage.key(i);
    if (k && k.startsWith(KEY_PREFIX)) keys.push(k);
  }
  return keys;
}

function teacherDraftKeys(teacherId) {
  const prefix = `${KEY_PREFIX}${teacherId}_`;
  return allDraftKeys().filter(k => k.startsWith(prefix));
}

function parseDraft(raw) {
  try {
    const obj = JSON.parse(raw);
    if (!obj || typeof obj !== 'object') return null;
    if (obj._version !== DRAFT_VERSION) return null;
    return obj;
  } catch {
    return null;
  }
}

function hasContent(draft) {
  if (!draft) return false;
  return CONTENT_FIELDS.some(f => {
    const v = draft[f];
    if (v === undefined || v === null || v === '') return false;
    if (f === 'HomeworkStatus' && v === 'completed') return false;
    if (f === 'Performance' && v === 'good') return false;
    return true;
  });
}

/**
 * Save a draft to localStorage.
 * @param {object} opts
 * @param {number|string} opts.teacherId
 * @param {number|string} opts.classSessionId
 * @param {object} opts.form - reactive form object
 * @param {object} [opts.meta] - display metadata (studentName, subject, sessionDate, startTime, endTime)
 * @param {object} [opts.fallback] - { studentClassId, sessionDate } for new-record mode
 * @returns {{ saved: boolean, key: string|null, error: string|null }}
 */
export function saveDraft({ teacherId, classSessionId, form, meta = {}, fallback = null }) {
  const key = buildKey(teacherId, classSessionId, fallback);
  if (!key) return { saved: false, key: null, error: 'no_key' };

  const data = { _version: DRAFT_VERSION, _savedAt: Date.now() };
  for (const f of CONTENT_FIELDS) {
    data[f] = form[f] ?? '';
  }
  Object.assign(data, {
    _studentName: meta.studentName || '',
    _subject: meta.subject || '',
    _sessionDate: meta.sessionDate || '',
    _startTime: meta.startTime || '',
    _endTime: meta.endTime || '',
    _classSessionId: classSessionId || 0,
    _teacherId: teacherId,
  });

  if (!hasContent(data)) {
    try { localStorage.removeItem(key); } catch {}
    return { saved: false, key, error: null };
  }

  try {
    localStorage.setItem(key, JSON.stringify(data));
    return { saved: true, key, error: null };
  } catch (e) {
    return { saved: false, key, error: 'quota_exceeded' };
  }
}

/**
 * Load a draft from localStorage.
 * @returns {{ draft: object|null, key: string|null, expired: boolean }}
 */
export function loadDraft({ teacherId, classSessionId, fallback = null }) {
  const key = buildKey(teacherId, classSessionId, fallback);
  if (!key) return { draft: null, key: null, expired: false };

  try {
    const raw = localStorage.getItem(key);
    if (!raw) return { draft: null, key, expired: false };
    const draft = parseDraft(raw);
    if (!draft) {
      localStorage.removeItem(key);
      return { draft: null, key, expired: false };
    }
    if (isExpired(draft)) {
      localStorage.removeItem(key);
      return { draft: null, key, expired: true };
    }
    if (!hasContent(draft)) {
      localStorage.removeItem(key);
      return { draft: null, key, expired: false };
    }
    return { draft, key, expired: false };
  } catch {
    return { draft: null, key: null, expired: false };
  }
}

/**
 * Apply draft fields onto a reactive form object.
 * Only overwrites content fields, never identity fields.
 */
export function applyDraftToForm(draft, form) {
  if (!draft) return;
  for (const f of CONTENT_FIELDS) {
    if (draft[f] !== undefined && draft[f] !== null && draft[f] !== '') {
      form[f] = draft[f];
    }
  }
}

/**
 * Clear a specific draft.
 */
export function clearDraft({ teacherId, classSessionId, fallback = null }) {
  const key = buildKey(teacherId, classSessionId, fallback);
  if (!key) return;
  try { localStorage.removeItem(key); } catch {}
}

/**
 * Clear all drafts for a specific teacher (call on logout).
 */
export function clearAllDraftsByTeacher(teacherId) {
  const keys = teacherDraftKeys(teacherId);
  for (const k of keys) {
    try { localStorage.removeItem(k); } catch {}
  }
}

/**
 * List all non-expired drafts for a teacher, sorted by most recently saved first.
 * @returns {Array<{ key, studentName, subject, sessionDate, startTime, endTime, savedAt, classSessionId }>}
 */
export function listDrafts(teacherId) {
  const keys = teacherDraftKeys(teacherId);
  const results = [];

  for (const k of keys) {
    try {
      const raw = localStorage.getItem(k);
      if (!raw) continue;
      const draft = parseDraft(raw);
      if (!draft) { localStorage.removeItem(k); continue; }
      if (isExpired(draft)) { localStorage.removeItem(k); continue; }
      if (!hasContent(draft)) { localStorage.removeItem(k); continue; }
      results.push({
        key: k,
        studentName: draft._studentName || '未知學生',
        subject: draft._subject || '',
        sessionDate: draft._sessionDate || '',
        startTime: draft._startTime || '',
        endTime: draft._endTime || '',
        savedAt: draft._savedAt || 0,
        classSessionId: draft._classSessionId || 0,
        teacherId: draft._teacherId || teacherId,
      });
    } catch { /* skip corrupt entries */ }
  }

  results.sort((a, b) => b.savedAt - a.savedAt);
  return results;
}

/**
 * Remove a draft by its storage key.
 */
export function removeDraftByKey(key) {
  try { localStorage.removeItem(key); } catch {}
}

/**
 * Enforce max drafts limit for a teacher by removing oldest.
 */
export function pruneOldDrafts(teacherId) {
  const drafts = listDrafts(teacherId);
  if (drafts.length <= MAX_DRAFTS_PER_TEACHER) return;
  const toRemove = drafts.slice(MAX_DRAFTS_PER_TEACHER);
  for (const d of toRemove) {
    removeDraftByKey(d.key);
  }
}

/**
 * Clean up legacy draft keys from the old format (lr_draft_{userId}_{studentId}_{date}).
 */
export function migrateLegacyDrafts() {
  for (let i = localStorage.length - 1; i >= 0; i--) {
    const k = localStorage.key(i);
    if (k && k.startsWith('lr_draft_') && !k.startsWith(KEY_PREFIX)) {
      try { localStorage.removeItem(k); } catch {}
    }
  }
}

export const DRAFT_CONTENT_FIELDS = CONTENT_FIELDS;
