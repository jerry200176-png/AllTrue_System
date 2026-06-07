<template>
  <div>
    <div v-if="calendarLoading" class="calendar-loading-bar">{{ calendarLoadProgress || '載入中…' }}</div>
    <!-- Top Bar -->
    <div class="smart-cal-top" data-guide="calendar-header">
      <div class="smart-cal-header">
        <h1 class="smart-cal-title">{{ isTeacher ? '我的課表' : '班級行事曆 / 課表' }}</h1>
        <div class="view-tabs">
          <button type="button" :class="{ active: viewMode === 'week' }" @click="viewMode = 'week'">排課表</button>
          <button v-if="!isTeacher" type="button" :class="{ active: viewMode === 'teacher' }" @click="viewMode = 'teacher'">老師清單</button>
        </div>
      </div>
      <div v-if="viewMode === 'week'" class="smart-cal-toolbar" data-guide="calendar-toolbar">
        <div class="toolbar-row toolbar-row-primary">
          <div class="toolbar-group">
            <span class="toolbar-label">月份</span>
            <div class="month-nav">
              <button type="button" class="icon-btn" @click="prevMonth" title="上一個月" aria-label="上一個月">‹</button>
              <span class="month-display">{{ displayYear }} / {{ displayMonth }}</span>
              <button type="button" class="icon-btn" @click="nextMonth" title="下一個月" aria-label="下一個月">›</button>
            </div>
          </div>
          <div class="toolbar-group">
            <span class="toolbar-label">週次</span>
            <!-- #740 Step 4d：週次導航剝離為 presentational 元件 -->
            <WeekNavBar v-model="displayWeek" :week-options="weekOptions" @prev="prevWeek" @next="nextWeek" />
          </div>
          <div class="toolbar-group">
            <span class="toolbar-label">跳至日期</span>
            <input
              v-model="jumpToDate"
              type="date"
              class="filter-input jump-date-input"
              @change="jumpToDateWeek"
            />
          </div>
          <div v-if="!isTeacher" class="toolbar-group">
            <div class="view-sub-toggle">
              <button type="button" :class="{ active: !isWeekOverview }" @click="isWeekOverview = false">日檢視</button>
              <button type="button" :class="{ active: isWeekOverview }" @click="isWeekOverview = true">週檢視</button>
            </div>
          </div>
          <div class="toolbar-fill"></div>
        </div>
        <div v-if="!isTeacher" class="toolbar-row toolbar-row-secondary">
          <div class="toolbar-secondary-line toolbar-secondary-line--meta">
            <div class="toolbar-secondary-meta">
              <span class="week-stat">本日 <b>{{ getDayCourseCount(selectedDow) }}</b> 堂 / 本週 <b>{{ weekCourseCount }}</b> 堂</span>
              <span class="rc-legend"><span class="rc-tag rc-done">✓</span>已點 <span class="rc-tag rc-missed">!</span>漏點 <span class="rc-tag rc-leave">假</span>請假 <span class="rc-tag rc-eval-missing">評</span>未填評量</span>
              <span v-if="viewMode === 'day'" class="rc-legend capacity-legend" title="每格右上角顯示：此時段學生人數 / 班型上限">
                <span class="capacity-legend-label">班型容量</span>
                <span class="capacity-legend-chip capacity-legend-chip--ok">1/3</span>可加
                <span class="capacity-legend-chip capacity-legend-chip--warn">2/3</span>剩 1 位
                <span class="capacity-legend-chip capacity-legend-chip--full">3/3</span>已滿
              </span>
            </div>
          </div>
          <div class="toolbar-secondary-line toolbar-secondary-line--filters">
            <div class="toolbar-secondary-mid">
              <div class="toolbar-filters">
                <select v-model="roomFilter" class="filter-select toolbar-room-select" title="依教室篩選老師欄">
                  <option value="">全部教室</option>
                  <option v-for="r in allRoomOptions" :key="r" :value="r">教室 {{ r }}</option>
                </select>
                <input v-model="teacherSearch" type="search" class="filter-input toolbar-search-input" placeholder="搜尋老師…" autocomplete="off" />
                <input v-model="studentSearch" type="search" class="filter-input toolbar-search-input" placeholder="搜尋學生…" autocomplete="off" />
                <button
                  v-if="featureSubstituteV2 && !isTeacher"
                  type="button"
                  class="filter-input toolbar-teacher-leave-btn"
                  title="老師請假一次處理當日多堂代課"
                  @click="openTeacherLeaveBatch"
                ><span class="material-symbols-outlined btn-icon">event_busy</span>老師請假</button>
                <label
                  v-if="!isWeekOverview && !isTeacher"
                  class="filter-toggle toolbar-hide-empty-toggle"
                  title="開啟後只顯示今日有排課的老師欄；此模式下無法點空格快速排課"
                >
                  <input type="checkbox" v-model="hideEmptyTeacherColumns" />
                  <span>只看有課老師</span>
                </label>
              </div>
              <!-- #740 Step 4c：老師篩選 chips 剝離為 presentational 元件 -->
              <WeekTeacherChips
                v-if="visibleTeachers.length > 1 && !isTeacher"
                :teachers="teacherChips"
                :selected-ids="selectedTeacherChipIds"
                @toggle="toggleTeacherSelection"
                @clear="clearTeacherSelection"
              />
            </div>
            <div class="toolbar-secondary-actions">
              <button type="button" class="btn-secondary btn-icon-text toolbar-action-btn" @click="showRoomManager = !showRoomManager" title="管理教室"><span class="material-symbols-outlined btn-icon">meeting_room</span><span class="btn-text">教室</span></button>
              <button type="button" class="btn-primary btn-icon-text toolbar-action-btn" data-guide="calendar-quick-add" @click="openQuickAdd"><span class="material-symbols-outlined btn-icon">add_circle</span><span class="btn-text">快速排課</span></button>
            </div>
          </div>
        </div>
      </div>

      <!-- Room Manager Panel -->
      <div v-if="showRoomManager && !isTeacher && viewMode === 'week'" class="room-manager-panel">
        <div class="room-manager-header">
          <h3>教室管理</h3>
          <button type="button" class="icon-btn" @click="showRoomManager = false" title="關閉">✕</button>
        </div>
        <div class="room-manager-body">
          <table v-if="roomList.length > 0" class="room-table">
            <thead><tr><th>名稱</th><th>容量（最多幾位老師）</th><th>操作</th></tr></thead>
            <tbody>
              <tr v-for="r in roomList" :key="r.id">
                <td>{{ r.name }}</td>
                <td>{{ r.capacity }}</td>
                <td>
                  <button class="room-action-btn" @click="editRoom(r)">編輯</button>
                  <button class="room-action-btn danger" @click="deleteRoom(r)">刪除</button>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-else class="room-empty-hint">尚未設定教室，請新增教室以啟用教室容量檢查。</p>
          <div class="room-form">
            <input v-model="roomForm.name" type="text" placeholder="教室名稱" class="room-input" />
            <input v-model.number="roomForm.capacity" type="number" min="1" placeholder="容量" class="room-input" style="width: 80px;" />
            <button type="button" class="btn-primary btn-sm" @click="saveRoom">{{ editingRoomId ? '更新' : '新增' }}</button>
            <button v-if="editingRoomId" type="button" class="btn-secondary btn-sm" @click="cancelRoomEdit">取消</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ===== VIEW: Teacher Grid (main view) ===== -->
    <div v-if="viewMode === 'week'" class="week-view">

      <!-- ── Day View (default) ── -->
      <template v-if="!isWeekOverview">
        <!-- #740 Step 4b：日分頁列剝離為 presentational 元件 DayTabsBar -->
        <DayTabsBar :tabs="dayTabs" :active-idx="selectedDayIdx" @select="selectedDayIdx = $event" />
        <div class="teacher-grid-wrapper" data-guide="calendar-grid">
          <div v-if="visibleTeachers.length === 0" class="teacher-empty">
            <template v-if="hideEmptyTeacherColumns && !isWeekOverview">
              <div style="font-weight:600;margin-bottom:6px;">今日無已排課老師</div>
              <div style="color:#6b7280;font-size:13px;margin-bottom:10px;">可關閉「只看有課老師」以顯示全部老師欄並快速排課。</div>
              <button type="button" class="btn-secondary" @click="hideEmptyTeacherColumns = false">顯示全部老師</button>
            </template>
            <template v-else>
              目前無老師資料，請先在「學生管理」中建立課程並指派老師。
            </template>
          </div>
          <div v-else :class="['teacher-grid', { 'teacher-grid-compact': isTeacherGridCompact }]" :style="gridTemplateStyle">
            <div class="time-col">
              <div class="col-header-blank"></div>
              <div v-for="h in hours" :key="h" class="time-label">{{ String(h).padStart(2, '0') }}:00</div>
            </div>
            <div v-for="teacher in dayViewTeacherColumns" :key="teacher.id" class="teacher-col">
              <!-- #740 Step 4a：老師欄表頭剝離為 presentational 元件 -->
              <TeacherColumnHeader
                :name="teacher.username"
                :room="teacher.roomLabel"
                :color="getTeacherColor(teacher.id)"
                :compact="isTeacherGridCompact"
              />
              <div v-for="h in hours" :key="h" class="slot"
                :class="{
                  'no-click': isTeacher,
                  'slot-room-full': isSlotRoomFull(selectedDow, h),
                  'drag-over': !isTeacher && dragOverSlot && dragOverSlot.teacherId === teacher.id && dragOverSlot.h === h
                }"
                @click="!isTeacher && onSlotClick(selectedDow, h, selectedDateStr, teacher.id)"
                @dragover.prevent="!isTeacher && (dragOverSlot = { teacherId: teacher.id, h })"
                @dragleave="dragOverSlot = null"
                @drop.prevent="!isTeacher && onSlotDrop(selectedDow, h, selectedDateStr, teacher.id)"
              >
                <span
                  v-if="getSlotOccupancy(teacher.id, selectedDow, h).count > 0"
                  class="capacity-badge"
                  :class="{ 'capacity-badge-compact': isTeacherGridCompact }"
                  :style="{ background: getSlotOccupancy(teacher.id, selectedDow, h).color }"
                  :title="getSlotOccupancy(teacher.id, selectedDow, h).tooltip"
                >{{ getSlotOccupancy(teacher.id, selectedDow, h).label }}</span>
                <div
                  v-for="(course, cIdx) in getCoursesForTeacherAt(teacher.id, h)"
                  :key="course.id"
                  class="course-block"
                  :style="getTeacherCourseBlockStyle(course, teacher.id, h, cIdx)"
                  :draggable="!isTeacher"
                  @click.stop="!isTeacher && onCourseClick(course, selectedDateStr)"
                  @contextmenu.prevent="!isTeacher && onCourseRightClick(course, selectedDateStr, $event)"
                  @dragstart.stop="!isTeacher && onCourseDragStart(course, selectedDateStr, $event)"
                  @dragend="draggingCourse = null; dragOverSlot = null"
                >
                  <!-- #740 Step 5：課程卡內容剝離為 presentational 元件（日檢視） -->
                  <CourseBlockContent
                    :course="course"
                    :badges="{ rollCall: rollCallBadge(course, selectedDateStr), evalMissing: evalBadge(course, selectedDateStr), teacherTag: null }"
                    :layout="{ compact: isTeacherGridCompact, firstBadge: (cIdx === 0 && getSlotOccupancy(teacher.id, selectedDow, h).count > 0) ? (isTeacherGridCompact ? 'compact' : 'full') : null }"
                  />
                </div>
              </div>
            </div>
          </div>
        </div>
      </template>

      <!-- ── Week Overview ── -->
      <template v-else>
        <div class="week-overview-grid-wrapper" data-guide="calendar-grid">
          <div v-if="visibleTeachers.length === 0" class="teacher-empty week-overview-empty">
            目前無符合條件的老師。請調整「教室」或「搜尋老師／學生」關鍵字。
          </div>
          <div v-else class="week-overview-body">
            <div class="week-overview-context-bar">
              <span class="week-overview-context-kicker">週檢視</span>
              <strong class="week-overview-context-name">{{ weekViewSelectedLabel }}</strong>
            </div>
            <div class="week-overview-grid">
            <div class="time-col">
              <div class="col-header-blank"></div>
              <div v-for="h in hours" :key="h" class="time-label">{{ String(h).padStart(2, '0') }}:00</div>
            </div>
            <div v-for="(dayName, idx) in dayNames" :key="idx" class="day-col">
              <div class="day-col-header" :class="{ 'day-col-today': selectedDayIdx === idx }">
                <span class="day-col-name">{{ dayName }}</span>
                <span class="day-col-date">{{ getDisplayDateString(idx + 1) }}</span>
                <span v-if="getWeekTeacherDayCount(idx + 1) > 0" class="day-col-badge">{{ getWeekTeacherDayCount(idx + 1) }}</span>
              </div>
              <div v-for="h in hours" :key="h" class="slot"
                :class="{
                  'slot-room-full': isSlotRoomFull(idx + 1, h),
                  'drag-over': !isTeacher && dragOverSlot && dragOverSlot.dow === (idx + 1) && dragOverSlot.h === h
                }"
                @click="!isTeacher && onSlotClick(idx + 1, h, getDisplayDateFull(idx + 1), weekViewTeacherIds.length === 1 ? weekViewTeacherIds[0] : '')"
                @dragover.prevent="!isTeacher && (dragOverSlot = { dow: idx + 1, h })"
                @dragleave="dragOverSlot = null"
                @drop.prevent="!isTeacher && onSlotDrop(idx + 1, h, getDisplayDateFull(idx + 1), weekViewTeacherIds.length === 1 ? weekViewTeacherIds[0] : '')"
              >
                <div
                  v-for="(course, cIdx) in getCoursesForWeekCell(idx + 1, h)"
                  :key="course.id"
                  class="course-block"
                  :style="getWeekCourseBlockStyle(course, idx + 1, h, cIdx)"
                  :draggable="!isTeacher"
                  @click.stop="!isTeacher && onCourseClick(course, getDisplayDateFull(idx + 1))"
                  @contextmenu.prevent="!isTeacher && onCourseRightClick(course, getDisplayDateFull(idx + 1), $event)"
                  @dragstart.stop="!isTeacher && onCourseDragStart(course, getDisplayDateFull(idx + 1), $event)"
                  @dragend="draggingCourse = null; dragOverSlot = null"
                >
                  <!-- #740 Step 5：課程卡內容剝離為 presentational 元件（週檢視，含老師標籤） -->
                  <CourseBlockContent
                    :course="course"
                    :badges="{ rollCall: rollCallBadge(course, getDisplayDateFull(idx + 1)), evalMissing: evalBadge(course, getDisplayDateFull(idx + 1)), teacherTag: weekViewTeacherIds.length !== 1 ? { name: course.teacher_name, color: getTeacherColor(course.teacher_id) } : null }"
                    :layout="{ compact: false, firstBadge: null }"
                  />
                </div>
              </div>
            </div>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- ===== VIEW: Teacher List (director only) ===== -->
    <div v-if="viewMode === 'teacher' && !isTeacher" class="teacher-view">
      <div v-if="teacherGroups.length === 0" class="teacher-empty">
        目前無排課資料，請先在「學生管理」中建立課程。
      </div>
      <div v-for="group in teacherGroups" :key="group.teacher_id" class="teacher-card">
        <div class="teacher-card-header" @click="group.open = !group.open">
          <div class="teacher-info">
            <div class="teacher-avatar" :style="{ background: getTeacherColor(group.teacher_id) }">
              {{ group.teacher_name.charAt(0) }}
            </div>
            <div class="teacher-meta">
              <h3>{{ group.teacher_name }}</h3>
              <span class="teacher-count">{{ group.courses.length }} 堂</span>
            </div>
          </div>
          <span class="expand-arrow" :aria-expanded="group.open">{{ group.open ? '▼' : '▶' }}</span>
        </div>
        <div v-if="group.open" class="teacher-courses">
          <table class="teacher-table">
            <thead>
              <tr>
                <th>時段</th>
                <th>學生</th>
                <th>科目</th>
                <th>類型</th>
                <th>時間</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in group.courses" :key="c.id">
                <td><strong>{{ dayLabel(c.day_of_week) }}</strong></td>
                <td>{{ c.student_name }}</td>
                <td>
                  <div class="course-text">
                    <span class="subject-tag">{{ getSubjectLabel(c.subject) }}</span>
                    <span v-if="getWeekLabel(c.weeks)" class="week-tag">{{ getWeekLabel(c.weeks) }}</span>
                  </div>
                </td>
                <td>
                  <span class="status-tag" :class="c.class_type">{{ classTypeLabel(c.class_type) }}</span>
                </td>
                <td>{{ c.start_time }} ~ {{ c.end_time }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ===== Quick Add / Edit Modal ===== -->
    <UniversalClassScheduler
      v-if="showModal && !editingCourseId"
      title="快速排課（統一排課介面）"
      submit-label="建立課程"
      :branch-id="props.branchId"
      :students="schedulerStudents"
      :teachers="schedulerTeachers"
      :rooms="schedulerRooms"
      :initial-teacher-id="modalForm.teacher_id"
      :initial-start-time="modalForm.start_time"
      :initial-days-of-week="modalForm.days_of_week || []"
      :initial-calendar-ymd="modalForm.action_date || modalForm.first_class_date || ''"
      mode="create"
      @cancel="showModal = false"
      @success="handleUniversalSchedulerSuccess"
    />

    <!-- #740 Modals：單堂檢視（SessionEdit） -->
    <CalendarSessionEditModal
      :show="showModal && !!editingCourseId"
      :form="modalForm"
      :rate-per2h="ratePer2h"
      :session="sessionEditSession"
      :options="sessionEditOptions"
      @close="showModal = false"
      @leave="openLeaveModal"
      @reschedule="openRescheduleModal"
      @substitute="openSubstituteModal"
      @substitute-v2="openSubstituteV2Modal"
      @show-cancel-confirm="cancelState.show = true"
      @dismiss-cancel-confirm="cancelState.show = false"
      @confirm-cancel="doConfirmCancelSession"
      @delete-exception="deleteException"
      @delete-course="deleteCourse"
      @cancel-makeup="cancelMakeupClass"
      @teacher-change="checkConflict"
    />

    <!-- #740 Modals：請假 -->
    <CalendarLeaveModal
      :show="showLeaveModal"
      :form="leaveForm"
      :student-name="leaveDisplay.studentName"
      :subject-label="leaveDisplay.subjectLabel"
      :original-slot-label="leaveDisplay.originalSlot"
      @close="showLeaveModal = false"
      @submit="submitLeave"
    />

    <!-- ===== PRD 9c058f19：代課 V2 Modal + Toast + 批次請假 ===== -->
    <SubstituteTeacherPickerModal
      v-if="featureSubstituteV2"
      ref="substituteV2PickerRef"
      v-model="showSubstituteV2Modal"
      :context="substituteV2Context"
      :teachers="teachers"
      :branch-name-map="branchNameMap"
      :fetch-availability="fetchTeacherAvailability"
      @submit="onSubstituteV2Submit"
    />
    <TeacherLeaveBatchModal
      v-if="featureSubstituteV2 && !isTeacher"
      v-model="showTeacherLeaveBatchModal"
      :teachers="teachers"
      :fetch-preview="previewTeacherLeaves"
      :submit-batch="batchSubstituteApi"
      :fetch-availability="fetchTeacherAvailability"
      @submitted="onBatchSubstituteSubmitted"
    />
    <ToastWithUndo v-if="featureSubstituteV2" ref="toastRef" />

    <!-- #740 Modals：舊版代課（feature flag 關閉時） -->
    <CalendarSubstituteLegacyModal
      :show="showSubstituteModal"
      :form="substituteForm"
      :student-name="substituteDisplay.studentName"
      :subject-label="substituteDisplay.subjectLabel"
      :session-slot-label="substituteDisplay.sessionSlot"
      :teachers="teachers || []"
      :submitting="substituteSubmitting"
      @close="showSubstituteModal = false"
      @submit="submitSubstitute"
    />

    <!-- #740 Modals：加課 -->
    <CalendarExtraLessonModal
      :show="showExtraModal"
      :form="extraForm"
      :student-options="studentSelectOptions"
      :subject-options="subjectOptions"
      :teachers="teachers || []"
      :new-end-time="computedExtraEndTime"
      :is-monthly="extraParentPaymentType === 'monthly'"
      @close="showExtraModal = false"
      @submit="submitExtraLesson"
      @duration-change="onExtraFormTimeChange"
      @start-time-change="onExtraFormStartTimeChange"
    />

    <!-- #740 Modals：調課 -->
    <CalendarRescheduleModal
      :show="showRescheduleModal"
      :form="rescheduleForm"
      :student-name="rescheduleDisplay.studentName"
      :subject-label="rescheduleDisplay.subjectLabel"
      :original-slot-label="rescheduleDisplay.originalSlot"
      :new-end-time="computedRescheduleNewEnd"
      :time-options="timeOptions30"
      @close="showRescheduleModal = false"
      @submit="submitReschedule"
      @new-start-change="onRescheduleNewStartChange"
    />

    <!-- ===== Right-click Context Menu ===== -->
    <div
      v-if="contextMenu.show"
      class="context-menu"
      :style="{ top: contextMenu.y + 'px', left: contextMenu.x + 'px' }"
      @click.stop
    >
      <button class="ctx-item" @click="onContextLeave">📋 請假</button>
      <button class="ctx-item ctx-cancel" @click="contextMenu.show = false">取消</button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue';
import { supabase } from '../supabase';
import { SUBJECTS, getSubjectLabel as getSubjectText } from '../lib/constants';
import { fetchSubjectOptions } from '../lib/subjectsApi';
import { mergeWeekCalendarOccurrences } from '../lib/calendarOccurrenceMerge';
import {
  resolveCalendarDataFetchBoundsYmd,
  isRangeWithinFetchedBounds,
} from '../lib/calendarLoadPerformance';
import UniversalClassScheduler from '../components/UniversalClassScheduler.vue';
import SubstituteTeacherPickerModal from '../components/substitute/SubstituteTeacherPickerModal.vue';
import TeacherLeaveBatchModal from '../components/substitute/TeacherLeaveBatchModal.vue';
import ToastWithUndo from '../components/substitute/ToastWithUndo.vue';
import TeacherColumnHeader from '../components/calendar/TeacherColumnHeader.vue';
import DayTabsBar from '../components/calendar/DayTabsBar.vue';
import WeekTeacherChips from '../components/calendar/WeekTeacherChips.vue';
import WeekNavBar from '../components/calendar/WeekNavBar.vue';
import CourseBlockContent from '../components/calendar/CourseBlockContent.vue';
import CalendarSessionEditModal from '../components/calendar/modals/CalendarSessionEditModal.vue';
import CalendarLeaveModal from '../components/calendar/modals/CalendarLeaveModal.vue';
import CalendarRescheduleModal from '../components/calendar/modals/CalendarRescheduleModal.vue';
import CalendarSubstituteLegacyModal from '../components/calendar/modals/CalendarSubstituteLegacyModal.vue';
import CalendarExtraLessonModal from '../components/calendar/modals/CalendarExtraLessonModal.vue';
import {
  fetchTeacherAvailability,
  previewTeacherLeaves,
  batchSubstitute as batchSubstituteApi,
} from '../lib/substituteApi.js';
import { pickBestSessionRow } from '../lib/classSessionPick.js';
// #740 Step 1：純日期工具已剝離至 lib（Leaf-First / Pure Move），測試見 calendarDateUtils.test.js
import {
  formatLocalDate,
  getNextWeekdayYmd,
  getMondayOfMonthWeek,
  toYmd,
  addDays,
  getWeekNumberOfDate,
} from '../lib/calendarDateUtils.js';
// #740 Step 2：純格式化工具
import {
  classTypeLabel,
  dayLabel,
  dayOfWeekFromDate,
  getWeekLabel,
  parseHour,
  normalizeTimeTo30,
  computeEndTime,
} from '../lib/calendarFormat.js';
// #740 Step 3：教師配色（有狀態 memo）
import { getTeacherColor } from '../lib/teacherColor.js';
import { useCalendarDataLoad } from '../composables/calendar/useCalendarDataLoad.js';
import { useCalendarLeaveExtra } from '../composables/calendar/useCalendarLeaveExtra.js';
import { useCalendarSubstitute } from '../composables/calendar/useCalendarSubstitute.js';
import { useCalendarReschedule } from '../composables/calendar/useCalendarReschedule.js';

const props = defineProps({
  branchId: [String, Number],
  userRole: String,
  userId: [String, Number],
  initialTeacherId: [String, Number],
  resetWeekToken: [String, Number],
});
const emit = defineEmits(['clear-initial-teacher']);

const isTeacher = computed(() => props.userRole === 'teacher');
const currentTeacherId = computed(() => {
  const raw = props.userId;
  if (raw == null || raw === '') return null;
  return String(raw);
});

const subjectOptions = ref([...SUBJECTS]);
async function loadSubjects() {
  try {
    const opts = await fetchSubjectOptions({ branchId: props.branchId });
    if (opts.length > 0) subjectOptions.value = opts;
  } catch { /* keep defaults */ }
}

const getToken = async () => {
  const { data: { session } } = await supabase.auth.getSession();
  return session?.access_token || null;
};

// 檢視月份（可切換到其他月）
const now = new Date();
const displayYear = ref(now.getFullYear());
const displayMonth = ref(now.getMonth() + 1); // 1-12

// 依選定月份計算週選項
const weekOptions = computed(() => {
  const year = displayYear.value;
  const month = displayMonth.value - 1; // 0-based for Date
  const options = [];

  const firstDay = new Date(year, month, 1);
  const lastDay = new Date(year, month + 1, 0);

  let currentStart = new Date(firstDay);
  if (currentStart.getDay() !== 1) {
    const adj = currentStart.getDay() === 0 ? -6 : 1 - currentStart.getDay();
    currentStart.setDate(currentStart.getDate() + adj);
  }

  let weekNum = 1;
  while (currentStart <= lastDay || weekNum <= 5) {
    if (weekNum > 5) break;
    const endOfWeek = new Date(currentStart);
    endOfWeek.setDate(currentStart.getDate() + 6);
    const startStr = `${currentStart.getMonth() + 1}/${currentStart.getDate()}`;
    const endStr = `${endOfWeek.getMonth() + 1}/${endOfWeek.getDate()}`;
    options.push({
      value: weekNum,
      label: `${year}年${month + 1}月 第${weekNum}週 (${startStr} - ${endStr})`,
      shortLabel: `第${weekNum}週 (${startStr}-${endStr})`
    });
    currentStart.setDate(currentStart.getDate() + 7);
    weekNum++;
  }
  return options;
});

const prevMonth = () => {
  if (displayMonth.value <= 1) {
    displayYear.value--;
    displayMonth.value = 12;
  } else {
    displayMonth.value--;
  }
  displayWeek.value = 0;
  weekOffset.value = 0;
};

const nextMonth = () => {
  if (displayMonth.value >= 12) {
    displayYear.value++;
    displayMonth.value = 1;
  } else {
    displayMonth.value++;
  }
  displayWeek.value = 0;
  weekOffset.value = 0;
};

// #740 Step 1：formatLocalDate / getNextWeekdayYmd / getMondayOfMonthWeek / toYmd /
// addDays / getWeekNumberOfDate 已剝離至 ../lib/calendarDateUtils.js（純函式 + 單元測試）。

// --- State ---
const viewMode = ref('week');
const displayWeek = ref(0);
const weekOffset = ref(0); // 上週/下週偏移
const jumpToDate = ref(formatLocalDate(new Date()));
// courses / exceptions / loaders → useCalendarDataLoad（#740 Step 7，見 getCalendarDataFetchBoundsYmd 之後）
const filterTeacherId = ref('');
const showModal = ref(false);
const editingCourseId = ref(null);
/** 點擊的那一堂的實際日期（僅編輯單堂時有值），用於限定只能做請假/調課/加課 */
const editingActionDate = ref('');
const editingException = ref(null); // When editing a rescheduled/extra schedule, holds the exception object
const conflictWarning = ref('');
const courseEvalRecords = ref([]);
const evalRecordsLoading = ref(false);

// Drag-to-reschedule state
const draggingCourse = ref(null); // { course, originalDate }
const dragOverSlot = ref(null);   // { dow, h }

// Right-click context menu state
const contextMenu = ref({ show: false, x: 0, y: 0, course: null, date: null });

// Teacher-grid: selected day tab within the current week
const selectedDayIdx = ref((() => { const d = new Date().getDay(); return d === 0 ? 6 : d - 1; })());
const selectedDow = computed(() => selectedDayIdx.value + 1);
const selectedDateStr = computed(() => getDisplayDateFull(selectedDow.value));
const roomFilter = ref('');
const teacherSearch = ref('');
const studentSearch = ref('');
// 日檢視：是否隱藏「當日無課」的老師欄（純覽模式；開啟後無法點空格快速排課）
const HIDE_EMPTY_TEACHERS_KEY = 'smart_calendar_hide_empty_teachers';
const hideEmptyTeacherColumns = ref((() => {
  try { return localStorage.getItem(HIDE_EMPTY_TEACHERS_KEY) === '1'; }
  catch (e) { return false; }
})());
watch(hideEmptyTeacherColumns, (v) => {
  try { localStorage.setItem(HIDE_EMPTY_TEACHERS_KEY, v ? '1' : '0'); }
  catch (e) { /* ignore storage errors (private mode etc.) */ }
});

/** 工具列「搜尋學生」：與老師名、教室篩選並用時為 AND；日檢視僅看當天、週檢視／老師下拉看整週。 */
const courseMatchesStudentSearch = (c) => {
  const q = (studentSearch.value || '').trim().toLowerCase();
  if (!q) return true;
  let label = (c.student_name && c.student_name !== '—') ? String(c.student_name) : '';
  if (!label && c.student_id) {
    const fromCourse = courses.value.find((x) => x.student_id === c.student_id);
    if (fromCourse?.student_name && fromCourse.student_name !== '—') label = String(fromCourse.student_name);
    else {
      const fromList = allStudents.value.find((s) => s.id === c.student_id);
      label = fromList?.name ? String(fromList.name) : '';
    }
  }
  return label.toLowerCase().includes(q);
};

// Week Overview mode
const isWeekOverview = ref(isTeacher.value);
const weekViewTeacherIds = ref([]);
const weekViewTeacherIdSet = computed(() => new Set(weekViewTeacherIds.value.map(String)));
const weekViewExpandedTeacherIdSet = computed(() => {
  const expanded = new Set();
  weekViewTeacherIds.value.forEach((id) => {
    const selected = visibleTeachers.value.find((t) => String(t.id) === String(id));
    const aliases = Array.isArray(selected?.alias_ids) && selected.alias_ids.length > 0
      ? selected.alias_ids
      : [id];
    aliases.forEach((aliasId) => expanded.add(String(aliasId)));
  });
  return expanded;
});

function toggleTeacherSelection(teacherId) {
  const tid = String(teacherId);
  const idx = weekViewTeacherIds.value.findIndex(id => String(id) === tid);
  if (idx >= 0) {
    weekViewTeacherIds.value.splice(idx, 1);
  } else {
    weekViewTeacherIds.value.push(teacherId);
  }
}
function clearTeacherSelection() {
  weekViewTeacherIds.value = [];
}

// Room management
const roomList = ref([]);
const showRoomManager = ref(false);
const roomForm = ref({ name: '', capacity: 1 });
const editingRoomId = ref(null);
const schedulerStudents = computed(() => (
  (allStudents.value || []).map((s) => ({
    id: Number(s?.id ?? 0),
    name: s?.name || `#${s?.id ?? ''}`,
  })).filter((s) => Number.isFinite(s.id) && s.id > 0)
));
const studentSelectOptions = computed(() => (
  schedulerStudents.value.map((student) => ({
    value: student.id,
    label: student.name,
  }))
));
const schedulerTeachers = computed(() => (
  (teachers.value || []).map((t) => ({
    id: Number(t?.id ?? 0),
    name: t?.username || t?.name || t?.Name || `#${t?.id ?? ''}`,
  })).filter((t) => Number.isFinite(t.id) && t.id > 0)
));
const schedulerRooms = computed(() => (
  (roomList.value || []).map((r) => ({
    id: Number(r?.id ?? 0),
    name: r?.name || `#${r?.id ?? ''}`,
  })).filter((r) => Number.isFinite(r.id) && r.id > 0)
));

const dayNames = ['週一', '週二', '週三', '週四', '週五', '週六', '週日'];
const hours = [8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22];

/** 開始時間選項：08:00, 08:30, 09:00, ... 22:00（拖放調課與選單皆可選半點） */
const timeOptions30 = Array.from({ length: (22 - 8 + 1) * 2 }, (_, i) => {
  const h = 8 + Math.floor(i / 2);
  const m = (i % 2) * 30;
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
});
const settlementDayOptions = Array.from({ length: 31 }, (_, index) => index + 1);

const modalForm = ref({
  student_id: '', subject: 'Math', teacher_id: '', class_type: 'one_on_one',
  day_of_week: 1, days_of_week: [], start_time: '16:00', end_time: '18:00',
  duration_hours: 2, rate_per_30min: 500, sessions_purchased: 8,
  settlement_day: null, monthly_sessions: 4,
  first_class_date: '', // 首堂上課日期 (First Class Date)
  action_date: '' // The exact absolute date clicked
});

// Add watch for auto-calculation after selection
watch(() => modalForm.value.start_time, (newVal) => {
  if (newVal) {
    modalForm.value.end_time = computeEndTime(newVal, modalForm.value.duration_hours);
  }
});
watch(() => modalForm.value.duration_hours, (newVal) => {
  if (newVal) {
    modalForm.value.end_time = computeEndTime(modalForm.value.start_time, newVal);
  }
});

const ratePer2h = ref(2000);
function syncRatePer2hToModel() {
  if (!modalForm.value) return;
  modalForm.value.rate_per_30min = Math.max(0, Math.round(Number(ratePer2h.value) || 0));
}
function syncRatePer2hFromModel() {
  ratePer2h.value = modalForm.value?.rate_per_30min ?? 0;
}
function syncRatePer2hBeforeSubmitModal() {
  syncRatePer2hToModel();
}

// #740 Step 3：getTeacherColor（含 memo 快取 palette/teacherColorMap）已剝離至
// ../lib/teacherColor.js（含 Cache Hit/Miss 單元測試 teacherColor.test.js）。

// --- Helpers ---
const getSubjectLabel = (val) => getSubjectText(val);
// #740 Step 2：classTypeLabel / dayLabel / dayOfWeekFromDate / getWeekLabel /
// parseHour / TIME_STEP_MINUTES / normalizeTimeTo30 / computeEndTime
// 已剝離至 ../lib/calendarFormat.js（純函式 + 單元測試 calendarFormat.test.js）。

// 取得「下一個星期一」作為首堂日預設（若今天已過則下週一）
const getDefaultFirstClassDate = () => {
  const d = new Date();
  const day = d.getDay();
  const diff = day === 0 ? 1 : day === 1 ? 0 : 8 - day;
  d.setDate(d.getDate() + diff);
  return formatLocalDate(d);
};

// 計算當週各天的真實日期 (用於顯示與合併)，支援上週/下週偏移
const getDisplayDateFull = (dayOfWeek) => {
  const weekNum = displayWeek.value || 1;
  const year = displayYear.value;
  const month = displayMonth.value;
  const refMonday = getMondayOfMonthWeek(year, month, weekNum);
  const actualMonday = addDays(refMonday, weekOffset.value * 7);
  return addDays(actualMonday, dayOfWeek - 1);
};

/**
 * 以「目前週檢視實際渲染的週一～週日」計算 ClassSession / schedules 抓取範圍（再向兩側各三週），
 * 避免僅依開頁月份或錯用之 Date month 索引導致週邊日期堂次載不到。
 */
function getCalendarDataFetchBoundsYmd() {
  const ymds = [];
  for (let dow = 1; dow <= 7; dow += 1) {
    const ymd = getDisplayDateFull(dow);
    if (ymd) ymds.push(String(ymd).slice(0, 10));
  }
  return resolveCalendarDataFetchBoundsYmd(ymds, {
    displayYear: displayYear.value,
    displayMonth: displayMonth.value,
  });
}

// 目前週檢視實際渲染的週一～週日日期範圍（YYYY-MM-DD），供視窗包含判斷用。
const getVisibleWeekRangeYmd = () => {
  const ymds = [];
  for (let dow = 1; dow <= 7; dow += 1) {
    const ymd = getDisplayDateFull(dow);
    if (ymd) ymds.push(String(ymd).slice(0, 10));
  }
  ymds.sort();
  return ymds.length ? { min: ymds[0], max: ymds[ymds.length - 1] } : null;
};

const {
  courses,
  exceptions,
  calendarLoading,
  calendarLoadProgress,
  sessionDatesByCourseId,
  allStudents,
  teachers,
  lastCalendarFetch,
  loadCourses,
  loadTeachers,
  reloadCalendarData: reloadCalendarDataCore,
} = useCalendarDataLoad({
  branchId: computed(() => props.branchId),
  userId: computed(() => props.userId),
  isTeacher,
  viewMode,
  getCalendarDataFetchBoundsYmd,
});

const {
  showLeaveModal, leaveForm, leaveDisplay, openLeaveModal, submitLeave, onContextLeave,
  showExtraModal, extraForm, computedExtraEndTime, extraParentPaymentType,
  onExtraFormStartTimeChange, onExtraFormTimeChange, openExtraLesson, submitExtraLesson,
} = useCalendarLeaveExtra({
  supabase,
  branchId: computed(() => props.branchId),
  showModal,
  modalForm,
  editingCourseId,
  contextMenu,
  loadCourses,
  getToken,
  allStudents,
  getSubjectLabel,
});

const {
  featureSubstituteV2,
  showSubstituteModal, substituteForm, substituteSubmitting, substituteDisplay,
  openSubstituteModal, openSubstituteFromDrag, submitSubstitute,
  showSubstituteV2Modal, substituteV2PickerRef, toastRef, substituteV2Context,
  substituteV2SessionId, substituteV2Submitting, branchNameMap,
  openSubstituteV2Modal, onSubstituteV2Submit, showTeacherLeaveBatchModal,
  openTeacherLeaveBatch, onBatchSubstituteSubmitted,
} = useCalendarSubstitute({
  branchId: computed(() => props.branchId),
  showModal,
  modalForm,
  editingCourseId,
  loadCourses,
  teachers,
  sessionDatesByCourseId,
  allStudents,
  getSubjectLabel,
});

const {
  showRescheduleModal, rescheduleForm, rescheduleDisplay, computedRescheduleNewEnd,
  onRescheduleNewStartChange, openRescheduleModal, submitReschedule,
} = useCalendarReschedule({
  supabase,
  branchId: computed(() => props.branchId),
  showModal,
  modalForm,
  editingCourseId,
  loadCourses,
  getToken,
  allStudents,
  courses,
  exceptions,
  getSubjectLabel,
});

const prevWeek = () => { weekOffset.value -= 1; };
const nextWeek = () => { weekOffset.value += 1; };

const jumpToDateWeek = () => {
  const ymd = String(jumpToDate.value || '').slice(0, 10);
  if (!ymd) return;
  const target = new Date(ymd + 'T12:00:00');
  if (Number.isNaN(target.getTime())) return;

  displayYear.value = target.getFullYear();
  displayMonth.value = target.getMonth() + 1;
  displayWeek.value = getWeekNumberOfDate(ymd);
  weekOffset.value = 0;
  const dow = target.getDay();
  selectedDayIdx.value = dow === 0 ? 6 : dow - 1;
};

const focusCalendarToday = () => {
  const today = new Date();
  const ymd = formatLocalDate(today);
  displayYear.value = today.getFullYear();
  displayMonth.value = today.getMonth() + 1;
  displayWeek.value = getWeekNumberOfDate(ymd);
  weekOffset.value = 0;
  selectedDayIdx.value = today.getDay() === 0 ? 6 : today.getDay() - 1;
  jumpToDate.value = ymd;
  isWeekOverview.value = isTeacher.value;
};



const getDisplayDateString = (dayOfWeek) => {
  const full = getDisplayDateFull(dayOfWeek);
  if (!full) return '';
  const [, m, d] = full.split('-');
  return `${parseInt(m)}/${parseInt(d)}`;
};

/**
 * 每門課的最後一堂日期，使用共用工具計算（與課程管理一致）。null 代表無限制（月結課程）。
 */
const courseLastSessionDate = computed(() => {
  const result = {};
  courses.value.forEach(c => {
    const cid = c.id != null ? String(c.id) : '';
    const dateSet = sessionDatesSetByCourseId.value[cid];
    if (dateSet && dateSet.size) {
      result[cid] = Array.from(dateSet).sort().pop();
      return;
    }
    result[cid] = null;
  });
  return result;
});

/** 每門課的「實際上課日」集合（僅此 N 堂會顯示）：使用共用工具確保與課程管理一致 */
const sessionDatesSetByCourseId = computed(() => {
  const out = {};
  courses.value.forEach((c) => {
    const cid = c.id != null ? String(c.id) : '';
    const rows = sessionDatesByCourseId.value[cid];
    if (!Array.isArray(rows) || rows.length === 0) return;
    const dates = rows
      .filter((row) => String(row?.status || '').toLowerCase() !== 'cancelled')
      .map((row) => String(row?.session_date || '').slice(0, 10))
      .filter(Boolean);
    const dateSet = new Set(dates);
    if (dateSet && dateSet.size) out[cid] = dateSet;
  });
  return out;
});

/** 堂數制：targetDate 是否在該課程的「實際上課日」清單內（有 API 資料時用此判斷，保證與課程管理一致） */
function isDateInSessionList(courseId, targetDate) {
  const key = courseId != null ? String(courseId) : '';
  const set = sessionDatesSetByCourseId.value[key];
  if (!set || !set.size) return null; // 無 API 資料，交給呼叫方用 fallback
  return set.has(String(targetDate).slice(0, 10));
}

/** 堂數制課程的「恰好 N 堂」日期 Set；優先使用 cache，否則呼叫共用工具計算 */
function getSessionDateSetForCourse(c) {
  if (!c) return null;
  const cid = c.id != null ? String(c.id) : '';
  const cached = sessionDatesSetByCourseId.value[cid];
  if (cached && cached.size) return cached;
  return null;
}

/** 由開始／結束時間推算時長（小時，一位小數），供課表格與 ClassSession 一致 */
function durationHoursFromStartEnd(start, end) {
  if (!start || !end) return null;
  const toM = (t) => {
    const [h, m] = String(t).split(':').map(Number);
    return (h || 0) * 60 + (m || 0);
  };
  const diff = toM(end) - toM(start);
  if (diff <= 0) return null;
  return Math.round((diff / 60) * 10) / 10;
}

/**
 * 智慧排課格子用的時段：優先該日的 ClassSession（與點名一致），否則後端 day_time_slots 對應星期幾，
 * 最後才用課程主檔 start_time（後端目前取 day_time_slots[0]，多日不同時會錯）。
 */
function resolveCourseGridTimes(c, dow, targetYmd) {
  const all = resolveAllCourseGridTimesForDate(c, dow, targetYmd);
  return all[0];
}

/**
 * 回傳該日所有堂次的時段陣列（同日多時段場景回傳多筆，例如週六 13:00 + 17:00）。
 */
function resolveAllCourseGridTimesForDate(c, dow, targetYmd) {
  const ymd = String(targetYmd || '').slice(0, 10);
  const rowKey = String((c && c.is_exception) ? (c.student_course_id ?? c.id) : (c?.id ?? ''));
  const rows = sessionDatesByCourseId.value[rowKey];
  if (ymd && Array.isArray(rows) && rows.length) {
    const sameDate = rows.filter((r) => String(r.session_date || '').slice(0, 10) === ymd);
    if (sameDate.length > 0) {
      const hits = sameDate.filter((r) => String(r?.status || '').toLowerCase() !== 'cancelled');
      if (hits.length > 0) {
        return hits.map((hit) => {
          const st = normalizeTimeTo30(hit.start_time);
          const en = hit.end_time ? normalizeTimeTo30(hit.end_time) : computeEndTime(st, c.duration_hours || 2);
          const dh = (durationHoursFromStartEnd(st, en) ?? Number(c.duration_hours)) || 2;
          const teacherFields = hit.teacher_id != null
            ? { teacher_id: hit.teacher_id, teacher_name: hit.teacher_name || c.teacher_name }
            : {};
          return { ...teacherFields, start_time: st, end_time: en, duration_hours: dh };
        });
      }
      // 當日僅有已取消堂次時，不可回退契約時段，否則課表仍會出現區塊 +「取消」角標
      return [];
    }
  }
  const slots = Array.isArray(c.day_time_slots) ? c.day_time_slots : [];
  const matchingSlots = slots.filter((s) => Number(s.day) === Number(dow));
  if (matchingSlots.length > 0) {
    return matchingSlots.map((slot) => {
      const st = normalizeTimeTo30(slot.start_time);
      const dh = Number(slot.duration_hours) || Number(c.duration_hours) || 2;
      const en = computeEndTime(st, dh);
      return { start_time: st, end_time: en, duration_hours: dh };
    });
  }
  const defSt = normalizeTimeTo30(c.start_time || '');
  const defDh = Number(c.duration_hours) || 2;
  return [{
    start_time: defSt,
    end_time: (c.end_time && normalizeTimeTo30(c.end_time)) || computeEndTime(defSt || '08:00', defDh),
    duration_hours: defDh,
  }];
}

/** 堂數制是否超過購買堂數；與課程管理一致：只到最後一堂日，之後一律視為超過 */
function isOverSessionLimit(courseId, targetDate) {
  const course = courses.value.find((item) => String(item.id) === String(courseId));
  const paymentType = String(course?.payment_type || (course?.ScheduleMode === 'count' ? 'session' : 'monthly') || 'session');
  if (paymentType !== 'session') return false;
  const key = courseId != null ? String(courseId) : '';
  const endDate = courseLastSessionDate.value[key];
  if (endDate == null) return true;
  return String(targetDate).slice(0, 10) > endDate;
}

const ATTENDED_STATUSES = new Set(['attended', 'completed', 'late', 'absent']);

function rollCallSessionKey(course) {
  return String(course.is_exception ? (course.student_course_id ?? course.id) : course.id);
}

function findSessionRowForCell(course, ymd) {
  const key = rollCallSessionKey(course);
  const rows = sessionDatesByCourseId.value[key];
  if (!Array.isArray(rows) || !rows.length) return null;
  const targetYmd = String(ymd || '').slice(0, 10);
  if (!targetYmd) return null;
  const courseStart = normalizeTimeTo30(course.start_time || '');
  const sameDateRows = rows.filter(r => String(r.session_date || '').slice(0, 10) === targetYmd);
  if (!sameDateRows.length) return null;

  const exactMatches = courseStart
    ? sameDateRows.filter(r => normalizeTimeTo30(r.start_time || '') === courseStart)
    : [];

  // FR-005: when the cell comes from a rescheduled-to exception entry, its
  // course.start_time IS the exception's new start_time. If the ClassSession row
  // for that date hasn't yet been synced to the new time (e.g. backend regression
  // or deployment lag), fall back to the first same-date row so the attendance
  // badge still mounts on the correct cell rather than disappearing.
  if (exactMatches.length) {
    return pickBestSessionRow(exactMatches);
  }
  if (course.is_exception) {
    return pickBestSessionRow(sameDateRows);
  }
  return null;
}

function rollCallBadge(course, ymd) {
  const row = findSessionRowForCell(course, ymd);
  if (!row) return null;
  const st = String(row.status || '').toLowerCase();
  if (st === 'leave' || st === 'leave_adjusted' || st === 'excused') return { kind: 'leave', label: '假' };
  if (st === 'cancelled') return { kind: 'cancelled', label: '取消' };
  if (ATTENDED_STATUSES.has(st) || row.attendance_sign_in_at) return { kind: 'done', label: '✓' };
  if (st === 'scheduled') {
    const endTime = row.end_time || '23:59';
    const sessionEnd = new Date(`${row.session_date}T${endTime}`);
    if (sessionEnd < new Date()) return { kind: 'missed', label: '!' };
  }
  return null;
}

const LEAVE_STATUSES = new Set(['leave', 'leave_adjusted', 'excused', 'cancelled']);

function evalBadge(course, ymd) {
  const row = findSessionRowForCell(course, ymd);
  if (!row) return null;
  const st = String(row.status || '').toLowerCase();
  if (LEAVE_STATUSES.has(st)) return null;
  const endTime = row.end_time || '23:59';
  const sessionEnd = new Date(`${row.session_date}T${endTime}`);
  if (sessionEnd >= new Date()) return null;
  if (row.learning_record_status === 'approved') return null;
  if (!row.learning_record_id || row.learning_record_status === 'missing' || !row.learning_record_body_filled) {
    return { kind: 'eval-missing', label: '評' };
  }
  return null;
}

// Get courses at a specific day + hour (start hour)
// 支援固定多日（如一四）：days_of_week 有值時在該幾天都顯示；堂數制：該格日期不得超過課程最後一堂日
const getCoursesAt = (dayOfWeek, hour) => {
  const dow = parseInt(dayOfWeek);
  const targetDate = getDisplayDateFull(dow);
  const targetYmd = targetDate ? String(targetDate).slice(0, 10) : '';
  return filteredCourses.value.filter(c => {
    const cStart = parseHour(c.start_time);
    if (cStart !== hour) return false;
    const days = (c.days_of_week && c.days_of_week.length) ? c.days_of_week : [parseInt(c.day_of_week) || 0];
    if (!days.includes(dow)) return false;
    if (targetYmd) {
      // Exception entries (rescheduled-to / extra) are explicitly placed on a date — never block them
      if (!c.is_exception) {
        const cid = String(c.student_course_id ?? c.id);
        const lastDate = courseLastSessionDate.value[cid];
        const paymentType = String(c.payment_type || (c.ScheduleMode === 'count' ? 'session' : 'monthly') || 'session');
        if (paymentType === 'session') {
          if (lastDate != null && targetYmd > lastDate) return false;
          const purchased = Math.max(0, parseInt(c.sessions_purchased ?? c.SessionCount ?? 0, 10) || 0);
          if (lastDate == null && purchased > 0) return false;
        }
      }
    }
    return true;
  });
};

const SLOT_HEIGHT = 56;
const SLOT_BORDER = 1;
const SLOT_TOTAL = SLOT_HEIGHT + SLOT_BORDER;
const getBlockHeight = (course) => {
  const dh = course.duration_hours || 1;
  return dh * SLOT_TOTAL - SLOT_BORDER - 6;
};

// Calculate style for course blocks to display side-by-side when overlapping
const getCourseBlockStyle = (course, dayOfWeek, hour, idx) => {
  const coursesAtSlot = getCoursesAt(dayOfWeek, hour);
  const total = coursesAtSlot.length;
  const gap = 2; // px gap between blocks

  if (total <= 1) {
    // Single course: full width
    return {
      background: getTeacherColor(course.teacher_id),
      height: getBlockHeight(course) + 'px',
      left: '2px',
      right: '2px'
    };
  }

  // Multiple courses: divide width equally
  const widthPercent = 100 / total;
  const leftPercent = idx * widthPercent;

  return {
    background: getTeacherColor(course.teacher_id),
    height: getBlockHeight(course) + 'px',
    left: `calc(${leftPercent}% + ${gap}px)`,
    width: `calc(${widthPercent}% - ${gap * 2}px)`,
    right: 'auto'
  };
};

const isSessionCancelledOnDate = (course, ymd) => {
  if (!ymd) return false;
  const key = String((course.is_exception ? (course.student_course_id ?? course.id) : course.id) ?? '');
  const rows = sessionDatesByCourseId.value[key];
  if (!Array.isArray(rows) || !rows.length) return false;
  const sameDate = rows.filter(r => String(r.session_date || '').slice(0, 10) === ymd);
  return sameDate.length > 0 && sameDate.every(r => String(r.status || '').toLowerCase() === 'cancelled');
};

// Returns true when the course on this date is on leave (請假 / 已請假).
// Two signals are consulted so the check works regardless of which source is
// already loaded in the current view:
//   1. ClassSession row status (sessionDatesByCourseId) — authoritative once loaded.
//   2. schedules exception (exceptions.value) with status='leave' — covers newly
//      created leave entries before the ClassSession list refreshes.
// Used by getSlotOccupancy so that a leave card keeps showing the 假 badge but
// frees up its seat in the capacity badge (e.g. 2/2 → 1/2) so another student
// can be booked into the same slot.
const isSessionOnLeaveOnDate = (course, ymd) => {
  if (!ymd) return false;
  const courseKey = String((course.is_exception ? (course.student_course_id ?? course.id) : course.id) ?? '');
  const rows = sessionDatesByCourseId.value[courseKey];
  if (Array.isArray(rows) && rows.length) {
    const sameDate = rows.filter(r => String(r.session_date || '').slice(0, 10) === ymd);
    if (sameDate.length > 0) {
      const allLeave = sameDate.every(r => LEAVE_STATUSES.has(String(r.status || '').toLowerCase()));
      if (allLeave) return true;
    }
  }
  const leaveCourseId = course.is_exception ? (course.student_course_id ?? course.id) : course.id;
  if (leaveCourseId == null) return false;
  return exceptions.value.some(ex =>
    String(ex.status || '').toLowerCase() === 'leave' &&
    toYmd(ex.schedule_date) === ymd &&
    String(ex.student_course_id) === String(leaveCourseId)
  );
};

const getCoursesForTeacherAt = (teacherId, hour) => {
  return filteredCourses.value.filter(c => {
    if (c.teacher_id !== teacherId) return false;
    if (parseHour(c.start_time) !== hour) return false;
    if (c.day_of_week !== selectedDow.value) return false;
    if (!courseMatchesStudentSearch(c)) return false;
    if (isSessionCancelledOnDate(c, selectedDateStr.value)) return false;
    return true;
  });
};

const getTeacherCourseBlockStyle = (course, teacherId, hour, idx) => {
  const coursesAtSlot = getCoursesForTeacherAt(teacherId, hour);
  const total = coursesAtSlot.length;
  const gap = 2;
  if (total <= 1) {
    return {
      background: getTeacherColor(course.teacher_id),
      height: getBlockHeight(course) + 'px',
      left: '2px',
      right: '2px'
    };
  }
  const widthPercent = 100 / total;
  return {
    background: getTeacherColor(course.teacher_id),
    height: getBlockHeight(course) + 'px',
    left: `calc(${idx * widthPercent}% + ${gap}px)`,
    width: `calc(${widthPercent}% - ${gap * 2}px)`,
    right: 'auto'
  };
};

const getDayCourseCount = (dow) => {
  const ymd = getDisplayDateFull(dow);
  return filteredCourses.value.filter(c =>
    c.day_of_week === dow &&
    courseMatchesStudentSearch(c) &&
    !isSessionCancelledOnDate(c, ymd)
  ).length;
};

// #740 Step 4b：DayTabsBar 的資料來源（父層 Container 計算，子層純渲染）
const dayTabs = computed(() => dayNames.map((name, idx) => ({
  name,
  dateLabel: getDisplayDateString(idx + 1),
  count: getDayCourseCount(idx + 1),
})));

// Week Overview helpers
const getCoursesForWeekCell = (dow, hour) => {
  return filteredCourses.value.filter(c => {
    if (weekViewTeacherIds.value.length > 0 && !weekViewExpandedTeacherIdSet.value.has(String(c.teacher_id))) return false;
    if (c.day_of_week !== dow) return false;
    if (parseHour(c.start_time) !== hour) return false;
    if (!courseMatchesStudentSearch(c)) return false;
    if (isSessionCancelledOnDate(c, getDisplayDateFull(dow))) return false;
    return true;
  });
};

const getWeekTeacherDayCount = (dow) => {
  return filteredCourses.value.filter(c => {
    if (weekViewTeacherIds.value.length > 0 && !weekViewExpandedTeacherIdSet.value.has(String(c.teacher_id))) return false;
    return c.day_of_week === dow && courseMatchesStudentSearch(c);
  }).length;
};

const getWeekCourseBlockStyle = (course, dow, hour, idx) => {
  const coursesAtSlot = getCoursesForWeekCell(dow, hour);
  const total = coursesAtSlot.length;
  const gap = 2;
  if (total <= 1) {
    return {
      background: getTeacherColor(course.teacher_id),
      height: getBlockHeight(course) + 'px',
      left: '2px',
      right: '2px'
    };
  }
  const widthPercent = 100 / total;
  return {
    background: getTeacherColor(course.teacher_id),
    height: getBlockHeight(course) + 'px',
    left: `calc(${idx * widthPercent}% + ${gap}px)`,
    width: `calc(${widthPercent}% - ${gap * 2}px)`,
    right: 'auto'
  };
};

// Combine active profiles with any teachers currently assigned to courses
const filterTeacherOptions = computed(() => {
  const map = new Map();
  // Add active teachers first
  teachers.value.forEach(t => map.set(t.id, t.username));
  // Add any teachers found in courses
  courses.value.forEach(c => {
    if (c.teacher_id && c.teacher_name && c.teacher_name !== '未指派' && !map.has(c.teacher_id)) {
      map.set(c.teacher_id, c.teacher_name);
    }
  });
  // Merge duplicate display names (same human teacher with multiple accounts),
  // and prefer the account currently carrying actual courses in calendar.
  const byName = new Map();
  Array.from(map.entries()).forEach(([id, username]) => {
    const name = String(username || '').trim();
    const tid = Number(id);
    if (!name || !Number.isFinite(tid) || tid <= 0) return;
    if (!byName.has(name)) byName.set(name, []);
    byName.get(name).push(tid);
  });

  return Array.from(byName.entries()).map(([username, ids]) => {
    const scored = ids
      .map((id) => ({
        id,
        courseCount: courses.value.filter((c) => Number(c.teacher_id || 0) === id).length,
      }))
      .sort((a, b) => {
        if (a.courseCount !== b.courseCount) return b.courseCount - a.courseCount;
        return a.id - b.id;
      });
    return {
      id: scored[0]?.id || ids[0],
      username,
      alias_ids: ids,
    };
  });
});

const visibleTeachers = computed(() => {
  const teacherList = filterTeacherOptions.value.map(t => {
    const aliasSet = new Set((t.alias_ids || [t.id]).map((id) => Number(id)));
    const teacherCourses = courses.value.filter(c => aliasSet.has(Number(c.teacher_id || 0)));
    const rooms = [...new Set(teacherCourses.map(c => c.room_id || c.RoomID).filter(Boolean))];
    return {
      ...t,
      roomLabel: rooms.length > 0 ? `教室 ${rooms.join(', ')}` : '',
      roomIds: rooms
    };
  });
  let filtered = teacherList;
  if (roomFilter.value) {
    filtered = filtered.filter(t => t.roomIds.includes(roomFilter.value));
  }
  if (teacherSearch.value) {
    const q = teacherSearch.value.toLowerCase();
    filtered = filtered.filter(t => t.username.toLowerCase().includes(q));
  }
  if (studentSearch.value.trim()) {
    const dowFilter = !isWeekOverview.value ? selectedDow.value : null;
    const tidSet = new Set(
      filteredCourses.value
        .filter((c) => {
          if (!courseMatchesStudentSearch(c)) return false;
          if (dowFilter != null && c.day_of_week !== dowFilter) return false;
          return true;
        })
        .map((c) => String(c.teacher_id))
    );
    filtered = filtered.filter((t) => {
      const aliases = Array.isArray(t.alias_ids) && t.alias_ids.length > 0 ? t.alias_ids : [t.id];
      return aliases.some((id) => tidSet.has(String(id)));
    });
  }
  if (filterTeacherId.value) {
    const tid = String(filterTeacherId.value);
    filtered = filtered.filter(t => String(t.id) === tid);
  }
  if (isTeacher.value && currentTeacherId.value) {
    filtered = filtered.filter(t => String(t.id) === currentTeacherId.value);
    // Defensive fallback: if options are stale, still render from current courses.
    if (filtered.length === 0) {
      const mine = courses.value.find(c => String(c.teacher_id) === currentTeacherId.value);
      if (mine) {
        filtered = [{
          id: mine.teacher_id,
          username: mine.teacher_name || '我的課表',
          roomLabel: '',
          roomIds: [],
        }];
      }
    }
  }
  // 日檢視：當日有排課的老師優先置左，無課老師排後方，減少橫向捲動
  const dowForSort = !isWeekOverview.value ? selectedDow.value : null;
  const ymdForSort = !isWeekOverview.value ? selectedDateStr.value : null;
  const teacherHasCourseToday = (tid) => {
    if (dowForSort == null) return false;
    return filteredCourses.value.some((c) => {
      if (c.teacher_id !== tid) return false;
      if (c.day_of_week !== dowForSort) return false;
      if (ymdForSort && isSessionCancelledOnDate(c, ymdForSort)) return false;
      return true;
    });
  };
  const withBusyFlag = filtered.map((t) => ({
    ...t,
    _hasCourseToday: teacherHasCourseToday(t.id),
  }));
  if (hideEmptyTeacherColumns.value && !isWeekOverview.value) {
    return withBusyFlag
      .filter((t) => t._hasCourseToday)
      .sort((a, b) => {
        if (a.roomLabel !== b.roomLabel) return a.roomLabel.localeCompare(b.roomLabel);
        return a.username.localeCompare(b.username);
      });
  }
  return withBusyFlag.sort((a, b) => {
    const ap = a._hasCourseToday ? 0 : 1;
    const bp = b._hasCourseToday ? 0 : 1;
    if (ap !== bp) return ap - bp;
    if (a.roomLabel !== b.roomLabel) return a.roomLabel.localeCompare(b.roomLabel);
    return a.username.localeCompare(b.username);
  });
});

// #740 Step 4c：WeekTeacherChips 資料源（color 由父層預算，active 時套用）
const teacherChips = computed(() => visibleTeachers.value.map(t => ({
  id: t.id,
  username: t.username,
  color: getTeacherColor(t.id),
})));
const selectedTeacherChipIds = computed(() => weekViewTeacherIds.value.map(String));

const dayViewTeacherColumns = computed(() => {
  if (isWeekOverview.value) return visibleTeachers.value;
  if (weekViewTeacherIds.value.length === 0) return visibleTeachers.value;
  return visibleTeachers.value.filter(t =>
    weekViewTeacherIdSet.value.has(String(t.id))
  );
});

/** 週檢視目前選定老師名稱（多選時顯示聯集） */
const weekViewSelectedLabel = computed(() => {
  if (weekViewTeacherIds.value.length === 0) return '全部老師';
  const names = weekViewTeacherIds.value
    .map(id => visibleTeachers.value.find(t => String(t.id) === String(id))?.username)
    .filter(Boolean);
  if (names.length <= 3) return names.join('、');
  return names.slice(0, 2).join('、') + ` 等 ${names.length} 位`;
});

const allRoomOptions = computed(() => {
  const roomSet = new Set();
  courses.value.forEach(c => {
    const rid = c.room_id || c.RoomID;
    if (rid) roomSet.add(String(rid));
  });
  return Array.from(roomSet).sort();
});

const isTeacherGridCompact = computed(() => visibleTeachers.value.length >= 10);
const gridTemplateStyle = computed(() => {
  const count = Math.max(1, visibleTeachers.value.length);
  const minColWidth = isTeacherGridCompact.value ? 140 : 150;
  return {
    gridTemplateColumns: `56px repeat(${count}, minmax(${minColWidth}px, 1fr))`,
  };
});

const displayWeekFilteredCourses = computed(() => {
  if (displayWeek.value === 0) return courses.value;
  return courses.value.filter(c => {
    const wks = c.weeks || [1, 2, 3, 4, 5];
    return wks.includes(displayWeek.value);
  });
});

const resolveStudentName = (sid) => {
  if (!sid) return null;
  const fromCourse = courses.value.find(c => c.student_id === sid);
  if (fromCourse?.student_name && fromCourse.student_name !== '—') return fromCourse.student_name;
  const fromList = allStudents.value.find(s => s.id === sid);
  return fromList?.name || null;
};
const resolveTeacherName = (tid) => {
  if (!tid) return null;
  const fromCourse = courses.value.find(c => c.teacher_id === tid);
  if (fromCourse?.teacher_name && fromCourse.teacher_name !== '未指派') return fromCourse.teacher_name;
  const fromList = teachers.value.find(t => t.id === tid);
  return fromList?.username || null;
};

const filteredCourses = computed(() => {
  let list = displayWeekFilteredCourses.value;
  const teacherScopeId = isTeacher.value && currentTeacherId.value ? String(currentTeacherId.value) : '';
  // 週檢視：不可在合併 schedules 前先依 course.teacher_id 過濾——代課-only 契約仍為正班 TeacherID，
  // 合併後例外列才會帶代課老師的 teacher_id。非週檢視維持原過濾。
  if (teacherScopeId && viewMode.value !== 'week') {
    list = list.filter((c) => String(c.teacher_id) === teacherScopeId);
  }
  if (filterTeacherId.value && viewMode.value !== 'week') {
    const selectedTeacherId = String(filterTeacherId.value);
    list = list.filter(c => String(c.teacher_id ?? '') === selectedTeacherId);
  }
  
  // Merge exceptions based on the exact date of the current week view
  if (viewMode.value === 'week') {
    const weekDatesByDow = {};
    for (let dow = 1; dow <= 7; dow += 1) {
      weekDatesByDow[dow] = getDisplayDateFull(dow);
    }

    return mergeWeekCalendarOccurrences({
      courses: list,
      allCourses: courses.value,
      exceptions: exceptions.value,
      sessionDatesByCourseId: sessionDatesByCourseId.value,
      weekDatesByDow,
      courseLastSessionDate: courseLastSessionDate.value,
      resolveAllCourseGridTimesForDate,
      isOverSessionLimit,
      normalizeTime: normalizeTimeTo30,
      computeEndTime,
      resolveStudentName,
      resolveTeacherName,
      filterTeacherId: filterTeacherId.value,
      teacherScopeId,
      isTeacher: isTeacher.value,
      currentTeacherId: currentTeacherId.value,
    });
  }
  
  return list;
});

const weekCourseCount = computed(() => filteredCourses.value.filter(courseMatchesStudentSearch).length);


// Teacher-grouped view
const teacherGroups = computed(() => {
  const map = {};
  filteredCourses.value.forEach(c => {
    const tid = c.teacher_id || 'unassigned';
    const tname = c.teacher_name || '未指派';
    if (!map[tid]) map[tid] = { teacher_id: tid, teacher_name: tname, courses: [], open: true };
    map[tid].courses.push(c);
  });
  // Sort courses within each group by day then time
  Object.values(map).forEach(g => {
    g.courses.sort((a, b) => (a.day_of_week - b.day_of_week) || (a.start_time || '').localeCompare(b.start_time || ''));
  });
  return Object.values(map).sort((a, b) => b.courses.length - a.courses.length);
});

// --- Room Loading & CRUD ---
const loadRooms = async () => {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
  const token = session?.access_token || '';
  if (!token || (!isTeacher.value && !props.branchId)) return;
  try {
    const baseUrl = import.meta.env.VITE_API_BASE || '/api';
    const roomParams = props.branchId ? `branch_id=${props.branchId}` : '';
    const res = await fetch(`${baseUrl}/v1/rooms?${roomParams}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    });
    if (res.ok) {
      const json = await res.json();
      roomList.value = Array.isArray(json) ? json : (json?.data || []);
    }
  } catch (e) {
    // Keep UI usable even if room options fail to load.
  }
};

const saveRoom = async () => {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
  const token = session?.access_token || '';
  if (!token) return;
  const baseUrl = import.meta.env.VITE_API_BASE || '/api';
  const payload = { name: roomForm.value.name, capacity: Number(roomForm.value.capacity) || 1, campus_id: Number(props.branchId) };
  try {
    if (editingRoomId.value) {
      await fetch(`${baseUrl}/v1/rooms/${editingRoomId.value}`, {
        method: 'PUT', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
    } else {
      await fetch(`${baseUrl}/v1/rooms`, {
        method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
    }
    editingRoomId.value = null;
    roomForm.value = { name: '', capacity: 1 };
    await loadRooms();
  } catch (e) {
    alert('儲存教室失敗：' + e.message);
  }
};

const editRoom = (room) => {
  editingRoomId.value = room.id;
  roomForm.value = { name: room.name, capacity: room.capacity };
};

const deleteRoom = async (room) => {
  if (!confirm(`確定刪除教室「${room.name}」？`)) return;
  const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
  const token = session?.access_token || '';
  const baseUrl = import.meta.env.VITE_API_BASE || '/api';
  try {
    await fetch(`${baseUrl}/v1/rooms/${room.id}`, {
      method: 'DELETE', headers: { Authorization: `Bearer ${token}` }
    });
    await loadRooms();
  } catch (e) {
    alert('刪除教室失敗：' + e.message);
  }
};

const cancelRoomEdit = () => {
  editingRoomId.value = null;
  roomForm.value = { name: '', capacity: 1 };
};

// --- Room Capacity Helper ---
const getRoomUsageAt = (dow, hour) => {
  const totalCapacity = roomList.value.reduce((sum, r) => sum + (r.capacity || 1), 0);
  if (totalCapacity === 0) return { occupied: 0, totalCapacity: 0, isFull: false };
  const teachersAtSlot = new Set();
  filteredCourses.value.forEach(c => {
    if (c.day_of_week !== dow) return;
    const cStart = parseHour(c.start_time);
    const cEnd = cStart + (c.duration_hours || 1);
    if (hour >= cStart && hour < cEnd) {
      teachersAtSlot.add(c.teacher_id);
    }
  });
  return { occupied: teachersAtSlot.size, totalCapacity, isFull: teachersAtSlot.size >= totalCapacity };
};

const isSlotRoomFull = (dow, hour) => {
  if (roomList.value.length === 0) return false;
  return getRoomUsageAt(dow, hour).isFull;
};

// --- Conflict Detection ---
// Rules: one_on_one max 1, one_on_two max 2, one_on_three max 3, tutoring counts as 1
const CAPACITY_MAP = { 'one_on_one': 1, 'one_on_two': 2, 'one_on_three': 3, 'tutoring': 1, 'trial': 1 };

const TEACHER_SLOT_ABSOLUTE_MAX = 3;
const getSlotOccupancy = (teacherId, dow, hour) => {
  const coursesAtSlot = filteredCourses.value.filter(c => {
    if (c.teacher_id !== teacherId) return false;
    if (c.day_of_week !== dow) return false;
    if (parseHour(c.start_time) !== hour) return false;
    if (!courseMatchesStudentSearch(c)) return false;
    const ymd = viewMode.value === 'day' ? selectedDateStr.value : getDisplayDateFull(dow);
    if (isSessionCancelledOnDate(c, ymd)) return false;
    // 已請假的課程不占用容量徽章的名額 — 該時段仍可排新學生。
    // 課程方塊本身仍會顯示（帶「假」角標），只是不算進 count。
    if (isSessionOnLeaveOnDate(c, ymd)) return false;
    return true;
  });
  if (coursesAtSlot.length === 0) {
    return { count: 0, max: TEACHER_SLOT_ABSOLUTE_MAX, color: '#10b981', label: '', tooltip: '' };
  }
  const types = coursesAtSlot.map(c => String(c.class_type || ''));
  let max = TEACHER_SLOT_ABSOLUTE_MAX;
  if (types.includes('one_on_one')) max = 1;
  else if (types.includes('one_on_two')) max = 2;
  const rawCount = coursesAtSlot.length;
  const count = Math.min(rawCount, max);
  const remaining = Math.max(0, max - count);
  const color = count >= max ? '#ef4444' : remaining === 1 ? '#f59e0b' : '#10b981';
  const status = count >= max ? '已滿' : `可再收 ${remaining} 位`;
  const tooltip = `此時段學生 ${count} 位（上限 ${max} 位，${status}）`;
  return { count, max, color, label: `${count}/${max}`, tooltip, raw: rawCount, courses: coursesAtSlot };
};

const checkConflict = () => {
  if (!modalForm.value.teacher_id || !modalForm.value.day_of_week || !modalForm.value.start_time) {
    conflictWarning.value = '';
    return;
  }

  const tid = modalForm.value.teacher_id;
  const dow = modalForm.value.day_of_week;
  const startH = parseHour(modalForm.value.start_time);
  const dur = modalForm.value.duration_hours || 2;
  const endH = startH + dur;
  const tName = teachers.value.find(t => t.id === tid)?.username || '老師';

  const overlapping = courses.value.filter(c => {
    if (c.id === editingCourseId.value) return false;
    if (c.teacher_id !== tid || c.day_of_week !== dow) return false;
    const cStart = parseHour(c.start_time);
    const cEnd = cStart + (c.duration_hours || 1);
    return startH < cEnd && endH > cStart;
  });

  // Room capacity check: if all rooms full and this teacher doesn't already teach at this slot
  if (roomList.value.length > 0) {
    const usage = getRoomUsageAt(dow, startH);
    const teacherAlreadyAtSlot = overlapping.length > 0;
    if (usage.isFull && !teacherAlreadyAtSlot) {
      conflictWarning.value = `${dayLabel(dow)} ${modalForm.value.start_time} 所有教室已滿（${usage.occupied}/${usage.totalCapacity}），無法排課`;
      return;
    }
  }

  if (overlapping.length === 0) {
    conflictWarning.value = '';
    return;
  }

  const newMax = CAPACITY_MAP[modalForm.value.class_type] || 1;

  if (overlapping.length >= newMax) {
    const names = overlapping.map(c => c.student_name).join('、');
    const typeLabel = classTypeLabel(modalForm.value.class_type);
    conflictWarning.value = `${tName} ${dayLabel(dow)} 已有 ${overlapping.length} 堂課（${names}），${typeLabel}最多 ${newMax} 堂`;
    return;
  }

  const blockedBy = overlapping.find(c => {
    const existingMax = CAPACITY_MAP[c.class_type] || 1;
    return overlapping.length >= existingMax;
  });
  if (blockedBy) {
    const existingLabel = classTypeLabel(blockedBy.class_type);
    conflictWarning.value = `${tName} ${dayLabel(dow)} 已有${existingLabel}課程（${blockedBy.student_name}），已達上限無法再加課`;
    return;
  }

  conflictWarning.value = '';
};

// 主表單：開始時間改為 30 分鐘步進並同步結束時間
const onMainFormStartTimeChange = () => {
  modalForm.value.start_time = normalizeTimeTo30(modalForm.value.start_time);
  modalForm.value.end_time = computeEndTime(modalForm.value.start_time, modalForm.value.duration_hours);
  checkConflict();
};
const onMainFormTimeChange = () => {
  modalForm.value.end_time = computeEndTime(modalForm.value.start_time, modalForm.value.duration_hours);
  checkConflict();
};

// 主表單預計結束時間（僅顯示用，實際寫入仍用 modalForm.end_time）
const computedMainEndTime = computed(() =>
  computeEndTime(modalForm.value.start_time, modalForm.value.duration_hours)
);

// 本堂費用顯示：
// - session mode（按堂計費）：Single Source of Truth = 合約 Rate（rate_per_30min），
//   不讀取可能落伍的 session_charge 衍生值，確保與排課設定一致。
// - hour mode（按時計費）：優先讀取 ClassSession.session_charge（實際時長計費結果）；
//   未設定則 fallback 為 Rate × 標準時長作為標準費。
const currentSessionChargeDisplay = computed(() => {
  const courseId = editingCourseId.value;
  const dateStr = editingActionDate.value;
  const start = modalForm.value?.start_time;
  if (!courseId || !dateStr) return null;

  const rateUnit = String(modalForm.value?.rate_unit || 'session').toLowerCase();
  const rate = Number(modalForm.value?.rate_per_30min ?? 0);
  const durationHours = Number(modalForm.value?.duration_hours ?? 0);

  if (rateUnit === 'session') {
    if (rate > 0) {
      return { value: Math.round(rate), isAdjusted: false };
    }
    return null;
  }

  // hour mode
  const sessions = (sessionDatesByCourseId.value && sessionDatesByCourseId.value[String(courseId)]) || [];
  let matched = null;
  if (Array.isArray(sessions)) {
    matched = sessions.find((s) => {
      if (String(s?.session_date || '').slice(0, 10) !== String(dateStr).slice(0, 10)) return false;
      if (start && s?.start_time && String(s.start_time).slice(0, 5) !== String(start).slice(0, 5)) return false;
      return true;
    });
  }

  if (matched && matched.session_charge != null) {
    return { value: Number(matched.session_charge), isAdjusted: true };
  }

  if (rate > 0 && durationHours > 0) {
    const standard = Math.round(rate * durationHours);
    return { value: standard, isAdjusted: false };
  }
  return null;
});

const handleUniversalSchedulerSuccess = async () => {
  showModal.value = false;
  await loadCourses();
};

// --- Modal Actions ---
const openQuickAdd = () => {
  editingCourseId.value = null;
  conflictWarning.value = '';
  const start = '16:00';
  modalForm.value = {
    student_id: '', subject: 'Math', teacher_id: '', class_type: 'one_on_one',
    weeks: [1, 2, 3, 4, 5],
    day_of_week: 1, days_of_week: [1], start_time: start, end_time: computeEndTime(start, 2),
    duration_hours: 2, rate_per_30min: 500, sessions_purchased: 8, sessions_used: 0,
    settlement_day: null, monthly_sessions: 4,
    payment_type: 'session',
    first_class_date: getDefaultFirstClassDate(),
    action_date: ''
  };
  syncRatePer2hFromModel();
  editingActionDate.value = '';
  showModal.value = true;
};

const onSlotClick = (dayOfWeek, hour, fullDateStr, teacherId) => {
  editingCourseId.value = null;
  editingActionDate.value = '';
  editingException.value = null;
  conflictWarning.value = '';
  const start = `${String(hour).padStart(2, '0')}:00`;
  modalForm.value = {
    student_id: '', subject: 'Math', teacher_id: teacherId || '', class_type: 'one_on_one',
    weeks: [1, 2, 3, 4, 5],
    day_of_week: dayOfWeek, days_of_week: [dayOfWeek],
    start_time: start,
    end_time: computeEndTime(start, 2),
    duration_hours: 2, rate_per_30min: 500, sessions_purchased: 8, sessions_used: 0,
    settlement_day: null, monthly_sessions: 4,
    payment_type: 'session',
    first_class_date: fullDateStr || getDefaultFirstClassDate(),
    action_date: fullDateStr
  };
  syncRatePer2hFromModel();
  checkConflict();
  showModal.value = true;
};

const onCourseClick = (course, fullDateStr) => {
  const baseId = course.is_exception ? course.student_course_id : course.id;
  editingCourseId.value = baseId;
  editingActionDate.value = fullDateStr || '';
  editingException.value = course.is_exception ? course : null;
  conflictWarning.value = '';
  const start = normalizeTimeTo30(course.start_time || '16:00');
  const baseCourse = courses.value.find(c => c.id === baseId) || course;
  
  modalForm.value = {
    student_id: baseCourse.student_id || course.student_id,
    subject: baseCourse.subject || course.subject,
    teacher_id: baseCourse.teacher_id || course.teacher_id || '',
    class_type: baseCourse.class_type || course.class_type,
    weeks: baseCourse.weeks || [1, 2, 3, 4, 5],
    day_of_week: course.day_of_week,
    days_of_week: (baseCourse.days_of_week && baseCourse.days_of_week.length)
      ? [...baseCourse.days_of_week]
      : (course.day_of_week != null ? [course.day_of_week] : [1]),
    start_time: start,
    end_time: computeEndTime(start, course.duration_hours || 2),
    duration_hours: course.duration_hours || 2,
    rate_per_30min: baseCourse.rate_per_30min || 500,
    rate_unit: baseCourse.rate_unit || 'session',
    payment_type: baseCourse.payment_type || 'session',
    remaining_sessions: baseCourse.remaining_sessions || 0,
    settlement_day: baseCourse.settlement_day ?? null,
    monthly_sessions: baseCourse.monthly_sessions ?? null,
    first_class_date: baseCourse.first_class_date || '', // 編輯時可選填
    action_date: fullDateStr // Pass exact date clicked to Leaves/Reschedules
  };
  syncRatePer2hFromModel();
  showModal.value = true;
  // Load evaluation records for this course
  loadCourseEvalRecords(baseId);
};

const loadCourseEvalRecords = async (courseId) => {
  courseEvalRecords.value = [];
  evalRecordsLoading.value = true;
  try {
    const token = await getToken();
    const resp = await fetch(`/api/v1/learning-records?student_class_id=${courseId}&limit=100`, {
      headers: token ? { Authorization: `Bearer ${token}` } : {}
    });
    const json = await resp.json();
    const items = (json.data || json || []).filter(r => r.session_date && r.session_date <= new Date().toISOString().slice(0, 10));
    courseEvalRecords.value = items;
  } catch (e) {
    courseEvalRecords.value = [];
  } finally {
    evalRecordsLoading.value = false;
  }
};

const submitModal = async () => {
  syncRatePer2hBeforeSubmitModal();
  if (!modalForm.value.student_id) { alert('請選擇學生'); return; }
  if (!modalForm.value.teacher_id) { alert('請選擇老師'); return; }
  if (modalForm.value.payment_type === 'monthly') {
    if (!modalForm.value.settlement_day) { alert('月結課請選擇結算日'); return; }
    if ((Number(modalForm.value.monthly_sessions) || 0) <= 0) { alert('月結課請輸入本月預排堂數'); return; }
  }
  if (!editingCourseId.value && (modalForm.value.days_of_week || []).length === 0) {
    alert('請至少選擇一個排課日（一～日）'); return;
  }
  if (conflictWarning.value) { alert('衝堂！無法儲存，請調整老師或時段。'); return; }
  const endTime = computeEndTime(modalForm.value.start_time, modalForm.value.duration_hours);

  const norm = (t) => (t ? String(t).trim().slice(0, 5) : '');
  const payload = {
    student_id: modalForm.value.student_id,
    branch_id: props.branchId,
    subject: modalForm.value.subject,
    teacher_id: modalForm.value.teacher_id || null,
    class_type: modalForm.value.class_type,
    start_time: normalizeTimeTo30(modalForm.value.start_time),
    end_time: endTime,
    duration_hours: modalForm.value.duration_hours,
    rate_per_30min: modalForm.value.rate_per_30min,
    payment_type: modalForm.value.payment_type || 'session',
    settlement_day: modalForm.value.payment_type === 'monthly' ? modalForm.value.settlement_day || null : null,
    monthly_sessions: modalForm.value.payment_type === 'monthly' ? Math.max(1, Number(modalForm.value.monthly_sessions) || 1) : null,
    first_class_date: modalForm.value.first_class_date || null,
    weeks: modalForm.value.weeks || [1, 2, 3, 4, 5]
  };

  if (editingCourseId.value) {
    if (modalForm.value.payment_type === 'session') {
      payload.remaining_sessions = modalForm.value.remaining_sessions;
    }
    const current = courses.value.find((c) => c.id === editingCourseId.value);
    const siblingIds = current
      ? courses.value
          .filter(
            (c) =>
              c.student_id === current.student_id &&
              c.subject === current.subject &&
              String(c.teacher_id ?? '') === String(current.teacher_id ?? '') &&
              norm(c.start_time) === norm(current.start_time) &&
              norm(c.end_time) === norm(current.end_time)
          )
          .map((c) => c.id)
      : [editingCourseId.value];
    for (const id of siblingIds) {
      const res = await supabase.from('student-classes').update(payload).eq('id', id);
      if (res.error) {
        alert(res.error.message || '儲存失敗，請稍後再試');
        return;
      }
    }
  } else {
    const days = (modalForm.value.days_of_week || []).length > 0 ? modalForm.value.days_of_week : [modalForm.value.day_of_week || 1];

    payload.day_of_week = days[0];
    payload.days_of_week = days;
    if (modalForm.value.payment_type === 'session') {
      const purchased = modalForm.value.sessions_purchased || 8;
      payload.sessions_purchased = purchased;
      const usedSessions = Math.max(0, modalForm.value.sessions_used || 0);
      payload.sessions_used = usedSessions;
      payload.remaining_sessions = Math.max(0, purchased - usedSessions);
    }
    const res = await supabase.from('student-classes').insert(payload);
    if (res.error) {
      alert(res.error.message || '儲存失敗，請稍後再試');
      return;
    }
  }

  editingActionDate.value = '';
  showModal.value = false;
  await loadCourses();
  alert('已儲存');
};

const deleteCourse = async () => {
  if (!confirm('確定刪除此排課？')) return;
  await supabase.from('student-classes').delete().eq('id', editingCourseId.value);
  editingActionDate.value = '';
  showModal.value = false;
  await loadCourses();
};

const deleteException = async () => {
  const exc = editingException.value;
  if (!exc) return;
  const label = exc.subject ? `${exc.student_name} ${getSubjectLabel(exc.subject)}` : '';
  if (!confirm(`確定刪除此調課/加課？${label}`)) return;

  const originalId = exc.original_id;
  if (originalId) {
    await supabase.from('schedules').delete().eq('id', originalId);
  }
  const origSchedId = exc.original_schedule_id;
  if (origSchedId) {
    await supabase.from('schedules').delete().eq('id', origSchedId);
  } else {
    const paired = exceptions.value.find(ex =>
      ex.status === 'rescheduled' &&
      String(ex.student_course_id) === String(exc.student_course_id) &&
      ex.id !== originalId &&
      (!origSchedId || ex.id !== origSchedId)
    );
    if (paired) {
      await supabase.from('schedules').delete().eq('id', paired.id);
    }
  }

  editingException.value = null;
  showModal.value = false;
  await loadCourses();
  alert('已刪除此調課');
};

// ===== Cancel makeup class (取消補課) =====

const editingExceptionIsExtra = computed(() => {
  const exc = editingException.value;
  if (!exc?.original_id) return false;
  const src = exceptions.value.find(e => e.id === exc.original_id);
  return src?.type === 'extra' && src?.status === 'scheduled';
});

const cancelMakeupClass = async () => {
  const exc = editingException.value;
  if (!exc?.original_id) return;
  const label = [exc.student_name, getSubjectLabel(exc.subject || '')].filter(Boolean).join(' ');
  if (!confirm(`確定取消此補課排程？${label ? `（${label}）` : ''}\n取消後仍可在歷史記錄查閱，堂數不退還。`)) return;

  const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
  const token = session?.access_token || '';
  const res = await fetch(`/api/v1/schedules/${exc.original_id}/cancel-makeup`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });

  if (!res.ok) {
    const data = await res.json().catch(() => ({}));
    alert(data.error || '取消失敗，請稍後再試');
    return;
  }

  editingException.value = null;
  showModal.value = false;
  await loadCourses();
  alert('補課已取消');
};

// ===== Cancel single session (取消本堂) =====

const cancelState = ref({ show: false, loading: false });

const cancelTargetSession = computed(() => {
  if (!editingCourseId.value) return null;
  const dateStr = editingActionDate.value || modalForm.value?.action_date || selectedDateStr.value;
  if (!dateStr) return null;
  const course = courses.value.find((c) => String(c.id) === String(editingCourseId.value));
  if (!course) return null;
  return findSessionRowForCell(course, dateStr);
});

const canCancelSelectedSession = computed(() => {
  const row = cancelTargetSession.value;
  if (!row?.id) return false;
  const st = String(row?.status || '').toLowerCase();
  return st !== 'cancelled' && st !== 'voided';
});

// #740 Modals：sessionEdit 分組 props（display 類見 getStudentName 之後）
const sessionEditSession = computed(() => ({
  actionDate: editingActionDate.value,
  dayName: dayNames[(modalForm.value.day_of_week || 1) - 1] || '',
  endTime: computedMainEndTime.value,
  chargeDisplay: currentSessionChargeDisplay.value,
  conflictWarning: conflictWarning.value,
  isTeacher: isTeacher.value,
  featureSubstituteV2,
  canCancelSession: canCancelSelectedSession.value,
  cancelState: cancelState.value,
  editingException: !!editingException.value,
  editingExceptionIsExtra: editingExceptionIsExtra.value,
  evalRecords: courseEvalRecords.value,
  evalLoading: evalRecordsLoading.value,
}));
const sessionEditOptions = computed(() => ({
  studentSelectOptions: studentSelectOptions.value,
  subjectOptions: subjectOptions.value,
  teachers: teachers.value || [],
  settlementDayOptions,
}));

const doConfirmCancelSession = async () => {
  const row = cancelTargetSession.value;
  if (!row?.id || cancelState.value.loading) return;
  cancelState.value.loading = true;
  try {
    const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
    const token = session?.access_token || '';
    const res = await fetch(`/api/v1/class-sessions/${row.id}`, {
      method: 'PATCH',
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ status: 'cancelled' }),
    });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      throw new Error(err.message || `HTTP ${res.status}`);
    }
    const cid = String(editingCourseId.value);
    const rows = sessionDatesByCourseId.value[cid];
    if (Array.isArray(rows)) {
      const idx = rows.findIndex((r) => r.id === row.id);
      if (idx >= 0) rows[idx] = { ...rows[idx], status: 'cancelled' };
    }
    cancelState.value = { show: false, loading: false };
    showModal.value = false;
  } catch (e) {
    cancelState.value.loading = false;
    alert(e.message || '取消失敗，請重試');
  }
};

// ===== Right-click context menu =====
const onCourseRightClick = (course, date, event) => {
  event.preventDefault();
  event.stopPropagation();
  const x = event.clientX;
  const y = event.clientY;
  contextMenu.value = { show: true, x, y, course, date };
};

// ===== Drag-to-Reschedule =====
const onCourseDragStart = (course, date, event) => {
  draggingCourse.value = { course, originalDate: date };
  event.dataTransfer.effectAllowed = 'move';
};

const onSlotDrop = (dow, h, targetDate, teacherId) => {
  if (!draggingCourse.value) return;
  const { course, originalDate } = draggingCourse.value;
  draggingCourse.value = null;
  dragOverSlot.value = null;
  const sameSlot = targetDate === originalDate && parseHour(course.start_time) === h && (!teacherId || course.teacher_id === teacherId);
  if (sameSlot) return;
  // 同日、同一開始鐘點，拖到「另一位老師」欄 → 開代課確認（非調課）
  if (
    !isTeacher.value &&
    targetDate === originalDate &&
    parseHour(course.start_time) === h &&
    teacherId &&
    course.teacher_id &&
    Number(teacherId) !== Number(course.teacher_id)
  ) {
    openSubstituteFromDrag(course, originalDate, teacherId);
    return;
  }
  const newStart = `${String(h).padStart(2, '0')}:00`;
  const dur = course.duration_hours || 2;
  const baseId = course.is_exception ? course.student_course_id : course.id;
  const baseCourse = courses.value.find(c => c.id === baseId) || course;
  rescheduleForm.value = {
    student_id: course.student_id,
    subject: course.subject,
    course_id: baseId,
    original_day: course.day_of_week,
    original_start: course.start_time,
    original_end: course.end_time,
    original_date: originalDate,
    new_date: targetDate,
    new_day_of_week: dow,
    new_start: newStart,
    new_end: computeEndTime(newStart, dur),
    teacher_id: teacherId || course.teacher_id || baseCourse.teacher_id || '',
    class_type: course.class_type || baseCourse.class_type || 'one_on_one',
    duration_hours: dur
  };
  showRescheduleModal.value = true;
};

// ===== Helpers =====
const getStudentName = (sid) => {
  const s = allStudents.value.find(s => s.id === sid);
  return s ? s.name : '—';
};

const reloadCalendarData = () => reloadCalendarDataCore(loadRooms, loadSubjects);

watch(() => props.branchId, () => { reloadCalendarData(); });

watch(
  [displayYear, displayMonth, displayWeek, weekOffset],
  () => {
    const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
    const token = session?.access_token || '';
    const branchOk = Number(props.branchId || 0) > 0;
    const teacherOk = isTeacher.value && currentTeacherId.value;
    if (!token || (!branchOk && !teacherOk)) return;
    // TD-062 Phase 1：目標週若仍在上次抓取視窗內（同分校），跳過網路重抓——
    // 既有 courses/exceptions/sessionDates 已涵蓋此範圍，reactive computed 會直接重渲染。
    // 保守判斷：缺快取、跨分校、或超出視窗一律重抓；mutation 路徑不經此處（仍完整重抓）。
    if (isRangeWithinFetchedBounds(getVisibleWeekRangeYmd(), lastCalendarFetch.value, props.branchId)) {
      return;
    }
    loadCourses();
  },
);

watch(() => props.resetWeekToken, () => { focusCalendarToday(); }, { immediate: true });
watch(() => props.initialTeacherId, (id) => {
  if (id != null && id !== '') {
    filterTeacherId.value = String(id);
    weekViewTeacherIds.value = [id];
    emit('clear-initial-teacher');
  }
}, { immediate: true });
watch(visibleTeachers, (list) => {
  if (!Array.isArray(list) || list.length === 0) {
    weekViewTeacherIds.value = [];
    return;
  }
  const visibleIds = new Set(list.map(t => String(t.id)));
  if (filterTeacherId.value && !visibleIds.has(String(filterTeacherId.value))) {
    filterTeacherId.value = '';
  }
  const cleaned = weekViewTeacherIds.value.filter(id => visibleIds.has(String(id)));
  if (cleaned.length !== weekViewTeacherIds.value.length) {
    weekViewTeacherIds.value = cleaned;
  }
});
watch(() => displayWeek.value, () => { weekOffset.value = 0; });
watch(
  [displayYear, displayMonth, displayWeek, weekOffset, selectedDayIdx],
  () => {
    const focused = getDisplayDateFull(selectedDow.value);
    if (focused) jumpToDate.value = focused;
  },
  { immediate: true }
);
watch(showModal, (v) => { if (!v) cancelState.value = { show: false, loading: false }; });

watch(isWeekOverview, () => {
  // Multi-select: no need to auto-select first teacher; empty = show all
});
watch(isTeacher, (val) => {
  if (val) {
    isWeekOverview.value = true;
  }
});
onMounted(() => {
  reloadCalendarData();
  // 僅在左鍵點擊時關閉右鍵選單，避免右鍵觸發的後續事件誤關選單
  document.addEventListener('click', (e) => {
    if (e.button === 0) contextMenu.value.show = false;
  });
  document.addEventListener('contextmenu', (e) => {
    if (e.target.closest && e.target.closest('.context-menu')) return;
    if (e.target.closest && e.target.closest('.course-block')) return;
    contextMenu.value.show = false;
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') contextMenu.value.show = false; });
});
</script>

<style scoped>
/* ----- 字體與排版基底 ----- */
.calendar-loading-bar {
  text-align: center;
  padding: 8px 16px;
  background: var(--ds-primary-wash, #fff8e1);
  color: var(--ds-primary-deep, #e65100);
  font-size: 0.85rem;
  font-weight: 500;
  border-radius: 8px;
  margin-bottom: 8px;
  animation: pulse 1.5s ease-in-out infinite;
}
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.6} }
.smart-cal-top,
.week-view,
.teacher-view {
  font-size: 14px;
  line-height: 1.5;
  letter-spacing: 0.01em;
}
.smart-cal-top *,
.week-view *,
.teacher-view * {
  box-sizing: border-box;
}

/* ----- Top / Header ----- */
.smart-cal-top {
  margin-bottom: 24px;
  max-width: 100%;
  min-width: 0;
}
.smart-cal-header {
  display: flex;
  align-items: center;
  gap: 24px;
  margin-bottom: 16px;
}
.smart-cal-title {
  margin: 0;
  font-size: 1.375rem;
  font-weight: 700;
  line-height: 1.3;
  color: var(--text-color, #1a1a1a);
  letter-spacing: -0.02em;
}
.view-tabs {
  display: inline-flex;
  background: var(--bg-muted, #f0f1f3);
  border-radius: 10px;
  padding: 4px;
  gap: 2px;
}
.view-tabs button {
  padding: 8px 18px;
  border: none;
  border-radius: 8px;
  background: transparent;
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
  line-height: 1.4;
  color: var(--text-light, #64748b);
  transition: background 0.2s, color 0.2s;
}
.view-tabs button:hover {
  color: var(--text-color, #1a1a1a);
}
.view-tabs button.active {
  background: #fff;
  color: var(--primary, #2563eb);
  box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
.smart-cal-toolbar {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 0;
  padding: 12px 16px;
  background: #fff;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 12px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.04);
  min-width: 0;
  max-width: 100%;
  overflow-x: hidden;
}
.toolbar-row-primary {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 20px;
  row-gap: 10px;
  min-width: 0;
  max-width: 100%;
}
.toolbar-row-secondary {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 10px;
  padding-top: 14px;
  margin-top: 10px;
  border-top: 1px solid var(--border-color, #e2e8f0);
  min-width: 0;
  max-width: 100%;
}
.toolbar-secondary-line--meta {
  min-width: 0;
}
.toolbar-secondary-line--filters {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 10px 12px;
  align-items: start;
  width: 100%;
  min-width: 0;
}
.toolbar-secondary-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px 12px;
  min-width: 0;
}
.toolbar-secondary-meta .rc-legend {
  margin-left: 0;
}
.toolbar-secondary-mid {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 10px;
  min-width: 0;
  max-width: 100%;
}
.toolbar-secondary-actions {
  display: flex;
  flex-wrap: nowrap;
  align-items: center;
  justify-content: flex-end;
  gap: 6px;
  flex-shrink: 0;
  justify-self: end;
}
.toolbar-action-btn.btn-primary {
  padding: 8px 14px;
  border-radius: 9px;
  font-size: 13px;
}
.toolbar-action-btn.btn-secondary {
  padding: 8px 12px;
  border-radius: 9px;
  font-size: 13px;
}
/* #740 Step 4c：.week-teacher-chip* 已隨 markup 搬移至 components/calendar/WeekTeacherChips.vue */
/* #740 Step 5：.cb-teacher-tag 已搬移至 components/calendar/CourseBlockContent.vue */
.week-overview-body {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.week-overview-context-bar {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 8px 12px;
  padding: 8px 12px;
  background: var(--bg-muted, #f8fafc);
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 10px;
  font-size: 14px;
  color: var(--text-light, #64748b);
}
.week-overview-context-kicker {
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.04em;
  color: var(--text-light, #64748b);
  text-transform: uppercase;
}
.week-overview-context-name {
  font-size: 15px;
  font-weight: 700;
  color: var(--text-color, #1a1a1a);
}
.week-overview-empty {
  padding: 28px 20px !important;
}
.toolbar-group {
  display: flex;
  align-items: center;
  gap: 10px;
}
.toolbar-label {
  font-size: 14px;
  font-weight: 600;
  line-height: 1.4;
  color: var(--text-light, #64748b);
  min-width: 2.2em;
  text-align: right;
}
.month-nav {
  display: flex;
  align-items: center;
  gap: 6px;
}
.icon-btn {
  width: 34px;
  height: 34px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 8px;
  background: #fff;
  font-size: 1.25rem;
  line-height: 1;
  color: var(--text-color);
  cursor: pointer;
  transition: background 0.2s, border-color 0.2s;
}
.icon-btn:hover {
  background: var(--bg-muted, #f0f1f3);
  border-color: var(--border, #cbd5e1);
}
/* #740 Step 4d：.week-nav* / .week-select 已隨 markup 搬移至 components/calendar/WeekNavBar.vue */
.month-display {
  min-width: 82px;
  text-align: center;
  font-size: 14px;
  font-weight: 600;
  line-height: 1.4;
  color: var(--text-color);
}
.day-nav-bar {
  display: flex;
  align-items: center;
  gap: 6px;
}
.day-date-input {
  padding: 5px 10px;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 8px;
  font-size: 14px;
  background: #fff;
  color: var(--text-color);
  cursor: pointer;
}
.toolbar-fill { flex: 1; min-width: 12px; }
.week-stat {
  font-size: 14px;
  line-height: 1.4;
  color: var(--text-light, #64748b);
  white-space: nowrap;
}
.week-stat b { color: var(--text-color); font-weight: 700; }
.btn-icon-text {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
}
.btn-icon-text .btn-icon {
  font-size: 18px;
  line-height: 1;
  font-variation-settings: 'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 20;
}
.btn-icon-text .btn-text { font-weight: 600; }
.toolbar-teacher-leave-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: var(--ds-primary-wash, #fff7ed);
  border: 1px solid color-mix(in srgb, var(--ds-primary-deep, #e65100) 30%, #ffffff);
  color: var(--ds-primary-deep, #e65100);
  cursor: pointer;
}
.toolbar-teacher-leave-btn:hover {
  background: color-mix(in srgb, var(--ds-primary-wash, #fff7ed) 70%, #ffffff);
}
.teacher-filter .filter-select {
  padding: 8px 12px;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 8px;
  font-size: 14px;
  line-height: 1.4;
  background: #fff;
  min-width: 120px;
}
.btn-primary {
  padding: 10px 20px;
  border: none;
  border-radius: 10px;
  background: var(--primary, #2563eb);
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  line-height: 1.4;
  cursor: pointer;
  transition: background 0.2s, transform 0.1s;
}
.btn-primary:hover { filter: brightness(1.08); }
.btn-primary:active { transform: scale(0.98); }

/* ----- Day Tabs ----- */
/* #740 Step 4b：.day-tabs-bar / .day-tab*（base + 768px RWD）已隨 markup 搬移至
   components/calendar/DayTabsBar.vue（.active 改由 activeIdx 驅動） */

/* ----- Week/Teacher Grid View ----- */
.week-view {
  background: #fff;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 14px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
  overflow: hidden;
}
.teacher-grid-wrapper {
  max-height: min(76vh, 820px);
  overflow: auto;
  -webkit-overflow-scrolling: touch;
}
.teacher-grid {
  display: grid;
  min-width: 0;
}
.teacher-grid.teacher-grid-compact .col-header-blank {
  height: 56px;
}
/* #740 Step 4a：teacher-col-header/-avatar/-name/-room 的 compact 變體已移至 TeacherColumnHeader.vue（改 prop 驅動） */
.teacher-grid.teacher-grid-compact .course-block {
  padding: 4px 3px;
  border-radius: 6px;
}
/* #740 Step 5：compact cb-* 已改 prop 驅動（.cbc-compact），移至 CourseBlockContent.vue */
.time-col {
  position: sticky;
  left: 0;
  z-index: 5;
  border-right: 1px solid var(--border-color, #e2e8f0);
  background: var(--bg-muted, #f8fafc);
}
.col-header-blank {
  height: 64px;
  border-top: 3px solid transparent;
  border-bottom: 1px solid var(--border-color, #e2e8f0);
  position: sticky;
  top: 0;
  left: 0;
  z-index: 12;
  background: var(--bg-muted, #f8fafc);
}
.time-label {
  height: 56px;
  display: flex;
  align-items: flex-start;
  justify-content: flex-end;
  padding: 8px 10px 0 0;
  font-size: 12px;
  font-weight: 500;
  line-height: 1.3;
  color: var(--text-light, #64748b);
  border-top: 1px solid var(--border-color, #e2e8f0);
}
.teacher-col {
  border-right: 1px solid var(--border-color, #e2e8f0);
  min-width: 0;
}
.teacher-col:last-child { border-right: none; }
/* #740 Step 4a：.teacher-col-header / -avatar / -info / -name / -room（base + compact + RWD）
   已隨 markup 搬移至 components/calendar/TeacherColumnHeader.vue */
.slot {
  height: 56px;
  border-top: 1px solid var(--border-color, #f1f5f9);
  position: relative;
  cursor: pointer;
  transition: background 0.15s;
}
.slot:hover { background: #fffbeb; }
.slot.no-click { cursor: default; }
.slot.no-click:hover { background: transparent; }

/* Course Block */
.course-block {
  position: absolute;
  left: 3px;
  right: 3px;
  top: 3px;
  border-radius: 8px;
  padding: 5px 6px;
  color: #fff;
  font-size: 11px;
  line-height: 1.35;
  cursor: pointer;
  overflow: hidden;
  z-index: 2;
  box-shadow: 0 1px 4px rgba(0,0,0,0.15);
  transition: transform 0.12s, box-shadow 0.12s;
  display: flex;
  flex-direction: column;
  justify-content: flex-start;
}
.course-block:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}
/* #740 Step 5：.cb-student / .cb-detail / .cb-type 已搬移至 CourseBlockContent.vue */
.rc-tag {
  position: absolute;
  top: 2px;
  right: 3px;
  font-size: 9px;
  font-weight: 700;
  line-height: 1;
  padding: 1px 4px;
  border-radius: 4px;
  pointer-events: none;
}
.rc-done  { background: rgba(34,197,94,.85); color: #fff; }
.rc-missed { background: rgba(245,158,11,.9); color: #fff; }
.rc-leave { background: rgba(148,163,184,.75); color: #fff; }
.rc-cancelled { background: rgba(100,116,139,.6); color: #fff; font-size: 8px; }
.rc-eval-missing { background: rgba(239,68,68,.85); color: #fff; }
.rc-tag-second { top: auto; bottom: 2px; }
.rc-legend {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  color: var(--text-muted, #64748b);
  margin-left: 8px;
}
.rc-legend .rc-tag { position: static; display: inline-block; }

/* Toolbar filter input */
.toolbar-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  min-width: 0;
  width: 100%;
}
.toolbar-filters .toolbar-room-select,
.toolbar-filters .filter-select.toolbar-room-select {
  padding: 8px 10px;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 8px;
  font-size: 14px;
  line-height: 1.4;
  background: #fff;
  color: var(--text-color);
  flex: 0 1 148px;
  min-width: 88px;
  max-width: 100%;
  height: 38px;
  cursor: pointer;
}
.toolbar-filters .filter-input.toolbar-search-input {
  flex: 1 1 96px;
  min-width: 0;
  max-width: 100%;
  height: 38px;
}
.filter-input {
  padding: 8px 12px;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 8px;
  font-size: 14px;
  line-height: 1.4;
  background: #fff;
  min-width: 120px;
  outline: none;
  transition: border-color 0.2s;
}
.filter-input:focus { border-color: var(--primary, #2563eb); }
.filter-input::placeholder { color: var(--text-light, #94a3b8); }

/* PRD-G (2026-04-18 晚)：只看有課老師 toggle 排版修正。
   舊版 overflow-wrap:anywhere + flex-shrink:0 會讓 min-content=1 字元寬，
   造成中文字元於寬螢幕也被逐字折行變成直書（「只 顯 示 今 日 有 課 老 師」），整體很醜。
   改為固定單行 nowrap + flex:0 0 auto；平板窄螢幕時單獨佔整列並允許自然換行。*/
.toolbar-filters .toolbar-hide-empty-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 0 12px;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 8px;
  font-size: 13px;
  line-height: 1.2;
  background: #fff;
  color: var(--text, #334155);
  cursor: pointer;
  user-select: none;
  height: 38px;
  box-sizing: border-box;
  flex: 0 0 auto;
  white-space: nowrap;
  transition: border-color 0.15s, background 0.15s;
}
.toolbar-filters .toolbar-hide-empty-toggle:hover {
  border-color: var(--primary, #2563eb);
  background: #f8fafc;
}
.toolbar-filters .toolbar-hide-empty-toggle input[type="checkbox"] {
  accent-color: var(--primary, #2563eb);
  margin: 0;
  flex-shrink: 0;
  width: 16px;
  height: 16px;
  cursor: pointer;
}
/* 平板（iPad portrait ~768px 至 1024px）：toggle 獨佔一列以避免擠壓；touch target ≥44px。 */
@media (max-width: 1024px) {
  .toolbar-filters .toolbar-hide-empty-toggle {
    flex: 1 1 100%;
    height: auto;
    min-height: 44px;
    padding: 8px 12px;
    justify-content: flex-start;
    white-space: normal;
  }
}

/* ----- Teacher View ----- */
.teacher-view { padding: 0; }
.teacher-empty {
  padding: 48px 24px;
  text-align: center;
  font-size: 15px;
  color: var(--text-light, #64748b);
  background: #fff;
  border: 1px dashed var(--border-color, #e2e8f0);
  border-radius: 14px;
}
.teacher-card {
  margin-bottom: 16px;
  background: #fff;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 14px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
  overflow: hidden;
}
.teacher-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  cursor: pointer;
  transition: background 0.15s;
}
.teacher-card-header:hover { background: var(--bg-muted, #f8fafc); }
.teacher-info { display: flex; align-items: center; gap: 14px; }
.teacher-avatar {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-weight: 700;
  font-size: 18px;
  flex-shrink: 0;
}
.teacher-meta h3 {
  font-size: 16px;
  font-weight: 700;
  line-height: 1.3;
  margin: 0 0 4px 0;
  color: var(--text-color);
}
.teacher-count {
  font-size: 13px;
  line-height: 1.4;
  color: var(--text-light, #64748b);
}
.expand-arrow { color: var(--text-light, #94a3b8); font-size: 12px; line-height: 1; }
.teacher-courses {
  margin: 0;
  padding: 16px 20px 20px;
  border-top: 1px solid var(--border-color, #e2e8f0);
}
.teacher-table {
  width: 100%;
  font-size: 14px;
  line-height: 1.45;
  border-collapse: collapse;
}
.teacher-table th {
  text-align: left;
  padding: 10px 14px;
  font-weight: 600;
  font-size: 12px;
  line-height: 1.4;
  color: var(--text-light, #64748b);
  letter-spacing: 0.03em;
  border-bottom: 1px solid var(--border-color, #e2e8f0);
  vertical-align: middle;
}
.teacher-table td {
  padding: 12px 14px;
  line-height: 1.45;
  vertical-align: middle;
  border-bottom: 1px solid var(--border-color, #f1f5f9);
}
.teacher-table tr:last-child td { border-bottom: none; }
.status-tag.one_on_one { background: #fff7ed; color: #c2410c; padding: 2px 8px; border-radius: 6px; font-size: 12px; }
.status-tag.one_on_two { background: #fefce8; color: #a16207; padding: 2px 8px; border-radius: 6px; font-size: 12px; }
.status-tag.one_on_three { background: #fef2f2; color: #b91c1c; padding: 2px 8px; border-radius: 6px; font-size: 12px; }
.status-tag.tutoring { background: #f0fdf4; color: #15803d; padding: 2px 8px; border-radius: 6px; font-size: 12px; }
.status-tag.trial { background: #eef2ff; color: #4338ca; padding: 2px 8px; border-radius: 6px; font-size: 12px; }

/* #740 Modals：session-info / schedule-actions / eval-summary / conflict / cal-day-chip
   已搬移至 components/calendar/modals/*.vue */
.course-text { display: inline-flex; align-items: center; gap: 6px; }
.subject-tag { font-weight: 600; }
.week-tag {
  font-size: 10px;
  background: var(--bg-muted, #f0f1f3);
  color: var(--text-light, #64748b);
  padding: 2px 6px;
  border-radius: 4px;
  white-space: nowrap;
}

/* Drag-to-reschedule */
.slot.drag-over {
  background: #dbeafe !important;
  outline: 2px dashed #3b82f6;
  outline-offset: -2px;
}
.course-block[draggable="true"] { cursor: grab; }
.course-block[draggable="true"]:active { cursor: grabbing; }

/* Right-click context menu */
.context-menu {
  position: fixed;
  z-index: 9999;
  background: #fff;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 10px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.15);
  padding: 4px 0;
  min-width: 120px;
  overflow: hidden;
}
.ctx-item {
  display: block;
  width: 100%;
  padding: 9px 16px;
  text-align: left;
  border: none;
  background: none;
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  color: var(--text-color, #1a1a1a);
  transition: background 0.15s;
}
.ctx-item:hover { background: #f1f5f9; }
.ctx-cancel { color: var(--text-light, #64748b); }
.ctx-cancel:hover { background: #fef2f2; color: #dc2626; }

/* ── Tablet Responsive ── */
@media (max-width: 1100px) {
  .teacher-col { min-width: 0; }
  /* 確保欄位維持 minmax 最小值，避免於平板寬度下被壓縮至溢出；外層 wrapper 已 overflow-x: auto */
  .teacher-grid { min-width: max-content; }
}

/* ── Tablet (iPad portrait, sidebar-aware ~520-650px content) ── */
@media (max-width: 900px) {
  .week-overview-grid { min-width: 540px; }
  .day-col { min-width: 66px; }
  .day-col-header { height: 50px; padding: 4px; }
  .day-col-name { font-size: 11px; }
  .day-col-date { font-size: 9px; }
  .day-col-badge { min-width: 14px; height: 14px; font-size: 8px; top: 3px; right: 3px; }
  /* 平板下固定欄寬避免壓縮，讓 wrapper 水平捲動 */
  .teacher-grid { min-width: max-content; }
  .col-header-blank { height: 56px; }
  .course-block { font-size: 10px; padding: 4px 4px; border-radius: 6px; }
  .teacher-courses { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .teacher-table { min-width: 500px; }
  .time-col { min-width: 40px; width: 40px; }
  .time-label { font-size: 10px; padding: 4px 2px 0 0; }
}

@media (max-width: 600px) {
  .toolbar-secondary-line--filters {
    grid-template-columns: 1fr;
  }
  .toolbar-secondary-actions {
    justify-self: stretch;
    width: 100%;
    flex-wrap: wrap;
    justify-content: flex-end;
  }
}

/* ── Mobile Responsive ── */
@media (max-width: 768px) {
  .smart-cal-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
  }
  .view-tabs button {
    padding: 6px 12px;
    font-size: 12px;
  }
  .teacher-col { min-width: 80px; }
  .week-view { overflow-x: auto; }
  .teacher-grid-wrapper { overflow-x: auto; }
  .teacher-grid { min-width: max-content; }
  .col-header-blank { height: 56px; }
  .time-col { min-width: 40px; width: 40px; }
  .time-label { font-size: 10px; padding: 4px 2px 0 0; }
  .course-block {
    font-size: 10px;
    padding: 3px 4px;
    border-radius: 6px;
  }
  .teacher-card { padding: 12px; }
}

/* ── Phone ── */
@media (max-width: 640px) {
  .smart-cal-header {
    flex-direction: column;
    align-items: stretch;
    gap: 8px;
    padding: 0;
  }
  .smart-cal-title { font-size: 1rem; text-align: center; }
  .view-tabs { justify-content: center; }
  .view-tabs button { padding: 6px 10px; font-size: 11px; }
  .toolbar-filters {
    width: 100%;
  }
  .filter-select, .filter-input {
    flex: 1;
    min-width: 0;
    font-size: 13px;
  }
  .teacher-col { min-width: 0; }
  .time-col { min-width: 36px; width: 36px; }
  .time-label { font-size: 9px; padding: 2px 1px 0 0; }
  .col-header-blank { height: 48px; }
  .course-block { font-size: 9px; padding: 2px 3px; border-radius: 4px; }
  .teacher-card { padding: 10px; }
  .teacher-card h3 { font-size: 14px; }
}

@media (max-width: 480px) {
  .smart-cal-title { font-size: 1.1rem; }
  .slot { min-height: 40px; }
}

/* ----- Day/Week Sub-Toggle ----- */
.view-sub-toggle {
  display: inline-flex;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 8px;
  overflow: hidden;
  background: var(--bg-muted, #f0f1f3);
}
.view-sub-toggle button {
  padding: 6px 14px;
  border: none;
  background: transparent;
  font-size: 13px;
  font-weight: 600;
  color: var(--text-light, #64748b);
  cursor: pointer;
  transition: background 0.2s, color 0.2s;
}
.view-sub-toggle button:hover { background: #e2e8f0; }
.view-sub-toggle button.active {
  background: var(--primary, #2563eb);
  color: #fff;
}

.btn-secondary {
  padding: 8px 14px;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 10px;
  background: #fff;
  color: var(--text-color, #1a1a1a);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.2s, border-color 0.2s;
}
.btn-secondary:hover { background: var(--bg-muted, #f0f1f3); }

.btn-sm { padding: 5px 12px; font-size: 12px; border-radius: 6px; }

/* ----- Room Manager Panel ----- */
.room-manager-panel {
  background: #fff;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 12px;
  margin-bottom: 12px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}
.room-manager-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border-bottom: 1px solid var(--border-color, #e2e8f0);
}
.room-manager-header h3 {
  font-size: 15px;
  font-weight: 700;
  margin: 0;
}
.room-manager-body { padding: 12px 16px; }
.room-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 12px;
  font-size: 13px;
}
.room-table th {
  text-align: left;
  padding: 6px 8px;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-light, #64748b);
  border-bottom: 1px solid var(--border-color, #e2e8f0);
}
.room-table td {
  padding: 8px 8px;
  border-bottom: 1px solid #f1f5f9;
}
.room-action-btn {
  padding: 3px 10px;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 6px;
  background: #fff;
  font-size: 12px;
  cursor: pointer;
  margin-right: 4px;
  transition: background 0.15s;
}
.room-action-btn:hover { background: var(--bg-muted, #f0f1f3); }
.room-action-btn.danger { color: #dc2626; border-color: #fca5a5; }
.room-action-btn.danger:hover { background: #fee2e2; }
.room-empty-hint {
  color: var(--text-light, #64748b);
  font-size: 13px;
  margin-bottom: 12px;
}
.room-form {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.room-input {
  padding: 6px 10px;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 6px;
  font-size: 13px;
  background: #fff;
}

/* ----- Week Overview Grid ----- */
.week-overview-grid-wrapper {
  max-height: min(76vh, 820px);
  overflow: auto;
  -webkit-overflow-scrolling: touch;
}
.week-overview-grid {
  display: grid;
  grid-template-columns: 60px repeat(7, 1fr);
  min-width: 700px;
}
.day-col {
  border-right: 1px solid var(--border-color, #e2e8f0);
  min-width: 90px;
}
.day-col:last-child { border-right: none; }
.day-col-header {
  height: 64px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  padding: 8px 6px;
  background: var(--bg-muted, #f8fafc);
  border-bottom: 1px solid var(--border-color, #e2e8f0);
  position: sticky;
  top: 0;
  z-index: 10;
  box-shadow: 0 1px 0 rgba(15, 23, 42, 0.06);
}
.day-col-header.day-col-today {
  background: #eff6ff;
  box-shadow: 0 -3px 0 var(--primary, #2563eb) inset;
}
.day-col-name {
  font-size: 13px;
  font-weight: 700;
  color: var(--text-color, #1a1a1a);
}
.day-col-date {
  font-size: 11px;
  color: var(--text-light, #94a3b8);
}
.day-col-badge {
  position: absolute;
  top: 4px;
  right: 6px;
  min-width: 18px;
  height: 18px;
  border-radius: 9px;
  background: var(--primary, #2563eb);
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 4px;
  line-height: 1;
}
.day-col-header { position: relative; }

/* ----- Room Full Slot Indicator ----- */
.slot.slot-room-full {
  background: repeating-linear-gradient(
    135deg,
    transparent,
    transparent 4px,
    rgba(239, 68, 68, 0.07) 4px,
    rgba(239, 68, 68, 0.07) 8px
  );
}
.slot.slot-room-full:hover {
  background: repeating-linear-gradient(
    135deg,
    transparent,
    transparent 4px,
    rgba(239, 68, 68, 0.12) 4px,
    rgba(239, 68, 68, 0.12) 8px
  );
}

/* --- Capacity Badge --- */
.capacity-badge {
  position: absolute;
  top: 2px;
  left: 2px;
  z-index: 5;
  font-size: 9px;
  font-weight: 700;
  line-height: 1;
  padding: 1px 3px;
  border-radius: 4px;
  color: #fff;
  border: 1.5px solid rgba(255, 255, 255, 0.9);
  min-width: 18px;
  text-align: center;
  pointer-events: auto;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}
.capacity-badge-compact {
  font-size: 7px;
  font-weight: 700;
  min-width: 14px;
  padding: 0 2px;
  border-radius: 3px;
  border-width: 1px;
  top: 2px;
  left: 2px;
  letter-spacing: -0.3px;
}
/* #740 Step 5：容量徽章讓位 / compact 角標 / :has(.rc-tag) 讓位 全部改 prop 旗標驅動，
   移至 CourseBlockContent.vue（.cbc-badge-* / .cbc-compact / .cbc-has-rc）。 */
.capacity-legend {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  color: var(--text-muted, #64748b);
  margin-left: 8px;
  flex-wrap: wrap;
}
.capacity-legend-label {
  font-weight: 600;
  color: var(--text-color, #334155);
  margin-right: 2px;
}
.capacity-legend-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 26px;
  padding: 2px 4px;
  border-radius: 4px;
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  border: 1.5px solid rgba(255, 255, 255, 0.85);
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
  margin: 0 2px 0 4px;
}
.capacity-legend-chip--ok { background: var(--ds-success, #10b981); }
.capacity-legend-chip--warn { background: var(--ds-warning, #f59e0b); }
.capacity-legend-chip--full { background: var(--ds-danger, #ef4444); }
@media (max-width: 768px) {
  .capacity-badge {
    font-size: 8px;
    min-width: 18px;
    padding: 0 2px;
    border-radius: 3px;
    border-width: 1px;
  }
}

/* Responsive for week overview */
@media (max-width: 768px) {
  .week-overview-grid { min-width: 460px; }
  .day-col { min-width: 56px; }
  .day-col-header { height: 44px; padding: 3px; }
  .day-col-name { font-size: 10px; }
  .day-col-date { font-size: 8px; }
  .view-sub-toggle button { padding: 5px 10px; font-size: 12px; }
}
</style>
