import { supabase } from './supabase';

const API_BASE = (import.meta.env.VITE_API_BASE || '/api') + '/v1';

// --- Parent Portal (使用後端 /api/v1/parent/*) ---
export async function parentLogin(credentials) {
  const payload = { Phone: String(credentials.Phone || '').trim() };
  const sid = Number(credentials.StudentID);
  const name = String(credentials.Name || '').trim();
  if (sid > 0) payload.StudentID = sid;
  if (name) payload.Name = name;
  const res = await fetch(`${API_BASE}/parent/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data?.message || '登入失敗');
  return { token: data.token, student: data.student, students: data.students || null };
}

export async function getParentDashboard(token, { lrPage = 1, lrPerPage = 10 } = {}) {
  const params = new URLSearchParams({ lr_page: lrPage, lr_per_page: lrPerPage });
  const res = await fetch(`${API_BASE}/parent/dashboard?${params}`, {
    headers: { Authorization: `Bearer ${token}` },
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data?.message || '無法取得資料');
  return data;
}

export async function parentLoginLine(lineUserId, campusId = null) {
  const payload = { line_user_id: lineUserId };
  if (campusId) payload.campus_id = Number(campusId);
  const res = await fetch(`${API_BASE}/parent/login-line`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data?.message || 'LINE 登入失敗');
  return { token: data.token, student: data.student, students: data.students || null };
}

export async function parentSwitchStudent(token, studentId) {
  const res = await fetch(`${API_BASE}/parent/switch-student`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
    body: JSON.stringify({ student_id: studentId }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data?.message || '切換學生失敗');
  return data;
}

export async function parentRequestLeave(token, sessionId) {
  const res = await fetch(`${API_BASE}/parent/sessions/${sessionId}/leave`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data?.message || '請假申請失敗');
  return data;
}

export async function upsertParentLearningRecordFeedback(token, learningRecordId, content) {
  const res = await fetch(`${API_BASE}/parent/learning-records/${learningRecordId}/feedback`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
    body: JSON.stringify({ content }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data?.message || '回饋送出失敗');
  return data.feedback;
}

export async function getPaymentMessage(token, studentId) {
  const res = await fetch(`${API_BASE}/parent/payment-message/${studentId}`, {
    headers: { Authorization: `Bearer ${token}` },
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data?.message || '無法取得繳費訊息');
  return data;
}

// --- Students ---
export async function getStudents(filters = {}) {
  let query = supabase.from('students').select('*');
  if (filters.name) query = query.ilike('name', `%${filters.name}%`);
  // Add other filters as needed
  const { data, error } = await query;
  if (error) throw error;
  return data;
}

export async function createStudent(student) {
  const { data, error } = await supabase.from('students').insert([{
    name: student.name,
    phone: student.Phone, // Mapped from frontend 'Phone' to DB 'phone' if needed, but schema uses lowercase usually. Schema says 'phone'.
    remaining_lessons: 0,
    smart_rates: {}
  }]).select().single();

  if (error) throw error;
  return data;
}

export async function updateStudent(id, updates) {
  const { data, error } = await supabase.from('students').update(updates).eq('id', id).select();
  if (error) throw error;
  return data;
}

// --- Classes (Schedules) ---
export async function getStudentClasses(filters = {}) {
  let query = supabase.from('schedules').select('*');
  if (filters.student_id) query = query.eq('student_id', filters.student_id);
  if (filters.teacher_id) query = query.eq('teacher_id', filters.teacher_id);

  const { data, error } = await query;
  if (error) throw error;
  return data;
}

export async function createStudentClass(classData) {
  // Logic to determine rate from smart memory would go here or in a separate function before calling this.
  const { data, error } = await supabase.from('schedules').insert([{
    student_id: classData.StudentID,
    course_id: classData.SubjectID, // Assuming mapping
    teacher_id: classData.TeacherID,
    start_time: classData.StartDate, // Needs proper formatting
    end_time: classData.EndDate,
    class_type: classData.ClassType === 'regular' ? '1v1' : '1v2', // Simple mapping
    rate: 0, // Should be fetched
    status: 'scheduled'
  }]).select().single();

  if (error) throw error;
  return data;
}

// --- Attendance ---
async function getAuthToken() {
  const { data: { session } } = await supabase.auth.getSession();
  return session?.access_token;
}

export async function getAttendance(filters = {}) {
  const token = await getAuthToken();
  const params = new URLSearchParams(filters).toString();
  const response = await fetch(`/api/v1/attendance?${params}`, {
    headers: {
      'Accept': 'application/json',
      'Authorization': `Bearer ${token}`
    }
  });
  if (!response.ok) throw new Error('Failed to fetch attendance');
  return await response.json();
}

export async function createAttendance(form) {
  const token = await getAuthToken();
  const response = await fetch('/api/v1/attendance', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'Authorization': `Bearer ${token}`
    },
    body: JSON.stringify(form)
  });
  if (!response.ok) throw new Error('Failed to create attendance');
  return await response.json();
}

// --- Learning Records ---
export async function getLearningRecords(filters = {}) {
  let query = supabase.from('evaluations').select('*, schedules(*)');
  if (filters.teacher_id) {
    // This is complex in Supabase syntax for nested filtering, usually done by filtered join.
    // query = query.eq('schedules.teacher_id', filters.teacher_id);
  }
  const { data, error } = await query;
  return data || [];
}

export async function createLearningRecord(form) {
  const { data, error } = await supabase.from('evaluations').insert([{
    schedule_id: form.ClassSessionID, // Assuming mapping
    content: form.Content,
    status: 'pending'
  }]).select().single();
  if (error) throw error;
  return data;
}

export async function updateLearningRecord(id, updates) {
  const { data, error } = await supabase.from('evaluations').update(updates).eq('id', id).select();
  if (error) throw error;
  return data;
}

export async function approveLearningRecord(id, context) {
  // This should ideally be a server-side function (RPC) to handle transaction (deduction).
  // For now, checking client-side.

  // 1. Update evaluation status
  const { error: evalError } = await supabase.from('evaluations').update({ status: 'approved', approved_at: new Date() }).eq('id', id);
  if (evalError) throw evalError;

  // 2. Deduct lesson (Tricky to do client side securely, but for prototype:)
  // Need to fetch schedule to get student_id
  // Then update student remaining_lessons
  return { success: true };
}

export function requestLearningRecordChanges(id, data = {}) {
  // Implementation for requesting changes
  return { success: true };
}

export function rejectLearningRecord(id, data = {}) {
  // Implementation for rejecting record
  return { success: true };
}


// --- Finance ---
export async function getInvoices(filters = {}) {
  const token = await getAuthToken();
  if (!token) {
    return { data: [], error: '請先登入' };
  }
  const params = new URLSearchParams();
  const sid = filters.student_id;
  if (sid != null && sid !== '' && Number.isFinite(Number(sid))) {
    params.set('student_id', String(Number(sid)));
  }
  if (filters.status) params.set('status', filters.status);
  if (filters.branch_id != null && filters.branch_id !== '') {
    params.set('branch_id', String(Number(filters.branch_id)));
  }
  const response = await fetch(`/api/v1/invoices?${params}`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  });
  let json = {};
  try {
    json = await response.json();
  } catch {
    json = {};
  }
  if (!response.ok) {
    return {
      data: [],
      error: json.message || json.error || `載入失敗（${response.status}）`,
    };
  }
  const rows = Array.isArray(json.data) ? json.data : (Array.isArray(json) ? json : []);
  return { data: rows, error: null, meta: json };
}

export async function createInvoice(data) {
  return { id: 'mock-invoice' };
}

export async function getTeacherPayroll(params) {
  return { teachers: [] };
}

// --- Alerts ---
export async function getTuitionAlerts() {
  return { low_balance: [], monthly_renewal: [] };
}

// --- Misc ---
export async function getLatestUnknownRfid() {
  return null;
}

export async function uploadExcel(type, file) {
  console.log('Upload not supported in Supabase frontend-only mode yet');
  return { success: false, message: 'Not implemented' };
}

export async function downloadExport(type) {
  console.log('Export not supported');
}

export async function getPendingSwipes() {
  const token = await getAuthToken();
  const response = await fetch('/api/v1/pending-swipes', {
    headers: {
      'Accept': 'application/json',
      'Authorization': `Bearer ${token}`
    }
  });
  if (!response.ok) throw new Error('Failed to fetch pending swipes');
  return await response.json();
}

export async function assignPendingSwipeStudent(id, studentId) {
  const token = await getAuthToken();
  const response = await fetch(`/api/v1/pending-swipes/${id}/assign-student`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'Authorization': `Bearer ${token}`
    },
    body: JSON.stringify({ StudentID: studentId })
  });
  if (!response.ok) throw new Error('Failed to assign student');
  return await response.json();
}

export async function matchPendingSwipe(id, classSessionId) {
  const token = await getAuthToken();
  const response = await fetch(`/api/v1/pending-swipes/${id}/match`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'Authorization': `Bearer ${token}`
    },
    body: JSON.stringify({ ClassSessionID: classSessionId })
  });
  if (!response.ok) throw new Error('Failed to match swipe');
  return await response.json();
}

export async function dismissPendingSwipe(id) {
  const token = await getAuthToken();
  const response = await fetch(`/api/v1/pending-swipes/${id}`, {
    method: 'DELETE',
    headers: {
      'Accept': 'application/json',
      'Authorization': `Bearer ${token}`
    }
  });
  if (!response.ok) throw new Error('Failed to dismiss swipe');
  return await response.json();
}

export async function getApiClients() {
  return [];
}

export async function createApiClient(data) {
  return { id: 'mock-key', api_key: 'mock-key-123' };
}

export async function revokeApiClient(id) {
  return { success: true };
}

export async function getCampuses() {
  return [];
}
