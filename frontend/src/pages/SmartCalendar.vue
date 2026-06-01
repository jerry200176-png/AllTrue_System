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
            <div class="week-nav">
              <button type="button" class="week-nav-btn" @click="prevWeek">‹ 上週</button>
              <select v-model.number="displayWeek" class="week-select">
                <option :value="0">全部</option>
                <option v-for="w in weekOptions" :key="w.value" :value="w.value">{{ w.label }}</option>
              </select>
              <button type="button" class="week-nav-btn" @click="nextWeek">下週 ›</button>
            </div>
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
                <span class="capacity-legend-chip" style="background:#10b981">1/3</span>可加
                <span class="capacity-legend-chip" style="background:#f59e0b">2/3</span>剩 1 位
                <span class="capacity-legend-chip" style="background:#ef4444">3/3</span>已滿
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
                  style="background:#fff7ed;border:1px solid #fed7aa;color:#c2410c;cursor:pointer;"
                  title="老師請假一次處理當日多堂代課"
                  @click="openTeacherLeaveBatch"
                >🗓️ 老師請假</button>
                <label
                  v-if="!isWeekOverview && !isTeacher"
                  class="filter-toggle toolbar-hide-empty-toggle"
                  title="開啟後只顯示今日有排課的老師欄；此模式下無法點空格快速排課"
                >
                  <input type="checkbox" v-model="hideEmptyTeacherColumns" />
                  <span>只看有課老師</span>
                </label>
              </div>
              <div
                v-if="visibleTeachers.length > 1 && !isTeacher"
                class="week-teacher-chips"
                role="group"
                aria-label="篩選老師（可多選）"
              >
                <span class="week-teacher-chips-label">老師</span>
                <div class="week-teacher-chips-scroll">
                  <button
                    v-for="t in visibleTeachers"
                    :key="t.id"
                    type="button"
                    :aria-pressed="weekViewTeacherIdSet.has(String(t.id))"
                    :class="['week-teacher-chip', { active: weekViewTeacherIdSet.has(String(t.id)) }]"
                    :style="weekViewTeacherIdSet.has(String(t.id)) ? { background: getTeacherColor(t.id), borderColor: getTeacherColor(t.id), color: '#fff' } : {}"
                    @click="toggleTeacherSelection(t.id)"
                  >
                    {{ t.username }}
                  </button>
                </div>
                <button
                  v-if="weekViewTeacherIds.length > 0"
                  type="button"
                  class="week-teacher-chip-clear"
                  @click="clearTeacherSelection"
                >全清除</button>
              </div>
            </div>
            <div class="toolbar-secondary-actions">
              <button type="button" class="btn-secondary btn-icon-text toolbar-action-btn" @click="showRoomManager = !showRoomManager" title="管理教室"><span class="btn-emoji">🏫</span><span class="btn-text">教室</span></button>
              <button type="button" class="btn-primary btn-icon-text toolbar-action-btn" data-guide="calendar-quick-add" @click="openQuickAdd"><span class="btn-emoji">＋</span><span class="btn-text">快速排課</span></button>
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
        <div class="day-tabs-bar">
          <button
            v-for="(dayName, idx) in dayNames"
            :key="idx"
            type="button"
            :class="['day-tab', { active: selectedDayIdx === idx }]"
            @click="selectedDayIdx = idx"
          >
            <span class="day-tab-name">{{ dayName }}</span>
            <span class="day-tab-date">{{ getDisplayDateString(idx + 1) }}</span>
            <span v-if="getDayCourseCount(idx + 1) > 0" class="day-tab-badge">{{ getDayCourseCount(idx + 1) }}</span>
          </button>
        </div>
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
              <div class="teacher-col-header" :style="{ borderTopColor: getTeacherColor(teacher.id) }">
                <div class="teacher-col-avatar" :style="{ background: getTeacherColor(teacher.id) }">{{ teacher.username.charAt(0) }}</div>
                <div class="teacher-col-info">
                  <div class="teacher-col-name">{{ teacher.username }}</div>
                  <div v-if="teacher.roomLabel" class="teacher-col-room">{{ teacher.roomLabel }}</div>
                </div>
              </div>
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
                  <div class="cb-student">{{ course.student_name }}</div>
                  <div class="cb-detail">{{ getSubjectLabel(course.subject) }}</div>
                  <div class="cb-type">{{ classTypeLabel(course.class_type) }}</div>
                  <span v-if="rollCallBadge(course, selectedDateStr)" class="rc-tag" :class="'rc-' + rollCallBadge(course, selectedDateStr).kind">{{ rollCallBadge(course, selectedDateStr).label }}</span>
                  <span v-if="evalBadge(course, selectedDateStr)" class="rc-tag rc-eval-missing" :class="{ 'rc-tag-second': !!rollCallBadge(course, selectedDateStr) }">{{ evalBadge(course, selectedDateStr).label }}</span>
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
                  <div v-if="weekViewTeacherIds.length !== 1" class="cb-teacher-tag" :style="{ background: getTeacherColor(course.teacher_id) }">{{ course.teacher_name }}</div>
                  <div class="cb-student">{{ course.student_name }}</div>
                  <div class="cb-detail">{{ getSubjectLabel(course.subject) }}</div>
                  <div class="cb-type">{{ classTypeLabel(course.class_type) }}</div>
                  <span v-if="rollCallBadge(course, getDisplayDateFull(idx + 1))" class="rc-tag" :class="'rc-' + rollCallBadge(course, getDisplayDateFull(idx + 1)).kind">{{ rollCallBadge(course, getDisplayDateFull(idx + 1)).label }}</span>
                  <span v-if="evalBadge(course, getDisplayDateFull(idx + 1))" class="rc-tag rc-eval-missing" :class="{ 'rc-tag-second': !!rollCallBadge(course, getDisplayDateFull(idx + 1)) }">{{ evalBadge(course, getDisplayDateFull(idx + 1)).label }}</span>
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

    <div v-if="showModal && editingCourseId" class="modal-overlay" @click.self="showModal = false">
      <div class="modal" style="width: 500px;">
        <h3>單堂檢視</h3>

        <!-- 本堂資訊（第一層：最高視覺優先級） -->
        <div v-if="editingActionDate" class="session-info-card">
          <div class="session-info-row">
            <span class="session-info-key">本堂日期</span>
            <span class="session-info-val"><strong>{{ editingActionDate }}</strong>（{{ dayNames[modalForm.day_of_week - 1] }}）</span>
          </div>
          <div class="session-info-row">
            <span class="session-info-key">上課時間</span>
            <span class="session-info-val">{{ modalForm.start_time }} ~ {{ computedMainEndTime }}（{{ modalForm.duration_hours }} 小時）</span>
          </div>
          <div v-if="currentSessionChargeDisplay" class="session-info-row">
            <span class="session-info-key">本堂費用</span>
            <span class="session-info-val">
              <strong>NT$ {{ currentSessionChargeDisplay.value.toLocaleString() }}</strong>
              <span v-if="currentSessionChargeDisplay.isAdjusted" class="session-charge-adjusted">（已依實際時長調整）</span>
              <span v-else class="session-charge-standard">（標準費用）</span>
            </span>
          </div>
        </div>
        <p class="hint occurrence-hint">
          僅可進行單堂操作（請假／調課／加課）。如需修改整門課設定，請至「課程管理」。
        </p>

        <!-- Conflict Warning：明顯色塊＋圖示 -->
        <div v-if="conflictWarning" class="conflict-box conflict-box-prominent">
          <span class="conflict-icon">⚠️</span>
          <div>
            <strong>衝堂警告</strong>
            <p>{{ conflictWarning }}</p>
          </div>
        </div>

        <!-- 單堂操作（第二層：行動按鈕，緊跟本堂資訊） -->
        <div class="schedule-actions-box">
          <div class="schedule-actions-title">單堂操作</div>
          <div class="schedule-actions-btns">
            <!-- 加課暫時隱藏：實際使用率極低，業務流程未完整（月結制費用、堂數制提前消耗） -->
            <!-- <button class="action-btn extra" @click="openExtraLesson">＋ 加課</button> -->
            <button class="action-btn leave" @click="openLeaveModal">📋 請假</button>
            <button class="action-btn reschedule" @click="openRescheduleModal">🔄 調課</button>
            <button
              v-if="!isTeacher"
              class="action-btn substitute"
              @click="featureSubstituteV2 ? openSubstituteV2Modal() : openSubstituteModal()"
            >👤 換代課老師</button>
            <button
              v-if="!isTeacher && canCancelSelectedSession"
              class="action-btn cancel-session"
              @click="cancelState.show = true"
            >🚫 取消本堂</button>
          </div>
          <!-- 取消本堂確認 -->
          <div v-if="cancelState.show" class="cancel-session-confirm">
            <p>確定取消這堂課？<br><small>此操作無法自動還原（可至課程管理手動設回「排程中」）。</small></p>
            <div class="cancel-session-confirm-btns">
              <button class="action-btn" style="background:#e2e8f0;color:#475569;" @click="cancelState.show = false">不取消</button>
              <button class="action-btn cancel-session" :disabled="cancelState.loading" @click="doConfirmCancelSession">
                {{ cancelState.loading ? '處理中...' : '確定取消本堂' }}
              </button>
            </div>
          </div>
        </div>

        <!-- 整門課參考資料（第三層：唯讀參考，視覺降級） -->
        <div class="modal-form-sections course-ref-section">
          <div class="form-section-label">課程資料（僅供參考）</div>
          <p class="course-ref-hint">以下為整門課設定，不可在此修改。如需調整請至「課程管理」。</p>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
          <div class="form-group">
            <label>學生</label>
            <SearchableSelect
              v-model="modalForm.student_id"
              :options="studentSelectOptions"
              :disabled="!!editingCourseId"
              placeholder="輸入學生姓名搜尋..."
            />
          </div>
          <div class="form-group">
            <label>科目</label>
            <select v-model="modalForm.subject" :disabled="!!editingCourseId">
              <option v-for="s in subjectOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>老師</label>
            <select v-model="modalForm.teacher_id" :disabled="!!editingCourseId" @change="checkConflict">
              <option value="">請選擇</option>
              <option v-for="t in (teachers || [])" :key="t.id" :value="t.id">{{ t.username }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>類型</label>
            <select v-model="modalForm.class_type" :disabled="!!editingCourseId">
              <option value="one_on_one">一對一</option>
              <option value="one_on_two">一對二</option>
              <option value="one_on_three">一對三</option>
              <option value="tutoring">輔導</option>
              <option value="trial">試聽</option>
            </select>
          </div>
          </div>

          <template v-if="!editingCourseId">
          <div class="form-section-label">何時／多久／費用</div>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
          <div class="form-group" style="grid-column: span 2;">
            <label>排課日（可選多天）</label>
            <div class="day-chip-row">
              <label v-for="d in calDayOptions" :key="d.value" :class="['cal-day-chip', { selected: (modalForm.days_of_week || []).includes(d.value) }]">
                <input type="checkbox" :value="d.value" v-model="modalForm.days_of_week" style="position:absolute;opacity:0;width:0;height:0;pointer-events:none;" />
                <span>{{ d.label }}</span>
              </label>
            </div>
          </div>
          <div class="form-group">
            <label>首堂上課日期 (First Class Date)</label>
            <input v-model="modalForm.first_class_date" type="date" />
          </div>
          <div class="form-group">
            <label>課程時長 (Duration)</label>
            <select v-model.number="modalForm.duration_hours" @change="onMainFormTimeChange">
              <option :value="1">1 小時</option>
              <option :value="1.5">1.5 小時</option>
              <option :value="2">2 小時</option>
              <option :value="2.5">2.5 小時</option>
              <option :value="3">3 小時</option>
            </select>
          </div>
          <div class="form-group">
            <label>開始時間 (Start Time)</label>
            <select v-model="modalForm.start_time" @change="onMainFormStartTimeChange">
              <optgroup label="上午">
                <option value="08:00">08:00</option>
                <option value="08:30">08:30</option>
                <option value="09:00">09:00</option>
                <option value="09:30">09:30</option>
                <option value="10:00">10:00</option>
                <option value="10:30">10:30</option>
                <option value="11:00">11:00</option>
                <option value="11:30">11:30</option>
              </optgroup>
              <optgroup label="下午">
                <option value="12:00">12:00</option>
                <option value="12:30">12:30</option>
                <option value="13:00">13:00</option>
                <option value="13:30">13:30</option>
                <option value="14:00">14:00</option>
                <option value="14:30">14:30</option>
                <option value="15:00">15:00</option>
                <option value="15:30">15:30</option>
                <option value="16:00">16:00</option>
                <option value="16:30">16:30</option>
                <option value="17:00">17:00</option>
                <option value="17:30">17:30</option>
                <option value="18:00">18:00</option>
                <option value="18:30">18:30</option>
                <option value="19:00">19:00</option>
                <option value="19:30">19:30</option>
                <option value="20:00">20:00</option>
                <option value="20:30">20:30</option>
                <option value="21:00">21:00</option>
                <option value="21:30">21:30</option>
                <option value="22:00">22:00</option>
              </optgroup>
            </select>
          </div>
          <div class="form-group">
            <label>預計結束時間</label>
            <p class="computed-time">{{ computedMainEndTime }}</p>
          </div>
          <div class="form-group">
            <label>一堂課費用 ($)</label>
            <input v-model.number="ratePer2h" type="number" min="0" step="100" @blur="syncRatePer2hToModel" />
          </div>
          </div>
          </template>
          <template v-if="editingCourseId">
          <div class="form-section-label">時段與費用</div>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
          <div class="form-group">
            <label>課程時長</label>
            <p class="computed-time">{{ modalForm.duration_hours }} 小時</p>
          </div>
          <div class="form-group">
            <label>一堂課費用</label>
            <p class="computed-time">${{ ratePer2h }}</p>
          </div>
          </div>
          </template>

          <div class="form-section-label">繳費狀態（僅供參考）</div>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
          <div class="form-group">
            <label>繳費方式</label>
            <select v-model="modalForm.payment_type" :disabled="!!editingCourseId">
              <option value="session">堂數制</option>
              <option value="monthly">月結</option>
            </select>
          </div>
          <div class="form-group" v-if="!editingCourseId && modalForm.payment_type === 'session'">
            <label>購買堂數</label>
            <input v-model.number="modalForm.sessions_purchased" type="number" placeholder="8" />
          </div>
          <div class="form-group" v-if="!editingCourseId && modalForm.payment_type === 'session'">
            <label>已上堂數（不確定可留空）</label>
            <input v-model.number="modalForm.sessions_used" type="number" placeholder="0" min="0" />
            <small v-if="(modalForm.sessions_used || 0) > 0" style="color:#888; font-size:12px;">
              剩餘 {{ Math.max(0,(modalForm.sessions_purchased||0)-(modalForm.sessions_used||0)) }} 堂
            </small>
          </div>
          <template v-if="modalForm.payment_type === 'monthly'">
            <div class="form-group">
              <label>結算日</label>
              <select v-model.number="modalForm.settlement_day" :disabled="!!editingCourseId">
                <option :value="null">請選擇</option>
                <option v-for="day in settlementDayOptions" :key="day" :value="day">每月 {{ day }} 號</option>
              </select>
            </div>
            <div class="form-group">
              <label>本月預排堂數</label>
              <input v-model.number="modalForm.monthly_sessions" type="number" min="1" :disabled="!!editingCourseId" />
            </div>
          </template>
          <div class="form-group" v-if="editingCourseId && modalForm.payment_type === 'session'">
            <label>剩餘堂數</label>
            <input v-model.number="modalForm.remaining_sessions" type="number" disabled />
          </div>
          </div>
        </div>

        <!-- (action buttons moved above to "單堂操作" section) -->

        <!-- 評量表總覽：顯示已過課程的評量紀錄 -->
        <div v-if="editingCourseId && courseEvalRecords.length > 0" class="eval-summary-box">
          <div class="eval-summary-title">評量表紀錄（今日前已上課程）</div>
          <table class="eval-summary-table">
            <thead>
              <tr><th>日期</th><th>科目</th><th>老師</th><th>狀態</th></tr>
            </thead>
            <tbody>
              <tr v-for="ev in courseEvalRecords" :key="ev.id">
                <td>{{ ev.session_date }}</td>
                <td>{{ ev.subject }}</td>
                <td>{{ ev.teacher_name }}</td>
                <td>
                  <span :class="['eval-status-tag', ev.status]">
                    {{ ev.status === 'approved' ? '已審核' : ev.status === 'pending' ? '待審核' : ev.status }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else-if="editingCourseId && evalRecordsLoading" style="padding:8px; color:#888; font-size:13px;">載入評量紀錄中…</div>
        <div v-else-if="editingCourseId && courseEvalRecords.length === 0 && !evalRecordsLoading" style="padding:8px; color:#aaa; font-size:13px;">（尚無評量紀錄）</div>

        <div class="actions">
          <button v-if="editingException && editingExceptionIsExtra" class="danger" @click="cancelMakeupClass">取消補課</button>
          <button v-if="editingException && !editingExceptionIsExtra" class="danger" @click="deleteException">刪除此調課</button>
          <button v-if="editingCourseId && !editingException" class="danger" @click="deleteCourse">刪除整門課</button>
          <div style="flex:1"></div>
          <button class="ghost" @click="showModal = false">{{ editingCourseId ? '關閉' : '取消' }}</button>
          <button v-if="!editingCourseId" class="primary" @click="submitModal" :disabled="!!conflictWarning">儲存</button>
        </div>
      </div>
    </div>

    <!-- ===== Leave Modal (請假) ===== -->
    <div v-if="showLeaveModal" class="modal-overlay" @click.self="showLeaveModal = false">
      <div class="modal" style="width: 420px;">
        <h3>📋 請假登記</h3>
        <p class="hint">請假不扣堂數、不需填寫評量表</p>
        <div class="form-group">
          <label>學生</label>
          <p style="font-weight: 600;">{{ getStudentName(leaveForm.student_id) }}</p>
        </div>
        <div class="form-group">
          <label>科目</label>
          <p>{{ getSubjectLabel(leaveForm.subject) }}</p>
        </div>
        <div class="form-group">
          <label>請假日期</label>
          <input v-model="leaveForm.schedule_date" type="date" />
        </div>
        <div class="form-group">
          <label>原時段</label>
          <p>{{ dayLabel(leaveForm.day_of_week) }} {{ leaveForm.start_time }}~{{ leaveForm.end_time }}</p>
        </div>
        <div class="actions">
          <button class="ghost" @click="showLeaveModal = false">取消</button>
          <button class="primary" @click="submitLeave">確認請假</button>
        </div>
      </div>
    </div>

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

    <!-- ===== Substitute Teacher Modal (legacy, 舊版 select 版；Feature flag 關閉時使用) ===== -->
    <div v-if="showSubstituteModal" class="modal-overlay" @click.self="showSubstituteModal = false">
      <div class="modal" style="width: 440px;">
        <h3>👤 換代課老師</h3>
        <p class="hint">僅替換此堂授課老師，不影響課程主檔與後續排課。</p>
        <div class="form-group">
          <label>學生</label>
          <p style="font-weight: 600;">{{ getStudentName(substituteForm.student_id) }}</p>
        </div>
        <div class="form-group">
          <label>科目</label>
          <p>{{ getSubjectLabel(substituteForm.subject) }}</p>
        </div>
        <div class="form-group">
          <label>日期 / 時段</label>
          <p>{{ substituteForm.session_date }} {{ substituteForm.start_time }}~{{ substituteForm.end_time }}</p>
        </div>
        <div class="form-group">
          <label>正班老師</label>
          <p>{{ substituteForm.original_teacher_name || '—' }}</p>
        </div>
        <div class="form-group">
          <label>代課老師 <span style="color: #ef4444;">*</span></label>
          <select v-model="substituteForm.substitute_teacher_id">
            <option value="">請選擇</option>
            <option v-for="t in (teachers || [])" :key="t.id" :value="t.id">{{ t.username }}</option>
          </select>
        </div>
        <div class="form-group">
          <label>原因（選填）</label>
          <input v-model="substituteForm.reason" type="text" placeholder="例：正班老師請假" style="width: 100%;" />
        </div>
        <div class="actions">
          <button class="ghost" @click="showSubstituteModal = false">取消</button>
          <button class="primary" @click="submitSubstitute" :disabled="substituteSubmitting || !substituteForm.substitute_teacher_id">
            {{ substituteSubmitting ? '處理中…' : '確認代課' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ===== Extra Lesson Modal (加課) ===== -->
    <div v-if="showExtraModal" class="modal-overlay" @click.self="showExtraModal = false">
      <div class="modal" style="width: 480px;">
        <h3>＋ 加課</h3>
        <p class="hint" v-if="extraParentPaymentType === 'monthly'">月結制加課需額外繳費，老師需上傳評量表</p>
        <p class="hint" v-else>堂數制加課會提早用完堂數（不額外收費），老師需上傳評量表</p>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
          <div class="form-group">
            <label>學生</label>
            <SearchableSelect
              v-model="extraForm.student_id"
              :options="studentSelectOptions"
              placeholder="輸入學生姓名搜尋..."
            />
          </div>
          <div class="form-group">
            <label>科目</label>
            <select v-model="extraForm.subject">
              <option v-for="s in subjectOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>老師</label>
            <select v-model="extraForm.teacher_id">
              <option value="">請選擇</option>
              <option v-for="t in (teachers || [])" :key="t.id" :value="t.id">{{ t.username }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>類型</label>
            <select v-model="extraForm.class_type">
              <option value="one_on_one">一對一</option>
              <option value="one_on_two">一對二</option>
              <option value="one_on_three">一對三</option>
              <option value="tutoring">輔導</option>
              <option value="trial">試聽</option>
            </select>
          </div>
          <div class="form-group">
            <label>日期</label>
            <input v-model="extraForm.schedule_date" type="date" />
          </div>
          <div class="form-group">
            <label>時長</label>
            <select v-model.number="extraForm.duration_hours" @change="onExtraFormTimeChange">
              <option :value="1">1 小時</option>
              <option :value="1.5">1.5 小時</option>
              <option :value="2">2 小時</option>
              <option :value="2.5">2.5 小時</option>
              <option :value="3">3 小時</option>
            </select>
          </div>
          <div class="form-group">
            <label>開始時間</label>
            <input
              v-model="extraForm.start_time"
              type="time"
              step="1800"
              @change="onExtraFormStartTimeChange"
            />
            <p class="hint" style="margin-top: 4px;">僅可選整點或半點</p>
          </div>
          <div class="form-group">
            <label>預計結束時間</label>
            <p class="computed-time">{{ computedExtraEndTime }}</p>
          </div>
        </div>
        <div class="actions">
          <button class="ghost" @click="showExtraModal = false">取消</button>
          <button class="primary" @click="submitExtraLesson">確認加課</button>
        </div>
      </div>
    </div>

    <!-- ===== Reschedule Modal (調課) ===== -->
    <div v-if="showRescheduleModal" class="modal-overlay" @click.self="showRescheduleModal = false">
      <div class="modal" style="width: 420px;">
        <h3>🔄 調課</h3>
        <p class="hint">將原本的課程改到新的日期時間</p>
        <div class="form-group">
          <label>學生</label>
          <p style="font-weight: 600;">{{ getStudentName(rescheduleForm.student_id) }}</p>
        </div>
        <div class="form-group">
          <label>科目</label>
          <p>{{ getSubjectLabel(rescheduleForm.subject) }}</p>
        </div>
        <div class="form-group">
          <label>原時段</label>
          <p>{{ dayLabel(rescheduleForm.original_day) }} {{ rescheduleForm.original_start }}~{{ rescheduleForm.original_end }}</p>
        </div>
        <hr style="border: none; border-top: 1px solid #eee; margin: 12px 0;" />
        <div class="form-group">
          <label>新日期</label>
          <input v-model="rescheduleForm.new_date" type="date" />
        </div>
        <div class="form-group">
          <label>新開始時間</label>
          <select v-model="rescheduleForm.new_start" @change="onRescheduleNewStartChange">
            <option v-for="t in timeOptions30" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>
        <div class="form-group">
          <label>預計新結束時間</label>
          <p class="computed-time">{{ computedRescheduleNewEnd }}</p>
        </div>
        <div class="actions">
          <button class="ghost" @click="showRescheduleModal = false">取消</button>
          <button class="primary" @click="submitReschedule">確認調課</button>
        </div>
      </div>
    </div>

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
import { fetchClassSessions } from '../lib/classSessionsApi';
import { fetchAllPages } from '../lib/pagedFetchAll';
import { mergeWeekCalendarOccurrences } from '../lib/calendarOccurrenceMerge';
import {
  resolveCalendarDataFetchBoundsYmd,
  shouldUseLegacyCalendarFallback,
  isRangeWithinFetchedBounds,
} from '../lib/calendarLoadPerformance';
import UniversalClassScheduler from '../components/UniversalClassScheduler.vue';
import SearchableSelect from '../components/SearchableSelect.vue';
import SubstituteTeacherPickerModal from '../components/substitute/SubstituteTeacherPickerModal.vue';
import TeacherLeaveBatchModal from '../components/substitute/TeacherLeaveBatchModal.vue';
import ToastWithUndo from '../components/substitute/ToastWithUndo.vue';
import {
  fetchTeacherAvailability,
  undoSubstitute,
  previewTeacherLeaves,
  batchSubstitute as batchSubstituteApi,
} from '../lib/substituteApi.js';
import { pickBestSessionRow, resolveSessionIdForSubstitute } from '../lib/classSessionPick.js';

// PRD 9c058f19 — 代課流程 UX 優化旗標；env 為字串，需解析。
const FEATURE_SUBSTITUTE_V2 = ((import.meta?.env?.VITE_FEATURE_SUBSTITUTE_V2 ?? '1') + '') !== '0';

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

/** 依本地時區輸出 YYYY-MM-DD，避免 toISOString() 在 UTC+8 造成日期少一天 */
function formatLocalDate(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

/** 從今天起算，下一個指定星期幾的日期 YYYY-MM-DD（dow 1=週一 … 7=週日） */
function getNextWeekdayYmd(dow) {
  const d = new Date();
  d.setHours(12, 0, 0, 0);
  const current = d.getDay() === 0 ? 7 : d.getDay();
  let diff = (Number(dow) || 1) - current;
  if (diff <= 0) diff += 7;
  d.setDate(d.getDate() + diff);
  return formatLocalDate(d);
}

/** 該月第 N 週的週一日期 YYYY-MM-DD */
function getMondayOfMonthWeek(year, month, weekNum) {
  const first = new Date(year, month - 1, 1);
  let mon = new Date(first);
  const day = first.getDay();
  const diff = day === 0 ? -6 : 1 - day;
  mon.setDate(first.getDate() + diff);
  mon.setDate(mon.getDate() + (weekNum - 1) * 7);
  return formatLocalDate(mon);
}

/** 將 API/DB 回傳的日期正規為 YYYY-MM-DD（Supabase 可能回傳 ISO 字串） */
function toYmd(val) {
  if (val == null) return '';
  return String(val).slice(0, 10);
}

/** 日期加減天數，回傳 YYYY-MM-DD */
function addDays(ymd, days) {
  const d = new Date(ymd + 'T12:00:00');
  d.setDate(d.getDate() + days);
  return formatLocalDate(d);
}

/** 給定 YYYY-MM-DD，回傳該日屬於當月第幾週 (1–5)，與週檢視的 displayWeek 定義一致 */
function getWeekNumberOfDate(ymd) {
  const d = new Date(ymd + 'T12:00:00');
  const year = d.getFullYear();
  const month = d.getMonth() + 1;
  const dow = d.getDay();
  const toMonday = dow === 0 ? -6 : 1 - dow;
  const mondayOfDate = addDays(ymd, toMonday);
  const firstMonday = getMondayOfMonthWeek(year, month, 1);
  const a = new Date(mondayOfDate + 'T12:00:00').getTime();
  const b = new Date(firstMonday + 'T12:00:00').getTime();
  const diffDays = Math.round((a - b) / (24 * 60 * 60 * 1000));
  const weekNum = Math.floor(diffDays / 7) + 1;
  return Math.max(1, Math.min(5, weekNum));
}

// --- State ---
const viewMode = ref('week');
const displayWeek = ref(0);
const weekOffset = ref(0); // 上週/下週偏移
const jumpToDate = ref(formatLocalDate(new Date()));
const courses = ref([]);
const exceptions = ref([]); // Store schedules (leaves, extras, reschedules)
const calendarLoading = ref(false);
const calendarLoadProgress = ref('');
/** 與課程管理相同：每門課的實際上課日期列表（來自 session-dates API），用於智慧排課只顯示到最後一堂 */
const sessionDatesByCourseId = ref({});
const allStudents = ref([]);
const teachers = ref([]);
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

const calDayOptions = [
  { value: 1, label: '一' }, { value: 2, label: '二' }, { value: 3, label: '三' },
  { value: 4, label: '四' }, { value: 5, label: '五' }, { value: 6, label: '六' }, { value: 7, label: '日' }
];

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

// Teacher colors
const teacherColorMap = {};
const palette = [
  '#1E88E5', '#43A047', '#E53935', '#FB8C00', '#8E24AA',
  '#00ACC1', '#D81B60', '#6D4C41', '#546E7A', '#F4511E',
  '#3949AB', '#00897B', '#C0CA33', '#5E35B1', '#039BE5'
];
const getTeacherColor = (teacherId) => {
  if (!teacherId) return '#90A4AE';
  if (!teacherColorMap[teacherId]) {
    const idx = Object.keys(teacherColorMap).length % palette.length;
    teacherColorMap[teacherId] = palette[idx];
  }
  return teacherColorMap[teacherId];
};

// --- Helpers ---
const getSubjectLabel = (val) => getSubjectText(val);
const classTypeLabel = (type) => ({ one_on_one: '一對一', one_on_two: '一對二', one_on_three: '一對三', tutoring: '輔導', trial: '試聽' }[type] || type);
const dayLabel = (d) => ['', '週一', '週二', '週三', '週四', '週五', '週六', '週日'][d] || '';
/** 從 YYYY-MM-DD 得到星期幾，1=週一 … 7=週日 */
const dayOfWeekFromDate = (ymd) => {
  if (!ymd) return 1;
  const d = new Date(ymd + 'T12:00:00');
  const n = d.getDay();
  return n === 0 ? 7 : n;
};

const getWeekLabel = (weeks) => {
  if (!weeks || weeks.length === 0 || weeks.length === 5) return '';
  return `第${weeks.join(',')}週`;
};

// Parse time string "HH:MM" to hour number
const parseHour = (t) => {
  if (!t) return 0;
  return parseInt(t.split(':')[0], 10);
};

// 排課以 30 分鐘為單位：將時間正規化為整點或半點 (08:14 → 08:00 或 08:30)
const TIME_STEP_MINUTES = 30;
const normalizeTimeTo30 = (timeStr) => {
  if (!timeStr) return '08:00';
  const [h, m] = timeStr.split(':').map(Number);
  const totalM = (h || 0) * 60 + (m || 0);
  const rounded = Math.round(totalM / TIME_STEP_MINUTES) * TIME_STEP_MINUTES;
  const hours = Math.min(23, Math.floor(rounded / 60));
  const mins = rounded % 60;
  return `${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}`;
};

// 由開始時間 + 時長計算結束時間
const computeEndTime = (startTime, durationHours) => {
  if (!startTime) return '--:--';
  const [h, m] = startTime.split(':').map(Number);
  const startM = (h || 0) * 60 + (m || 0);
  const durM = Math.round((durationHours || 2) * 60);
  const endM = startM + durM;
  const endH = Math.min(23, Math.floor(endM / 60));
  const endMin = endM % 60;
  return `${String(endH).padStart(2, '0')}:${String(endMin).padStart(2, '0')}`;
};

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

// TD-062 Phase 1：記錄上一次 loadCourses 實際抓取的視窗（分校 + ±buffer 日期範圍）。
// 換週/換日若仍落在此視窗內，可跳過全量重抓，由 reactive computed 直接重渲染既有資料。
// 僅在「資料成功載入」時設定；任何 mutation 仍走完整 loadCourses（不讀此快取）→ 無 staleness。
const lastCalendarFetch = ref(null);

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

const isCourseActiveForCalendar = (course) => {
  // Calendar should not render explicitly paused/closed courses.
  // Legacy payloads may expose either `status=inactive` or Stop/stop=1.
  const status = String(course?.status || '').toLowerCase();
  if (status === 'inactive') return false;
  const stopFlag = Number(course?.stop ?? course?.Stop ?? 0);
  return stopFlag !== 1;
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

// --- Data Loading ---
// 優先從 Laravel API 載入課程（含 days_of_week），固定一四才會在週一與週四都顯示
const loadCourses = async () => {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
  const token = session?.access_token || '';
  const baseUrl = import.meta.env.VITE_API_BASE || '/api';
  const branchId = Number(props.branchId) || 0;
  if (!branchId && !isTeacher.value) {
    courses.value = [];
    exceptions.value = [];
    sessionDatesByCourseId.value = {};
    calendarLoading.value = false;
    calendarLoadProgress.value = '';
    lastCalendarFetch.value = null;
    return;
  }

  calendarLoading.value = true;
  calendarLoadProgress.value = '載入課程中…';
  const mapCourse = (c) => ({
    id: c.id,
    student_id: c.student_id,
    teacher_id: c.teacher_id,
    subject: c.subject,
    class_type: c.class_type,
    rate_per_30min: c.rate_per_30min,
    rate_unit: c.rate_unit || 'session',
    duration_hours: c.duration_hours ?? 2,
    day_of_week: parseInt(c.day_of_week) || 0,
    days_of_week: Array.isArray(c.days_of_week) && c.days_of_week.length ? c.days_of_week : null,
    start_time: c.start_time || '',
    end_time: c.end_time || '',
    day_time_slots: Array.isArray(c.day_time_slots) && c.day_time_slots.length ? c.day_time_slots : null,
    student_name: c.student_name || '—',
    teacher_name: c.teacher_name || '未指派',
    weeks: Array.isArray(c.weeks) && c.weeks.length ? c.weeks : [1, 2, 3, 4, 5],
    first_class_date: c.first_class_date || c.StartDate || null,
    end_date: c.end_date || (c.EndDate ? String(c.EndDate).slice(0, 10) : null),
    status: c.status || '',
    stop: c.stop ?? c.Stop ?? 0,
    payment_type: c.payment_type || (c.ScheduleMode === 'count' ? 'session' : 'monthly'),
    sessions_purchased: c.sessions_purchased ?? c.SessionCount ?? 0,
    room_id: c.RoomID || c.room_id || ''
  });

  let courseList = [];
  let courseApiSucceeded = false;
  if (token) {
    try {
      const scParams = new URLSearchParams();
      if (!isTeacher.value && branchId) scParams.set('branch_id', String(branchId));
      if (isTeacher.value && props.userId) scParams.set('teacher_id', String(props.userId));
      const apiUrl = `${baseUrl}/v1/student-classes?${scParams.toString()}`;
      const { data: allCourses } = await fetchAllPages(apiUrl, token, {
        perPage: 200,
        concurrency: 4,
        onProgress: (loaded, total) => {
          calendarLoadProgress.value = `載入課程中… ${loaded}/${total}`;
        },
      });
      courseList = allCourses.map(mapCourse);
      courseApiSucceeded = true;
    } catch (e) {
    // Keep fallback silent for end users; API failure is handled by fallback path.
    }
  }

  let supabaseList = [];
  if (shouldUseLegacyCalendarFallback({ apiSucceeded: courseApiSucceeded })) {
    let query = supabase
      .from('student-classes')
      .select('*, student:students(name), teacher:profiles(username)');
    if (!isTeacher.value && branchId) query = query.eq('branch_id', branchId);
    if (isTeacher.value && props.userId) query = query.eq('teacher_id', props.userId);
    const { data } = await query;
    supabaseList = (data || []).map(c => ({
      ...c,
      student_name: c.student?.name || c.student_name || '—',
      teacher_name: c.teacher_name || c.teacher?.username || '未指派',
      day_of_week: parseInt(c.day_of_week) || 0,
      days_of_week: Array.isArray(c.days_of_week) && c.days_of_week.length ? c.days_of_week : null,
      weeks: Array.isArray(c.weeks) && c.weeks.length ? c.weeks : [1, 2, 3, 4, 5],
      first_class_date: c.first_class_date || c.StartDate || null,
      end_date: c.end_date || (c.EndDate ? String(c.EndDate).slice(0, 10) : null),
      status: c.status || '',
      stop: c.stop ?? c.Stop ?? 0,
      payment_type: c.payment_type || (c.ScheduleMode === 'count' ? 'session' : 'monthly'),
      sessions_purchased: c.sessions_purchased ?? c.SessionCount ?? 0
    }));
  }
  // Laravel API is primary (has correct student_name, teacher_name, days_of_week).
  // Merge: use API data, enrich with any Supabase-only fields (remaining_sessions from Supabase may be fresher).
  if (courseList.length > 0) {
    const sbMap = Object.fromEntries(supabaseList.map(c => [c.id, c]));
    courseList = courseList.map(c => {
      const sb = sbMap[c.id];
      if (sb) {
        const hasApiRemaining = c.remaining_sessions !== null && c.remaining_sessions !== undefined;
        if (!hasApiRemaining && sb.remaining_sessions != null) c.remaining_sessions = sb.remaining_sessions;
        if (sb.first_class_date && !c.first_class_date) c.first_class_date = sb.first_class_date;
      }
      return c;
    });
  } else {
    courseList = supabaseList;
  }

  courseList = courseList.filter(isCourseActiveForCalendar);

  // schedules / ClassSession API：對齊「當前渲染週 ± 約六週」，換週換月會由 watch(loadCourses) 重抓
  const { schedStart, schedEnd } = getCalendarDataFetchBoundsYmd();

  let excData = [];
  let exceptionsApiSucceeded = false;
  if (token) {
    try {
      const excParams = new URLSearchParams({ per_page: '2000', start: schedStart, end: schedEnd });
      if (!isTeacher.value && branchId) excParams.set('branch_id', String(branchId));
      // Teacher week view needs substitute exceptions owned by other teachers to
      // remove/transfer the original teacher's base occurrence before scoping.
      if (!isTeacher.value && props.userId) excParams.set('teacher_id', props.userId);
      const excRes = await fetch(`${baseUrl}/v1/schedules?${excParams}`, {
        credentials: 'include',
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }
      });
      if (excRes.ok) {
        const excJson = await excRes.json();
        const excList = Array.isArray(excJson) ? excJson : (excJson?.data ?? []);
        excData = excList.map(ex => ({
          ...ex,
          schedule_date: ex.schedule_date != null ? String(ex.schedule_date).slice(0, 10) : ex.schedule_date
        }));
        exceptionsApiSucceeded = true;
      }
    } catch (_) {}
  }
  if (shouldUseLegacyCalendarFallback({ apiSucceeded: exceptionsApiSucceeded })) {
    let excQuery = supabase
      .from('schedules')
      .select('*');
    if (!isTeacher.value && branchId) excQuery = excQuery.eq('branch_id', branchId);
    if (!isTeacher.value && props.userId) excQuery = excQuery.eq('teacher_id', props.userId);
    const { data: excRaw } = await excQuery;
    excData = Array.isArray(excRaw) ? excRaw : (excRaw?.data || []);
  }

  // Build name lookup maps from courses (which already have correct names from Laravel API)
  const studentNameMap = {};
  const teacherNameMap = {};
  courseList.forEach(c => {
    if (c.student_id && c.student_name) studentNameMap[c.student_id] = c.student_name;
    if (c.teacher_id && c.teacher_name) teacherNameMap[c.teacher_id] = c.teacher_name;
  });
  // Also from loaded teachers/students
  (teachers.value || []).forEach(t => { if (t.id && t.username) teacherNameMap[t.id] = t.username; });
  (allStudents.value || []).forEach(s => { if (s.id && s.name) studentNameMap[s.id] = s.name; });

  // Enrich exceptions with student/teacher names
  excData = excData.map(ex => ({
    ...ex,
    student: ex.student || (ex.student_id ? { name: studentNameMap[ex.student_id] || null } : null),
    teacher: ex.teacher || (ex.teacher_id ? { username: teacherNameMap[ex.teacher_id] || null } : null),
  }));

  courses.value = courseList;
  exceptions.value = excData;

  // Single source of truth: class session dates come from backend ClassSession API.
  sessionDatesByCourseId.value = {};
  if (token && (branchId || isTeacher.value) && courseList.length > 0) {
    const ids = courseList.map((c) => Number(c?.id || 0)).filter((id) => id > 0);
    if (ids.length > 0) {
      try {
        const { byClass } = await fetchClassSessions({
          token,
          branchId: isTeacher.value ? 0 : branchId,
          studentClassIds: ids,
          start: schedStart,
          end: schedEnd,
          perPage: 2000,
        });
        sessionDatesByCourseId.value = byClass || {};
      } catch (_) {
        sessionDatesByCourseId.value = {};
      }
    }
  }

  // Legacy Supabase→MySQL sync removed (backend returns 410 since the
  // endpoint was retired).  Course data now lives exclusively in MySQL
  // and is managed through the standard student-classes CRUD endpoints.
  // TD-062 Phase 1：僅在 student-classes API 成功時記錄已抓視窗，避免失敗（空資料）污染快取。
  lastCalendarFetch.value = courseApiSucceeded
    ? { branchId, schedStart, schedEnd }
    : null;
  calendarLoading.value = false;
  calendarLoadProgress.value = '';
};

const loadStudents = async () => {
  const { data: { session: sess } } = await supabase.auth.getSession();
  const token = sess?.access_token;
  if (!token) return;
  const stuParams = new URLSearchParams({ per_page: '500' });
  if (!isTeacher.value && props.branchId) stuParams.set('branch_id', String(props.branchId));
  try {
    const res = await fetch(`/api/v1/students?${stuParams.toString()}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    const json = await res.json();
    allStudents.value = Array.isArray(json) ? json : (json?.data || []);
  } catch (e) {
    // Keep UI usable even if student options fail to load.
    let query = supabase.from('students').select('id, name');
    if (!isTeacher.value && props.branchId) query = query.eq('branch_id', props.branchId);
    const { data } = await query;
    allStudents.value = Array.isArray(data) ? data : (data?.data || []);
  }
};

const loadTeachers = async () => {
  const { data: { session: sess } } = await supabase.auth.getSession();
  const token = sess?.access_token;
  if (!token) return;
  const branchId = Number(props.branchId) || 0;
  if (!branchId && !isTeacher.value) {
    teachers.value = [];
    return;
  }
  try {
    const params = new URLSearchParams({ per_page: 'all' });
    if (!isTeacher.value && branchId) {
      params.set('branch_id', String(branchId));
      params.set('strict_branch', '1');
    }
    const res = await fetch(`/api/v1/teachers?${params.toString()}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    const json = await res.json();
    const list = Array.isArray(json) ? json : (json?.data || []);
    const normalized = list
      .map((t) => {
        const id = Number(t?.id ?? 0);
        if (!Number.isFinite(id) || id <= 0) return null;
        const branchIds = Array.isArray(t?.branch_ids)
          ? t.branch_ids.map((v) => Number(v)).filter((v) => Number.isFinite(v) && v > 0)
          : [];
        const branch = Number(t?.branch_id || 0) || null;
        return {
          id,
          name: t?.name || t?.Name || t?.T_Name || t?.username || t?.LoginName || `老師#${id}`,
          username: t?.username || '',
          email: t?.email || '',
          branch_ids: branchIds,
          branch_id: branch,
        };
      })
      .filter(Boolean);
    teachers.value = isTeacher.value
      ? normalized
      : normalized.filter((t) => (t.branch_ids || []).includes(branchId) || Number(t.branch_id || 0) === branchId);
  } catch (e) {
    // Keep UI usable even if teacher options fail to load.
    teachers.value = [];
  }
};

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

// ===== Leave (請假) =====
const showLeaveModal = ref(false);
const leaveForm = ref({
  student_id: '', subject: '', day_of_week: 1,
  start_time: '', end_time: '', schedule_date: '', course_id: '',
  teacher_id: '', duration_hours: 2, class_type: 'one_on_one'
});

const openLeaveModal = () => {
  // Use exact date clicked, fallback to today
  const exactDate = modalForm.value.action_date || new Date().toISOString().split('T')[0];
  leaveForm.value = {
    student_id: modalForm.value.student_id,
    subject: modalForm.value.subject,
    day_of_week: modalForm.value.day_of_week,
    start_time: modalForm.value.start_time,
    end_time: modalForm.value.end_time,
    schedule_date: exactDate,
    course_id: editingCourseId.value,
    teacher_id: modalForm.value.teacher_id || '',
    duration_hours: modalForm.value.duration_hours || 2,
    class_type: modalForm.value.class_type || 'one_on_one'
  };
  showModal.value = false;
  showLeaveModal.value = true;
};

const submitLeave = async () => {
  if (!leaveForm.value.schedule_date) { alert('請選擇日期'); return; }
  const studentId = Number(leaveForm.value.student_id) || 0;
  const courseId = Number(leaveForm.value.course_id) || null;
  const teacherId = Number(leaveForm.value.teacher_id) || null;
  const branchId = Number(props.branchId) || 0;
  if (!studentId || !branchId) { alert('請假登記失敗：缺少學生或分校資訊'); return; }
  const payload = {
    student_id: studentId,
    teacher_id: teacherId,
    subject: leaveForm.value.subject,
    day_of_week: Number(leaveForm.value.day_of_week) || 1,
    start_time: leaveForm.value.start_time,
    end_time: leaveForm.value.end_time,
    duration_hours: leaveForm.value.duration_hours || 2,
    class_type: leaveForm.value.class_type || 'one_on_one',
    status: 'leave',
    type: 'normal',
    deduction: 0,
    branch_id: branchId,
    schedule_date: leaveForm.value.schedule_date,
    student_course_id: courseId
  };
  const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
  const token = session?.access_token || '';
  const baseUrl = import.meta.env.VITE_API_BASE || '/api';
  if (!token) {
    alert('請假登記失敗：請重新登入後再試');
    return;
  }
  try {
    const res = await fetch(`${baseUrl}/v1/schedules`, {
      method: 'POST',
      credentials: 'include',
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload)
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
      alert('請假登記失敗：' + (body.message || res.statusText || '請稍後再試'));
      return;
    }
  } catch (error) {
    alert('請假登記失敗：' + (error?.message || '請稍後再試'));
    return;
  }
  showLeaveModal.value = false;
  contextMenu.value = { show: false, x: 0, y: 0, course: null, date: null };
  await loadCourses();
  alert('請假登記完成');
};

// ===== Substitute Teacher (換代課老師) =====
const showSubstituteModal = ref(false);
const substituteSubmitting = ref(false);
const substituteForm = ref({
  student_id: '', subject: '', session_date: '', start_time: '', end_time: '',
  original_teacher_name: '', substitute_teacher_id: '', reason: '',
  session_id: null, course_id: null,
});

const teacherDisplayName = (tid) => {
  if (tid == null || tid === '') return '—';
  const t = (teachers.value || []).find((x) => String(x.id) === String(tid));
  return t?.name || t?.username || '—';
};

const openSubstituteFromDrag = (course, dateStr, dropTeacherId) => {
  const baseId = course.is_exception ? course.student_course_id : course.id;
  substituteForm.value = {
    student_id: course.student_id,
    subject: course.subject,
    session_date: dateStr,
    start_time: course.start_time || '',
    end_time: course.end_time || '',
    original_teacher_name: teacherDisplayName(course.teacher_id),
    substitute_teacher_id: dropTeacherId != null && dropTeacherId !== '' ? String(dropTeacherId) : '',
    reason: '行事曆拖曳至代課老師',
    session_id: null,
    course_id: baseId,
  };
  if (baseId && sessionDatesByCourseId.value) {
    const sessions = sessionDatesByCourseId.value[String(baseId)] || [];
    const sid = resolveSessionIdForSubstitute(sessions, dateStr, course.start_time);
    if (sid) substituteForm.value.session_id = sid;
  }
  showSubstituteModal.value = true;
};

const openSubstituteModal = () => {
  const exactDate = modalForm.value.action_date || new Date().toISOString().split('T')[0];
  substituteForm.value = {
    student_id: modalForm.value.student_id,
    subject: modalForm.value.subject,
    session_date: exactDate,
    start_time: modalForm.value.start_time || '',
    end_time: modalForm.value.end_time || '',
    original_teacher_name: teacherDisplayName(modalForm.value.teacher_id),
    substitute_teacher_id: '',
    reason: '',
    session_id: null,
    course_id: editingCourseId.value,
  };
  showModal.value = false;

  // Resolve the ClassSession id for this date + course
  const courseId = editingCourseId.value;
  if (courseId && sessionDatesByCourseId.value) {
    const sessions = sessionDatesByCourseId.value[String(courseId)] || [];
    const sid = resolveSessionIdForSubstitute(sessions, exactDate, modalForm.value.start_time);
    if (sid) substituteForm.value.session_id = sid;
  }

  showSubstituteModal.value = true;
};

const submitSubstitute = async () => {
  if (!substituteForm.value.substitute_teacher_id) { alert('請選擇代課老師'); return; }
  if (!substituteForm.value.session_id) {
    alert('找不到該堂次 ClassSession，無法設定代課。\n（可能此日期尚未有 ClassSession 紀錄）');
    return;
  }

  substituteSubmitting.value = true;
  try {
    const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
    const token = session?.access_token || '';
    if (!token) { alert('請重新登入'); return; }

    const res = await fetch(`/api/v1/class-sessions/${substituteForm.value.session_id}/substitute`, {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({
        substitute_teacher_id: Number(substituteForm.value.substitute_teacher_id),
        reason: substituteForm.value.reason || null,
      }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      alert('代課設定失敗：' + (json.message || res.statusText));
      return;
    }
    showSubstituteModal.value = false;
    alert(json.message || '代課設定完成');
    await loadCourses();
  } catch (e) {
    alert('代課設定失敗：' + (e?.message || '請稍後再試'));
  } finally {
    substituteSubmitting.value = false;
  }
};

// ===== PRD 9c058f19 代課流程 UX 優化（V2） =====
const featureSubstituteV2 = FEATURE_SUBSTITUTE_V2;
const showSubstituteV2Modal = ref(false);
const substituteV2PickerRef = ref(null);
const toastRef = ref(null);
const substituteV2Context = ref({});
const substituteV2SessionId = ref(null);
const substituteV2Submitting = ref(false);

const showTeacherLeaveBatchModal = ref(false);

// 多數使用者為單分校主任，名稱由後端返回時可擴充；此處使用 id → label 降級，保持 UX 可用。
const branchNameMap = computed(() => {
  const m = {};
  const bid = Number(props.branchId || 0);
  if (bid > 0) m[bid] = `分校#${bid}`;
  return m;
});

const openSubstituteV2Modal = () => {
  const exactDate = modalForm.value.action_date || new Date().toISOString().split('T')[0];
  const courseId = editingCourseId.value;
  let sessionId = null;
  if (courseId && sessionDatesByCourseId.value) {
    const sessions = sessionDatesByCourseId.value[String(courseId)] || [];
    sessionId = resolveSessionIdForSubstitute(sessions, exactDate, modalForm.value.start_time);
  }
  if (!sessionId) {
    alert('找不到該堂次 ClassSession，無法設定代課。\n（可能此日期尚未有 ClassSession 紀錄）');
    return;
  }
  substituteV2SessionId.value = sessionId;
  substituteV2Context.value = {
    student_name: getStudentName(modalForm.value.student_id),
    subject_id: modalForm.value.subject_id || null,
    subject_label: getSubjectLabel(modalForm.value.subject),
    session_date: exactDate,
    start_time: (modalForm.value.start_time || '').toString().slice(0, 5),
    end_time: (modalForm.value.end_time || '').toString().slice(0, 5),
    original_teacher_id: modalForm.value.teacher_id,
    original_teacher_name: teacherDisplayName(modalForm.value.teacher_id),
    session_campus_id: Number(props.branchId || 0) || null,
  };
  showModal.value = false;
  showSubstituteV2Modal.value = true;
};

const onSubstituteV2Submit = async (submitPayload) => {
  if (substituteV2Submitting.value) return;
  const { substitute_teacher_id, reason, new_date, new_start_time, new_end_time } = submitPayload || {};
  const sessionId = substituteV2SessionId.value;
  substituteV2Submitting.value = true;
  try {
    const ses = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
    const tkn = ses?.access_token || '';
    if (!tkn) throw new Error('請重新登入');
    const body = {
      substitute_teacher_id: Number(substitute_teacher_id),
      reason: reason || null,
    };
    // PRD f0cce4d5：合併代課+換時（三欄同填同省）
    if (new_date && new_start_time && new_end_time) {
      body.new_date = new_date;
      body.new_start_time = new_start_time;
      body.new_end_time = new_end_time;
    }
    const res = await fetch(`/api/v1/class-sessions/${sessionId}/substitute`, {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${tkn}` },
      body: JSON.stringify(body),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      // FR-004: no_class_session 是「目標日期尚無課堂紀錄」的資料前提錯誤，
      // 以 warning 色系提示用戶先到課程管理確認課堂，而不是當作紅色硬錯誤。
      if (res.status === 422 && json?.code === 'no_class_session') {
        substituteV2PickerRef.value?.setError?.(
          '此日期尚未建立課堂，請先在課程管理確認課堂日期，再重新指派代課。',
          'warning'
        );
      } else {
        const msg = json.message || res.statusText || '代課設定失敗';
        substituteV2PickerRef.value?.setError?.(msg);
      }
      throw new Error(json.message || res.statusText || '代課設定失敗');
    }
    showSubstituteV2Modal.value = false;
    const teacherName = json.substitute_teacher_name || teacherDisplayName(substitute_teacher_id);
    const uiSeconds = Number(json.undo_window_seconds);
    const durationMs = Number.isFinite(uiSeconds) && uiSeconds > 0 ? uiSeconds * 1000 : 5000;
    const isCombined = json.rescheduled === true || json.operation_type === 'substitute_with_reschedule';
    const effDate = json.session_date || substituteV2Context.value.session_date;
    const effStart = json.start_time || substituteV2Context.value.start_time;
    const effEnd = json.end_time || substituteV2Context.value.end_time;
    const studentName = substituteV2Context.value.student_name || '';
    const description = isCombined
      ? `${studentName ? studentName + ' · ' : ''}已調整至 ${effDate} ${effStart}~${effEnd}`
      : (studentName ? `${studentName} · ${effDate} ${effStart}` : '');
    toastRef.value?.show?.({
      title: isCombined ? `已指派 ${teacherName} 代課並調整時間` : `已指派 ${teacherName} 代課`,
      description,
      variant: 'success',
      durationMs,
      undoDescription: isCombined ? '代課與換時已撤銷，家長通知已作廢' : '代課已撤銷，家長通知已作廢',
      onUndo: async () => {
        await undoSubstitute(sessionId);
        await loadCourses();
      },
    });
    await loadCourses();
  } catch (e) {
    substituteV2PickerRef.value?.setError?.(e?.message || '代課設定失敗');
    // Do not re-throw: error is already displayed in UI via setError.
    // Re-throwing would cause Sentry to capture expected business conflicts (409).
  } finally {
    substituteV2Submitting.value = false;
  }
};

const openTeacherLeaveBatch = () => {
  showTeacherLeaveBatchModal.value = true;
};

const onBatchSubstituteSubmitted = async (resp) => {
  const sum = resp?.summary || {};
  toastRef.value?.show?.({
    title: '批次代課完成',
    description: `成功 ${sum.success ?? 0} · 失敗 ${sum.fail ?? 0}${sum.cross_campus ? ` · 跨分校 ${sum.cross_campus}` : ''}`,
    variant: sum.fail ? 'info' : 'success',
    durationMs: 6000,
  });
  await loadCourses();
};

// ===== Right-click context menu =====
const onCourseRightClick = (course, date, event) => {
  event.preventDefault();
  event.stopPropagation();
  const x = event.clientX;
  const y = event.clientY;
  contextMenu.value = { show: true, x, y, course, date };
};

const onContextLeave = () => {
  const { course, date } = contextMenu.value;
  const baseId = course.is_exception ? course.student_course_id : course.id;
  leaveForm.value = {
    student_id: course.student_id,
    subject: course.subject,
    teacher_id: course.teacher_id || '',
    day_of_week: course.day_of_week,
    start_time: course.start_time,
    end_time: course.end_time,
    duration_hours: course.duration_hours || 2,
    class_type: course.class_type || 'one_on_one',
    schedule_date: date,
    course_id: baseId
  };
  contextMenu.value = { show: false, x: 0, y: 0, course: null, date: null };
  showLeaveModal.value = true;
};

// ===== Extra Lesson (加課) =====
const showExtraModal = ref(false);
const extraForm = ref({
  student_id: '', subject: 'Math', teacher_id: '', class_type: 'one_on_one',
  schedule_date: '', start_time: '16:00', end_time: '18:00', duration_hours: 2
});

const onExtraFormStartTimeChange = () => {
  extraForm.value.start_time = normalizeTimeTo30(extraForm.value.start_time);
  extraForm.value.end_time = computeEndTime(extraForm.value.start_time, extraForm.value.duration_hours);
};
const onExtraFormTimeChange = () => {
  extraForm.value.end_time = computeEndTime(extraForm.value.start_time, extraForm.value.duration_hours);
};
const computedExtraEndTime = computed(() =>
  computeEndTime(extraForm.value.start_time, extraForm.value.duration_hours)
);

const extraParentPaymentType = computed(() => {
  return modalForm.value?.payment_type || 'session';
});

const openExtraLesson = () => {
  const exactDate = modalForm.value.action_date || new Date().toISOString().split('T')[0];
  const start = normalizeTimeTo30(modalForm.value.start_time || '16:00');
  const dur = modalForm.value.duration_hours || 2;
  extraForm.value = {
    student_id: modalForm.value.student_id,
    subject: modalForm.value.subject,
    teacher_id: modalForm.value.teacher_id || '',
    class_type: modalForm.value.class_type || 'one_on_one',
    schedule_date: exactDate,
    start_time: start,
    end_time: computeEndTime(start, dur),
    duration_hours: dur
  };
  showModal.value = false;
  showExtraModal.value = true;
};

const submitExtraLesson = async () => {
  if (!extraForm.value.student_id) { alert('請選擇學生'); return; }
  if (!extraForm.value.schedule_date) { alert('請選擇日期'); return; }
  const endTime = computeEndTime(extraForm.value.start_time, extraForm.value.duration_hours);

  // Get day of week from the date
  const date = new Date(extraForm.value.schedule_date);
  let dow = date.getDay(); // 0=Sun, 1=Mon...
  if (dow === 0) dow = 7; // Convert Sunday to 7

  await supabase.from('schedules').insert([{
    student_id: extraForm.value.student_id,
    teacher_id: extraForm.value.teacher_id || null,
    subject: extraForm.value.subject,
    day_of_week: dow,
    start_time: normalizeTimeTo30(extraForm.value.start_time),
    end_time: endTime,
    duration_hours: extraForm.value.duration_hours,
    class_type: extraForm.value.class_type,
    status: 'scheduled',
    type: 'extra',
    deduction: 1,
    branch_id: props.branchId,
    schedule_date: extraForm.value.schedule_date,
    student_course_id: editingCourseId.value || null
  }]);

  // Ensure a ClassSession exists for RFID deduction
  if (editingCourseId.value) {
    try {
      const token = await getToken();
      await fetch('/api/v1/learning-records/reschedule-session', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify({
          student_class_id: editingCourseId.value,
          new_date: extraForm.value.schedule_date,
          start_time: normalizeTimeTo30(extraForm.value.start_time),
          end_time: endTime,
        }),
      });
    } catch (_) { /* non-critical */ }
  }

  showExtraModal.value = false;
  alert('加課建立完成，老師上課後需填寫評量表');
  await loadCourses();
};

// ===== Reschedule (調課) =====
const showRescheduleModal = ref(false);
const rescheduleForm = ref({
  student_id: '', subject: '', course_id: '',
  original_day: 1, original_start: '', original_end: '',
  new_date: '', new_day_of_week: 1, new_start: '', new_end: '',
  teacher_id: '', class_type: '', duration_hours: 2
});

const onRescheduleNewStartChange = () => {
  rescheduleForm.value.new_start = normalizeTimeTo30(rescheduleForm.value.new_start);
  rescheduleForm.value.new_end = computeEndTime(rescheduleForm.value.new_start, rescheduleForm.value.duration_hours);
};
const computedRescheduleNewEnd = computed(() =>
  computeEndTime(rescheduleForm.value.new_start, rescheduleForm.value.duration_hours)
);

const openRescheduleModal = () => {
  const exactDate = modalForm.value.action_date || new Date().toISOString().split('T')[0];
  const newStart = normalizeTimeTo30(modalForm.value.start_time);
  const dur = modalForm.value.duration_hours || 2;
  rescheduleForm.value = {
    student_id: modalForm.value.student_id,
    subject: modalForm.value.subject,
    course_id: editingCourseId.value,
    original_day: modalForm.value.day_of_week,
    original_start: modalForm.value.start_time,
    original_end: modalForm.value.end_time,
    original_date: exactDate, // Need to know which date was rescheduled
    new_date: exactDate,
    new_day_of_week: modalForm.value.day_of_week,
    new_start: newStart,
    new_end: computeEndTime(newStart, dur),
    teacher_id: modalForm.value.teacher_id,
    class_type: modalForm.value.class_type,
    duration_hours: dur
  };
  showModal.value = false;
  showRescheduleModal.value = true;
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

/** 調課寫 schedules 時，若仍以合約 TeacherID 顯示、但鏈結上已有「代課 scheduled」，改用代課老師避免假性撞課（後端亦有相同防呆）。 */
const resolveTeacherIdForRescheduledSlot = (anchorId, courseId, fallbackTeacherId) => {
  if (anchorId == null || anchorId === '' || !courseId) {
    return fallbackTeacherId ?? null;
  }
  const subEx = exceptions.value.find((ex) =>
    ex.status === 'scheduled'
    && ex.original_schedule_id != null
    && String(ex.original_schedule_id) === String(anchorId)
    && String(ex.student_course_id) === String(courseId));
  if (subEx?.teacher_id == null || String(subEx.teacher_id).trim() === '') {
    return fallbackTeacherId ?? null;
  }
  const substituteTid = Number(subEx.teacher_id);
  const fbNum = fallbackTeacherId != null && fallbackTeacherId !== ''
    ? Number(fallbackTeacherId)
    : 0;
  const baseCourse = courses.value.find((c) => String(c.id) === String(courseId));
  const contractTid = baseCourse?.teacher_id != null ? Number(baseCourse.teacher_id) : 0;
  if (
    contractTid > 0
    && Number.isFinite(substituteTid)
    && substituteTid > 0
    && substituteTid !== contractTid
    && (fbNum === 0 || fbNum === contractTid)) {
    return substituteTid;
  }
  return fallbackTeacherId ?? null;
};

const submitReschedule = async () => {
  if (!rescheduleForm.value.new_date) { alert('請選擇新日期'); return; }
  const newDayOfWeek = dayOfWeekFromDate(rescheduleForm.value.new_date);
  const branchId = Number(props.branchId) || 0;
  if (!branchId) { alert('請先選擇分校'); return; }

  const payload1 = {
    student_id: rescheduleForm.value.student_id,
    teacher_id: rescheduleForm.value.teacher_id || null,
    subject: rescheduleForm.value.subject,
    day_of_week: rescheduleForm.value.original_day,
    start_time: rescheduleForm.value.original_start,
    end_time: rescheduleForm.value.original_end,
    duration_hours: rescheduleForm.value.duration_hours,
    class_type: rescheduleForm.value.class_type,
    status: 'rescheduled',
    type: 'normal',
    deduction: 0,
    branch_id: branchId,
    student_course_id: rescheduleForm.value.course_id,
    schedule_date: rescheduleForm.value.original_date
  };

  // Check for duplicate: if this date already has a rescheduled/leave exception, skip inserting
  const alreadyRescheduled = exceptions.value.some(ex =>
    (ex.status === 'rescheduled' || ex.status === 'leave') &&
    String(ex.student_course_id) === String(rescheduleForm.value.course_id) &&
    ex.schedule_date === rescheduleForm.value.original_date
  );
  let originalId = null;
  if (!alreadyRescheduled) {
    const res1 = await supabase.from('schedules').insert([payload1]);
    if (res1.error) {
      alert('調課失敗：' + (res1.error?.message || '無法寫入原堂次紀錄'));
      return;
    }
    const origList = Array.isArray(res1.data) ? res1.data : (res1.data ? [res1.data] : []);
    originalId = origList[0]?.id ?? null;
  } else {
    // Find existing rescheduled exception
    const existing = exceptions.value.find(ex =>
      ex.status === 'rescheduled' &&
      String(ex.student_course_id) === String(rescheduleForm.value.course_id) &&
      ex.schedule_date === rescheduleForm.value.original_date
    );
    originalId = existing?.id ?? null;
  }

  const newEnd = computeEndTime(rescheduleForm.value.new_start, rescheduleForm.value.duration_hours);

  // FR-001: If a substitute scheduled row already exists for this rescheduled
  // anchor (originalId), DO NOT insert a second scheduled row. The backend
  // /reschedule-session call below will move the existing substitute row to
  // the new date/time via syncSchedulesForRescheduledSession. Inserting here
  // would create a duplicate scheduled row with the ORIGINAL teacher_id,
  // which wins the MAX(id) tiebreak in ClassSessionController::index and
  // causes CourseManagement to display the wrong teacher.
  // Fallback: if exceptions hasn't loaded or doesn't carry original_schedule_id,
  // proceed with insert (FR-002 backend purge will clean up duplicates).
  const alreadySubstituted = originalId !== null && exceptions.value.some(ex =>
    ex.status === 'scheduled' &&
    ex.original_schedule_id != null &&
    String(ex.original_schedule_id) === String(originalId) &&
    String(ex.student_course_id) === String(rescheduleForm.value.course_id)
  );

  if (!alreadySubstituted) {
    const effectiveTid = resolveTeacherIdForRescheduledSlot(
      originalId,
      rescheduleForm.value.course_id,
      rescheduleForm.value.teacher_id
    );
    const payload2 = {
      student_id: rescheduleForm.value.student_id,
      teacher_id: effectiveTid != null && effectiveTid !== '' ? effectiveTid : null,
      subject: rescheduleForm.value.subject,
      day_of_week: newDayOfWeek,
      start_time: normalizeTimeTo30(rescheduleForm.value.new_start),
      end_time: newEnd,
      duration_hours: rescheduleForm.value.duration_hours,
      class_type: rescheduleForm.value.class_type,
      status: 'scheduled',
      type: 'normal',
      deduction: 1,
      branch_id: branchId,
      schedule_date: rescheduleForm.value.new_date,
      original_schedule_id: originalId,
      student_course_id: rescheduleForm.value.course_id
    };

    const res2 = await supabase.from('schedules').insert([payload2]);
    if (res2.error) {
      // Roll back the first step — delete the orphan rescheduled record so the original class reappears
      if (originalId) {
        await supabase.from('schedules').delete().eq('id', originalId);
      }
      const errMsg = res2.error?.message || '無法寫入新堂次';
      const isConflict = res2.error?.conflicts?.length > 0 || String(errMsg).includes('已有') || String(errMsg).includes('上限');
      if (isConflict) {
        alert('調課失敗：目標時段已有其他學生（撞課），請換一個時段再試。\n\n詳細：' + errMsg);
      } else {
        alert('調課失敗：' + errMsg);
      }
      return;
    }
  }

  // Sync ClassSession to new date for RFID deduction.
  // FR-002/003: pass old_start_time for precise location and surface API failures.
  if (rescheduleForm.value.course_id) {
    try {
      const token = await getToken();
      const resched = await fetch('/api/v1/learning-records/reschedule-session', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify({
          student_class_id: rescheduleForm.value.course_id,
          old_date: rescheduleForm.value.original_date || null,
          old_start_time: rescheduleForm.value.original_start || undefined,
          new_date: rescheduleForm.value.new_date,
          start_time: normalizeTimeTo30(rescheduleForm.value.new_start),
          end_time: computeEndTime(rescheduleForm.value.new_start, rescheduleForm.value.duration_hours),
        }),
      });
      if (!resched.ok) {
        const err = await resched.json().catch(() => ({}));
        alert('調課失敗：' + (err.message || '找不到指定堂次，請確認日期與時間是否正確'));
        return;
      }
    } catch (e) {
      alert('調課失敗：' + (e?.message || '網路錯誤，請稍後再試'));
      return;
    }
  }

  showRescheduleModal.value = false;
  await loadCourses();
  alert('調課完成');
};

// ===== Helpers =====
const getStudentName = (sid) => {
  const s = allStudents.value.find(s => s.id === sid);
  return s ? s.name : '—';
};

const reloadCalendarData = () => Promise.allSettled([
  loadCourses(),
  loadStudents(),
  loadTeachers(),
  loadRooms(),
  loadSubjects(),
]);

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
.week-teacher-chips {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.week-teacher-chips-label {
  flex-shrink: 0;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-light, #64748b);
}
.week-teacher-chips-scroll {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
  min-width: 0;
  max-height: 5.5rem;
  overflow-y: auto;
  padding: 2px 0;
}
.week-teacher-chip {
  padding: 6px 12px;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 999px;
  font-size: 13px;
  font-weight: 600;
  line-height: 1.3;
  color: var(--text-color, #1a1a1a);
  background: #fff;
  cursor: pointer;
  transition: background 0.15s, border-color 0.15s, color 0.15s;
}
.week-teacher-chip:hover {
  background: var(--bg-muted, #f8fafc);
  border-color: #cbd5e1;
}
.week-teacher-chip.active {
  color: #fff;
}
.week-teacher-chip-clear {
  flex-shrink: 0;
  padding: 4px 10px;
  border: 1px dashed var(--border-color, #cbd5e1);
  border-radius: 999px;
  font-size: 12px;
  color: var(--text-light, #64748b);
  background: transparent;
  cursor: pointer;
  transition: color 0.15s, border-color 0.15s;
}
.week-teacher-chip-clear:hover {
  color: #e53935;
  border-color: #e53935;
}
.cb-teacher-tag {
  font-size: 10px;
  font-weight: 700;
  color: #fff;
  border-radius: 3px;
  padding: 1px 4px;
  margin-bottom: 1px;
  line-height: 1.3;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
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
.week-nav-btn {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  padding: 4px 10px;
  height: 30px;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 20px;
  background: #fff;
  font-size: 12px;
  font-weight: 500;
  color: var(--text-color);
  cursor: pointer;
  white-space: nowrap;
  transition: background 0.2s, border-color 0.2s;
}
.week-nav-btn:hover {
  background: var(--bg-muted, #f0f1f3);
  border-color: var(--border, #cbd5e1);
}
.month-display {
  min-width: 82px;
  text-align: center;
  font-size: 14px;
  font-weight: 600;
  line-height: 1.4;
  color: var(--text-color);
}
.week-nav {
  display: flex;
  align-items: center;
  gap: 6px;
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
.week-nav .week-select {
  min-width: 180px;
}
.week-select {
  padding: 8px 12px;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 8px;
  font-size: 14px;
  line-height: 1.4;
  background: #fff;
  color: var(--text-color);
  min-width: 96px;
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
.btn-icon-text .btn-emoji { font-size: 1em; line-height: 1; }
.btn-icon-text .btn-text { font-weight: 600; }
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
.day-tabs-bar {
  display: flex;
  gap: 2px;
  padding: 12px 16px 0;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}
.day-tab {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  padding: 10px 16px 8px;
  border: none;
  border-radius: 10px 10px 0 0;
  background: var(--bg-muted, #f0f1f3);
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  color: var(--text-light, #64748b);
  transition: background 0.2s, color 0.2s;
  position: relative;
  min-width: 72px;
  flex-shrink: 0;
}
.day-tab:hover { background: #e8e9ec; color: var(--text-color, #1a1a1a); }
.day-tab.active {
  background: #fff;
  color: var(--primary, #2563eb);
  box-shadow: 0 -2px 0 var(--primary, #2563eb) inset;
}
.day-tab-name { font-size: 13px; font-weight: 700; line-height: 1.3; }
.day-tab-date { font-size: 11px; font-weight: 400; opacity: 0.8; line-height: 1.2; }
.day-tab-badge {
  position: absolute;
  top: 4px;
  right: 4px;
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
.day-tab.active .day-tab-badge { background: #1d4ed8; }

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
.teacher-grid.teacher-grid-compact .teacher-col-header {
  height: 56px;
  padding: 6px 6px;
  gap: 5px;
}
.teacher-grid.teacher-grid-compact .col-header-blank {
  height: 56px;
}
.teacher-grid.teacher-grid-compact .teacher-col-avatar {
  width: 24px;
  height: 24px;
  font-size: 11px;
  border-radius: 6px;
}
.teacher-grid.teacher-grid-compact .teacher-col-name {
  font-size: 12px;
}
.teacher-grid.teacher-grid-compact .teacher-col-room {
  font-size: 9px;
}
.teacher-grid.teacher-grid-compact .course-block {
  padding: 4px 3px;
  border-radius: 6px;
}
.teacher-grid.teacher-grid-compact .cb-student {
  font-size: 12px;
  line-height: 1.15;
}
.teacher-grid.teacher-grid-compact .cb-detail,
.teacher-grid.teacher-grid-compact .cb-type {
  font-size: 8px;
  margin-top: 1px;
}
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
.teacher-col-header {
  height: 64px;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  background: var(--bg-muted, #f8fafc);
  border-bottom: 1px solid var(--border-color, #e2e8f0);
  border-top: 3px solid transparent;
  position: sticky;
  top: 0;
  z-index: 10;
  box-shadow: 0 1px 0 rgba(15, 23, 42, 0.06);
}
.teacher-col-avatar {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-weight: 700;
  font-size: 14px;
  flex-shrink: 0;
}
.teacher-col-info { min-width: 0; flex: 1; }
.teacher-col-name {
  font-size: 13px;
  font-weight: 700;
  line-height: 1.3;
  color: var(--text-color, #1a1a1a);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.teacher-col-room {
  font-size: 10px;
  line-height: 1.3;
  color: var(--text-light, #94a3b8);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
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
.cb-student {
  font-weight: 800;
  font-size: 14px;
  line-height: 1.2;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  display: block;
  letter-spacing: -0.2px;
  text-shadow: 0 1px 2px rgba(15, 23, 42, 0.18);
}
.cb-detail {
  font-size: 9px;
  line-height: 1.2;
  opacity: 0.9;
  margin-top: 1px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  display: block;
}
.cb-type {
  font-size: 9px;
  line-height: 1.2;
  opacity: 0.78;
  margin-top: 1px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  display: block;
}
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

/* Conflict Warning */
.conflict-box {
  background: #FFEBEE;
  border: 1px solid #EF9A9A;
  border-radius: 8px;
  padding: 12px 14px;
  margin-bottom: 16px;
  color: #C62828;
  font-size: 13px;
  line-height: 1.5;
}
.conflict-box-prominent {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  background: #ffcdd2;
  border: 2px solid #e57373;
  padding: 14px 16px;
  margin-bottom: 16px;
  border-radius: 8px;
  color: #b71c1c;
  font-size: 14px;
  line-height: 1.5;
}
.conflict-box-prominent strong { font-size: 14px; }
.conflict-box-prominent p { margin: 4px 0 0 0; font-size: 13px; line-height: 1.45; }
.conflict-icon { font-size: 20px; line-height: 1; flex-shrink: 0; }
.occurrence-hint { margin: 0 0 12px 0; padding: 8px 10px; background: var(--ds-canvas-soft, #f8f9fb); color: var(--ds-ink-mute, #555); border-radius: 6px; font-size: 13px; }
.session-info-card {
  background: #E8F5E9;
  border: 1px solid #A5D6A7;
  border-radius: 8px;
  padding: 12px 14px;
  margin-bottom: 12px;
}
.session-info-row {
  display: flex;
  align-items: baseline;
  gap: 10px;
  padding: 3px 0;
}
.session-info-key {
  font-size: 12px;
  font-weight: 600;
  color: #2E7D32;
  min-width: 60px;
  flex-shrink: 0;
}
.session-info-val {
  font-size: 14px;
  color: #1B5E20;
}
.session-charge-adjusted {
  margin-left: 6px;
  font-size: 12px;
  color: #C2410C;
  font-weight: 600;
}
.session-charge-standard {
  margin-left: 6px;
  font-size: 12px;
  color: #64748B;
}
.course-ref-section {
  background: #FAFAFA;
  border: 1px solid #E0E0E0;
  border-radius: 8px;
  padding: 0 12px 12px;
  margin-top: 12px;
}
.course-ref-hint {
  font-size: 12px;
  color: #9E9E9E;
  margin: 0 0 8px 0;
  line-height: 1.4;
}
.modal-form-sections { margin-top: 4px; }
.form-section-label {
  font-size: 12px;
  font-weight: 600;
  line-height: 1.4;
  color: var(--text-light);
  letter-spacing: 0.04em;
  margin: 20px 0 10px 0;
  padding-bottom: 6px;
  border-bottom: 1px solid var(--border-color);
}
.form-section-label:first-child { margin-top: 0; }

/* Schedule Actions Box */
.schedule-actions-box {
  margin-top: 16px;
  padding: 12px;
  background: #F5F5F5;
  border-radius: 8px;
  border: 1px solid #E0E0E0;
}

.schedule-actions-title {
  font-size: 12px;
  font-weight: 700;
  color: #616161;
  margin-bottom: 8px;
}
.eval-summary-box {
  margin-top: 16px;
  padding: 12px;
  background: var(--ds-canvas-soft, #f8f9fb);
  border-radius: 8px;
  border: 1px solid var(--ds-hairline, #e5e7eb);
}
.eval-summary-title {
  font-size: 12px;
  font-weight: 700;
  color: var(--ds-ink, #1a1a1a);
  margin-bottom: 8px;
}
.eval-summary-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.eval-summary-table th, .eval-summary-table td {
  padding: 4px 8px;
  text-align: left;
  border-bottom: 1px solid var(--ds-hairline, #e5e7eb);
}
.eval-summary-table th { color: #555; font-weight: 600; font-size: 11px; }
.eval-status-tag { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
.eval-status-tag.approved { background: #e6f4ea; color: #2e7d32; }
.eval-status-tag.pending { background: #fff3e0; color: #e65100; }
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
.schedule-actions-btns {
  display: flex;
  gap: 8px;
}

.action-btn {
  flex: 1;
  padding: 8px 12px;
  border: 2px solid transparent;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
  text-align: center;
  font-family: inherit;
}

.action-btn.extra {
  background: #E3F2FD;
  color: #1565C0;
  border-color: #90CAF9;
}
.action-btn.extra:hover {
  background: #BBDEFB;
}

.action-btn.leave {
  background: #FFF3E0;
  color: #E65100;
  border-color: #FFCC80;
}
.action-btn.leave:hover {
  background: #FFE0B2;
}

.action-btn.reschedule {
  background: #F3E5F5;
  color: #6A1B9A;
  border-color: #CE93D8;
}
.action-btn.reschedule:hover {
  background: #E1BEE7;
}

.action-btn.substitute {
  background: #ecfeff;
  color: #0e7490;
  border-color: #67e8f9;
}
.action-btn.substitute:hover {
  background: #cffafe;
}
.action-btn.cancel-session {
  background: #fff1f2;
  color: #be123c;
  border-color: #fda4af;
}
.action-btn.cancel-session:hover:not(:disabled) {
  background: #ffe4e6;
}
.action-btn.cancel-session:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.cancel-session-confirm {
  margin-top: 10px;
  padding: 12px 14px;
  background: #fff1f2;
  border: 1.5px solid #fda4af;
  border-radius: 8px;
  font-size: 13px;
  color: #be123c;
}
.cancel-session-confirm p { margin: 0 0 10px; line-height: 1.5; }
.cancel-session-confirm small { color: #9f1239; }
.cancel-session-confirm-btns { display: flex; gap: 8px; }

/* Day Chips */
.day-chip-row {
  display: flex;
  gap: 6px;
}

.cal-day-chip {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  padding: 0;
  margin: 0;
  box-sizing: border-box;
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
.cal-day-chip span {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  line-height: 1;
  text-align: center;
  pointer-events: none;
}

.cal-day-chip:hover {
  border-color: #FF9800;
  background: #FFF3E0;
}

.cal-day-chip.selected {
  border-color: #E65100;
  background: #FF9800;
  color: #fff;
}

/* Modal：表單與按鈕排版 */
.modal h3 {
  margin: 0 0 16px 0;
  font-size: 1.125rem;
  font-weight: 700;
  line-height: 1.35;
}
.modal-form-sections .form-group {
  margin-bottom: 0;
}
.modal-form-sections label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  line-height: 1.4;
  margin-bottom: 6px;
  color: var(--text-color);
}
.modal-form-sections select,
.modal-form-sections input {
  font-size: 14px;
  line-height: 1.4;
  padding: 8px 10px;
  width: 100%;
}
.computed-time {
  margin: 0;
  padding: 10px 12px;
  background: var(--bg-muted, #f5f5f5);
  border-radius: 8px;
  font-weight: 600;
  font-size: 15px;
  line-height: 1.4;
  color: var(--text);
}
.schedule-actions-title {
  font-size: 12px;
  font-weight: 700;
  line-height: 1.4;
  margin-bottom: 10px;
}
.action-btn { line-height: 1.4; }
.actions {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 20px;
  padding-top: 16px;
  border-top: 1px solid var(--border-color, #e2e8f0);
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
  .teacher-col-header { height: 56px; padding: 6px; gap: 4px; overflow: hidden; }
  .col-header-blank { height: 56px; }
  .teacher-col-avatar { width: 26px; height: 26px; font-size: 11px; border-radius: 6px; }
  .teacher-col-name { font-size: 11px; }
  .teacher-col-room { font-size: 9px; }
  .course-block { font-size: 10px; padding: 4px 4px; border-radius: 6px; }
  .cb-student { font-size: 12px; }
  .cb-detail, .cb-type { font-size: 8px; margin-top: 1px; }
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
  .week-nav {
    flex-wrap: wrap;
    gap: 6px;
  }
  .day-tabs-bar {
    padding: 8px 8px 0;
    gap: 1px;
  }
  .day-tab {
    min-width: 56px;
    padding: 8px 8px 6px;
    font-size: 11px;
  }
  .day-tab-name { font-size: 11px; }
  .day-tab-date { font-size: 9px; }
  .teacher-col { min-width: 80px; }
  .week-view { overflow-x: auto; }
  .teacher-grid-wrapper { overflow-x: auto; }
  .teacher-grid { min-width: max-content; }
  .teacher-col-header { padding: 6px 6px; gap: 4px; height: 56px; }
  .col-header-blank { height: 56px; }
  .teacher-col-avatar { width: 26px; height: 26px; font-size: 12px; }
  .teacher-col-name { font-size: 11px; }
  .teacher-col-room { font-size: 9px; }
  .time-col { min-width: 40px; width: 40px; }
  .time-label { font-size: 10px; padding: 4px 2px 0 0; }
  .course-block {
    font-size: 10px;
    padding: 3px 4px;
    border-radius: 6px;
  }
  .cb-student { font-size: 12px; }
  .cb-detail, .cb-type { font-size: 8px; }
  .modal {
    width: 100% !important;
    max-width: 100vw !important;
    max-height: 100vh !important;
    max-height: 100dvh !important;
    border-radius: 0 !important;
  }
  .modal-overlay { padding: 0; }
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
  .week-nav { flex-wrap: wrap; justify-content: center; gap: 4px; }
  .week-nav button { padding: 5px 10px; font-size: 11px; }
  .toolbar-filters {
    width: 100%;
  }
  .filter-select, .filter-input {
    flex: 1;
    min-width: 0;
    font-size: 13px;
  }
  .day-tabs-bar { padding: 6px 4px 0; }
  .day-tab { min-width: 44px; padding: 6px 4px 4px; }
  .day-tab-name { font-size: 10px; }
  .day-tab-date { font-size: 8px; }
  .day-tab-badge { min-width: 14px; height: 14px; font-size: 8px; }
  .teacher-col { min-width: 0; }
  .teacher-col-header { height: 48px; padding: 4px; }
  .teacher-col-avatar { width: 22px; height: 22px; font-size: 10px; border-radius: 6px; }
  .teacher-col-name { font-size: 10px; }
  .teacher-col-room { font-size: 8px; }
  .time-col { min-width: 36px; width: 36px; }
  .time-label { font-size: 9px; padding: 2px 1px 0 0; }
  .col-header-blank { height: 48px; }
  .course-block { font-size: 9px; padding: 2px 3px; border-radius: 4px; }
  .cb-student { font-size: 11px; line-height: 1.15; }
  .cb-detail, .cb-type { font-size: 7px; }
  .teacher-card { padding: 10px; }
  .teacher-card h3 { font-size: 14px; }
  .modal {
    width: 100% !important;
    max-width: 100vw !important;
    border-radius: 16px 16px 0 0 !important;
  }
  .modal-form-grid { grid-template-columns: 1fr !important; }
  .cal-day-chip { width: 32px; height: 32px; font-size: 12px; }
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
/* 當時段有容量徽章時，讓第一張課程卡的學生姓名向右讓位，避免遮擋 */
.slot:has(.capacity-badge) .course-block:first-of-type .cb-student {
  padding-left: 20px;
}
.slot:has(.capacity-badge-compact) .course-block:first-of-type .cb-student {
  padding-left: 12px;
}
/* 緊湊模式的角標（到班/漏點/請假/未填評量）：字更小、邊距更緊，讓 split 卡片有更多姓名空間 */
.teacher-grid.teacher-grid-compact .rc-tag {
  font-size: 8px;
  padding: 0 2px;
  right: 2px;
}
/* 修正：第二個角標（rc-tag-second）須維持在底部，避免特異性衝突讓 top/bottom 同時生效而縱向撐開 */
.teacher-grid.teacher-grid-compact .rc-tag.rc-tag-second {
  top: auto;
  bottom: 2px;
}
/* 當課程卡有「到班 / 漏點 / 請假 / 未填評量」角標時，讓學生姓名留出右側空間，避免被遮擋 */
.course-block:has(.rc-tag) .cb-student {
  padding-right: 18px;
}
.teacher-grid.teacher-grid-compact .course-block:has(.rc-tag) .cb-student {
  padding-right: 10px;
}
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
