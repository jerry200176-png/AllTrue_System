<template>
  <div class="lr-page">
    <HelpGuide
      title="學習評量表 — 使用說明"
      :items="[
        '「+ 新增評量」：選擇學生、老師、日期等，填寫學習內容與評量。',
        '主任可篩選學生、老師、班級、審核狀態；老師僅能看自己的評量。',
        '待審核評量可「核准」「退回」或「請老師修改」；核准後自動累計老師科目數。'
      ]"
      tip="學習評量表核准後，老師的科目數會自動累計。堂數扣除改由刷卡點名觸發。"
    />

    <!-- Page Header -->
    <div class="page-header lr-header">
      <div>
        <h2>{{ isTeacher ? '我的課表 & 評量' : '學習評量表' }}</h2>
        <p class="page-desc">{{ isTeacher ? '查看本週課表，填寫學習評量' : '查看、新增與審核學生每堂課的學習評量' }}</p>
      </div>
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button v-if="!isTeacher" class="ghost" @click="openBulkBackfill">一鍵補登</button>
        <button class="primary" @click="openModal()">+ 新增評量</button>
      </div>
    </div>

    <!-- ===== TEACHER: Week Schedule Widget ===== -->
    <div v-if="isTeacher" class="teacher-schedule card">
      <div class="ts-header">
        <h3>📅 課表</h3>
        <div class="ts-tabs">
          <button :class="{ active: scheduleView === 'today' }" @click="scheduleView = 'today'">今日</button>
          <button :class="{ active: scheduleView === 'week' }" @click="scheduleView = 'week'">本週</button>
        </div>
        <div class="ts-nav" v-if="scheduleView === 'week'">
          <button class="icon-btn" @click="weekOffset--">‹</button>
          <span class="ts-week-label">{{ weekLabel }}</span>
          <button class="icon-btn" @click="weekOffset++">›</button>
        </div>
      </div>

      <!-- Today view -->
      <div v-if="scheduleView === 'today'" class="ts-today">
        <div v-if="todayEvents.length === 0" class="ts-empty">今日無排課</div>
        <div
          v-for="ev in todayEvents"
          :key="ev.key"
          class="ts-event"
        >
          <div class="ts-time">{{ ev.time }}</div>
          <div class="ts-info">
            <div class="ts-student">{{ ev.studentName }}</div>
            <div class="ts-subject">{{ ev.subject }}</div>
          </div>
          <button class="ts-fill-btn" @click="openFromSchedule(ev)">填評量</button>
        </div>
      </div>

      <!-- Week view -->
      <div v-if="scheduleView === 'week'" class="ts-week">
        <div
          v-for="day in weekDays"
          :key="day.date"
          :class="['ts-day', { today: day.isToday, 'has-events': day.events.length > 0 }]"
        >
          <div class="ts-day-header">
            <span class="ts-day-name">{{ day.label }}</span>
            <span class="ts-day-date">{{ day.shortDate }}</span>
          </div>
          <div v-if="day.events.length === 0" class="ts-day-empty">—</div>
          <div
            v-for="ev in day.events"
            :key="ev.key"
            class="ts-event ts-event-sm"
            @click="openFromSchedule(ev)"
          >
            <div class="ts-time">{{ ev.time }}</div>
            <div class="ts-info">
              <div class="ts-student">{{ ev.studentName }}</div>
              <div class="ts-subject">{{ ev.subject }}</div>
            </div>
            <span class="ts-fill-hint">填評量</span>
          </div>
        </div>
      </div>
    </div>

    <!-- ===== 一鍵補登 Modal ===== -->
    <div v-if="showBulkModal" class="modal-overlay" @click.self="showBulkModal = false">
      <div class="lr-modal" style="max-width: 600px;">
        <div class="lr-modal-header">
          <h3>一鍵補登</h3>
          <button class="ghost icon" @click="showBulkModal = false">✕</button>
        </div>
        <div class="lr-form">
          <p style="color:#666; font-size:13px; margin-bottom:12px;">選擇課程，系統自動列出今日以前的應上課日期，選取後批量建立並核准評量記錄。</p>

          <div class="lr-form-grid" style="grid-template-columns:1fr 1fr; gap:12px;">
            <div class="form-group">
              <label>學生</label>
              <SearchableSelect v-model="bulkForm.studentId" :options="studentOptions" placeholder="選擇學生..." />
            </div>
            <div class="form-group">
              <label>課程</label>
              <select v-model="bulkForm.courseId" @change="loadBulkCourseDates">
                <option value="">-- 選擇課程 --</option>
                <option v-for="c in bulkCourseOptions" :key="c.id" :value="c.id">
                  {{ c.subject }} {{ c.days_label }} {{ c.start_time }}
                </option>
              </select>
            </div>
          </div>

          <div v-if="bulkDatesLoading" style="padding:8px; color:#888;">計算上課日期中…</div>

          <div v-if="bulkDateList.length > 0 && !bulkDatesLoading" style="margin-top:12px;">
            <div style="font-size:13px; font-weight:600; margin-bottom:8px;">
              應上課日期（今日前共 {{ bulkDateList.length }} 堂，已勾選 {{ bulkSelectedDates.length }} 堂）
              <button class="ghost" style="margin-left:8px; padding:2px 10px; font-size:12px;" @click="toggleSelectAllDates">
                {{ bulkSelectedDates.length === bulkDateList.length ? '取消全選' : '全選' }}
              </button>
            </div>
            <div class="bulk-date-grid">
              <label v-for="d in bulkDateList" :key="d" :class="['bulk-date-item', { selected: bulkSelectedDates.includes(d), existing: bulkExistingDates.includes(d) }]">
                <input type="checkbox" :value="d" v-model="bulkSelectedDates" style="position:absolute;opacity:0;width:0;" />
                <span>{{ d }}</span>
                <span v-if="bulkExistingDates.includes(d)" style="font-size:10px; color:#2e7d32;">✓已有</span>
              </label>
            </div>
          </div>

          <div class="lr-form-actions" style="margin-top:16px;">
            <button class="ghost" @click="showBulkModal = false">取消</button>
            <button class="primary" :disabled="bulkSelectedDates.length === 0 || bulkSubmitting" @click="submitBulkBackfill">
              {{ bulkSubmitting ? '補登中…' : `確認補登 ${bulkSelectedDates.length} 堂` }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="card lr-filters">
      <div class="lr-filters-grid">
        <div class="form-group">
          <label>搜尋學生</label>
          <input v-model="filters.student_name" type="text" placeholder="輸入學生姓名...">
        </div>
        <div v-if="!isTeacher" class="form-group">
          <label>篩選老師</label>
          <SearchableSelect
            v-model="filters.teacher_id"
            :options="teacherOptions"
            placeholder="選擇老師..."
          />
        </div>
        <div class="form-group">
          <label>審核狀態</label>
          <select v-model="filters.status">
            <option value="">全部</option>
            <option value="pending">待審核</option>
            <option value="approved">已核准</option>
            <option value="rejected">已退回</option>
            <option value="changes_requested">需修改</option>
          </select>
        </div>
        <div class="form-group lr-filter-btn-wrap">
          <label>&nbsp;</label>
          <button class="ghost" @click="fetchRecords">搜尋</button>
        </div>
      </div>
    </div>

    <!-- ===== Records Table ===== -->
    <div class="card lr-table-card">
      <div class="lr-table-scroll">
        <table>
          <thead>
            <tr>
              <th>日期</th>
              <th>學生 / 班級</th>
              <th>科目</th>
              <th v-if="!isTeacher">授課老師</th>
              <th>狀態</th>
              <th style="text-align:right">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="record in records" :key="record.id" class="lr-table-row" @click="viewRecord(record)">
              <td>
                <span class="lr-date">{{ record.SessionDate }}</span>
                <span class="lr-time">{{ record.StartTime }}</span>
              </td>
              <td>
                <div class="lr-student-name">{{ record.student_name }}</div>
                <div class="lr-class-label">{{ record.student_class_label || record.Subject }}</div>
              </td>
              <td>
                <span class="tag">{{ record.Subject }}</span>
              </td>
              <td v-if="!isTeacher">{{ record.teacher_name }}</td>
              <td>
                <span :class="statusTagClass(record.Status)" class="status-tag">
                  {{ statusLabel(record.Status) }}
                </span>
              </td>
              <td class="lr-actions" @click.stop>
                <button class="ghost xs" @click="viewRecord(record)">檢視</button>
                <button v-if="canEdit(record)" class="ghost xs" @click="editRecord(record)">編輯</button>
                <button v-if="canApprove(record)" class="primary xs" @click="approveRecord(record)">核准</button>
                <button v-if="canApprove(record)" class="danger xs" @click="rejectRecord(record)">退回</button>
                <button v-if="canDelete(record)" class="danger xs" @click="deleteRecord(record)">刪除</button>
              </td>
            </tr>
            <tr v-if="records.length === 0">
              <td :colspan="isTeacher ? 5 : 6" class="empty-text">尚無評量資料</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ======== Modal Form ======== -->
    <div v-if="showModal" class="modal-overlay" @click.self="closeModal">
      <div class="modal lr-modal">
        <!-- Modal Header -->
        <div class="lr-modal-header">
          <h3>{{ isEditing ? (isReadOnly ? '檢視評量' : '編輯評量') : '新增學習評量' }}</h3>
          <button class="lr-modal-close" @click="closeModal">&times;</button>
        </div>

        <form @submit.prevent="submitForm" class="lr-form">
          <!-- Section 1: 基本資訊 -->
          <div class="lr-form-section">
            <div class="lr-form-section-title">基本資訊</div>
            <div class="lr-form-grid">
              <div class="form-group">
                <label>選擇學生 <span class="lr-required">*</span></label>
                <SearchableSelect
                  v-model="form.StudentID"
                  :options="studentOptions"
                  :disabled="isReadOnly || isEditing"
                  placeholder="搜尋並選擇學生..."
                />
              </div>
              <div class="form-group" v-if="!isTeacher">
                <label>授課老師 <span class="lr-required">*</span></label>
                <SearchableSelect
                  v-model="form.TeacherID"
                  :options="teacherOptions"
                  :disabled="isReadOnly"
                  placeholder="搜尋並選擇老師..."
                />
              </div>
              <div class="form-group">
                <label>授課科目 <span class="lr-required">*</span></label>
                <select v-model="form.Subject" :disabled="isReadOnly">
                  <option value="">請選擇科目</option>
                  <option v-for="s in subjectList" :key="s" :value="s">{{ s }}</option>
                </select>
              </div>
              <div class="form-group">
                <label>上課日期 <span class="lr-required">*</span></label>
                <input v-model="form.SessionDate" type="date" :disabled="isReadOnly">
              </div>
              <div class="form-group">
                <label>開始時間</label>
                <select v-model="form.StartTime" @change="onStartTimeChange" :disabled="isReadOnly">
                  <option v-for="t in timeOptions" :key="t" :value="t">{{ t }}</option>
                </select>
              </div>
              <div class="form-group">
                <label>結束時間</label>
                <select v-model="form.EndTime" :disabled="isReadOnly">
                  <option v-for="t in timeOptions" :key="t" :value="t">{{ t }}</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Section 2: 作業與進度 -->
          <div class="lr-form-section">
            <div class="lr-form-section-title">作業與進度</div>
            <div class="form-group">
              <label>上次作業</label>
              <div class="lr-radio-group">
                <label class="lr-radio"><input v-model="form.HomeworkStatus" type="radio" value="completed" :disabled="isReadOnly"> 已完成</label>
                <label class="lr-radio"><input v-model="form.HomeworkStatus" type="radio" value="partial" :disabled="isReadOnly"> 部分完成</label>
                <label class="lr-radio"><input v-model="form.HomeworkStatus" type="radio" value="incomplete" :disabled="isReadOnly"> 未完成</label>
                <label class="lr-radio"><input v-model="form.HomeworkStatus" type="radio" value="missing" :disabled="isReadOnly"> 未攜帶</label>
              </div>
            </div>
            <div class="form-group">
              <label>周考成績</label>
              <input v-model="form.QuizScore" type="number" min="0" max="100" step="1" :disabled="isReadOnly" placeholder="填寫分數（0–100）">
            </div>
            <div class="form-group">
              <label>授課進度</label>
              <textarea v-model="form.Progress" rows="3" :disabled="isReadOnly" placeholder="紀錄本次上課內容..."></textarea>
            </div>
            <div class="form-group">
              <label>下次作業範圍</label>
              <textarea v-model="form.NextHomework" rows="2" :disabled="isReadOnly" placeholder="指定下次作業..."></textarea>
            </div>
          </div>

          <!-- Section 3: 上課狀況 -->
          <div class="lr-form-section">
            <div class="lr-form-section-title">上課狀況與評語</div>
            <div class="form-group">
              <label>上課狀況</label>
              <div class="lr-radio-group">
                <label class="lr-radio"><input v-model="form.Performance" type="radio" value="good" :disabled="isReadOnly"> 良好</label>
                <label class="lr-radio"><input v-model="form.Performance" type="radio" value="average" :disabled="isReadOnly"> 普通</label>
                <label class="lr-radio"><input v-model="form.Performance" type="radio" value="bad" :disabled="isReadOnly"> 不良</label>
              </div>
            </div>
            <div class="form-group">
              <label>學習進度與家長溝通</label>
              <textarea v-model="form.Comment" rows="4" :disabled="isReadOnly" placeholder="綜合評語與聯絡事項..."></textarea>
            </div>
          </div>

          <!-- Rejected Note -->
          <div v-if="form.Status === 'rejected' || form.Status === 'changes_requested'" class="lr-reject-note">
            <div class="lr-reject-note-title">
              {{ form.Status === 'rejected' ? '退回原因' : '需修改說明' }}
            </div>
            <p>{{ form.ReviewNote || '（無說明）' }}</p>
          </div>

          <!-- Actions -->
          <div class="lr-form-actions">
            <button type="button" class="ghost" @click="closeModal">關閉</button>
            <button v-if="!isReadOnly" type="submit" class="primary">
              {{ isEditing ? '儲存變更' : '提交評量' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, reactive, computed, watch } from 'vue';
import { supabase } from '../supabase';
import SearchableSelect from '../components/SearchableSelect.vue';
import HelpGuide from '../components/HelpGuide.vue';

const props = defineProps(['branchId', 'userRole', 'userId']);

const isTeacher = computed(() => props.userRole === 'teacher');

const records = ref([]);
const showModal = ref(false);
const isEditing = ref(false);
const teacherList = ref([]);
const studentList = ref([]);
const courseList = ref([]);
const teacherClassList = ref([]);  // teacher's own StudentClasses for schedule
const filters = reactive({ status: '', student_name: '', teacher_id: '' });

// Teacher schedule state
const scheduleView = ref('today');
const weekOffset = ref(0);  // 0 = current week, -1 = last week, +1 = next week

// Bulk backfill state
const showBulkModal = ref(false);
const bulkForm = reactive({ studentId: '', courseId: '' });
const bulkCourseOptions = ref([]);
const bulkDateList = ref([]);
const bulkSelectedDates = ref([]);
const bulkExistingDates = ref([]);
const bulkDatesLoading = ref(false);
const bulkSubmitting = ref(false);

const subjectList = ['國文', '英文', '數學', '自然', '社會'];

const timeOptions = [];
for (let h = 8; h <= 23; h++) {
  const hr = h.toString().padStart(2, '0');
  timeOptions.push(`${hr}:00`);
  timeOptions.push(`${hr}:30`);
}

const form = reactive({
  id: null,
  StudentID: '',
  TeacherID: '',
  ClassSessionID: 0,
  Subject: '數學',
  SessionDate: new Date().toISOString().split('T')[0],
  StartTime: '18:00',
  EndTime: '20:00',
  HomeworkStatus: 'completed',
  QuizScore: '',
  Progress: '',
  NextHomework: '',
  Performance: 'good',
  Comment: '',
  Status: '',
  ReviewNote: ''
});

const forceReadOnly = ref(false);

const isReadOnly = computed(() => {
  if (forceReadOnly.value) return true;
  if (form.Status === 'approved') {
    if (props.userRole === 'director' || props.userRole === 'super_admin') return false;
    return true;
  }
  return false;
});

const teacherOptions = computed(() =>
  (Array.isArray(teacherList.value) ? teacherList.value : []).map(t => ({ value: t.id, label: t.username || t.T_Name || t.Name || '?' }))
);

const studentOptions = computed(() =>
  (Array.isArray(studentList.value) ? studentList.value : []).map(s => ({
    value: s.id,
    label: `${s.name} (${s.phone || s.parent_phone || ''})`
  }))
);

// ── Auth ──
const getToken = async () => {
  const { data: { session } } = await supabase.auth.getSession();
  return session?.access_token;
};

// ── Fetch dropdown data ──
const fetchTeachers = async () => {
  try {
    const token = await getToken();
    if (!token) return;
    const res = await fetch('/api/v1/teachers', {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (res.ok) {
      const json = await res.json();
      teacherList.value = json.data || json || [];
    }
  } catch (e) { console.error('fetchTeachers', e); }
};

const fetchStudents = async () => {
  try {
    const token = await getToken();
    if (!token) return;
    const res = await fetch('/api/v1/students?per_page=all', {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (res.ok) {
      const json = await res.json();
      studentList.value = json.data || json;
    }
  } catch (e) { console.error('fetchStudents', e); }
};

// ── Teacher: fetch own student-classes for schedule widget ──
const fetchTeacherClasses = async () => {
  if (!isTeacher.value) return;
  try {
    const token = await getToken();
    if (!token) return;
    const params = new URLSearchParams({ per_page: 200, stop: 0 });
    if (props.branchId) params.set('branch_id', props.branchId);
    const res = await fetch(`/api/v1/student-classes?${params}`, {
      headers: { 'Authorization': `Bearer ${token}`, 'Accept': 'application/json' }
    });
    if (res.ok) {
      const json = await res.json();
      teacherClassList.value = json.data || json || [];
    }
  } catch (e) { console.error('fetchTeacherClasses', e); }
};

// ── Teacher Schedule Computed ──

// Get the Monday of the current (offset) week
const weekStart = computed(() => {
  const today = new Date();
  const dow = today.getDay();  // 0=Sun
  const mondayOffset = dow === 0 ? -6 : 1 - dow;
  const d = new Date(today);
  d.setDate(today.getDate() + mondayOffset + weekOffset.value * 7);
  d.setHours(0, 0, 0, 0);
  return d;
});

const weekLabel = computed(() => {
  const start = weekStart.value;
  const end = new Date(start);
  end.setDate(start.getDate() + 6);
  const fmt = (d) => `${d.getMonth() + 1}/${d.getDate()}`;
  return `${fmt(start)} — ${fmt(end)}`;
});

// Build schedule events from teacher's student-classes
const buildEvents = (targetDates) => {
  const events = [];
  for (const sc of teacherClassList.value) {
    if (sc.Stop == 1) continue;
    for (let w = 1; w <= 6; w++) {
      const dowVal = sc[`week${w}`];
      const timeVal = sc[`time${w}`];
      if (dowVal == null || dowVal === '' || !timeVal) continue;
      const dow = parseInt(dowVal); // 0=Sun, 1=Mon, ..., 6=Sat
      for (const dateStr of targetDates) {
        const d = new Date(dateStr + 'T00:00:00');
        if (d.getDay() === dow) {
          const student = studentList.value.find(s => String(s.id) === String(sc.StudentID));
          const studentName = student?.name || sc.student_name || `學生#${sc.StudentID}`;
          events.push({
            key: `${sc.ID || sc.id}-w${w}-${dateStr}`,
            classId: sc.ID || sc.id,
            studentId: sc.StudentID,
            studentName,
            subject: sc.Subject || '?',
            dayOfWeek: dow,
            time: timeVal,
            date: dateStr,
          });
        }
      }
    }
  }
  return events;
};

const todayStr = computed(() => new Date().toISOString().split('T')[0]);

const todayEvents = computed(() => {
  const events = buildEvents([todayStr.value]);
  return events.sort((a, b) => a.time.localeCompare(b.time));
});

const weekDays = computed(() => {
  const days = [];
  const todayDate = todayStr.value;
  for (let i = 0; i < 7; i++) {
    const d = new Date(weekStart.value);
    d.setDate(weekStart.value.getDate() + i);
    const dateStr = d.toISOString().split('T')[0];
    const dayNames = ['日', '一', '二', '三', '四', '五', '六'];
    const events = buildEvents([dateStr]).sort((a, b) => a.time.localeCompare(b.time));
    days.push({
      date: dateStr,
      label: `週${dayNames[d.getDay()]}`,
      shortDate: `${d.getMonth() + 1}/${d.getDate()}`,
      isToday: dateStr === todayDate,
      events,
    });
  }
  return days;
});

const openFromSchedule = (ev) => {
  _clearForm();
  forceReadOnly.value = false;
  Object.assign(form, {
    StudentID: ev.studentId,
    TeacherID: props.userId,
    Subject: ev.subject,
    SessionDate: ev.date,
    StartTime: ev.time,
  });
  if (ev.time) onStartTimeChange();
  showModal.value = true;
};

// ── Fetch Records ──
const fetchRecords = async () => {
  try {
    const token = await getToken();
    if (!token) return;

    const params = new URLSearchParams();
    if (props.branchId) params.set('branch_id', props.branchId);
    if (filters.student_name) params.set('student_name', filters.student_name);
    if (filters.teacher_id) params.set('teacher_id', filters.teacher_id);
    if (filters.status) params.set('status', filters.status);
    params.set('sort', 'session_date');
    params.set('per_page', '100');

    const res = await fetch(`/api/v1/learning-records?${params.toString()}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });

    if (!res.ok) throw new Error('Fetch failed');

    const data = await res.json();
    records.value = data.data || [];
  } catch (e) {
    console.error(e);
  }
};

// ── Modal ──
const _fillForm = (record) => {
  isEditing.value = true;
  Object.assign(form, {
    id: record.id,
    StudentID: Number(record.student_id) || '',
    TeacherID: Number(record.TeacherID),
    ClassSessionID: Number(record.ClassSessionID) || 0,
    Subject: record.Subject,
    SessionDate: record.SessionDate,
    StartTime: record.StartTime,
    EndTime: record.EndTime,
    HomeworkStatus: record.HomeworkStatus || 'completed',
    QuizScore: record.QuizScore || '',
    Progress: record.Progress || '',
    NextHomework: record.NextHomework || '',
    Performance: record.Performance || 'good',
    Comment: record.Comment || '',
    Status: record.Status,
    ReviewNote: record.ReviewNote || ''
  });
};

const _clearForm = () => {
  isEditing.value = false;
  Object.assign(form, {
    id: null,
    StudentID: '',
    TeacherID: isTeacher.value ? props.userId : '',
    ClassSessionID: 0,
    Subject: '數學',
    SessionDate: new Date().toISOString().split('T')[0],
    StartTime: '18:00',
    EndTime: '20:00',
    HomeworkStatus: 'completed',
    QuizScore: '',
    Progress: '',
    NextHomework: '',
    Performance: 'good',
    Comment: '',
    Status: 'pending',
    ReviewNote: ''
  });
};

const openModal = (record = null) => {
  forceReadOnly.value = false;
  if (record) {
    _fillForm(record);
  } else {
    _clearForm();
  }
  showModal.value = true;
};

const viewRecord = (record) => {
  forceReadOnly.value = true;
  _fillForm(record);
  showModal.value = true;
};

const editRecord = (record) => {
  forceReadOnly.value = false;
  _fillForm(record);
  showModal.value = true;
};

const closeModal = () => { showModal.value = false; };

const submitForm = async () => {
  const token = await getToken();
  const url = isEditing.value ? `/api/v1/learning-records/${form.id}` : '/api/v1/learning-records';
  const method = isEditing.value ? 'PUT' : 'POST';

  if (!form.ClassSessionID) form.ClassSessionID = 0;

  const res = await fetch(url, {
    method,
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`
    },
    body: JSON.stringify(form)
  });

  if (res.ok) {
    closeModal();
    fetchRecords();
  } else {
    const err = await res.json();
    alert('儲存失敗: ' + (err.message || '未知錯誤'));
  }
};

// ── Approve / Reject ──
const approveRecord = async (record) => {
  if (!confirm('確定要核准此評量嗎？')) return;

  const token = await getToken();
  const res = await fetch(`/api/v1/learning-records/${record.id}/approve`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`
    },
    body: JSON.stringify({ DirectorID: props.userId })
  });

  if (res.ok) {
    fetchRecords();
  } else {
    alert('核准失敗');
  }
};

const rejectRecord = async (record) => {
  const note = prompt('請輸入退回原因：');
  if (!note) return;

  const token = await getToken();
  const res = await fetch(`/api/v1/learning-records/${record.id}/reject`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`
    },
    body: JSON.stringify({ ReviewNote: note })
  });
  if (res.ok) fetchRecords();
};

// ── Helpers ──
const statusLabel = (status) => {
  const map = { pending: '待審核', approved: '已核准', rejected: '已退回', changes_requested: '需修改' };
  return map[status] || status;
};

const statusTagClass = (status) => {
  const map = {
    pending: 'pending',
    approved: 'active',
    rejected: 'rejected',
    changes_requested: 'pending'
  };
  return map[status] || '';
};

const canApprove = (record) => {
  if (props.userRole !== 'director' && props.userRole !== 'super_admin') return false;
  return record.Status === 'pending';
};

const canEdit = (record) => {
  if (props.userRole === 'director' || props.userRole === 'super_admin') return true;
  if (record.Status === 'approved') return false;
  return true;
};

const canDelete = (record) => {
  return props.userRole === 'director' || props.userRole === 'super_admin';
};

const deleteRecord = async (record) => {
  if (!confirm(`確定要刪除 ${record.student_name} — ${record.SessionDate} 的評量記錄嗎？此操作無法還原。`)) return;
  const token = await getToken();
  const res = await fetch(`/api/v1/learning-records/${record.id}`, {
    method: 'DELETE',
    headers: { 'Authorization': `Bearer ${token}` }
  });
  if (res.ok) {
    fetchRecords();
  } else {
    alert('刪除失敗');
  }
};

const onStartTimeChange = () => {
  if (form.StartTime) {
    const [h, m] = form.StartTime.split(':').map(Number);
    const endH = (h + 2) % 24;
    form.EndTime = `${endH.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}`;
  }
};

// ── Fetch Courses (for bulk backfill) ──
const fetchCourses = async () => {
  try {
    const token = await getToken();
    if (!token || !props.branchId) return;
    const res = await fetch(`/api/v1/student-classes?branch_id=${props.branchId}&per_page=200`, {
      headers: { 'Authorization': `Bearer ${token}`, 'Accept': 'application/json' }
    });
    if (res.ok) {
      const json = await res.json();
      courseList.value = json.data || json || [];
    }
  } catch (e) { console.error('fetchCourses', e); }
};

// ── Bulk Backfill ──
const _dayLabel = (d) => ['', '一', '二', '三', '四', '五', '六', '日'][d] || '';

const openBulkBackfill = () => {
  bulkForm.studentId = '';
  bulkForm.courseId = '';
  bulkCourseOptions.value = [];
  bulkDateList.value = [];
  bulkSelectedDates.value = [];
  bulkExistingDates.value = [];
  fetchCourses();
  showBulkModal.value = true;
};

watch(() => bulkForm.studentId, (sid) => {
  bulkForm.courseId = '';
  bulkDateList.value = [];
  bulkSelectedDates.value = [];
  if (!sid) { bulkCourseOptions.value = []; return; }
  bulkCourseOptions.value = courseList.value
    .filter(c => String(c.student_id) === String(sid))
    .map(c => ({
      id: c.id,
      subject: c.subject || '未知',
      days_label: (c.days_of_week || (c.day_of_week ? [c.day_of_week] : [])).map(d => '週' + _dayLabel(d)).join('') || '',
      start_time: c.start_time || '',
      days_of_week: c.days_of_week || (c.day_of_week ? [c.day_of_week] : []),
      first_class_date: c.first_class_date,
      sessions_purchased: c.sessions_purchased,
      teacher_id: c.teacher_id
    }));
});

const loadBulkCourseDates = async () => {
  const cid = bulkForm.courseId;
  if (!cid) { bulkDateList.value = []; return; }
  bulkDatesLoading.value = true;
  try {
    const course = bulkCourseOptions.value.find(c => String(c.id) === String(cid));
    if (!course) return;
    const token = await getToken();
    const res = await fetch('/api/v1/student-classes/session-dates', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({
        branch_id: props.branchId,
        courses: [{ id: course.id, first_class_date: course.first_class_date, sessions_purchased: course.sessions_purchased, days_of_week: course.days_of_week }]
      })
    });
    const json = await res.json();
    const allDates = json[String(course.id)] || [];
    const today = new Date().toISOString().split('T')[0];
    bulkDateList.value = allDates.filter(d => d <= today).sort();

    const lrRes = await fetch(`/api/v1/learning-records?student_class_id=${cid}&per_page=200`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (lrRes.ok) {
      const lrJson = await lrRes.json();
      bulkExistingDates.value = (lrJson.data || []).filter(r => r.Status === 'approved').map(r => r.SessionDate).filter(Boolean);
      bulkSelectedDates.value = bulkDateList.value.filter(d => !bulkExistingDates.value.includes(d));
    } else {
      bulkSelectedDates.value = [...bulkDateList.value];
    }
  } catch (e) { console.error(e); }
  finally { bulkDatesLoading.value = false; }
};

const toggleSelectAllDates = () => {
  const selectable = bulkDateList.value.filter(d => !bulkExistingDates.value.includes(d));
  bulkSelectedDates.value = bulkSelectedDates.value.length === selectable.length ? [] : [...selectable];
};

const submitBulkBackfill = async () => {
  const course = bulkCourseOptions.value.find(c => String(c.id) === String(bulkForm.courseId));
  if (!course || bulkSelectedDates.value.length === 0) return;
  bulkSubmitting.value = true;
  try {
    const token = await getToken();
    const res = await fetch('/api/v1/learning-records/bulk-backdoor-approve', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({
        StudentClassID: course.id,
        TeacherID: course.teacher_id,
        DirectorID: props.userId,
        session_dates: bulkSelectedDates.value
      })
    });
    const json = await res.json();
    alert(json.message || '補登完成');
    showBulkModal.value = false;
    fetchRecords();
  } catch (e) { alert('補登失敗: ' + e.message); }
  finally { bulkSubmitting.value = false; }
};

const ensurePastRecords = async () => {
  try {
    const token = await getToken();
    if (!token || !props.branchId) return;
    await fetch('/api/v1/learning-records/ensure-past', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ branch_id: props.branchId })
    });
  } catch (e) { /* silent */ }
};

// ── Init ──
onMounted(async () => {
  await ensurePastRecords();
  fetchRecords();
  fetchTeachers();
  await fetchStudents();
  if (isTeacher.value) fetchTeacherClasses();
});

watch(() => props.branchId, () => {
  fetchRecords();
  fetchCourses();
  if (isTeacher.value) fetchTeacherClasses();
});
</script>

<style scoped>
/* ── Page Layout ── */
.lr-page {
  max-width: 1200px;
}

.lr-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  flex-wrap: wrap;
  gap: 12px;
}

.lr-header button {
  white-space: nowrap;
}

/* ── Teacher Schedule Widget ── */
.teacher-schedule {
  padding: 16px 20px;
  margin-bottom: 16px;
}

.ts-header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
  flex-wrap: wrap;
}

.ts-header h3 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
}

.ts-tabs {
  display: flex;
  border: 1px solid var(--border);
  border-radius: 6px;
  overflow: hidden;
}

.ts-tabs button {
  padding: 5px 14px;
  font-size: 13px;
  border: none;
  background: none;
  cursor: pointer;
  color: var(--text-light);
  transition: var(--transition);
}

.ts-tabs button.active {
  background: var(--primary);
  color: #fff;
}

.ts-nav {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
}

.ts-week-label {
  font-size: 13px;
  color: var(--text-light);
  min-width: 110px;
  text-align: center;
}

.icon-btn {
  background: none;
  border: 1px solid var(--border);
  border-radius: 4px;
  padding: 3px 8px;
  cursor: pointer;
  font-size: 16px;
  color: var(--text);
  line-height: 1;
}

.ts-empty {
  color: var(--text-light);
  font-size: 13px;
  padding: 8px 0;
}

/* Today view */
.ts-today {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.ts-event {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  background: var(--primary-bg);
  border-left: 3px solid var(--primary);
  border-radius: 0 8px 8px 0;
  cursor: pointer;
  transition: var(--transition);
}

.ts-event:hover {
  background: #dbeafe;
}

.ts-time {
  font-weight: 700;
  font-size: 15px;
  color: var(--primary);
  min-width: 48px;
}

.ts-info {
  flex: 1;
}

.ts-student {
  font-weight: 600;
  font-size: 14px;
}

.ts-subject {
  font-size: 12px;
  color: var(--text-light);
  margin-top: 1px;
}

.ts-fill-btn {
  background: var(--primary);
  color: #fff;
  border: none;
  padding: 6px 14px;
  border-radius: 6px;
  font-size: 13px;
  cursor: pointer;
  white-space: nowrap;
  flex-shrink: 0;
}

.ts-fill-btn:hover {
  background: var(--primary-dark, #1557b0);
}

/* Week view */
.ts-week {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 6px;
}

.ts-day {
  border: 1px solid var(--border);
  border-radius: 8px;
  overflow: hidden;
}

.ts-day.today {
  border-color: var(--primary);
  box-shadow: 0 0 0 1px var(--primary);
}

.ts-day-header {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 6px 4px;
  background: #f9f9f9;
  font-size: 11px;
}

.ts-day.today .ts-day-header {
  background: var(--primary);
  color: #fff;
}

.ts-day-name {
  font-weight: 700;
  font-size: 12px;
}

.ts-day-date {
  font-size: 11px;
  opacity: 0.7;
}

.ts-day-empty {
  text-align: center;
  color: #ccc;
  padding: 8px;
  font-size: 16px;
}

.ts-event-sm {
  border-radius: 0;
  border-left: none;
  border-top: 1px solid rgba(0,0,0,0.05);
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  padding: 6px 8px;
  background: var(--primary-bg);
}

.ts-event-sm .ts-time {
  font-size: 12px;
  min-width: unset;
}

.ts-event-sm .ts-student {
  font-size: 12px;
}

.ts-event-sm .ts-subject {
  font-size: 11px;
}

.ts-fill-hint {
  font-size: 10px;
  color: var(--primary);
  font-weight: 600;
  margin-top: 2px;
}

/* ── Filters ── */
.lr-filters {
  padding: 20px 24px;
}

.lr-filters-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px 16px;
  align-items: end;
}

.lr-filter-btn-wrap {
  display: flex;
  flex-direction: column;
}

.lr-filter-btn-wrap button {
  width: 100%;
}

/* ── Table ── */
.lr-table-card {
  padding: 0;
  overflow: hidden;
}

.lr-table-scroll {
  overflow-x: auto;
}

.lr-table-row {
  cursor: pointer;
  transition: background 0.15s;
}

.lr-table-row:hover {
  background: #f7f9ff;
}

.lr-date {
  font-weight: 600;
  display: block;
}

.lr-time {
  font-size: 12px;
  color: var(--text-light);
}

.lr-student-name {
  font-weight: 600;
  font-size: 13.5px;
}

.lr-class-label {
  font-size: 12px;
  color: var(--text-light);
  margin-top: 1px;
}

.lr-actions {
  text-align: right;
  white-space: nowrap;
}

.lr-actions button {
  margin-left: 4px;
}

/* Status tag variant */
.status-tag.rejected {
  background: var(--danger-bg);
  color: var(--danger);
}

/* ── Mobile Card List ── */
.lr-card-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.lr-empty-card {
  text-align: center;
  color: var(--text-light);
  padding: 40px 16px;
  font-size: 14px;
  background: var(--card-bg);
  border-radius: var(--radius);
}

.lr-record-card {
  background: var(--card-bg);
  border-radius: 10px;
  padding: 14px 16px;
  box-shadow: var(--shadow);
  cursor: pointer;
  transition: var(--transition);
  border-left: 3px solid transparent;
}

.lr-record-card:active {
  transform: scale(0.98);
}

.lrc-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 6px;
}

.lrc-date {
  font-weight: 700;
  font-size: 13px;
  color: var(--text-light);
}

.lrc-status {
  font-size: 11px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 10px;
}

.lrc-status.active {
  background: #e8f5e9;
  color: #2e7d32;
}

.lrc-status.pending {
  background: #fff3e0;
  color: #e65100;
}

.lrc-status.rejected {
  background: #ffebee;
  color: #c62828;
}

.lrc-student {
  font-weight: 700;
  font-size: 16px;
  margin-bottom: 6px;
}

.lrc-meta {
  display: flex;
  align-items: center;
  gap: 8px;
}

.lrc-teacher {
  font-size: 12px;
  color: var(--text-light);
}

.lrc-actions {
  margin-top: 10px;
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}

/* ── Modal ── */
.lr-modal {
  width: 720px;
  max-width: 95vw;
  max-height: 90vh;
  overflow-y: auto;
  padding: 0;
}

.lr-modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px 28px;
  border-bottom: 1px solid var(--border);
  position: sticky;
  top: 0;
  background: var(--card-bg);
  z-index: 1;
}

.lr-modal-header h3 {
  margin: 0;
  font-size: 18px;
}

.lr-modal-close {
  background: none;
  border: none;
  font-size: 28px;
  line-height: 1;
  color: var(--text-light);
  padding: 0 4px;
  transition: color 0.2s;
  min-width: 44px;
  min-height: 44px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.lr-modal-close:hover {
  color: var(--danger);
}

/* ── Form ── */
.lr-form {
  padding: 24px 28px;
}

.lr-form-section {
  margin-bottom: 24px;
}

.lr-form-section-title {
  font-size: 13px;
  font-weight: 700;
  color: var(--primary);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-bottom: 14px;
  padding-bottom: 8px;
  border-bottom: 2px solid var(--primary-bg);
}

.lr-form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px 16px;
}

.lr-required {
  color: var(--danger);
}

.lr-radio-group {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 8px;
}

.lr-radio {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-weight: 400;
  cursor: pointer;
  padding: 8px 12px;
  border: 1px solid var(--border);
  border-radius: 6px;
  font-size: 14px;
  min-height: 40px;
  transition: var(--transition);
  user-select: none;
}

.lr-radio:has(input:checked) {
  border-color: var(--primary);
  background: var(--primary-bg);
  color: var(--primary);
  font-weight: 600;
}

.lr-radio input[type="radio"] {
  width: 16px;
  height: 16px;
  accent-color: var(--primary);
  cursor: pointer;
  flex-shrink: 0;
}

/* ── Reject Note ── */
.lr-reject-note {
  background: var(--danger-bg);
  border-left: 4px solid var(--danger);
  border-radius: 0 8px 8px 0;
  padding: 14px 18px;
  margin-bottom: 20px;
}

.lr-reject-note-title {
  font-weight: 700;
  font-size: 13px;
  color: var(--danger);
  margin-bottom: 6px;
}

.lr-reject-note p {
  font-size: 13.5px;
  color: #C62828;
  margin: 0;
  line-height: 1.6;
}

/* ── Form Actions ── */
.lr-form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding-top: 16px;
  border-top: 1px solid var(--border);
}

/* ── Bulk backfill ── */
.bulk-date-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  max-height: 280px;
  overflow-y: auto;
  padding: 4px 0;
}

.bulk-date-item {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  padding: 6px 12px;
  border: 2px solid var(--border);
  border-radius: 8px;
  cursor: pointer;
  font-size: 13px;
  transition: var(--transition);
  user-select: none;
}

.bulk-date-item.selected {
  border-color: var(--primary);
  background: var(--primary-bg);
  color: var(--primary);
  font-weight: 600;
}

.bulk-date-item.existing {
  opacity: 0.5;
  border-color: #aed581;
  background: #f1f8e9;
}

/* ── Responsive: Tablet ── */
@media (max-width: 900px) {
  .ts-week {
    grid-template-columns: repeat(7, minmax(0, 1fr));
  }
}

@media (max-width: 768px) {
  .lr-filters-grid {
    grid-template-columns: 1fr;
  }
  .lr-form-grid {
    grid-template-columns: 1fr;
  }
  .lr-modal {
    width: 100%;
    max-width: 100vw;
    max-height: 100vh;
    border-radius: 0;
  }
  .lr-header {
    flex-direction: column;
  }
  .lr-header button {
    width: 100%;
  }
  .ts-week {
    grid-template-columns: repeat(4, 1fr);
  }
}

/* ── Responsive: Phone ── */
@media (max-width: 640px) {
  /* Show cards, hide table */
  .lr-desktop-only {
    display: none !important;
  }
  .lr-mobile-only {
    display: flex !important;
  }

  .lr-page {
    padding: 0;
  }

  .lr-header {
    flex-direction: column;
    gap: 8px;
  }

  .lr-header h2 {
    font-size: 1.1rem;
  }

  .lr-header > div:last-child {
    display: flex;
    gap: 8px;
    width: 100%;
  }

  .lr-header button {
    flex: 1;
    padding: 10px;
    font-size: 13px;
  }

  .lr-filters-grid {
    grid-template-columns: 1fr;
    gap: 8px;
  }

  .lr-filters {
    padding: 12px;
  }

  /* Teacher schedule on mobile */
  .teacher-schedule {
    padding: 12px;
  }

  .ts-week {
    grid-template-columns: 1fr;
    gap: 10px;
  }

  .ts-day {
    border-radius: 8px;
  }

  .ts-day-header {
    flex-direction: row;
    justify-content: flex-start;
    gap: 10px;
    padding: 8px 12px;
  }

  .ts-day-name {
    font-size: 14px;
  }

  .ts-day-date {
    font-size: 13px;
    opacity: 1;
    color: var(--text-light);
  }

  .ts-day-empty {
    padding: 8px 12px;
    text-align: left;
    font-size: 13px;
    color: var(--text-light);
  }

  .ts-event-sm {
    flex-direction: row;
    align-items: center;
    padding: 8px 12px;
    gap: 10px;
    border-radius: 0;
    border-left: 3px solid var(--primary);
    border-top: none;
    margin: 2px 0;
  }

  .ts-event-sm .ts-time {
    font-size: 13px;
    font-weight: 700;
    min-width: 44px;
  }

  .ts-event-sm .ts-info {
    flex: 1;
  }

  .ts-event-sm .ts-student {
    font-size: 14px;
  }

  .ts-fill-hint {
    font-size: 12px;
    padding: 4px 10px;
    border: 1px solid var(--primary);
    border-radius: 4px;
  }

  /* Modal: full screen bottom sheet on mobile */
  .modal-overlay {
    align-items: flex-end !important;
  }

  .lr-modal {
    width: 100%;
    max-width: 100vw;
    max-height: 92vh;
    border-radius: 20px 20px 0 0;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
  }

  .lr-modal-header {
    padding: 16px 20px;
    border-radius: 20px 20px 0 0;
  }

  .lr-modal-header h3 {
    font-size: 17px;
  }

  .lr-form {
    padding: 16px 20px 24px;
  }

  .lr-form-grid {
    grid-template-columns: 1fr;
    gap: 10px;
  }

  .form-group input,
  .form-group select,
  .form-group textarea {
    font-size: 16px; /* prevents iOS zoom */
    min-height: 46px;
    padding: 10px 12px;
  }

  .form-group textarea {
    min-height: 80px;
  }

  .lr-radio-group {
    gap: 6px;
  }

  .lr-radio {
    flex: 1 1 calc(50% - 6px);
    justify-content: center;
    font-size: 14px;
    padding: 10px 8px;
    min-height: 44px;
    text-align: center;
  }

  .lr-form-actions {
    flex-direction: column-reverse;
    gap: 10px;
  }

  .lr-form-actions button {
    width: 100%;
    padding: 14px;
    font-size: 15px;
  }

  .bulk-date-grid {
    max-height: 200px;
  }

  .bulk-date-item {
    padding: 6px 10px;
    font-size: 12px;
  }
}

@media (max-width: 480px) {
  .ts-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
  }

  .ts-nav {
    margin-left: 0;
    width: 100%;
    justify-content: space-between;
  }

  .ts-week-label {
    min-width: 0;
    flex: 1;
    text-align: center;
  }
}
</style>
