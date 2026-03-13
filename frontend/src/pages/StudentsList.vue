<template>
  <div>
    <HelpGuide
      title="學生管理 — 使用說明"
      :items="[
        '點擊學生列可<strong>展開/收合</strong>該學生的課程明細（科目、老師、類型、費率、排課時段）。',
        '「+ 新增學生」建立新生；展開學生後「+ 新增課程」可為該生加課程；「加購堂數」可追加購買堂數。',
        '剩餘堂數顯示<strong>紅色</strong>表示即將或已用完，請提醒家長繳費。',
        '「匯入 CSV」可批次匯入舊生，格式：姓名,手機,年級（依系統提示欄位）。'
      ]"
      tip="課程建立後會同步至智慧排課與科目數統計；修改課程後請至排課頁確認時段。"
    />
    <div class="card">
      <div class="header-actions">
        <div>
          <h2>🎓 學生管理</h2>
          <p class="hint">點擊學生可展開課程明細</p>
        </div>
        <div class="header-buttons">
          <label class="button-outline">
            📥 匯入 CSV
            <input type="file" @change="importStudents" accept=".csv" style="display: none;" />
          </label>
          <button class="primary" @click="openAddStudent">+ 新增學生</button>
        </div>
      </div>

      <div class="grid filter-bar">
        <div>
          <label>搜尋姓名</label>
          <input v-model="filters.name" placeholder="輸入姓名..." @input="debouncedLoad" />
        </div>
        <div>
          <label>年級</label>
          <select v-model="filters.grade" @change="loadStudents">
            <option value="">全部</option>
            <option v-for="g in GRADES" :key="g.value" :value="g.value">{{ g.label }}</option>
          </select>
        </div>
        <div>
          <label>狀態</label>
          <select v-model="filters.status" @change="loadStudents">
            <option value="active">在學中</option>
            <option value="">全部</option>
            <option value="graduated">已畢業</option>
            <option value="paused">暫停中</option>
            <option value="transferred">已轉校</option>
          </select>
        </div>
        <div style="display: flex; align-items: flex-end;">
          <button class="small ghost" @click="showGradePromotion = true" style="white-space: nowrap;">🎓 年級升級</button>
        </div>
      </div>

      <!-- Student Table -->
      <table v-if="students.length">
        <thead>
          <tr>
            <th style="width: 30px;"></th>
            <th>姓名</th>
            <th>年級</th>
            <th>學校</th>
            <th>家長</th>
            <th>RFID</th>
            <th>補習科目 / 剩餘堂數</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody v-for="student in students" :key="student.id">
          <!-- Student Row -->
          <tr
            class="student-row"
            :class="{ expanded: expandedId === student.id }"
            @click="toggleExpand(student)"
          >
            <td class="expand-icon">{{ expandedId === student.id ? '▼' : '▶' }}</td>
            <td>
              <strong>{{ student.name }}</strong>
              <span v-if="student.notes" class="note-icon" :title="student.notes">📝</span>
              <span v-if="student.status && student.status !== 'active'" :class="['student-status-badge', student.status]">
                {{ studentStatusLabel(student.status) }}
              </span>
            </td>
            <td>{{ getGradeLabel(student.grade) }}</td>
            <td>{{ student.school || '—' }}</td>
            <td>{{ student.parent_name || '—' }}</td>
            <td @click.stop>
              <span v-if="student.rfid" class="rfid-tag">{{ student.rfid }}</span>
              <button class="small ghost" @click="editStudent(student)">{{ student.rfid ? '重新綁定' : '綁定' }}</button>
            </td>
            <td>
              <div class="subject-tags" v-if="getStudentCourses(student.id).length > 0">
                <span
                  v-for="course in getStudentCourses(student.id)"
                  :key="course.id"
                  :class="['subject-pill', { low: course.remaining_sessions <= 2 }]"
                >
                  {{ getSubjectLabel(course.subject).split('(')[0].trim() }}
                  <strong>{{ course.remaining_sessions ?? 0 }}</strong>堂
                </span>
              </div>
              <span class="hint" v-else>尚未設定</span>
            </td>
            <td @click.stop>
              <button class="small ghost" @click="editStudent(student)">✏️ 編輯</button>
            </td>
          </tr>

          <!-- Expanded Course Detail -->
          <tr v-if="expandedId === student.id" class="course-detail-row">
            <td colspan="9">
              <div class="course-panel">
                <div class="course-panel-header">
                  <h4>📚 {{ student.name }} 的課程安排</h4>
                  <button class="primary small" @click="openAddCourse(student)">+ 新增課程</button>
                </div>

                <div v-if="getStudentCourses(student.id).length === 0" class="empty-text">
                  尚未建立課程，請點擊「+ 新增課程」開始設定
                </div>

                <table v-else class="course-inner-table">
                  <thead>
                    <tr>
                      <th>科目</th>
                      <th>老師</th>
                      <th>類型</th>
                      <th>剩餘 / 已購</th>
                      <th>一堂課（2h）</th>
                      <th>時長</th>
                      <th>排課時段</th>
                      <th>地點</th>
                      <th>操作</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="course in getStudentCourses(student.id)" :key="course.id">
                      <td><span class="tag">{{ getSubjectLabel(course.subject) }}</span></td>
                      <td>{{ course.teacher_name || '待指派' }}</td>
                      <td>
                        <span class="status-tag" :class="course.class_type">
                          {{ classTypeLabel(course.class_type) }}
                        </span>
                      </td>
                      <td>
                        <span v-if="course.payment_type === 'session'" :class="{ 'text-red': course.remaining_sessions <= 2 }">
                          <strong>{{ course.remaining_sessions ?? 0 }}</strong> / {{ course.sessions_purchased || 0 }} 堂
                        </span>
                        <span v-else class="hint">
                          月結<span v-if="course.settlement_day">（每月{{ course.settlement_day }}號）</span>
                        </span>
                      </td>
                      <td style="font-weight: 600;">${{ ratePer2hDisplay(course) }}</td>
                      <td>{{ course.duration_hours }} 小時</td>
                      <td>
                        <span v-if="course.day_of_week">
                          {{ dayLabel(course.day_of_week) }} {{ course.start_time }}~{{ course.end_time }}
                        </span>
                        <span v-else-if="course.days_of_week && course.days_of_week.length">
                          {{ course.days_of_week.map(d => dayLabel(d)).join(' ') }} {{ course.start_time }}~{{ course.end_time }}
                        </span>
                        <span v-else class="hint">未排定</span>
                      </td>
                      <td>
                        <span v-if="course.branch_name || course.room_name">
                          {{ [course.branch_name, course.room_name].filter(Boolean).join(' － ') }}
                        </span>
                        <span v-else class="hint">—</span>
                      </td>
                      <td>
                        <button
                          :class="['small', course.payment_status === 'paid' ? 'ghost' : 'primary']"
                          @click="togglePaymentStatus(course)"
                          style="font-size: 11px;"
                        >
                          {{ course.payment_status === 'paid' ? '已繳費' : '未繳費' }}
                        </button>
                        <button class="small ghost" @click="openAddSessionsForCourse(course)">加購</button>
                        <button class="small ghost" @click="editCourse(course)">編輯</button>
                        <button class="small danger" @click="deleteCourse(course)">刪除</button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-else class="empty-text">
        目前無學生資料，請點擊「+ 新增學生」或匯入 CSV
      </div>
    </div>

    <!-- Add/Edit Student Modal -->
    <div v-if="showStudentModal" class="modal-overlay" @click.self="closeStudentModal">
      <div class="modal" style="width: 520px;">
        <h3>{{ editingStudentId ? '編輯學生' : '新增學生' }}</h3>
        
        <div class="form-section-title">基本資料</div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
          <div class="form-group">
            <label>姓名 <span class="required">*</span></label>
            <input v-model="studentForm.name" placeholder="請輸入學生姓名" />
          </div>
          <div class="form-group">
            <label>年級</label>
            <select v-model="studentForm.grade">
              <option v-for="g in GRADES" :key="g.value" :value="g.value">{{ g.label }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>就讀學校</label>
            <input v-model="studentForm.school" placeholder="例：大安國中" />
          </div>
        </div>

        <div class="form-section-title">家長資訊</div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
          <div class="form-group">
            <label>家長姓名</label>
            <input v-model="studentForm.parent_name" placeholder="請輸入家長姓名" />
          </div>
          <div class="form-group">
            <label>家長手機</label>
            <input v-model="studentForm.parent_phone" placeholder="09xxxxxxxx" />
          </div>
        </div>

        <div class="form-section-title">RFID 卡片</div>
        <div class="form-group">
          <label>RFID</label>
          <div class="rfid-bind-row">
            <input v-model="studentForm.rfid" readonly placeholder="刷卡後點「綁定卡片」" />
            <button type="button" class="small" @click="bindRfidFromTemp">{{ studentForm.rfid ? '重新綁定卡片' : '綁定卡片' }}</button>
          </div>
        </div>

        <div class="form-section-title">其他</div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
          <div class="form-group" v-if="editingStudentId">
            <label>學生狀態</label>
            <select v-model="studentForm.status">
              <option value="active">在學中</option>
              <option value="graduated">已畢業</option>
              <option value="paused">暫停中</option>
              <option value="transferred">已轉校</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>備註</label>
          <textarea v-model="studentForm.notes" rows="2" placeholder="特殊需求、過敏、家長偏好等..." style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-family: inherit; resize: vertical;"></textarea>
        </div>

        <div class="actions">
          <button class="ghost" @click="closeStudentModal">取消</button>
          <button class="primary" @click="submitStudent">儲存</button>
        </div>
      </div>
    </div>

    <!-- Add/Edit Course Modal -->
    <div v-if="showCourseModal" class="modal-overlay" @click.self="closeCourseModal">
      <div class="modal" style="width: 520px;">
        <h3>{{ editingCourseId ? '編輯課程' : '為 ' + selectedStudent?.name + ' 新增課程' }}</h3>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
          <div class="form-group">
            <label>科目</label>
            <select v-model="courseForm.subject">
              <option v-for="s in SUBJECTS" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>老師</label>
            <select v-model="courseForm.teacher_id">
              <option value="">請選擇</option>
              <option v-for="t in teachers" :key="t.id" :value="t.id">{{ t.username }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>開課日 <span class="required">*</span></label>
            <input v-model="courseForm.first_class_date" type="date" />
          </div>
          <div class="form-group">
            <label>上課類型</label>
            <select v-model="courseForm.class_type">
              <option value="one_on_one">一對一</option>
              <option value="one_on_two">一對二</option>
              <option value="one_on_three">一對三</option>
              <option value="tutoring">輔導</option>
            </select>
          </div>
          <div class="form-group">
            <label>一堂課（2小時）費用 ($)</label>
            <input v-model.number="ratePer2hCourse" type="number" placeholder="2000" min="0" step="100" />
          </div>
          <div class="form-group">
            <label>上課時長（小時）</label>
            <select v-model.number="courseForm.duration_hours">
              <option :value="1">1 小時</option>
              <option :value="1.5">1.5 小時</option>
              <option :value="2">2 小時</option>
              <option :value="2.5">2.5 小時</option>
              <option :value="3">3 小時</option>
            </select>
          </div>
          <div class="form-group">
            <label>繳費方式</label>
            <select v-model="courseForm.payment_type">
              <option value="session">堂數制</option>
              <option value="monthly">月結</option>
            </select>
          </div>
          <div class="form-group" v-if="courseForm.payment_type === 'session'">
            <label>購買堂數</label>
            <input v-model.number="courseForm.sessions_purchased" type="number" placeholder="8" />
          </div>
          <template v-if="courseForm.payment_type === 'monthly'">
            <div class="form-group">
              <label>結算日（每月幾號） <span class="required">*</span></label>
              <select v-model.number="courseForm.settlement_day">
                <option :value="null">請選擇</option>
                <option v-for="d in settlementDayOptions" :key="d" :value="d">每月 {{ d }} 號</option>
              </select>
            </div>
            <div class="form-group">
              <label>每月堂數（選填）</label>
              <input v-model.number="courseForm.monthly_sessions" type="number" placeholder="依學生個案" min="0" />
            </div>
          </template>
          <div class="form-group" v-if="editingCourseId" style="grid-column: span 2;">
            <label>固定排課（星期幾）</label>
            <select v-model.number="courseForm.day_of_week">
              <option :value="0">未排定</option>
              <option :value="1">星期一</option>
              <option :value="2">星期二</option>
              <option :value="3">星期三</option>
              <option :value="4">星期四</option>
              <option :value="5">星期五</option>
              <option :value="6">星期六</option>
              <option :value="7">星期日</option>
            </select>
          </div>
          <div class="form-group" v-else style="grid-column: span 2;">
            <label>固定排課日（可選多天）</label>
            <div class="day-checkbox-group">
              <label v-for="d in dayOptions" :key="d.value" :class="['day-chip', { selected: courseForm.days_of_week.includes(d.value) }]">
                <input type="checkbox" :value="d.value" v-model="courseForm.days_of_week" style="display: none;" />
                {{ d.label }}
              </label>
            </div>
          </div>
          <div class="form-group">
            <label>開始時間</label>
            <select v-model="courseForm.start_time">
              <option v-for="t in TIME_OPTIONS_30" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
          <div class="form-group">
            <label class="text-secondary">結束時間</label>
            <p class="computed-end-time">{{ computeEndTime(courseForm.start_time, courseForm.duration_hours) || '—' }}</p>
          </div>
          <div class="form-group" style="grid-column: span 2;">
            <label>上課地點（教室）</label>
            <select v-model="courseForm.room_id">
              <option :value="null">請選擇教室</option>
              <option v-for="r in rooms" :key="r.id" :value="r.id">{{ r.name }}{{ r.memo ? ' — ' + r.memo : '' }}</option>
            </select>
          </div>
          <div class="form-group" style="grid-column: span 2;">
            <label>備註（選填）</label>
            <textarea v-model="courseForm.memo" rows="2" placeholder="課程或地點補充說明" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px; resize:vertical;"></textarea>
          </div>
        </div>

        <!-- Cost Preview -->
        <div class="cost-preview" v-if="ratePer2hCourse && courseForm.duration_hours">
          <div class="cost-preview-label">預估一堂課費用（依時長）</div>
          <div class="cost-preview-value">
            ${{ (Math.round((ratePer2hCourse / 4) * courseForm.duration_hours * 2)).toLocaleString() }}
          </div>
          <div class="cost-preview-formula">
            2h = ${{ ratePer2hCourse }}；{{ courseForm.duration_hours }}h = ${{ (ratePer2hCourse * courseForm.duration_hours / 2).toLocaleString() }}
          </div>
        </div>

        <div class="actions">
          <button class="ghost" @click="closeCourseModal">取消</button>
          <button class="primary" @click="submitCourse">儲存</button>
        </div>
      </div>
    </div>

    <!-- Add Sessions Modal (per-course) -->
    <div v-if="showSessionsModal" class="modal-overlay" @click.self="showSessionsModal = false">
      <div class="modal">
        <h3>💰 加購堂數 — {{ getSubjectLabel(selectedCourse?.subject) }}</h3>
        <div class="form-group">
          <label>學生</label>
          <p style="font-weight: 600;">{{ selectedStudent?.name }}</p>
        </div>
        <div class="form-group">
          <label>目前剩餘</label>
          <p style="font-size: 20px; font-weight: 700; color: var(--primary);">{{ selectedCourse?.remaining_sessions ?? 0 }} 堂</p>
        </div>
        <div class="form-group">
          <label>加購堂數</label>
          <input v-model.number="addSessionCount" type="number" placeholder="8" />
        </div>
        <p class="hint" v-if="addSessionCount > 0">
          加購後將變為 <strong>{{ (selectedCourse?.remaining_sessions ?? 0) + addSessionCount }}</strong> 堂
        </p>
        <div class="actions">
          <button class="ghost" @click="showSessionsModal = false">取消</button>
          <button class="primary" @click="submitAddSessions">確認加購</button>
        </div>
      </div>
    </div>
    <!-- Grade Promotion Modal -->
    <div v-if="showGradePromotion" class="modal-overlay" @click.self="showGradePromotion = false">
      <div class="modal" style="width: 500px;">
        <h3>🎓 年級升級</h3>
        <p class="hint">一鍵將所有在學中的學生年級 +1（例如 J1 → J2）。H3 學生會被標記為已畢業。</p>
        <div v-if="promotionPreview.length > 0" style="max-height: 300px; overflow-y: auto; margin: 16px 0;">
          <table class="course-inner-table">
            <thead>
              <tr>
                <th>姓名</th>
                <th>目前年級</th>
                <th>升級後</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in promotionPreview" :key="p.id">
                <td>{{ p.name }}</td>
                <td>{{ getGradeLabel(p.from) }}</td>
                <td>
                  <strong :class="{ 'text-red': p.graduated }">
                    {{ p.graduated ? '畢業' : getGradeLabel(p.to) }}
                  </strong>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="empty-text">沒有在學中的學生</div>
        <div class="actions">
          <button class="ghost" @click="showGradePromotion = false">取消</button>
          <button class="primary" @click="executeGradePromotion" :disabled="promotionPreview.length === 0">確認升級</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, watch, computed } from 'vue';
import { supabase } from '../supabase';
import { GRADES, SUBJECTS } from '../lib/constants';
import HelpGuide from '../components/HelpGuide.vue';

const props = defineProps({ branchId: [String, Number] });

// --- State ---
const students = ref([]);
const studentCourses = ref({}); // { studentId: [courses] }
const teachers = ref([]);
const expandedId = ref(null);
const filters = ref({ name: '', grade: '', status: 'active' });

// Student modal
const showStudentModal = ref(false);
const editingStudentId = ref(null);
const studentForm = ref({ name: '', grade: 'J1', phone: '', school: '', parent_name: '', parent_phone: '', status: 'active', notes: '' });

// Course modal
const showCourseModal = ref(false);
const editingCourseId = ref(null);
const editingCourseFromLaravel = ref(false);
const selectedStudent = ref(null);
const courseForm = ref({
  subject: 'Math',
  teacher_id: '',
  class_type: 'one_on_one',
  rate_per_30min: 500,
  duration_hours: 2,
  payment_type: 'session',
  sessions_purchased: 8,
  settlement_day: null,
  monthly_sessions: null,
  day_of_week: 0,
  days_of_week: [],
  start_time: '16:00',
  end_time: '18:00',
  first_class_date: '',
  room_id: null,
  memo: ''
});
const rooms = ref([]);

const dayOptions = [
  { value: 1, label: '一' }, { value: 2, label: '二' }, { value: 3, label: '三' },
  { value: 4, label: '四' }, { value: 5, label: '五' }, { value: 6, label: '六' }, { value: 7, label: '日' }
];
const settlementDayOptions = Array.from({ length: 28 }, (_, i) => i + 1);
const TIME_OPTIONS_30 = (() => {
  const opts = [];
  for (let h = 7; h <= 22; h++) {
    opts.push(`${String(h).padStart(2, '0')}:00`);
    if (h < 22) opts.push(`${String(h).padStart(2, '0')}:30`);
  }
  return opts;
})();
function computeEndTime(startTime, durationHours) {
  if (!startTime || durationHours == null) return '';
  const [h, m] = startTime.split(':').map(Number);
  const totalMins = (h * 60 + (m || 0)) + durationHours * 60;
  const endH = Math.floor(totalMins / 60) % 24;
  const endM = totalMins % 60;
  return `${String(endH).padStart(2, '0')}:${String(endM).padStart(2, '0')}`;
}
function normalizeTo30Min(timeStr) {
  if (!timeStr) return '16:00';
  const [h, m] = timeStr.split(':').map(Number);
  const totalMins = h * 60 + (m || 0);
  const rounded = Math.round(totalMins / 30) * 30;
  const nh = Math.floor(rounded / 60) % 24;
  const nm = rounded % 60;
  return `${String(nh).padStart(2, '0')}:${String(nm).padStart(2, '0')}`;
}

// Grade promotion
const showGradePromotion = ref(false);

// Sessions modal
const showSessionsModal = ref(false);
const addSessionCount = ref(8);
const selectedCourse = ref(null);

// --- Helpers ---
const getGradeLabel = (val) => GRADES.find(g => g.value === val)?.label || val;
const getSubjectLabel = (val) => SUBJECTS.find(s => s.value === val)?.label || val;
const ratePer2hDisplay = (c) => ((c && c.rate_per_30min != null) ? c.rate_per_30min * 4 : 0);
const ratePer2hCourse = computed({
  get: () => (courseForm.value?.rate_per_30min ?? 0) * 4,
  set: (v) => { if (courseForm.value) courseForm.value.rate_per_30min = Math.max(0, Math.round((Number(v) || 0) / 4)); }
});
function syncRatePer2hBeforeSubmitCourse() {
  const v = ratePer2hCourse.value;
  if (courseForm.value && v >= 0) courseForm.value.rate_per_30min = Math.round(v / 4) || 0;
}
const classTypeLabel = (type) => {
  const map = { one_on_one: '一對一', one_on_two: '一對二', one_on_three: '一對三', tutoring: '輔導' };
  return map[type] || type;
};
const dayLabel = (d) => {
  const days = ['', '週一', '週二', '週三', '週四', '週五', '週六', '週日'];
  return days[d] || '';
};

const getStudentCourses = (id) => studentCourses.value[id] || [];
const getStudentCourseCount = (id) => (studentCourses.value[id] || []).length;
const studentStatusLabel = (s) => ({ active: '在學中', graduated: '已畢業', paused: '暫停中', transferred: '已轉校' }[s] || s);

// Grade promotion logic
const GRADE_ORDER = ['P1','P2','P3','P4','P5','P6','J1','J2','J3','H1','H2','H3'];
const nextGrade = (g) => {
  const idx = GRADE_ORDER.indexOf(g);
  if (idx < 0 || idx >= GRADE_ORDER.length - 1) return null;
  return GRADE_ORDER[idx + 1];
};

const promotionPreview = computed(() => {
  return students.value
    .filter(s => s.status === 'active' || !s.status)
    .map(s => {
      const ng = nextGrade(s.grade);
      return { id: s.id, name: s.name, from: s.grade, to: ng, graduated: !ng };
    });
});

const executeGradePromotion = async () => {
  if (!confirm(`確定將 ${promotionPreview.value.length} 位學生年級升級？`)) return;
  for (const p of promotionPreview.value) {
    if (p.graduated) {
      // H3 -> graduated
      await supabase.from('students').update({ status: 'graduated' }).eq('id', p.id);
      // Deactivate their courses
      await supabase.from('student-classes').update({ status: 'inactive' }).eq('student_id', p.id);
    } else {
      await supabase.from('students').update({ grade: p.to }).eq('id', p.id);
    }
  }
  showGradePromotion.value = false;
  alert('升級完成！');
  loadStudents();
};

// --- Data Loading ---
const loadStudents = async () => {
  if (!props.branchId) return;
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (token) {
      const params = new URLSearchParams({
        branch_id: String(props.branchId),
        per_page: '1000'
      });
      if (filters.value.name) params.set('name', filters.value.name);
      if (filters.value.status) params.set('status', filters.value.status || '');
      const gradeToClassId = { P1:1,P2:2,P3:3,P4:4,P5:5,P6:6,J1:7,J2:8,J3:9,H1:10,H2:11,H3:12 };
      if (filters.value.grade && gradeToClassId[filters.value.grade]) params.set('class_id', gradeToClassId[filters.value.grade]);
      const res = await fetch(`/api/v1/students?${params}`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      if (res.ok) {
        const json = await res.json();
        const laravelList = json?.data ?? json;
        const arr = Array.isArray(laravelList) ? laravelList : (laravelList?.data || []);
        students.value = arr.map(s => ({
          ...s,
          rfid: s.rfid ?? s.RFID ?? '',
          _laravelId: s.id
        }));
        return;
      }
    }
  } catch (_) {}

  // Fallback: Supabase list + merge Laravel RFID / _laravelId
  let query = supabase.from('students').select('*').eq('branch_id', props.branchId).order('name');
  if (filters.value.name) query = query.ilike('name', `%${filters.value.name}%`);
  if (filters.value.grade) query = query.eq('grade', filters.value.grade);
  if (filters.value.status) query = query.eq('status', filters.value.status);
  const { data } = await query;
  let list = data || [];
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (token) {
      const res = await fetch(`/api/v1/students?branch_id=${props.branchId}&per_page=1000`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      if (res.ok) {
        const json = await res.json();
        const laravelList = json?.data ?? json;
        const arr = Array.isArray(laravelList) ? laravelList : (laravelList?.data || []);
        const rfidMap = {};
        const laravelIdMap = {};
        arr.forEach(s => {
          const key = `${(s.name || '').trim()}_${props.branchId}`;
          if (s.RFID) rfidMap[key] = s.RFID;
          if (s.id) laravelIdMap[key] = s.id;
        });
        list = list.map(st => {
          const key = `${(st.name || '').trim()}_${props.branchId}`;
          return {
            ...st,
            rfid: st.rfid || rfidMap[key] || '',
            _laravelId: laravelIdMap[key]
          };
        });
      }
    }
  } catch (_) {}
  students.value = list;
};

const loadTeachers = async () => {
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { teachers.value = []; return; }
    const res = await fetch('/api/v1/teachers?per_page=all', {
      headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${token}` }
    });
    const data = await res.json().catch(() => ({}));
    const list = Array.isArray(data) ? data : (data?.data ?? []);
    teachers.value = list.map(t => ({ id: t.id, username: t.username }));
  } catch (_) {
    teachers.value = [];
  }
};

const loadStudentCourses = async (studentId) => {
  const student = students.value.find(s => s.id === studentId);
  const laravelId = student?._laravelId ?? studentId;
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (token) {
      const res = await fetch(`/api/v1/student-classes?student_id=${laravelId}&per_page=100`, {
        credentials: 'include',
        headers: { 'Authorization': `Bearer ${token}` }
      });
      if (res.ok) {
        const json = await res.json();
        const list = json?.data ?? json;
        const arr = Array.isArray(list) ? list : (list?.data ?? []);
        const courses = arr.map(c => ({
          id: c.id,
          student_id: studentId,
          subject: c.subject,
          teacher_id: c.teacher_id,
          teacher_name: c.teacher_name,
          class_type: c.class_type,
          rate_per_30min: c.rate_per_30min,
          duration_hours: c.duration_hours,
          payment_type: c.payment_type,
          sessions_purchased: c.sessions_purchased,
          remaining_sessions: c.remaining_sessions,
          start_time: c.start_time,
          end_time: c.end_time,
          days_of_week: c.days_of_week,
          day_of_week: c.day_of_week,
          first_class_date: c.first_class_date,
          branch_id: c.branch_id,
          branch_name: c.branch_name,
          room_name: c.room_name,
          room_id: c.room_id,
          settlement_day: c.settlement_day,
          monthly_sessions: c.monthly_sessions,
          memo: c.Memo
        }));
        studentCourses.value = { ...studentCourses.value, [studentId]: courses };
        return;
      }
    }
  } catch (_) {}
  const { data } = await supabase
    .from('student-classes')
    .select('*, teacher:profiles(username)')
    .eq('student_id', studentId);

  const courses = (data || []).map(c => ({
    ...c,
    teacher_name: c.teacher?.username || ''
  }));
  studentCourses.value = { ...studentCourses.value, [studentId]: courses };
};

const loadAllStudentCourses = async () => {
  if (!props.branchId) return;
  const { data } = await supabase
    .from('student-classes')
    .select('*, teacher:profiles(username)')
    .eq('branch_id', props.branchId);

  const map = {};
  (data || []).forEach(c => {
    const sid = c.student_id;
    if (!map[sid]) map[sid] = [];
    map[sid].push({ ...c, teacher_name: c.teacher?.username || '' });
  });
  studentCourses.value = map;
};

const debouncedLoad = () => setTimeout(loadStudents, 300);

// --- Expand ---
const toggleExpand = async (student) => {
  if (expandedId.value === student.id) {
    expandedId.value = null;
  } else {
    expandedId.value = student.id;
    await loadStudentCourses(student.id);
  }
};

// --- Student CRUD ---
const openAddStudent = () => {
  editingStudentId.value = null;
  studentForm.value = { name: '', grade: 'J1', phone: '', school: '', parent_name: '', parent_phone: '', status: 'active', notes: '', rfid: '' };
  showStudentModal.value = true;
};

const editStudent = (student) => {
  editingStudentId.value = student.id;
  studentForm.value = {
    name: student.name,
    grade: student.grade,
    phone: student.phone || '',
    school: student.school || '',
    parent_name: student.parent_name || '',
    parent_phone: student.parent_phone || '',
    status: student.status || 'active',
    notes: student.notes || '',
    rfid: student.rfid || ''
  };
  showStudentModal.value = true;
};

const closeStudentModal = () => {
  showStudentModal.value = false;
  editingStudentId.value = null;
};

const bindRfidFromTemp = async () => {
  if (!props.branchId) { alert('請先選擇分校'); return; }
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { alert('請重新登入'); return; }
    const res = await fetch(`/api/v1/temp-rfid?campus_id=${props.branchId}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    const json = await res.json();
    if (json?.data?.rfid) {
      studentForm.value.rfid = json.data.rfid;
    } else {
      alert('暫無刷卡資料，請先刷卡後 5 分鐘內點擊綁定');
    }
  } catch (e) {
    alert('取得暫存 RFID 失敗');
  }
};

const submitStudent = async () => {
  if (!studentForm.value.name) { alert('請輸入姓名'); return; }
  if (!editingStudentId.value && !props.branchId) {
    alert('請先在上方「切換分校」選擇要新增學生的分校');
    return;
  }
  const payload = {
    name: studentForm.value.name,
    grade: studentForm.value.grade,
    phone: studentForm.value.phone,
    school: studentForm.value.school,
    parent_name: studentForm.value.parent_name,
    parent_phone: studentForm.value.parent_phone,
    notes: studentForm.value.notes
  };
  if (studentForm.value.rfid) payload.rfid = studentForm.value.rfid;
  if (editingStudentId.value) {
    payload.status = studentForm.value.status;
    const st = students.value.find(s => s.id === editingStudentId.value);
    const laravelId = st?._laravelId ?? st?.id;
    if (laravelId) {
      try {
        const { data: { session: sess } } = await supabase.auth.getSession();
        const token = sess?.access_token;
        if (token) {
          const res = await fetch(`/api/v1/students/${laravelId}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
            body: JSON.stringify({ ...payload, branch_id: props.branchId })
          });
          if (res.ok) {
            if (payload.rfid) {
              await fetch(`/api/v1/students/${laravelId}/bind-card`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
                body: JSON.stringify({ rfid: payload.rfid })
              });
            }
            closeStudentModal();
            loadStudents();
            loadAllStudentCourses();
            return;
          }
        }
      } catch (_) {}
    }
    await supabase.from('students').update(payload).eq('id', editingStudentId.value);
    if (payload.rfid && laravelId) {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (token) {
        await fetch(`/api/v1/students/${laravelId}/bind-card`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
          body: JSON.stringify({ rfid: payload.rfid })
        });
      }
    }
    if (payload.status !== 'active') {
      await supabase.from('student-classes').update({ status: 'inactive' }).eq('student_id', editingStudentId.value);
    }
  } else {
    // 新增：優先呼叫 Laravel API，成功後列表會從 Laravel 載入並顯示
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (!token) {
        alert('無法新增：請重新登入後再試');
        return;
      }
      const body = {
        branch_id: Number(props.branchId),
        name: payload.name,
        grade: payload.grade,
        phone: payload.phone || '',
        school: payload.school || '',
        parent_name: payload.parent_name || '',
        parent_phone: payload.parent_phone || '',
        notes: payload.notes || '',
        status: 'active'
      };
      if (payload.rfid) body.rfid = payload.rfid;
      const res = await fetch('/api/v1/students', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify(body)
      });
      if (res.ok) {
        closeStudentModal();
        loadStudents();
        loadAllStudentCourses();
        return;
      }
      const err = await res.json().catch(() => ({}));
      const msg = err?.message || (err?.errors ? Object.values(err.errors || {}).flat().join(' ') : null) || '新增學生失敗，請稍後再試';
      alert(msg);
      return;
    } catch (e) {
      console.warn('Laravel create student failed', e);
      alert('連線失敗：' + (e?.message || '請檢查網路或稍後再試'));
      return;
    }
  }
  closeStudentModal();
  loadStudents();
  loadAllStudentCourses();
};

// --- Course CRUD ---
const loadRoomsForBranch = async () => {
  if (!props.branchId) { rooms.value = []; return; }
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    const headers = { 'Accept': 'application/json' };
    if (token) headers['Authorization'] = `Bearer ${token}`;
    const res = await fetch(`/api/v1/rooms?branch_id=${props.branchId}`, { credentials: 'include', headers });
    if (res.ok) {
      const data = await res.json();
      rooms.value = Array.isArray(data) ? data : [];
    } else {
      rooms.value = [];
    }
  } catch {
    rooms.value = [];
  }
};

const openAddCourse = (student) => {
  selectedStudent.value = student;
  editingCourseId.value = null;
  editingCourseFromLaravel.value = false;
  const today = new Date().toISOString().slice(0, 10);
  courseForm.value = {
    subject: 'Math', teacher_id: '', class_type: 'one_on_one',
    rate_per_30min: 500, duration_hours: 2, payment_type: 'session',
    sessions_purchased: 8, settlement_day: null, monthly_sessions: null,
    day_of_week: 0, days_of_week: [], start_time: '16:00', end_time: '18:00',
    first_class_date: today, room_id: null, memo: ''
  };
  loadRoomsForBranch();
  showCourseModal.value = true;
};

const editCourse = (course) => {
  selectedStudent.value = students.value.find(s => s.id === course.student_id);
  editingCourseId.value = course.id;
  editingCourseFromLaravel.value = !!(course.branch_name != null || course.room_name != null);
  courseForm.value = {
    subject: course.subject,
    teacher_id: course.teacher_id || '',
    class_type: course.class_type,
    rate_per_30min: course.rate_per_30min,
    duration_hours: course.duration_hours,
    payment_type: course.payment_type || 'session',
    sessions_purchased: course.sessions_purchased || 8,
    settlement_day: course.settlement_day ?? null,
    monthly_sessions: course.monthly_sessions ?? null,
    day_of_week: course.day_of_week || 0,
    days_of_week: Array.isArray(course.days_of_week) ? course.days_of_week : (course.day_of_week ? [course.day_of_week] : []),
    start_time: normalizeTo30Min(course.start_time || '16:00'),
    end_time: course.end_time || '18:00',
    first_class_date: course.first_class_date || '',
    room_id: course.room_id ?? null,
    memo: course.memo || ''
  };
  loadRoomsForBranch();
  showCourseModal.value = true;
};

const closeCourseModal = () => {
  showCourseModal.value = false;
  editingCourseId.value = null;
  editingCourseFromLaravel.value = false;
};

const submitCourse = async () => {
  syncRatePer2hBeforeSubmitCourse();
  const form = courseForm.value;
  const student = selectedStudent.value;
  const laravelStudentId = student._laravelId ?? student.id;

  if (!editingCourseId.value) {
    if (!form.first_class_date) {
      alert('請選擇開課日');
      return;
    }
    if (form.payment_type === 'monthly' && (form.settlement_day == null || form.settlement_day < 1 || form.settlement_day > 28)) {
      alert('月結制度請選擇結算日（每月 1–28 號）');
      return;
    }
    if (!form.days_of_week || form.days_of_week.length === 0) {
      alert('請至少選擇一天固定排課');
      return;
    }
    if (!form.teacher_id) {
      alert('請選擇老師');
      return;
    }
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (!token) {
        alert('請重新登入後再試');
        return;
      }
      const body = {
        student_id: laravelStudentId,
        subject: form.subject,
        teacher_id: form.teacher_id,
        class_type: form.class_type,
        rate_per_30min: form.rate_per_30min,
        duration_hours: form.duration_hours,
        payment_type: form.payment_type,
        sessions_purchased: form.payment_type === 'session' ? (form.sessions_purchased || 8) : 0,
        first_class_date: form.first_class_date,
        days_of_week: form.days_of_week,
        start_time: form.start_time,
        room_id: form.room_id || null,
        settlement_day: form.payment_type === 'monthly' ? form.settlement_day : null,
        monthly_sessions: form.payment_type === 'monthly' ? (form.monthly_sessions || null) : null,
        Memo: form.memo || null
      };
      const res = await fetch('/api/v1/student-classes', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify(body)
      });
      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        const msg = err?.message || (err?.errors ? Object.values(err.errors || {}).flat().join(' ') : null) || '新增課程失敗';
        alert(msg);
        return;
      }
      const created = await res.json();
      closeCourseModal();
      await loadStudentCourses(student.id);
      await loadAllStudentCourses();
      return;
    } catch (e) {
      alert('連線失敗：' + (e?.message || '請稍後再試'));
      return;
    }
  }

  if (editingCourseFromLaravel.value) {
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (token) {
        const body = {
          subject: form.subject,
          teacher_id: form.teacher_id || null,
          class_type: form.class_type,
          rate_per_30min: form.rate_per_30min,
          duration_hours: form.duration_hours,
          payment_type: form.payment_type,
          sessions_purchased: form.sessions_purchased,
          days_of_week: form.days_of_week?.length ? form.days_of_week : (form.day_of_week ? [form.day_of_week] : []),
          start_time: form.start_time,
          end_time: computeEndTime(form.start_time, form.duration_hours),
          room_id: form.room_id || null,
          settlement_day: form.payment_type === 'monthly' ? form.settlement_day : null,
          monthly_sessions: form.payment_type === 'monthly' ? form.monthly_sessions : null,
          Memo: form.memo || null
        };
        const res = await fetch(`/api/v1/student-classes/${editingCourseId.value}`, {
          method: 'PUT',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
          body: JSON.stringify(body)
        });
        if (res.ok) {
          closeCourseModal();
          await loadStudentCourses(student.id);
          await loadAllStudentCourses();
          return;
        }
      }
    } catch (_) {}
  }
  const base = {
    student_id: student.id,
    branch_id: props.branchId,
    subject: form.subject,
    teacher_id: form.teacher_id || null,
    class_type: form.class_type,
    rate_per_30min: form.rate_per_30min,
    duration_hours: form.duration_hours,
    payment_type: form.payment_type,
    sessions_purchased: form.sessions_purchased,
    start_time: form.start_time,
    end_time: computeEndTime(form.start_time, form.duration_hours)
  };
  base.day_of_week = form.day_of_week;
  await supabase.from('student-classes').update(base).eq('id', editingCourseId.value);
  closeCourseModal();
  await loadAllStudentCourses();
};

const deleteCourse = async (course) => {
  if (!confirm('確定刪除此課程設定？')) return;
  if (course.branch_name != null || course.room_name != null) {
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (token) {
        const res = await fetch(`/api/v1/student-classes/${course.id}`, {
          method: 'DELETE',
          credentials: 'include',
          headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
          const sid = selectedStudent.value?.id ?? Object.keys(studentCourses.value).find(sid => (studentCourses.value[sid] || []).some(c => c.id === course.id));
          if (sid) await loadStudentCourses(sid);
          await loadAllStudentCourses();
          return;
        }
      }
    } catch (_) {}
  }
  await supabase.from('student-classes').delete().eq('id', course.id);
  await loadAllStudentCourses();
};

// --- Add Sessions (per-course) ---
const openAddSessionsForCourse = (course) => {
  selectedStudent.value = students.value.find(s => s.id === course.student_id);
  selectedCourse.value = course;
  addSessionCount.value = 8;
  showSessionsModal.value = true;
};

const submitAddSessions = async () => {
  if (addSessionCount.value <= 0 || !selectedCourse.value) return;
  const newRemaining = (selectedCourse.value.remaining_sessions ?? 0) + addSessionCount.value;
  const newPurchased = (selectedCourse.value.sessions_purchased ?? 0) + addSessionCount.value;
  await supabase.from('student-classes').update({
    remaining_sessions: newRemaining,
    sessions_purchased: newPurchased
  }).eq('id', selectedCourse.value.id);
  showSessionsModal.value = false;
  await loadAllStudentCourses();
};

// --- Toggle Payment Status ---
const togglePaymentStatus = async (course) => {
  const newStatus = course.payment_status === 'paid' ? 'unpaid' : 'paid';
  await supabase.from('student-classes').update({ payment_status: newStatus }).eq('id', course.id);
  await loadAllStudentCourses();
};

// --- CSV Import ---
const importStudents = async (event) => {
  const file = event.target.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = async (e) => {
    const rows = e.target.result.split('\n');
    const newStudents = [];
    for (const row of rows) {
      const r = row.trim();
      if (!r) continue;
      const cols = r.split(',');
      if (cols[0] === 'Name' || cols[0] === '姓名') continue;
      if (cols.length >= 1) {
        newStudents.push({
          branch_id: props.branchId,
          name: cols[0].trim(),
          phone: cols[1]?.trim() || '',
          grade: cols[2]?.trim() || 'J1',
          remaining_lessons: 0
        });
      }
    }
    if (newStudents.length > 0) {
      const { error } = await supabase.from('students').insert(newStudents);
      if (error) alert('匯入失敗: ' + error.message);
      else { alert(`成功匯入 ${newStudents.length} 筆`); loadStudents(); }
    }
  };
  reader.readAsText(file);
};

watch(() => props.branchId, () => { loadStudents(); loadTeachers(); loadAllStudentCourses(); });
onMounted(() => { loadStudents(); loadTeachers(); loadAllStudentCourses(); });
</script>

<style scoped>
.text-secondary { color: var(--text-light); font-size: 0.9rem; }
.computed-end-time { margin: 0; font-weight: 600; font-size: 1rem; }
.header-actions {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 20px;
}

.header-buttons {
  display: flex;
  gap: 8px;
  align-items: center;
}

.button-outline {
  border: 1px solid var(--border);
  padding: 8px 14px;
  border-radius: 8px;
  cursor: pointer;
  background: var(--card-bg);
  font-size: 13px;
  display: flex;
  align-items: center;
  transition: var(--transition);
}

.button-outline:hover {
  background: var(--bg);
  border-color: var(--accent);
}

.filter-bar {
  margin-bottom: 20px;
  background: #FAFAFA;
  padding: 16px;
  border-radius: 8px;
  border: 1px solid var(--border);
}

.student-row {
  cursor: pointer;
  transition: var(--transition);
}

.student-row:hover td {
  background: #FFF8E1 !important;
}

.student-row.expanded td {
  background: #FFF3E0;
  border-bottom-color: var(--accent);
}

.expand-icon {
  font-size: 10px;
  color: var(--text-light);
  text-align: center;
}

.text-red {
  color: var(--danger) !important;
}

.subject-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.subject-pill {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 11px;
  background: #E8F5E9;
  color: #2E7D32;
  white-space: nowrap;
}

.subject-pill strong {
  font-weight: 800;
}

.subject-pill.low {
  background: #FFEBEE;
  color: #C62828;
}

.note-icon {
  font-size: 12px;
  margin-left: 4px;
  cursor: help;
}

.student-status-badge {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 8px;
  font-size: 10px;
  margin-left: 6px;
  font-weight: 600;
}
.student-status-badge.graduated { background: #E3F2FD; color: #1565C0; }
.student-status-badge.paused { background: #FFF3E0; color: #E65100; }
.student-status-badge.transferred { background: #F3E5F5; color: #6A1B9A; }

.day-checkbox-group {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.day-chip {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border: 2px solid #E0E0E0;
  background: #FAFAFA;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  color: #616161;
  transition: all 0.2s;
  user-select: none;
}

.day-chip:hover {
  border-color: #FF9800;
  background: #FFF3E0;
}

.day-chip.selected {
  border-color: #E65100;
  background: #FF9800;
  color: #fff;
}

/* Course Detail Panel */
.course-detail-row td {
  padding: 0 !important;
  background: #FAFAFA !important;
}

.course-panel {
  padding: 20px 24px;
  border-left: 3px solid var(--accent);
  margin: 0;
}

.course-panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.course-panel-header h4 {
  font-size: 15px;
  font-weight: 700;
  color: var(--primary);
  margin: 0;
}

.course-inner-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.course-inner-table th {
  background: #F0F0F0;
  font-size: 11px;
  padding: 8px;
}

.course-inner-table td {
  padding: 10px 8px;
  border-bottom: 1px solid #EEEEEE;
}

.status-tag.one_on_one { background: #FFF3E0; color: #E65100; }
.status-tag.one_on_two { background: #FFF8E1; color: #F57F17; }
.status-tag.one_on_three { background: #FBE9E7; color: #BF360C; }
.status-tag.tutoring { background: #E8F5E9; color: #2E7D32; }

/* Form Section */
.form-section-title {
  font-size: 13px;
  font-weight: 700;
  color: var(--primary);
  margin: 16px 0 8px 0;
  padding-bottom: 4px;
  border-bottom: 1px solid var(--border);
}

.form-section-title:first-of-type {
  margin-top: 0;
}

.rfid-bind-row {
  display: flex;
  gap: 8px;
  align-items: center;
}
.rfid-bind-row input {
  flex: 1;
  padding: 8px 12px;
  border: 1px solid #ddd;
  border-radius: 6px;
}
.rfid-bind-row input[readonly] {
  background: #f5f5f5;
  color: #333;
  cursor: default;
}

.rfid-tag {
  font-size: 0.8em;
  font-family: monospace;
  color: var(--primary);
}

.required {
  color: var(--danger);
}

/* Cost Preview */
.cost-preview {
  background: linear-gradient(135deg, #FFF8E1, #FFECB3);
  border: 1px solid #FFE082;
  border-radius: 10px;
  padding: 16px;
  text-align: center;
  margin-top: 16px;
}

.cost-preview-label {
  font-size: 12px;
  color: #5D4037;
  font-weight: 600;
}

.cost-preview-value {
  font-size: 28px;
  font-weight: 800;
  color: var(--primary);
  margin: 4px 0;
}

.cost-preview-formula {
  font-size: 12px;
  color: var(--text-light);
}
</style>
