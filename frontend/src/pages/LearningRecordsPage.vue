<template>
  <div class="lr-page">
    <!-- Page Header -->
    <div class="page-header lr-header" data-guide="learning-header">
      <div>
        <h2>{{ isTeacher ? '我的課表 & 評量' : '學習評量表' }}</h2>
        <p class="page-desc">{{ isTeacher ? '查看本週課表，填寫學習評量' : '查看、新增與審核學生每堂課的學習評量' }}</p>
      </div>
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button v-if="!isTeacher" class="ghost" @click="openBulkBackfill">一鍵補登</button>
        <button class="ghost" @click="openExportModal">匯出評量圖</button>
        <button class="primary" @click="openModal()">+ 新增評量</button>
      </div>
    </div>

    <!-- ===== TEACHER: Week Schedule Widget ===== -->
    <div v-if="isTeacher" class="teacher-schedule card" data-guide="learning-teacher-schedule">
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
          <div class="ts-time">{{ ev.timeRange }}</div>
          <div class="ts-info">
            <div class="ts-student">{{ ev.studentName }}</div>
            <div class="ts-subject-row">
              <span class="ts-subject">{{ ev.subject }}</span>
              <span :class="['ts-status-chip', `status-${ev.formStatus}`]">{{ ev.formStatusLabel }}</span>
            </div>
          </div>
          <button
            class="ts-fill-btn"
            :disabled="!ev.recordId && ev.fillLocked"
            :title="!ev.recordId && ev.fillLocked ? ev.fillLockReason : ''"
            @click="openFromScheduleMaybe(ev)"
          >{{ ev.recordId ? '看評量' : (ev.fillLocked ? '未開放' : '填評量') }}</button>
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
            <span class="ts-day-name">{{ day.label }}<span v-if="day.missingCount > 0" class="ts-missing-pill">{{ day.missingCount }} 未填</span></span>
            <span class="ts-day-date">{{ day.shortDate }}</span>
          </div>
          <div v-if="day.events.length === 0" class="ts-day-empty">—</div>
          <div
            v-for="ev in day.events"
            :key="ev.key"
            class="ts-event ts-event-sm"
            :class="{ locked: !ev.recordId && ev.fillLocked }"
            @click="openFromScheduleMaybe(ev)"
          >
            <div class="ts-time">{{ ev.timeRange }}</div>
            <div class="ts-info">
              <div class="ts-student">{{ ev.studentName }}</div>
              <div class="ts-subject-row">
                <span class="ts-subject">{{ ev.subject }}</span>
                <span :class="['ts-status-chip', `status-${ev.formStatus}`]">{{ ev.formStatusLabel }}</span>
              </div>
            </div>
            <span class="ts-fill-hint">{{ ev.recordId ? '看評量' : (ev.fillLocked ? '未開放' : '填評量') }}</span>
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
          <p style="color:#666; font-size:13px; margin-bottom:12px;">選擇課程後，系統會先核准歷史堂次評量，並依固定星期自動往未來推算剩餘未排課堂次。</p>

          <div class="lr-form-grid" style="grid-template-columns:1fr 1fr; gap:12px;">
            <div class="form-group">
              <label>學生</label>
              <SearchableSelect v-model="bulkForm.studentId" :options="bulkStudentOptions" placeholder="選擇學生..." />
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
    <div class="card lr-filters" data-guide="learning-filters">
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

    <!-- ===== Records Grouped By Student ===== -->
    <div class="card lr-table-card" data-guide="learning-table">
      <div v-if="groupedRecordsByStudent.length === 0" class="empty-text" style="padding: 24px;">
        尚無評量資料
      </div>

      <div v-else class="lr-groups">
        <details
          v-for="(group, groupIndex) in groupedRecordsByStudent"
          :key="group.key"
          class="lr-group"
          :open="groupIndex === 0"
        >
          <summary class="lr-group-summary">
            <div class="lr-group-title">
              <span class="lr-group-student">{{ group.student_name }}</span>
              <span class="lr-group-count">{{ group.records.length }} 筆</span>
              <span v-if="group.pending_count > 0" class="lr-group-pending">{{ group.pending_count }} 待處理</span>
            </div>
            <span class="lr-group-hint">展開 / 收合</span>
          </summary>

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
                <tr v-for="record in group.records" :key="record.id" class="lr-table-row" @click="viewRecord(record)">
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
                    <button v-if="canChangeTeacher(record)" class="ghost xs" @click="openChangeTeacherModal(record)">換老師</button>
                    <span v-if="showTimeLockHint(record)" class="lr-lock-hint">課程結束後開放填寫</span>
                    <button v-if="canApprove(record)" class="primary xs" @click="approveRecord(record)">核准</button>
                    <button v-if="canReject(record)" class="danger xs" @click="rejectRecord(record)">退回</button>
                    <button v-if="canRollbackApproval(record)" class="ghost xs" @click="rollbackApproval(record)">退回待審</button>
                    <button v-if="canDelete(record)" class="danger xs" @click="deleteRecord(record)">刪除</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </details>
      </div>
    </div>

    <div v-if="showChangeTeacherModal" class="modal-overlay" @click.self="closeChangeTeacherModal">
      <div class="modal lr-modal" style="max-width: 520px;">
        <div class="lr-modal-header">
          <h3>更換授課老師</h3>
          <button class="lr-modal-close" @click="closeChangeTeacherModal">&times;</button>
        </div>

        <div class="lr-form">
          <div class="lr-form-section">
            <div class="lr-form-grid">
              <div class="form-group">
                <label>學生</label>
                <input :value="teacherChangeForm.student_name" type="text" disabled>
              </div>
              <div class="form-group">
                <label>上課日期</label>
                <input :value="teacherChangeForm.session_date" type="text" disabled>
              </div>
              <div class="form-group">
                <label>目前老師</label>
                <input :value="teacherChangeForm.current_teacher_name" type="text" disabled>
              </div>
              <div class="form-group">
                <label>新老師 *</label>
                <SearchableSelect
                  v-model="teacherChangeForm.teacher_id"
                  :options="teacherOptions"
                  placeholder="搜尋並選擇老師..."
                />
              </div>
              <div class="form-group" style="grid-column: 1 / -1;">
                <label>調整原因</label>
                <textarea v-model="teacherChangeForm.reason" rows="3" placeholder="例如：3/18 由王老師代課"></textarea>
              </div>
            </div>
          </div>

          <div class="lr-form-actions">
            <button type="button" class="ghost" @click="closeChangeTeacherModal">取消</button>
            <button type="button" class="primary" :disabled="teacherChangeSubmitting" @click="submitTeacherChange">
              {{ teacherChangeSubmitting ? '更新中...' : '確認更換老師' }}
            </button>
          </div>
        </div>
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
                <input v-model="form.SessionDate" type="date" :disabled="isReadOnly || isSessionDateLocked">
              </div>
              <template v-if="isSessionTimeLocked">
                <div class="form-group">
                  <label>開始時間</label>
                  <div class="lr-readonly-time" :title="formatTimeForDisplay(form.StartTime)">
                    {{ formatTimeForDisplay(form.StartTime) }}
                  </div>
                </div>
                <div class="form-group">
                  <label>結束時間</label>
                  <div class="lr-readonly-time" :title="formatTimeForDisplay(form.EndTime)">
                    {{ formatTimeForDisplay(form.EndTime) }}
                  </div>
                </div>
              </template>
              <template v-else>
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
              </template>
              <div v-if="isSessionTimeLocked" class="form-group" style="grid-column: 1 / -1;">
                <p class="lr-time-lock-note" style="margin-top: 0;">
                  上課時間已依課程／排課堂次帶入，無法手動更改。
                </p>
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
              <input
                v-model="form.QuizScore"
                type="text"
                :disabled="isReadOnly"
                maxlength="32"
                placeholder="可填分數或文字（例：92、缺考、待補考）"
              >
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
          <div v-if="timeLockMessage && !forceReadOnly" class="lr-time-lock-note">{{ timeLockMessage }}</div>
        </form>
      </div>
    </div>

    <!-- ===== Export Modal ===== -->
    <div v-if="showExportModal" class="modal-overlay" @click.self="showExportModal = false">
      <div class="lr-modal" style="max-width: 480px;">
        <div class="lr-modal-header">
          <h3>匯出學習評量圖</h3>
          <button class="ghost icon" @click="showExportModal = false">✕</button>
        </div>
        <div class="lr-form">
          <p style="color:#666; font-size:13px; margin-bottom:16px;">
            選擇日期區間後，系統會依學生分組，每位學生產出一張評量報告圖片。
          </p>
          <div class="lr-form-grid" style="grid-template-columns:1fr 1fr; gap:12px;">
            <div class="form-group">
              <label>開始日期</label>
              <input v-model="exportForm.startDate" type="date" />
            </div>
            <div class="form-group">
              <label>結束日期</label>
              <input v-model="exportForm.endDate" type="date" />
            </div>
          </div>

          <div v-if="exportForm.status === 'loading'" class="export-progress">
            <div class="export-progress-bar">
              <div class="export-progress-fill" :style="{ width: exportProgressPct + '%' }"></div>
            </div>
            <p class="export-progress-text">
              正在匯出 {{ exportForm.progressCurrent }} ({{ exportForm.progressCompleted }}/{{ exportForm.progressTotal }})…
            </p>
          </div>

          <div v-if="exportForm.status === 'done'" class="export-done">
            <p v-if="exportForm.errorNames.length === 0">全部匯出完成！共 {{ exportForm.progressCompleted }} 位學生。</p>
            <p v-else>匯出完成，但 {{ exportForm.errorNames.join('、') }} 匯出失敗。</p>
          </div>

          <div v-if="exportForm.status === 'empty'" class="export-empty">
            <p>此日期區間內沒有評量資料。</p>
          </div>

          <div class="lr-form-actions" style="margin-top:16px;">
            <button class="ghost" @click="showExportModal = false">{{ exportForm.status === 'done' ? '關閉' : '取消' }}</button>
            <button
              v-if="exportForm.status !== 'loading'"
              class="primary"
              :disabled="!exportForm.startDate || !exportForm.endDate"
              @click="executeExport"
            >
              {{ exportForm.status === 'done' ? '重新匯出' : '開始匯出' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, reactive, computed, watch, nextTick } from 'vue';
import { supabase } from '../supabase';
import SearchableSelect from '../components/SearchableSelect.vue';
import { fetchClassSessions } from '../lib/classSessionsApi';
import { exportStudentCards } from '../lib/learningRecordExport';

const props = defineProps(['branchId', 'userRole', 'userId']);

const formatLocalDate = (date) => {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
};

const localTodayYmd = () => formatLocalDate(new Date());

const dayOfWeekFromYmd = (ymd) => {
  if (!ymd) return 1;
  const d = new Date(`${ymd}T12:00:00`);
  const n = d.getDay();
  return n === 0 ? 7 : n; // 1=Mon ... 7=Sun
};

const addMinutesToTime = (timeStr, minutes) => {
  const [hRaw, mRaw] = String(timeStr || '').split(':');
  const h = Number(hRaw);
  const m = Number(mRaw);
  if (!Number.isFinite(h) || !Number.isFinite(m)) return '';
  const d = new Date(2000, 0, 1, h, m, 0, 0);
  d.setMinutes(d.getMinutes() + minutes);
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};

const isTeacher = computed(() => props.userRole === 'teacher');
const isDirectorRole = computed(() => ['director', 'admin', 'super_admin'].includes(String(props.userRole || '')));

const records = ref([]);
const showModal = ref(false);
const isEditing = ref(false);
const showChangeTeacherModal = ref(false);
const teacherChangeSubmitting = ref(false);
const teacherList = ref([]);
const studentList = ref([]);
const courseList = ref([]);
const teacherClassList = ref([]);  // teacher's own StudentClasses for schedule
const sessionDatesByClassId = ref({});
/** Director: class-sessions keyed by student_class id (for form time binding). */
const directorSessionsByClassId = ref({});
/** Director 新增：時間已由課程／堂次帶入，與 ClassSessionID>0 一併鎖定。 */
const formTimesFromBinding = ref(false);
const filters = reactive({ status: '', student_name: '', teacher_id: '' });

const groupedRecordsByStudent = computed(() => {
  const groups = new Map();
  for (const record of records.value || []) {
    const studentId = Number(record?.student_id || 0) || null;
    const studentName = String(record?.student_name || '').trim() || '未命名學生';
    const key = studentId ? `student-${studentId}` : `name-${studentName}`;
    if (!groups.has(key)) {
      groups.set(key, {
        key,
        student_id: studentId,
        student_name: studentName,
        pending_count: 0,
        records: [],
      });
    }
    const group = groups.get(key);
    group.records.push(record);
    if (record?.Status === 'pending' || record?.Status === 'changes_requested') {
      group.pending_count += 1;
    }
  }

  const collator = new Intl.Collator('zh-Hant');
  return Array.from(groups.values())
    .map((group) => {
      group.records.sort((a, b) => {
        const aDate = String(a?.SessionDate || '');
        const bDate = String(b?.SessionDate || '');
        if (aDate !== bDate) return bDate.localeCompare(aDate);
        return String(b?.StartTime || '').localeCompare(String(a?.StartTime || ''));
      });
      return group;
    })
    .sort((a, b) => collator.compare(a.student_name, b.student_name));
});

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
  SessionDate: localTodayYmd(),
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
const teacherChangeForm = reactive({
  record_id: null,
  teacher_id: '',
  reason: '',
  student_name: '',
  current_teacher_name: '',
  session_date: '',
});

const isSessionEnded = (sessionDate, endTime) => {
  const date = String(sessionDate || '').slice(0, 10);
  const time = normalizeTime(endTime);
  if (!date || !time) return true;
  const endAt = new Date(`${date}T${time}:00`);
  if (Number.isNaN(endAt.getTime())) return true;
  return Date.now() > endAt.getTime();
};

const resolveTimeLockMessage = (sessionDate, endTime) => (
  isSessionEnded(sessionDate, endTime) ? '' : '課程結束後開放填寫'
);

const timeLockMessage = computed(() => resolveTimeLockMessage(form.SessionDate, form.EndTime));
/** 上課日期：有綁定堂次時鎖定；主任僅依範本帶入時間時仍可改日期以重算。 */
const isSessionDateLocked = computed(() => isTeacher.value || Number(form.ClassSessionID || 0) > 0);
/** 開始／結束時間：老師一律鎖定；有 ClassSessionID 或主任已成功帶入課程時間則鎖定。 */
const isSessionTimeLocked = computed(() => {
  if (isTeacher.value) return true;
  if (Number(form.ClassSessionID || 0) > 0) return true;
  if (isDirectorRole.value && formTimesFromBinding.value) return true;
  return false;
});

const isReadOnly = computed(() => {
  if (forceReadOnly.value) return true;
  if (timeLockMessage.value) return true;
  if (form.Status === 'approved') {
    if (isDirectorRole.value) return false;
    return true;
  }
  return false;
});

const teacherOptions = computed(() =>
  (Array.isArray(teacherList.value) ? teacherList.value : []).map(t => ({ value: t.id, label: t.username || t.T_Name || t.Name || '?' }))
);

const studentOptions = computed(() =>
  (Array.isArray(studentList.value) ? studentList.value : []).map(s => {
    const contact = s.phone || s.parent_phone || '';
    return {
      value: s.id,
      label: contact ? `${s.name} (${contact})` : s.name
    };
  })
);

const bulkStudentOptions = computed(() => {
  const students = Array.isArray(studentList.value) ? studentList.value : [];
  const courses = Array.isArray(courseList.value) ? courseList.value : [];
  const courseStudentIds = new Set(courses.map(c => String(c.student_id)).filter(Boolean));
  const withCourses = courseStudentIds.size > 0
    ? students.filter(s => courseStudentIds.has(String(s.id)))
    : students;

  // Deduplicate exact same id first.
  const byId = new Map();
  for (const s of withCourses) {
    byId.set(String(s.id), s);
  }
  const dedupById = Array.from(byId.values());

  // If same name appears twice and only one has contact, keep the one with contact.
  const groups = new Map();
  for (const s of dedupById) {
    const key = String(s.name || '').trim();
    if (!key) continue;
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(s);
  }

  const keptIds = new Set();
  for (const group of groups.values()) {
    if (group.length <= 1) {
      keptIds.add(String(group[0].id));
      continue;
    }
    const withContact = group.filter(s => Boolean((s.phone || s.parent_phone || '').trim()));
    const withoutContact = group.filter(s => !Boolean((s.phone || s.parent_phone || '').trim()));
    if (withContact.length === 1 && withoutContact.length >= 1) {
      keptIds.add(String(withContact[0].id));
    } else {
      group.forEach(s => keptIds.add(String(s.id)));
    }
  }

  return dedupById
    .filter(s => keptIds.has(String(s.id)))
    .map(s => {
      const contact = s.phone || s.parent_phone || '';
      return {
        value: s.id,
        label: contact ? `${s.name} (${contact})` : s.name
      };
    });
});

const upsertRecordInList = (incoming) => {
  if (!incoming || !incoming.id) return;
  const normalized = {
    ...incoming,
    id: Number(incoming.id),
    student_id: Number(incoming.student_id || incoming.StudentID || 0) || null,
    student_name: incoming.student_name || studentList.value.find((s) => String(s.id) === String(incoming.student_id || incoming.StudentID || ''))?.name || '',
    teacher_name: incoming.teacher_name || teacherList.value.find((t) => String(t.id) === String(incoming.TeacherID || incoming.teacher_id || ''))?.Name || '',
    student_class_label: incoming.student_class_label || incoming.Subject || '',
  };
  const next = [...records.value];
  const idx = next.findIndex((record) => Number(record?.id || 0) === normalized.id);
  if (idx >= 0) {
    next[idx] = { ...next[idx], ...normalized };
  } else {
    next.unshift(normalized);
  }
  records.value = next;
};

const buildLocalRecordFromForm = (savedRecord = null) => {
  const studentId = Number(form.StudentID || savedRecord?.student_id || savedRecord?.StudentID || 0) || null;
  const teacherId = Number(form.TeacherID || savedRecord?.TeacherID || savedRecord?.teacher_id || 0) || null;
  const student = studentList.value.find((item) => String(item.id) === String(studentId || ''));
  const teacher = teacherList.value.find((item) => String(item.id) === String(teacherId || ''));
  return {
    ...(savedRecord || {}),
    id: Number(savedRecord?.id || form.id || 0),
    StudentID: studentId,
    student_id: studentId,
    TeacherID: teacherId,
    teacher_id: teacherId,
    ClassSessionID: Number(form.ClassSessionID || savedRecord?.ClassSessionID || 0) || 0,
    Subject: form.Subject || savedRecord?.Subject || '',
    SessionDate: form.SessionDate || savedRecord?.SessionDate || '',
    StartTime: form.StartTime || savedRecord?.StartTime || '',
    EndTime: form.EndTime || savedRecord?.EndTime || '',
    HomeworkStatus: form.HomeworkStatus ?? savedRecord?.HomeworkStatus ?? null,
    QuizScore: form.QuizScore ?? savedRecord?.QuizScore ?? '',
    Progress: form.Progress ?? savedRecord?.Progress ?? '',
    NextHomework: form.NextHomework ?? savedRecord?.NextHomework ?? '',
    Performance: form.Performance ?? savedRecord?.Performance ?? '',
    Comment: form.Comment ?? savedRecord?.Comment ?? '',
    Status: savedRecord?.Status || form.Status || 'pending',
    ReviewNote: savedRecord?.ReviewNote ?? form.ReviewNote ?? '',
    student_name: savedRecord?.student_name || student?.name || '',
    teacher_name: savedRecord?.teacher_name || teacher?.username || teacher?.T_Name || teacher?.Name || '',
    student_class_label: savedRecord?.student_class_label || savedRecord?.Subject || form.Subject || '',
  };
};

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
    const params = new URLSearchParams({ per_page: 'all', status: 'active' });
    if (props.branchId) params.set('branch_id', String(props.branchId));

    const res = await fetch(`/api/v1/teachers?${params.toString()}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (res.ok) {
      const json = await res.json();
      const rows = Array.isArray(json?.data) ? json.data : (Array.isArray(json) ? json : []);
      const currentBranchId = props.branchId != null ? String(props.branchId) : '';
      const filteredRows = rows.filter((teacher) => {
        if ((teacher?.status || 'active') !== 'active') return false;
        if (!currentBranchId) return true;
        const branchIds = Array.isArray(teacher?.branch_ids)
          ? teacher.branch_ids.map((id) => String(id))
          : [];
        if (branchIds.length > 0) return branchIds.includes(currentBranchId);
        if (teacher?.branch_id == null) return false;
        return String(teacher.branch_id) === currentBranchId;
      });
      const dedupById = new Map();
      filteredRows.forEach((teacher) => dedupById.set(String(teacher.id), teacher));
      teacherList.value = Array.from(dedupById.values());
      if (filters.teacher_id && !dedupById.has(String(filters.teacher_id))) {
        filters.teacher_id = '';
      }
    }
  } catch (e) { console.error('fetchTeachers', e); }
};

const fetchStudents = async () => {
  try {
    const token = await getToken();
    if (!token) return;
    const params = new URLSearchParams({ per_page: 'all' });
    if (props.branchId) params.set('branch_id', String(props.branchId));
    params.set('status', 'active');
    const res = await fetch(`/api/v1/students?${params.toString()}`, {
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
      const rows = json.data || json || [];
      teacherClassList.value = rows;
      await fetchTeacherSessionDates(rows);
    }
  } catch (e) { console.error('fetchTeacherClasses', e); }
};

const fetchTeacherSessionDates = async (rows = []) => {
  if (!isTeacher.value || !props.branchId) {
    sessionDatesByClassId.value = {};
    return;
  }
  try {
    const token = await getToken();
    if (!token) return;
    const classIds = (rows || [])
      .map((c) => Number(c.id || c.ID || 0))
      .filter((id) => id > 0);
    if (classIds.length === 0) {
      sessionDatesByClassId.value = {};
      return;
    }

    const { byClass } = await fetchClassSessions({
      token,
      branchId: props.branchId,
      studentClassIds: classIds,
      perPage: 2000,
    });

    sessionDatesByClassId.value = byClass || {};
  } catch (e) {
    console.error('fetchTeacherSessionDates', e);
  }
};

const fetchDirectorSessionsForCourses = async (courses) => {
  if (!isDirectorRole.value || !props.branchId) {
    directorSessionsByClassId.value = {};
    return;
  }
  const token = await getToken();
  if (!token) return;
  const classIds = (courses || [])
    .map((c) => Number(c.id || c.ID || 0))
    .filter((id) => id > 0);
  if (classIds.length === 0) {
    directorSessionsByClassId.value = {};
    return;
  }
  try {
    const { byClass } = await fetchClassSessions({
      token,
      branchId: props.branchId,
      studentClassIds: classIds,
      perPage: 2000,
    });
    directorSessionsByClassId.value = byClass || {};
  } catch (e) {
    console.error('fetchDirectorSessionsForCourses', e);
    directorSessionsByClassId.value = {};
  }
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

const scheduleStatusPriority = (status) => {
  if (status === 'approved') return 4;
  if (status === 'pending') return 3;
  if (status === 'changes_requested') return 2;
  if (status === 'rejected') return 1;
  return 0;
};

const scheduleStatusLabel = (status) => {
  if (status === 'approved') return '已審';
  if (status === 'pending') return '待審';
  if (status === 'changes_requested') return '待修改';
  if (status === 'rejected') return '已退回';
  return '未填';
};

const recordLookup = computed(() => {
  const map = new Map();
  for (const record of records.value || []) {
    const classId = Number(record.StudentClassID || 0);
    const date = String(record.SessionDate || '').slice(0, 10);
    if (!classId || !date) continue;
    const key = `${classId}|${date}`;
    const prev = map.get(key);
    if (!prev || scheduleStatusPriority(record.Status) > scheduleStatusPriority(prev.Status)) {
      map.set(key, record);
    }
  }
  return map;
});

const normalizeTime = (timeStr) => {
  const raw = String(timeStr || '').trim();
  if (!raw) return '';
  const match = raw.match(/(\d{1,2}):(\d{2})/);
  if (!match) return '';
  const h = Math.max(0, Math.min(23, Number(match[1])));
  const m = Math.max(0, Math.min(59, Number(match[2])));
  if (!Number.isFinite(h) || !Number.isFinite(m)) return '';
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
};

const canonicalSubjectLabel = (s) => {
  const t = String(s || '').trim();
  if (!t) return '';
  const map = {
    Chinese: '國文', English: '英文', Math: '數學', Mathematics: '數學',
    Social: '社會', Science: '理化', Physics: '物理', Chemistry: '化學', Biology: '生物',
    自然: '理化',
  };
  return map[t] || t;
};

const subjectsMatch = (a, b) => canonicalSubjectLabel(a) === canonicalSubjectLabel(b);

const formatTimeForDisplay = (t) => {
  const n = normalizeTime(t);
  return n || '—';
};

const resolveCourseStartTime = (course, dateStr) => {
  const targetDow = dayOfWeekFromYmd(dateStr);
  const slots = [
    [course?.week, course?.time],
    [course?.week1, course?.time1],
    [course?.week2, course?.time2],
    [course?.week3, course?.time3],
    [course?.week4, course?.time4],
    [course?.week5, course?.time5],
    [course?.week6, course?.time6],
  ];

  for (const [dowRaw, timeRaw] of slots) {
    const dow = Number(dowRaw);
    const time = normalizeTime(timeRaw);
    if (dow === targetDow && time) return time;
  }

  return normalizeTime(
    course?.start_time
      || course?.time
      || course?.time1
      || course?.time2
      || course?.time3
      || course?.time4
      || course?.time5
      || course?.time6
  );
};

const computedEndTimeForClass = (course, startTime) => {
  const start = normalizeTime(startTime || course?.start_time || course?.time || course?.time1);
  if (!start) return '';
  const durationHours = Number(course?.duration_hours || 2);
  const durationMinutes = Number.isFinite(durationHours) ? Math.round(durationHours * 60) : 120;
  return addMinutesToTime(start, durationMinutes);
};

// Build schedule events from teacher's effective session dates (same source as director flow).
const buildEvents = (targetDates) => {
  const targetSet = new Set(targetDates.map((d) => String(d).slice(0, 10)));
  const events = [];
  for (const sc of teacherClassList.value) {
    if (sc.Stop == 1) continue;
    const classId = Number(sc.id || sc.ID || 0);
    if (!classId) continue;
    const rawSessions = sessionDatesByClassId.value[String(classId)] || [];
    for (const rawSession of rawSessions) {
      const dateStr = String(rawSession?.session_date || rawSession?.SessionDate || rawSession).slice(0, 10);
      if (!targetSet.has(dateStr)) continue;
      const startTime = normalizeTime(rawSession?.start_time || rawSession?.StartTime) || resolveCourseStartTime(sc, dateStr);
      const endTime = normalizeTime(rawSession?.end_time || rawSession?.EndTime) || computedEndTimeForClass(sc, startTime);
      const record = recordLookup.value.get(`${classId}|${dateStr}`);
      const rowStatus = String(rawSession?.learning_record_status || '');
      const formStatus = rowStatus || record?.Status || 'missing';
      const recordId = rawSession?.learning_record_id != null
        ? Number(rawSession.learning_record_id)
        : (record?.id || null);
      const fillLocked = !isSessionEnded(dateStr, endTime);
      const student = studentList.value.find(s => String(s.id) === String(sc.student_id || sc.StudentID));
      const studentName = student?.name || sc.student_name || `學生#${sc.student_id || sc.StudentID}`;
      events.push({
        key: `${classId}-${dateStr}`,
        classSessionId: Number(rawSession?.id || 0) || null,
        classId,
        studentId: sc.student_id || sc.StudentID,
        studentName,
        subject: sc.subject || sc.Subject || '?',
        date: dateStr,
        startTime,
        endTime,
        timeRange: endTime ? `${startTime}~${endTime}` : startTime,
        recordId: recordId || null,
        formStatus,
        formStatusLabel: scheduleStatusLabel(formStatus),
        fillLocked,
        fillLockReason: fillLocked ? '課程結束後開放填寫' : '',
      });
    }
  }
  return events;
};

const todayStr = computed(() => localTodayYmd());

const todayEvents = computed(() => {
  const events = buildEvents([todayStr.value]);
  return events.sort((a, b) => a.startTime.localeCompare(b.startTime));
});

const weekDays = computed(() => {
  const days = [];
  const todayDate = todayStr.value;
  for (let i = 0; i < 7; i++) {
    const d = new Date(weekStart.value);
    d.setDate(weekStart.value.getDate() + i);
    const dateStr = formatLocalDate(d);
    const dayNames = ['日', '一', '二', '三', '四', '五', '六'];
    const events = buildEvents([dateStr]).sort((a, b) => a.startTime.localeCompare(b.startTime));
    days.push({
      date: dateStr,
      label: `週${dayNames[d.getDay()]}`,
      shortDate: `${d.getMonth() + 1}/${d.getDate()}`,
      isToday: dateStr === todayDate,
      events,
      missingCount: events.filter((ev) => ev.formStatus === 'missing').length,
    });
  }
  return days;
});

const findTeacherCourseForStudent = (studentId) => {
  if (!studentId) return null;
  return teacherClassList.value.find((sc) => String(sc.student_id || sc.StudentID) === String(studentId)) || null;
};

const resolveTeacherFormDefaults = ({ studentId = '', dateStr = localTodayYmd() } = {}) => {
  const targetDate = String(dateStr || localTodayYmd()).slice(0, 10);
  const dayEvents = buildEvents([targetDate]).sort((a, b) => String(a.startTime || '').localeCompare(String(b.startTime || '')));
  const matchingEvents = studentId
    ? dayEvents.filter((ev) => String(ev.studentId || '') === String(studentId))
    : dayEvents;
  const chosenEvent = matchingEvents[0] || null;
  if (chosenEvent) {
    return {
      StudentID: chosenEvent.studentId || '',
      ClassSessionID: chosenEvent.classSessionId || 0,
      Subject: chosenEvent.subject || '數學',
      SessionDate: chosenEvent.date || targetDate,
      StartTime: chosenEvent.startTime || '18:00',
      EndTime: chosenEvent.endTime || addMinutesToTime(chosenEvent.startTime || '18:00', 120),
    };
  }

  const fallbackCourse = studentId
    ? findTeacherCourseForStudent(studentId)
    : (teacherClassList.value[0] || null);
  if (!fallbackCourse) return null;

  const fallbackStartTime = resolveCourseStartTime(fallbackCourse, targetDate) || '18:00';
  const fallbackEndTime = computedEndTimeForClass(fallbackCourse, fallbackStartTime) || addMinutesToTime(fallbackStartTime, 120);
  return {
    StudentID: studentId || fallbackCourse.student_id || fallbackCourse.StudentID || '',
    ClassSessionID: 0,
    Subject: fallbackCourse.subject || fallbackCourse.Subject || '數學',
    SessionDate: targetDate,
    StartTime: fallbackStartTime,
    EndTime: fallbackEndTime,
  };
};

const applyTeacherFormDefaults = ({ studentId = '', preserveStudent = false } = {}) => {
  if (!isTeacher.value || isEditing.value) return;
  const defaults = resolveTeacherFormDefaults({
    studentId: preserveStudent ? (studentId || form.StudentID) : studentId,
    dateStr: form.SessionDate || localTodayYmd(),
  });
  if (!defaults) return;
  Object.assign(form, {
    StudentID: preserveStudent ? (defaults.StudentID || form.StudentID) : (defaults.StudentID || ''),
    ClassSessionID: defaults.ClassSessionID || 0,
    Subject: defaults.Subject || form.Subject || '數學',
    SessionDate: defaults.SessionDate || form.SessionDate || localTodayYmd(),
    StartTime: defaults.StartTime || form.StartTime || '18:00',
    EndTime: defaults.EndTime || form.EndTime || '20:00',
  });
};

/** 主任新增評量：依學生、日期、科目（與老師）對應課程與堂次，帶入並鎖定上課時間。 */
const syncFormTimesFromCourseSchedule = () => {
  if (!isDirectorRole.value || !showModal.value || isEditing.value || forceReadOnly.value) return;
  if (!form.StudentID || !form.SessionDate || !form.Subject) {
    formTimesFromBinding.value = false;
    return;
  }
  const sid = String(form.StudentID);
  const dateStr = String(form.SessionDate).slice(0, 10);

  let candidates = courseList.value.filter((c) => {
    if (String(c.student_id || c.StudentID) !== sid) return false;
    if (!subjectsMatch(c.subject || c.Subject, form.Subject)) return false;
    return true;
  });

  if (form.TeacherID) {
    const withT = candidates.filter((c) => String(c.teacher_id || c.TeacherID) === String(form.TeacherID));
    if (withT.length) candidates = withT;
  }

  const course = candidates[0];
  if (!course) {
    formTimesFromBinding.value = false;
    return;
  }

  const classId = Number(course.id || course.ID || 0);
  const sessions = directorSessionsByClassId.value[String(classId)] || [];
  const daySession = sessions.find((s) => {
    if (String(s.session_date).slice(0, 10) !== dateStr) return false;
    const st = String(s.status || '').toLowerCase();
    return st !== 'cancelled' && st !== 'leave';
  });

  if (daySession && daySession.id) {
    form.ClassSessionID = Number(daySession.id);
    form.StartTime = normalizeTime(daySession.start_time) || '18:00';
    form.EndTime = normalizeTime(daySession.end_time) || addMinutesToTime(form.StartTime, 120);
    formTimesFromBinding.value = true;
    return;
  }

  const start = resolveCourseStartTime(course, dateStr);
  const end = computedEndTimeForClass(course, start);
  if (start) {
    form.ClassSessionID = 0;
    form.StartTime = start;
    form.EndTime = end || addMinutesToTime(start, 120);
    formTimesFromBinding.value = true;
  } else {
    formTimesFromBinding.value = false;
  }
};

const openFromSchedule = (ev) => {
  if (ev.recordId) {
    const existing = records.value.find((r) => Number(r.id) === Number(ev.recordId));
    if (existing) {
      editRecord(existing);
      return;
    }
  }
  _clearForm();
  forceReadOnly.value = false;
  Object.assign(form, {
    StudentID: ev.studentId,
    TeacherID: props.userId,
    ClassSessionID: ev.classSessionId || 0,
    Subject: ev.subject,
    SessionDate: ev.date,
    StartTime: normalizeTime(ev.startTime) || String(ev.startTime || '').slice(0, 5),
    EndTime: normalizeTime(ev.endTime) || String(ev.endTime || '').slice(0, 5),
  });
  formTimesFromBinding.value = false;
  showModal.value = true;
};

const openFromScheduleMaybe = (ev) => {
  if (!ev?.recordId && ev?.fillLocked) {
    alert(ev.fillLockReason || '課程結束後開放填寫');
    return;
  }
  openFromSchedule(ev);
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
    params.set('per_page', '200');

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
  formTimesFromBinding.value = false;
  Object.assign(form, {
    id: record.id,
    StudentID: Number(record.student_id) || '',
    TeacherID: Number(record.TeacherID),
    ClassSessionID: Number(record.ClassSessionID) || 0,
    Subject: record.Subject,
    SessionDate: record.SessionDate,
    StartTime: normalizeTime(record.StartTime) || String(record.StartTime || '').match(/(\d{1,2}:\d{2})/)?.[1] || '',
    EndTime: normalizeTime(record.EndTime) || String(record.EndTime || '').match(/(\d{1,2}:\d{2})/)?.[1] || '',
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
  formTimesFromBinding.value = false;
  Object.assign(form, {
    id: null,
    StudentID: '',
    TeacherID: isTeacher.value ? props.userId : '',
    ClassSessionID: 0,
    Subject: '數學',
    SessionDate: localTodayYmd(),
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
  if (isTeacher.value) {
    applyTeacherFormDefaults();
  }
};

const openModal = (record = null) => {
  forceReadOnly.value = false;
  if (record) {
    _fillForm(record);
  } else {
    _clearForm();
  }
  showModal.value = true;
  nextTick(() => {
    if (!record && isDirectorRole.value && !forceReadOnly.value) {
      syncFormTimesFromCourseSchedule();
    }
  });
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
  if (timeLockMessage.value) {
    alert(timeLockMessage.value);
    return;
  }
  if (isTeacher.value && Number(form.ClassSessionID || 0) <= 0) {
    alert('請從課表點選該堂課進入評量，系統會自動帶入並鎖定上課時間。');
    return;
  }

  const token = await getToken();
  const url = isEditing.value ? `/api/v1/learning-records/${form.id}` : '/api/v1/learning-records';
  // Some deployments reject PUT at the web server layer; use POST for edits too.
  const method = 'POST';

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
    const savedRecord = await res.json().catch(() => null);
    const localRecord = buildLocalRecordFromForm(savedRecord);
    await fetchRecords();
    if (localRecord?.id) {
      upsertRecordInList(localRecord);
    }
    if (isTeacher.value) {
      await fetchTeacherClasses();
    }
    closeModal();
  } else {
    const err = await res.json().catch(() => ({}));
    alert('儲存失敗: ' + (err.message || `${res.status} ${res.statusText}` || '未知錯誤'));
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
    const err = await res.json().catch(() => ({}));
    alert('核准失敗: ' + (err.message || '未知錯誤'));
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
  if (res.ok) {
    fetchRecords();
  } else {
    const err = await res.json().catch(() => ({}));
    alert('退回失敗: ' + (err.message || '未知錯誤'));
  }
};

const rollbackApproval = async (record) => {
  const note = prompt('可輸入退回待審原因（選填）：', '');
  if (note === null) return;

  const token = await getToken();
  const res = await fetch(`/api/v1/learning-records/${record.id}/rollback-approval`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`
    },
    body: JSON.stringify({ DirectorID: props.userId, ReviewNote: note || null })
  });

  if (res.ok) {
    fetchRecords();
  } else {
    const err = await res.json().catch(() => ({}));
    alert('退回待審失敗: ' + (err.message || '未知錯誤'));
  }
};

const openChangeTeacherModal = (record) => {
  teacherChangeForm.record_id = record.id;
  teacherChangeForm.teacher_id = Number(record.TeacherID || 0) || '';
  teacherChangeForm.reason = '';
  teacherChangeForm.student_name = record.student_name || '';
  teacherChangeForm.current_teacher_name = record.teacher_name || '';
  teacherChangeForm.session_date = record.SessionDate || '';
  showChangeTeacherModal.value = true;
};

const closeChangeTeacherModal = () => {
  showChangeTeacherModal.value = false;
  teacherChangeSubmitting.value = false;
  teacherChangeForm.record_id = null;
  teacherChangeForm.teacher_id = '';
  teacherChangeForm.reason = '';
  teacherChangeForm.student_name = '';
  teacherChangeForm.current_teacher_name = '';
  teacherChangeForm.session_date = '';
};

const submitTeacherChange = async () => {
  if (!teacherChangeForm.record_id) return;
  if (!teacherChangeForm.teacher_id) {
    alert('請選擇新老師');
    return;
  }

  teacherChangeSubmitting.value = true;
  try {
    const token = await getToken();
    const res = await fetch(`/api/v1/learning-records/${teacherChangeForm.record_id}/teacher`, {
      method: 'PATCH',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
      },
      body: JSON.stringify({
        TeacherID: Number(teacherChangeForm.teacher_id),
        reason: teacherChangeForm.reason || null,
      }),
    });

    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      throw new Error(err?.message || '更新授課老師失敗');
    }

    await fetchRecords();
    closeChangeTeacherModal();
  } catch (error) {
    alert(error?.message || '更新授課老師失敗');
  } finally {
    teacherChangeSubmitting.value = false;
  }
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
  if (!isDirectorRole.value) return false;
  return record.Status === 'pending' || record.Status === 'changes_requested';
};

const canReject = (record) => {
  if (!isDirectorRole.value) return false;
  return record.Status === 'pending' || record.Status === 'changes_requested';
};

const canRollbackApproval = (record) => {
  if (!isDirectorRole.value) return false;
  return record.Status === 'approved';
};

const isWriteLockedBySessionEnd = (record) => {
  if (!record) return false;
  return !isSessionEnded(record.SessionDate, record.EndTime);
};

const canEdit = (record) => {
  if (isWriteLockedBySessionEnd(record)) return false;
  if (isDirectorRole.value) return true;
  if (record.Status === 'approved') return false;
  return true;
};

const canChangeTeacher = (record) => {
  if (!isDirectorRole.value) return false;
  return Boolean(record?.id);
};

const showTimeLockHint = (record) => isWriteLockedBySessionEnd(record);

const canDelete = (record) => {
  return isDirectorRole.value;
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

watch(
  () => [form.StudentID, form.SessionDate, form.Subject, form.TeacherID],
  () => {
    if (!showModal.value || isEditing.value || forceReadOnly.value) return;
    if (isTeacher.value) {
      if (!form.StudentID) return;
      applyTeacherFormDefaults({ studentId: form.StudentID, preserveStudent: true });
      return;
    }
    if (isDirectorRole.value) {
      formTimesFromBinding.value = false;
      nextTick(() => syncFormTimesFromCourseSchedule());
    }
  }
);

watch(
  () => [teacherClassList.value.length, Object.keys(sessionDatesByClassId.value || {}).length],
  () => {
    if (!showModal.value || !isTeacher.value || isEditing.value || forceReadOnly.value) return;
    applyTeacherFormDefaults({ studentId: form.StudentID, preserveStudent: true });
  }
);

watch(
  () => [courseList.value.length, Object.keys(directorSessionsByClassId.value || {}).join(',')],
  () => {
    if (!showModal.value || !isDirectorRole.value || isEditing.value || forceReadOnly.value) return;
    syncFormTimesFromCourseSchedule();
  }
);

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
      const rows = json.data || json || [];
      courseList.value = rows;
      if (isDirectorRole.value) {
        await fetchDirectorSessionsForCourses(rows);
      }
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
    const dateRes = await fetch(`/api/v1/student-classes/session-dates?branch_id=${props.branchId}`, {
      headers: { 'Authorization': `Bearer ${token}`, 'Accept': 'application/json' }
    });
    let effectiveDates = [];
    if (dateRes.ok) {
      const dateJson = await dateRes.json().catch(() => ({}));
      const mapped = dateJson?.[String(course.id)];
      if (Array.isArray(mapped)) {
        effectiveDates = mapped.map((d) => String(d || '').slice(0, 10)).filter(Boolean);
      }
    }

    const { byClass } = await fetchClassSessions({
      token,
      branchId: props.branchId,
      studentClassId: course.id,
      perPage: 2000,
    });
    const sessions = Array.isArray(byClass?.[String(course.id)]) ? byClass[String(course.id)] : [];
    const effectiveSessions = sessions.filter((s) => {
      const status = String(s?.status || '').toLowerCase();
      return status !== 'cancelled' && status !== 'leave';
    });
    const allDates = effectiveDates.length > 0
      ? [...new Set(effectiveDates)]
      : [...new Set(
        effectiveSessions
          .map((s) => String(s?.session_date || '').slice(0, 10))
          .filter(Boolean)
      )];
    const today = localTodayYmd();
    bulkDateList.value = allDates.filter(d => d <= today).sort();
    bulkExistingDates.value = effectiveSessions
      .filter((s) => String(s?.learning_record_status || '') === 'approved')
      .map((s) => String(s?.session_date || '').slice(0, 10))
      .filter(Boolean);
    bulkSelectedDates.value = bulkDateList.value.filter(d => !bulkExistingDates.value.includes(d));
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
        session_dates: bulkSelectedDates.value,
        auto_project_future: true
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
    if (!isDirectorRole.value) return;
    const token = await getToken();
    if (!token || !props.branchId) return;
    await fetch('/api/v1/learning-records/ensure-past', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ branch_id: props.branchId })
    });
  } catch (e) { /* silent */ }
};

// ── Export ──
const showExportModal = ref(false);
const exportForm = reactive({
  startDate: '',
  endDate: '',
  status: 'idle', // idle | loading | done | empty
  progressCompleted: 0,
  progressTotal: 0,
  progressCurrent: '',
  errorNames: [],
});

const exportProgressPct = computed(() => {
  if (exportForm.progressTotal <= 0) return 0;
  return Math.round((exportForm.progressCompleted / exportForm.progressTotal) * 100);
});

const openExportModal = () => {
  const today = localTodayYmd();
  const d = new Date();
  d.setDate(1);
  const monthStart = formatLocalDate(d);
  exportForm.startDate = monthStart;
  exportForm.endDate = today;
  exportForm.status = 'idle';
  exportForm.progressCompleted = 0;
  exportForm.progressTotal = 0;
  exportForm.progressCurrent = '';
  exportForm.errorNames = [];
  showExportModal.value = true;
};

const executeExport = async () => {
  if (!exportForm.startDate || !exportForm.endDate) return;
  exportForm.status = 'loading';
  exportForm.progressCompleted = 0;
  exportForm.progressTotal = 0;
  exportForm.progressCurrent = '';
  exportForm.errorNames = [];

  try {
    const token = await getToken();
    if (!token) { exportForm.status = 'idle'; return; }

    const params = new URLSearchParams();
    if (props.branchId) params.set('branch_id', props.branchId);
    params.set('start_date', exportForm.startDate);
    params.set('end_date', exportForm.endDate);
    params.set('per_page', '200');
    params.set('status', 'approved');

    const res = await fetch(`/api/v1/learning-records?${params.toString()}`, {
      headers: { 'Authorization': `Bearer ${token}` },
    });

    if (!res.ok) {
      alert('查詢評量資料失敗');
      exportForm.status = 'idle';
      return;
    }

    const data = await res.json();
    const allRecords = data.data || [];

    if (allRecords.length === 0) {
      exportForm.status = 'empty';
      return;
    }

    // Group by student
    const groups = new Map();
    for (const rec of allRecords) {
      const sid = Number(rec.student_id || 0) || null;
      const sname = String(rec.student_name || '').trim() || '未命名學生';
      const key = sid ? `s-${sid}` : `n-${sname}`;
      if (!groups.has(key)) {
        groups.set(key, { key, student_name: sname, student_id: sid, records: [] });
      }
      groups.get(key).records.push(rec);
    }

    const grouped = Array.from(groups.values())
      .map((g) => {
        g.records.sort((a, b) => {
          const ad = String(a.SessionDate || '');
          const bd = String(b.SessionDate || '');
          return ad.localeCompare(bd) || String(a.StartTime || '').localeCompare(String(b.StartTime || ''));
        });
        return g;
      })
      .sort((a, b) => new Intl.Collator('zh-Hant').compare(a.student_name, b.student_name));

    exportForm.progressTotal = grouped.length;

    const dateRange = `${exportForm.startDate} ~ ${exportForm.endDate}`;

    const branchNames = { 1: '興隆校', 2: '新店校', 3: '大安校', 4: '木柵校' };
    const branchName = branchNames[Number(props.branchId)] || 'AllTrue 補習班';

    const { errors } = await exportStudentCards({
      groupedRecords: grouped,
      dateRange,
      branchName,
      onProgress: ({ completed, total, current, error }) => {
        exportForm.progressCompleted = completed;
        exportForm.progressTotal = total;
        exportForm.progressCurrent = current;
        if (error) exportForm.errorNames.push(current);
      },
    });

    exportForm.status = 'done';
  } catch (err) {
    console.error('Export error:', err);
    alert('匯出失敗：' + (err.message || '未知錯誤'));
    exportForm.status = 'idle';
  }
};

// ── Init ──
onMounted(async () => {
  await ensurePastRecords();
  fetchRecords();
  fetchTeachers();
  await fetchStudents();
  if (props.branchId && isDirectorRole.value) {
    await fetchCourses();
  }
  if (isTeacher.value) fetchTeacherClasses();
});

watch(() => props.branchId, () => {
  fetchRecords();
  fetchTeachers();
  fetchCourses();
  fetchStudents();
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

.ts-subject-row {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 1px;
}

.ts-status-chip {
  display: inline-flex;
  align-items: center;
  padding: 1px 6px;
  border-radius: 10px;
  font-size: 10px;
  font-weight: 700;
  white-space: nowrap;
}

.ts-status-chip.status-missing {
  background: #fff1f2;
  color: #b91c1c;
}

.ts-status-chip.status-pending {
  background: #fff7ed;
  color: #c2410c;
}

.ts-status-chip.status-approved {
  background: #ecfdf3;
  color: #166534;
}

.ts-status-chip.status-changes_requested,
.ts-status-chip.status-rejected {
  background: #eef2ff;
  color: #3730a3;
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

.ts-fill-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
  background: #94a3b8;
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
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.ts-missing-pill {
  display: inline-flex;
  align-items: center;
  border-radius: 999px;
  padding: 0 6px;
  font-size: 10px;
  line-height: 16px;
  background: #fff1f2;
  color: #b91c1c;
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

.ts-event-sm.locked {
  opacity: 0.72;
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

.lr-groups {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 10px;
}

.lr-group {
  border: 1px solid var(--border);
  border-radius: 10px;
  overflow: hidden;
  background: var(--card-bg);
}

.lr-group-summary {
  list-style: none;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  cursor: pointer;
  background: #f8fafc;
  border-bottom: 1px solid var(--border);
}

.lr-group-summary::-webkit-details-marker {
  display: none;
}

.lr-group-title {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.lr-group-student {
  font-weight: 700;
  font-size: 14px;
}

.lr-group-count {
  display: inline-flex;
  align-items: center;
  border-radius: 999px;
  padding: 2px 8px;
  font-size: 12px;
  background: #e2e8f0;
  color: #334155;
}

.lr-group-pending {
  display: inline-flex;
  align-items: center;
  border-radius: 999px;
  padding: 2px 8px;
  font-size: 12px;
  background: #fff7ed;
  color: #c2410c;
}

.lr-group-hint {
  font-size: 12px;
  color: var(--text-light);
}

.lr-group[open] .lr-group-summary {
  background: #eff6ff;
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

.lr-lock-hint {
  display: inline-block;
  margin-left: 6px;
  font-size: 11px;
  color: #b45309;
  background: #fff7ed;
  border: 1px solid #fed7aa;
  border-radius: 999px;
  padding: 2px 8px;
  vertical-align: middle;
}

.lr-time-lock-note {
  margin-top: 10px;
  font-size: 12px;
  color: #b45309;
  background: #fff7ed;
  border: 1px solid #fed7aa;
  border-radius: 8px;
  padding: 8px 10px;
}

.lr-readonly-time {
  min-height: 38px;
  display: flex;
  align-items: center;
  padding: 8px 12px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #f8fafc;
  color: #334155;
  font-size: 14px;
  font-variant-numeric: tabular-nums;
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

/* ── Export Modal ── */
.export-progress {
  margin-top: 16px;
}

.export-progress-bar {
  height: 8px;
  background: #e2e8f0;
  border-radius: 4px;
  overflow: hidden;
}

.export-progress-fill {
  height: 100%;
  background: var(--primary);
  border-radius: 4px;
  transition: width 0.3s ease;
}

.export-progress-text {
  font-size: 13px;
  color: var(--text-light);
  margin-top: 8px;
}

.export-done {
  margin-top: 16px;
  padding: 12px 16px;
  background: #ecfdf3;
  border-radius: 8px;
  color: #166534;
  font-size: 14px;
}

.export-empty {
  margin-top: 16px;
  padding: 12px 16px;
  background: #fff7ed;
  border-radius: 8px;
  color: #c2410c;
  font-size: 14px;
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
