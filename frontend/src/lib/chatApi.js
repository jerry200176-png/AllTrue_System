const API = '/api/v1';

function getToken() {
  try {
    const raw = localStorage.getItem('alltrue_session');
    if (!raw) return null;
    const s = JSON.parse(raw);
    return s?.access_token ?? null;
  } catch { return null; }
}

function headers() {
  const h = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
  const t = getToken();
  if (t) h['Authorization'] = `Bearer ${t}`;
  return h;
}

// Headers without Content-Type (for multipart/form-data)
function headersNoContentType() {
  const h = { 'Accept': 'application/json' };
  const t = getToken();
  if (t) h['Authorization'] = `Bearer ${t}`;
  return h;
}

async function json(res) {
  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || `HTTP ${res.status}`);
  }
  return res.json();
}

// ── Thread list & creation ─────────────────────────────────────

export async function fetchThreads(branchId, perPage = 30) {
  const params = new URLSearchParams({ branch_id: branchId, per_page: perPage });
  const res = await fetch(`${API}/chat/threads?${params}`, { headers: headers() });
  return json(res);
}

export async function createDm(branchId, otherUserId) {
  const res = await fetch(`${API}/chat/threads/dm`, {
    method: 'POST',
    headers: headers(),
    body: JSON.stringify({ branch_id: branchId, other_user_id: otherUserId }),
  });
  return json(res);
}

export async function createGroup(branchId, name, memberIds) {
  const res = await fetch(`${API}/chat/threads/group`, {
    method: 'POST',
    headers: headers(),
    body: JSON.stringify({ branch_id: branchId, name, member_ids: memberIds }),
  });
  return json(res);
}

// ── Delete thread ──────────────────────────────────────────────

export async function deleteThread(threadId) {
  const res = await fetch(`${API}/chat/threads/${threadId}`, {
    method: 'DELETE',
    headers: headers(),
  });
  return json(res);
}

// ── Messages ───────────────────────────────────────────────────

export async function fetchMessages(threadId, beforeId = null, limit = 40) {
  const params = new URLSearchParams({ limit });
  if (beforeId) params.set('before_id', beforeId);
  const res = await fetch(`${API}/chat/threads/${threadId}/messages?${params}`, { headers: headers() });
  return json(res);
}

export async function sendMessage(threadId, body, replyToMessageId = null) {
  const payload = { body };
  if (replyToMessageId) payload.reply_to_message_id = replyToMessageId;
  const res = await fetch(`${API}/chat/threads/${threadId}/messages`, {
    method: 'POST',
    headers: headers(),
    body: JSON.stringify(payload),
  });
  return json(res);
}

export async function deleteMessage(messageId) {
  const res = await fetch(`${API}/chat/messages/${messageId}`, {
    method: 'DELETE',
    headers: headers(),
  });
  return json(res);
}

export async function uploadAttachment(threadId, file) {
  const formData = new FormData();
  formData.append('file', file);
  const res = await fetch(`${API}/chat/threads/${threadId}/attachments`, {
    method: 'POST',
    headers: headersNoContentType(),
    body: formData,
  });
  return json(res);
}

export async function markThreadRead(threadId, messageId = null) {
  const res = await fetch(`${API}/chat/threads/${threadId}/read`, {
    method: 'POST',
    headers: headers(),
    body: JSON.stringify(messageId ? { message_id: messageId } : {}),
  });
  return json(res);
}

// ── Unread ─────────────────────────────────────────────────────

export async function fetchChatUnreadCount(branchId) {
  const params = new URLSearchParams();
  if (branchId) params.set('branch_id', branchId);
  const res = await fetch(`${API}/chat/unread-count?${params}`, { headers: headers() });
  return json(res);
}

// ── Group management ───────────────────────────────────────────

export async function fetchThreadMembers(threadId) {
  const res = await fetch(`${API}/chat/threads/${threadId}/members`, { headers: headers() });
  return json(res);
}

export async function updateThreadName(threadId, name) {
  const res = await fetch(`${API}/chat/threads/${threadId}`, {
    method: 'PATCH',
    headers: headers(),
    body: JSON.stringify({ name }),
  });
  return json(res);
}

export async function addThreadMembers(threadId, userIds) {
  const res = await fetch(`${API}/chat/threads/${threadId}/members`, {
    method: 'POST',
    headers: headers(),
    body: JSON.stringify({ user_ids: userIds }),
  });
  return json(res);
}

export async function removeThreadMember(threadId, userId) {
  const res = await fetch(`${API}/chat/threads/${threadId}/members/${userId}`, {
    method: 'DELETE',
    headers: headers(),
  });
  return json(res);
}

export async function leaveThread(threadId) {
  const res = await fetch(`${API}/chat/threads/${threadId}/leave`, {
    method: 'POST',
    headers: headers(),
  });
  return json(res);
}

export async function transferOwner(threadId, newOwnerId) {
  const res = await fetch(`${API}/chat/threads/${threadId}/transfer-owner`, {
    method: 'POST',
    headers: headers(),
    body: JSON.stringify({ new_owner_id: newOwnerId }),
  });
  return json(res);
}

// ── Staff list (for new chat dialog) ──────────────────────────

export async function fetchStaffList(branchId) {
  const params = new URLSearchParams({ per_page: 200 });
  if (branchId) params.set('branch_id', branchId);
  const res = await fetch(`${API}/profiles?${params}`, { headers: headers() });
  return json(res);
}

export async function pinThread(threadId, pinned) {
  const res = await fetch(`${API}/chat/threads/${threadId}/pin`, {
    method: 'POST',
    headers: headers(),
    body: JSON.stringify({ pinned }),
  });
  return json(res);
}
