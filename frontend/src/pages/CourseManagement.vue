<template>
  <div class="course-page">
    <!-- Top Bar -->
    <div class="card course-header-card" data-guide="course-mgmt-header">
      <div class="header-actions">
        <div class="page-title-block">
          <h2 class="page-title">課程管理</h2>
          <p class="ref-hint">管理所有學生的課程安排，快速新增課程</p>
          <div class="meta-pills">
            <span class="meta-pill">{{ groupedCourses.length }} 位學生</span>
            <span class="meta-pill">{{ courses.length }} 筆課程</span>
          </div>
        </div>
        <div class="header-buttons">
          <button class="btn-soft" @click="expandAllGroups">全部展開</button>
          <button class="btn-soft" @click="collapseAllGroups">全部收合</button>
          <button class="btn-soft" @click="showBulkLeaveModal = true">
            <span class="btn-icon">🏖️</span> 連假批次請假
          </button>
          <button class="btn-accent" @click="openBackfillModal">
            <span class="btn-icon">📥</span> 新增課程
          </button>
        </div>
      </div>

      <!-- Filters -->
      <div class="filter-bar grid" data-guide="course-mgmt-filters">
        <div class="filter-field">
          <label>搜尋學生</label>
          <input v-model="filters.name" placeholder="輸入姓名..." @input="debouncedLoad" />
        </div>
        <div class="filter-field">
          <label>上課類型</label>
          <select v-model="filters.class_type" @change="loadCourses">
            <option value="">全部</option>
            <option value="one_on_one">一對一</option>
            <option value="one_on_two">一對二</option>
            <option value="one_on_three">一對三</option>
            <option value="tutoring">輔導</option>
          </select>
        </div>
        <div class="filter-field">
          <label>老師</label>
          <select v-model="filters.teacher_id" @change="loadCourses">
            <option value="">全部</option>
            <option v-for="t in teachers" :key="t.id" :value="t.id">{{ t.username }}</option>
          </select>
        </div>
        <div class="filter-field">
          <label>課程狀態</label>
          <select v-model="filters.course_status" @change="loadCourses">
            <option value="">全部</option>
            <option value="active">進行中</option>
            <option value="inactive">已暫停</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-row">
      <div class="summary-card summary-total">
        <span class="summary-label">學生課程數</span>
        <span class="summary-value">{{ courses.length }}</span>
      </div>
      <div class="summary-card">
        <span class="summary-label">一對一</span>
        <span class="summary-value">{{ coursesByType.one_on_one }}</span>
      </div>
      <div class="summary-card">
        <span class="summary-label">一對二</span>
        <span class="summary-value">{{ coursesByType.one_on_two }}</span>
      </div>
      <div class="summary-card">
        <span class="summary-label">輔導</span>
        <span class="summary-value">{{ coursesByType.tutoring }}</span>
      </div>
    </div>

    <!-- Subject Stats -->
    <div v-if="coursesBySubject.length" class="card subject-stats-card">
      <div class="subject-stats-header">
        <span class="subject-stats-title">科目數統計</span>
        <span class="subject-stats-total">共 {{ coursesBySubject.length }} 科</span>
      </div>
      <div class="subject-stats-list">
        <span
          v-for="s in coursesBySubject"
          :key="s.subject"
          class="subject-stat-chip"
        >
          {{ s.label }}：{{ s.count }}
        </span>
      </div>
    </div>

    <!-- Course Table -->
    <div class="card table-card" data-guide="course-mgmt-table">
      <div v-if="groupedCourses.length" class="grouped-course-list">
        <section
          v-for="group in groupedCourses"
          :key="group.key"
          class="student-group-card"
        >
          <button class="student-group-header" @click="toggleStudentGroup(group.key)">
            <span class="student-group-left">
              <span class="expand-indicator">{{ expandedStudentGroups.has(group.key) ? '▼' : '▶' }}</span>
              <span class="cell-student">{{ group.student_name }}</span>
            </span>
            <span class="student-group-meta">{{ group.courses.length }} 筆課程</span>
          </button>
          <div v-if="expandedStudentGroups.has(group.key)" class="table-wrap group-table-wrap">
            <table class="course-table">
              <thead>
                <tr>
                  <th>科目</th>
                  <th>老師</th>
                  <th>類型</th>
                  <th>每堂費用</th>
                  <th>總費用</th>
                  <th>排課時段</th>
                  <th>地點</th>
                  <th>繳費方式</th>
                  <th>繳費狀態</th>
                  <th>剩餘堂數</th>
                  <th>上課日期</th>
                  <th class="col-actions">操作</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="c in group.courses" :key="c.id">
                  <tr :class="['course-row', { 'course-paused': c.status === 'inactive' }]">
                    <td>
                      <span class="tag subject-tag">{{ getSubjectLabel(c.subject) }}</span>
                      <span v-if="c.status === 'inactive'" class="tag tag-paused">已暫停</span>
                    </td>
                    <td>{{ c.teacher_name || '待指派' }}</td>
                    <td>
                      <span class="status-tag" :class="c.class_type">{{ classTypeLabel(c.class_type) }}</span>
                    </td>
                    <td class="cell-fee">${{ sessionPrice(c) }}</td>
                    <td class="cell-total">${{ totalPrice(c) }}</td>
                    <td class="cell-schedule">
                      <span v-if="Array.isArray(c.day_time_slots) && c.day_time_slots.length > 0">
                        {{ formatDayTimeSlots(c) }}
                      </span>
                      <span v-else-if="(c.days_of_week || []).length > 0">
                        {{ (c.days_of_week || []).map(d => dayLabel(d)).join('、') }} {{ c.start_time }}~{{ c.end_time }}
                      </span>
                      <span v-else-if="c.day_of_week">
                        {{ dayLabel(c.day_of_week) }} {{ c.start_time }}~{{ c.end_time }}
                      </span>
                      <span v-else class="hint">未排定</span>
                    </td>
                    <td>
                      <span v-if="c.branch_name || c.room_name">
                        {{ [c.branch_name, c.room_name].filter(Boolean).join(' － ') }}
                      </span>
                      <span v-else class="hint">—</span>
                    </td>
                    <td>
                      <span v-if="c.payment_type === 'session'">堂數制</span>
                      <span v-else>月結<span v-if="c.settlement_day">（每月{{ c.settlement_day }}號）</span></span>
                    </td>
                    <td>
                      <button
                        :class="['small', 'btn-status', paymentStatusButtonClass(c)]"
                        title="點擊後會跳出確認視窗"
                        @click="togglePaymentStatus(c)"
                      >{{ paymentStatusButtonLabel(c) }}</button>
                    </td>
                    <td :class="{ 'cell-remaining': true, 'low': isSessionMode(c) && Number(displayRemainingSessions(c) ?? 0) <= 2 }">
                      {{ displayRemainingSessions(c) ?? '—' }}
                    </td>
                    <td>
                      <button class="small ghost btn-toggle" @click="toggleDates(c)">
                        {{ expandedDates.has(c.id) ? '收起' : '查看' }}
                      </button>
                    </td>
                    <td class="cell-actions">
                      <div class="action-btns action-btns-compact">
                        <button class="small ghost action-main" @click="editCourse(c)">編輯</button>
                        <button class="small ghost" @click="openPurchaseModal(c)">加購</button>
                        <button v-if="c.status !== 'inactive'" class="small ghost" @click="toggleCoursePause(c)" title="暫停此課程，未來堂次將取消">暫停</button>
                        <button v-else class="small primary" @click="toggleCoursePause(c)" title="恢復此課程">恢復</button>
                        <button class="small ghost" @click="duplicateCourseForTeacher(c)" title="複製此課程並換另一位老師">換師複製</button>
                        <button class="small danger" @click="deleteCourse(c)">刪除</button>
                      </div>
                    </td>
                  </tr>
                  <tr v-if="expandedDates.has(c.id)" class="dates-row">
                    <td colspan="12">
                      <div class="dates-panel">
                        <strong>上課日期（實際 {{ countNonLeaveSessions(c) }} 堂，共 {{ displaySessions(c).length }} 筆）：</strong>
                        <span v-if="displaySessions(c).length === 0" class="hint">無法計算（請確認排課設定）</span>
                        <span
                          v-for="d in displaySessions(c)"
                          :key="`${c.id}-${d}`"
                          :class="['date-chip', 'date-chip-clickable', getSessionStateClass(c, d)]"
                          :title="getSessionTooltip(c, d)"
                          @click="openSessionEdit(c, d)"
                        >
                          <template v-if="getSessionNumber(c, d)">第{{ getSessionNumber(c, d) }}堂 </template>{{ d }}<template v-if="getSessionStateLabel(c, d)">（{{ getSessionStateLabel(c, d) }}）</template>
                        </span>
                      </div>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </section>
      </div>
      <div v-else class="empty-state">
        <div class="empty-icon">📋</div>
        <p class="empty-title">目前尚無課程資料</p>
        <p class="empty-desc">請在「學生管理」為學生建立課程，或使用上方「新增課程」快速建立課程。</p>
      </div>
    </div>

    <UniversalClassScheduler
      v-if="showBackfillModal"
      title="新增課程（統一排課介面）"
      submit-label="建立課程"
      :branch-id="props.branchId"
      :students="schedulerStudents"
      :teachers="schedulerTeachers"
      :rooms="rooms"
      mode="backfill"
      @cancel="showBackfillModal = false"
      @success="handleUniversalBackfillSuccess"
    />

    <!-- Edit Course Modal -->
    <div v-if="showEditModal" class="modal-overlay" @click.self="showEditModal = false">
      <div class="modal course-modal">
        <h3 class="modal-title">編輯課程</h3>
        <div class="form-section">
          <CourseEditForm
            v-model="editForm"
            :teachers="teachers"
            :rooms="rooms"
            :subjects="SUBJECTS"
            :day-options="DAY_OPTIONS"
            :time-options="TIME_OPTIONS_30"
            :settlement-day-options="settlementDayOptions"
            :show-remaining="true"
          />
        </div>
        <div class="actions">
          <button class="ghost" @click="showEditModal = false">取消</button>
          <button class="primary" @click="submitEdit">儲存</button>
        </div>
      </div>
    </div>

    <!-- Purchase Sessions Modal -->
    <div v-if="showPurchaseModal" class="modal-overlay" @click.self="showPurchaseModal = false">
      <div class="modal course-modal" style="max-width: 420px;">
        <h3 class="modal-title">加購堂數</h3>
        <p class="modal-desc">
          {{ purchaseForm.student_name }} — {{ getSubjectLabel(purchaseForm.subject) }}
        </p>
        <div class="form-group">
          <label>加購堂數</label>
          <input v-model.number="purchaseForm.sessions" type="number" min="1" step="1" />
        </div>
        <div class="form-group">
          <label>新批次開始日期</label>
          <input v-model="purchaseForm.start_date" type="date" />
        </div>
        <div class="actions">
          <button class="ghost" @click="showPurchaseModal = false">取消</button>
          <button class="primary" @click="submitPurchaseSessions">確認加購</button>
        </div>
      </div>
    </div>

    <!-- Quick Add Session Modal -->
    <div v-if="showQuickAddSessionModal" class="modal-overlay" @click.self="showQuickAddSessionModal = false">
      <div class="modal course-modal" style="max-width: 440px;">
        <h3 class="modal-title">加課／補登（不增加總堂數）</h3>
        <p class="modal-desc">
          {{ quickAddSessionForm.student_name }} — {{ getSubjectLabel(quickAddSessionForm.subject) }}
        </p>
        <div class="form-group">
          <label>上課日期</label>
          <input v-model="quickAddSessionForm.session_date" type="date" />
        </div>
        <div class="form-group">
          <label>開始時間</label>
          <select v-model="quickAddSessionForm.start_time">
            <option v-for="t in TIME_OPTIONS_30" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>
        <div class="form-group">
          <label>時長（分鐘）</label>
          <input v-model.number="quickAddSessionForm.duration_minutes" type="number" min="30" step="30" />
        </div>
        <div class="form-group">
          <label>備註（選填）</label>
          <input v-model.trim="quickAddSessionForm.note" type="text" placeholder="例如：臨時加課" />
        </div>
        <div class="form-group">
          <label class="hint">
            <input v-model="quickAddSessionForm.auto_approve" type="checkbox" />
            若該堂已下課，直接補登並扣堂
          </label>
        </div>
        <div class="actions">
          <button class="ghost" @click="showQuickAddSessionModal = false">取消</button>
          <button class="primary" @click="submitQuickAddSession">確認送出</button>
        </div>
      </div>
    </div>

    <!-- Leave Modal (請假) -->
    <div v-if="showLeaveModal" class="modal-overlay" @click.self="showLeaveModal = false">
      <div class="modal course-modal" style="max-width: 420px;">
        <h3 class="modal-title">請假登記</h3>
        <p class="modal-desc">請假不扣堂數、不需填寫評量表</p>
        <div v-if="leaveSessionOptions.length === 0" class="form-group">
          <p class="hint">此課程無可請假堂次（請確認開課日與排課設定）。</p>
        </div>
        <template v-else>
          <div class="form-group">
            <label>選擇要請假的堂次</label>
            <select v-model="leaveForm.schedule_date">
              <option value="">請選擇</option>
              <option v-for="(opt, i) in leaveSessionOptions" :key="opt.date" :value="opt.date">
                第{{ opt.index }}堂 {{ opt.date }}{{ opt.isRetro ? ' ⚠️ 已上課' : '' }}
              </option>
            </select>
          </div>
          <div v-if="isSelectedRetroLeave" class="retro-leave-warning" style="margin: 8px 0; padding: 10px 14px; border-radius: 8px; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; font-size: 0.92em;">
            <strong>補請假：</strong>此堂已點名/上課，確認後將沖回堂數並作廢該堂出缺勤與評量記錄。
            <div class="form-group" style="margin-top: 8px;">
              <label>補請假原因（選填）</label>
              <input v-model="leaveForm.reason" type="text" placeholder="例：家長臨時通知" style="width: 100%;" />
            </div>
          </div>
          <template v-if="leaveForm.schedule_date">
            <div class="form-group">
              <label>學生</label>
              <p style="font-weight: 600;">{{ leaveForm.student_name }}</p>
            </div>
            <div class="form-group">
              <label>科目</label>
              <p>{{ getSubjectLabel(leaveForm.subject) }}</p>
            </div>
            <div class="form-group">
              <label>原時段</label>
              <p>{{ dayLabel(leaveForm.day_of_week) }} {{ leaveForm.start_time }}~{{ leaveForm.end_time }}</p>
            </div>
          </template>
          <div class="actions">
            <button class="ghost" @click="showLeaveModal = false">取消</button>
            <button class="primary" @click="submitLeave" :disabled="!leaveForm.schedule_date">
              {{ isSelectedRetroLeave ? '確認補請假（沖回堂數）' : '確認請假' }}
            </button>
          </div>
        </template>
      </div>
    </div>

    <!-- Bulk Holiday Leave Modal (連假批次請假) -->
    <div v-if="showBulkLeaveModal" class="modal-overlay" @click.self="showBulkLeaveModal = false">
      <div class="modal course-modal" style="max-width: 460px;">
        <h3 class="modal-title">連假批次請假</h3>
        <p class="modal-desc">一次將該分校指定日期區間內所有課程標記請假，並自動順延補堂</p>
        <div class="form-group">
          <label>開始日期</label>
          <input type="date" v-model="bulkLeaveForm.start_date" />
        </div>
        <div class="form-group">
          <label>結束日期</label>
          <input type="date" v-model="bulkLeaveForm.end_date" />
        </div>
        <p v-if="bulkLeaveForm.start_date && bulkLeaveForm.end_date" class="hint" style="margin-top:6px;">
          將對「{{ bulkLeaveForm.start_date }}」至「{{ bulkLeaveForm.end_date }}」區間所有可請假堂次執行批次請假。
        </p>
        <div v-if="bulkLeaveResult" class="bulk-leave-result" style="margin-top:12px;padding:10px;border-radius:8px;background:#f0fdf4;border:1px solid #bbf7d0;">
          <p style="font-weight:600;margin-bottom:4px;">{{ bulkLeaveResult.message }}</p>
          <p v-if="bulkLeaveResult.skipped && bulkLeaveResult.skipped.length">
            略過原因：
            <span v-for="(s, i) in bulkLeaveResult.skipped" :key="i" style="display:block;font-size:12px;color:#6b7280;">
              課程 #{{ s.course_id }} {{ s.session_date }}：{{ s.reason }}
            </span>
          </p>
        </div>
        <div class="actions">
          <button class="ghost" @click="showBulkLeaveModal = false; bulkLeaveResult = null;">關閉</button>
          <button
            class="primary"
            @click="submitBulkLeave"
            :disabled="!bulkLeaveForm.start_date || !bulkLeaveForm.end_date || bulkLeaveSubmitting"
          >{{ bulkLeaveSubmitting ? '處理中…' : '確認批次請假' }}</button>
        </div>
      </div>
    </div>

    <!-- Reschedule Modal (調課) -->
    <div v-if="showRescheduleModal" class="modal-overlay" @click.self="showRescheduleModal = false">
      <div class="modal course-modal" style="max-width: 420px;">
        <h3 class="modal-title">調課</h3>
        <p class="modal-desc">將原本的課程改到新的日期時間</p>
        <div v-if="rescheduleSessionOptions.length === 0" class="form-group">
          <p class="hint">此課程無可調課堂次（請確認開課日與排課設定）。</p>
        </div>
        <template v-else>
          <div class="form-group">
            <label>選擇要調動的堂次</label>
            <select v-model="rescheduleForm.original_date">
              <option value="">請選擇</option>
              <option v-for="(opt, i) in rescheduleSessionOptions" :key="opt.date" :value="opt.date">
                第{{ opt.index }}堂 {{ opt.date }} {{ rescheduleCourse ? dayLabel(dayOfWeekFromDate(opt.date)) : '' }}
              </option>
            </select>
          </div>
          <template v-if="rescheduleForm.original_date">
            <div class="form-group">
              <label>學生</label>
              <p style="font-weight: 600;">{{ rescheduleForm.student_name }}</p>
            </div>
            <div class="form-group">
              <label>科目</label>
              <p>{{ getSubjectLabel(rescheduleForm.subject) }}</p>
            </div>
            <div class="form-group">
              <label>原時段</label>
              <p>{{ rescheduleForm.original_date }} {{ rescheduleForm.original_start }}~{{ rescheduleForm.original_end }}</p>
            </div>
            <hr style="border: none; border-top: 1px solid var(--border); margin: 12px 0;" />
            <div style="margin-bottom: 12px;">
              <button class="small ghost btn-makeup-query" @click="fetchMakeupSlots" :disabled="makeupLoading">
                {{ makeupLoading ? '查詢中…' : '查詢可補課時段' }}
              </button>
            </div>
            <div class="form-group">
              <label>新日期</label>
              <input v-model="rescheduleForm.new_date" type="date" />
            </div>
            <div class="form-group">
              <label>新開始時間</label>
              <select v-model="rescheduleForm.new_start" @change="onRescheduleNewStartChange">
                <option v-for="t in TIME_OPTIONS_30" :key="t" :value="t">{{ t }}</option>
              </select>
            </div>
            <div class="form-group">
              <label>預計新結束時間</label>
              <p class="computed-end-time">{{ computeEndTime(rescheduleForm.new_start, rescheduleForm.duration_hours) || '—' }}</p>
            </div>
          </template>
          <div class="actions">
            <button class="ghost" @click="showRescheduleModal = false">取消</button>
            <button class="primary" @click="submitReschedule" :disabled="!rescheduleForm.new_date">確認調課</button>
          </div>
        </template>
      </div>
    </div>

    <!-- Makeup Slots Modal (補課空檔查詢) -->
    <div v-if="showMakeupSlotsModal" class="modal-overlay" @click.self="showMakeupSlotsModal = false">
      <div class="modal course-modal" style="max-width: 520px;">
        <h3 class="modal-title">老師可補課時段</h3>
        <p class="modal-desc">
          {{ rescheduleForm.student_name }} — {{ getSubjectLabel(rescheduleForm.subject) }}
          ｜老師空檔（未來 {{ makeupDateRange }} 天）
        </p>
        <div class="makeup-range-bar">
          <label>查詢範圍</label>
          <select v-model.number="makeupDateRange" @change="fetchMakeupSlots">
            <option :value="7">未來 7 天</option>
            <option :value="14">未來 14 天</option>
            <option :value="30">未來 30 天</option>
            <option :value="60">未來 60 天</option>
          </select>
        </div>
        <div v-if="makeupLoading" class="makeup-status">查詢中…</div>
        <div v-else-if="makeupSlotsGrouped.length === 0" class="makeup-status">
          查無可補課空檔，請嘗試放寬查詢範圍。
        </div>
        <div v-else class="makeup-slots-list">
          <div v-for="group in makeupSlotsGrouped" :key="group.date" class="makeup-date-group">
            <div class="makeup-date-header">{{ group.date }} {{ dayLabel(group.day_of_week) }}</div>
            <div v-for="slot in group.slots" :key="slot.start_time"
              class="makeup-slot-row" :class="{ 'slot-has-students': slot.currentStudentCount > 0 }">
              <div class="slot-info">
                <span class="slot-time">{{ slot.start_time }} ~ {{ slot.end_time }}</span>
                <span class="slot-capacity" :class="slot.currentStudentCount > 0 ? 'cap-partial' : 'cap-free'">
                  {{ slot.currentStudentCount }} / {{ slot.capacity }} 人
                </span>
                <span v-if="slot.existingStudents && slot.existingStudents.length" class="slot-students">
                  {{ slot.existingStudents.join('、') }}
                </span>
              </div>
              <button class="small primary" @click="selectMakeupSlot(slot)">選擇</button>
            </div>
          </div>
        </div>
        <div class="actions">
          <button class="ghost" @click="showMakeupSlotsModal = false">關閉</button>
        </div>
      </div>
    </div>

    <!-- Session Edit Modal (單堂課編輯) -->
    <div v-if="showSessionEditModal" class="modal-overlay" @click.self="closeSessionEdit">
      <div class="modal course-modal session-edit-modal" style="max-width: 520px;">
        <h3 class="modal-title">單堂課操作</h3>
        <p class="modal-desc">{{ sessionEditForm.student_name }} — {{ getSubjectLabel(sessionEditForm.subject) }}</p>

        <div class="session-edit-info">
          <div class="se-row"><span class="se-label">日期</span><span>{{ sessionEditForm.session_date }}</span></div>
          <div class="se-row"><span class="se-label">時段</span><span>{{ sessionEditForm.start_time || '—' }} ~ {{ sessionEditForm.end_time || '—' }}</span></div>
          <div class="se-row"><span class="se-label">老師</span><span>{{ sessionEditForm.teacher_name || '—' }}</span></div>
          <div class="se-row">
            <span class="se-label">目前狀態</span>
            <span :class="['se-status-badge', 'se-st-' + sessionEditForm.current_status]">{{ sessionStatusLabel(sessionEditForm.current_status) }}</span>
          </div>
          <div v-if="sessionEditForm.attendance_time" class="se-row"><span class="se-label">點名時間</span><span>{{ sessionEditForm.attendance_time }}</span></div>
          <div v-if="sessionEditForm.lr_status && sessionEditForm.lr_status !== 'missing'" class="se-row"><span class="se-label">評量</span><span>{{ sessionEditForm.lr_status }}</span></div>
        </div>

        <div v-if="sessionEditMode === 'menu'" class="session-edit-actions">
          <h4 class="se-section-title">操作</h4>
          <div class="se-action-grid se-action-grid-compact">
            <button v-if="canTransitionTo('scheduled')" class="se-action-btn se-btn-scheduled" @click="doStatusChange('scheduled')">改為未上</button>
            <button v-if="canTransitionTo('leave') || canTransitionTo('leave_adjusted')" class="se-action-btn se-btn-leave" @click="canTransitionTo('leave_adjusted') ? startRetroLeave() : doStatusChange('leave')">標記請假</button>
            <button class="se-action-btn se-btn-reschedule" @click="startSessionReschedule">調課</button>
          </div>
          <div v-if="secondaryStatusOptions.length" class="se-secondary-action">
            <label>其他狀態</label>
            <div class="se-secondary-row">
              <select v-model="secondaryStatusSelection">
                <option value="">請選擇</option>
                <option v-for="opt in secondaryStatusOptions" :key="opt" :value="opt">{{ sessionStatusLabel(opt) }}</option>
              </select>
              <button class="small ghost" :disabled="!secondaryStatusSelection" @click="applySecondaryStatus">套用</button>
            </div>
          </div>
        </div>

        <div v-if="sessionEditMode === 'retro-leave'" class="session-edit-retro">
          <h4 class="se-section-title">補請假確認</h4>
          <div class="retro-leave-warning" style="margin: 8px 0; padding: 10px 14px; border-radius: 8px; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; font-size: 0.92em;">
            <strong>此堂已上課/已點名</strong>，確認後將沖回堂數並作廢該堂出缺勤與評量記錄。
          </div>
          <div class="form-group">
            <label>補請假原因（選填）</label>
            <input v-model="sessionEditForm.reason" type="text" placeholder="例：家長臨時通知" style="width: 100%;" />
          </div>
          <div class="actions">
            <button class="ghost" @click="sessionEditMode = 'menu'">返回</button>
            <button class="primary" @click="doRetroLeave" :disabled="sessionEditSubmitting">確認補請假</button>
          </div>
        </div>

        <div v-if="sessionEditMode === 'reschedule'" class="session-edit-reschedule">
          <h4 class="se-section-title">調課 — 選擇新時段</h4>
          <div class="se-reschedule-grid">
            <div class="form-group">
              <label>新日期</label>
              <input v-model="sessionEditForm.new_date" type="date" :min="todayYmd" />
            </div>
            <div class="form-group">
              <label>新開始時間</label>
              <select v-model="sessionEditForm.new_start">
                <option v-for="t in TIME_OPTIONS_30" :key="t" :value="t">{{ t }}</option>
              </select>
            </div>
            <div class="form-group">
              <label>預計新結束</label>
              <p class="computed-end-time">{{ computeEndTime(sessionEditForm.new_start, sessionEditForm.duration_hours) || '—' }}</p>
            </div>
          </div>
          <div style="margin: 8px 0;">
            <button class="small ghost btn-makeup-query" @click="fetchMakeupSlotsForEdit" :disabled="makeupLoading">
              {{ makeupLoading ? '查詢中…' : '查詢老師可補課時段' }}
            </button>
          </div>
          <div class="actions">
            <button class="ghost" @click="sessionEditMode = 'menu'">返回</button>
            <button class="primary" @click="doSessionReschedule" :disabled="sessionEditSubmitting || !sessionEditForm.new_date">確認調課</button>
          </div>
        </div>

        <div v-if="sessionEditMode === 'menu'" class="actions" style="margin-top: 16px; justify-content: space-between;">
          <button class="ghost" @click="closeSessionEdit">關閉</button>
          <button class="small ghost" @click="addSessionFromModal">+ 新增堂次</button>
        </div>

        <div v-if="sessionEditSubmitting" class="se-loading">處理中…</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { supabase } from '../supabase';
import { SUBJECTS, getSubjectLabel as getSubjectText } from '../lib/constants';
import { fetchClassSessions, normalizeClassSessionsPayload } from '../lib/classSessionsApi';
import { getPerSessionFee, getCourseTotalFee } from '../lib/coursePricing';
import CourseEditForm from '../components/CourseEditForm.vue';
import UniversalClassScheduler from '../components/UniversalClassScheduler.vue';

const DAY_OPTIONS = [
  { value: 1, label: '一' }, { value: 2, label: '二' }, { value: 3, label: '三' },
  { value: 4, label: '四' }, { value: 5, label: '五' }, { value: 6, label: '六' },
  { value: 7, label: '日' },
];
// 時間以半小時為單位：07:00 ~ 22:30
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
/** 由 YYYY-MM-DD 取得星期幾，1=週一 … 7=週日 */
function dayOfWeekFromDate(ymd) {
  if (!ymd) return 1;
  const d = new Date(ymd + 'T12:00:00');
  const n = d.getDay();
  return n === 0 ? 7 : n;
}
function toYmd(date) {
  if (!date) return '';
  return new Date(date.getTime() - (date.getTimezoneOffset() * 60000)).toISOString().slice(0, 10);
}
function addDays(ymd, days) {
  const d = new Date(ymd + 'T12:00:00');
  d.setDate(d.getDate() + days);
  return toYmd(d);
}

const props = defineProps({ branchId: [String, Number], initialTeacherId: [String, Number] });
const emit = defineEmits(['clear-initial-teacher']);

const courses = ref([]);
const allStudents = ref([]);
const teachers = ref([]);
const schedulerStudents = computed(() => (
  (allStudents.value || []).map((s) => ({
    id: Number(s?.id ?? 0),
    name: s?.name || `#${s?.id ?? ''}`,
  })).filter((s) => Number.isFinite(s.id) && s.id > 0)
));
const schedulerTeachers = computed(() => (
  (teachers.value || []).map((t) => ({
    id: Number(t?.id ?? 0),
    name: t?.username || t?.name || t?.Name || `#${t?.id ?? ''}`,
  })).filter((t) => Number.isFinite(t.id) && t.id > 0)
));
const filters = ref({ name: '', class_type: '', teacher_id: '', course_status: '' });
const completedSessionDatesByCourse = ref({});
const classSessionsByCourse = ref({});
const effectiveSessionDatesByCourse = ref({});
const expandedStudentGroups = ref(new Set());

// Bulk Holiday Leave
const showBulkLeaveModal = ref(false);
const bulkLeaveSubmitting = ref(false);
const bulkLeaveResult = ref(null);
const bulkLeaveForm = ref({ start_date: '', end_date: '' });

// Backfill (aligned with edit form fields)
const showBackfillModal = ref(false);
const backfillForm = ref({
  student_id: '', subject: 'Math', teacher_id: '', class_type: 'one_on_one',
  rate_per_30min: 500, duration_hours: 2,
  payment_type: 'session', sessions_purchased: 8, sessions_used: 0,
  settlement_day: null, monthly_sessions: null,
  days_of_week: [],
  start_time: '16:00', end_time: '18:00',
  room_id: null, memo: ''
});
const backfillSelectedDates = ref([]);
const backfillCalendarMonth = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
const backfillCsvInputRef = ref(null);
const backfillImporting = ref(false);
const todayYmd = computed(() => toYmd(new Date()));
const backfillRemainingSessions = computed(() => {
  const purchased = Math.max(0, Number(backfillForm.value.sessions_purchased) || 0);
  return Math.max(0, purchased - backfillSelectedDates.value.length);
});
const backfillCalendarTitle = computed(() =>
  new Intl.DateTimeFormat('zh-TW', { year: 'numeric', month: 'long' }).format(backfillCalendarMonth.value)
);
const backfillCalendarCells = computed(() => {
  const monthStart = new Date(backfillCalendarMonth.value.getFullYear(), backfillCalendarMonth.value.getMonth(), 1);
  const firstWeekday = monthStart.getDay() === 0 ? 7 : monthStart.getDay();
  const gridStart = new Date(monthStart);
  gridStart.setDate(monthStart.getDate() - (firstWeekday - 1));
  const selectedSet = new Set(backfillSelectedDates.value);
  const cells = [];
  for (let i = 0; i < 42; i++) {
    const d = new Date(gridStart);
    d.setDate(gridStart.getDate() + i);
    const ymd = toYmd(d);
    const inCurrentMonth = d.getMonth() === monthStart.getMonth();
    const disabled = ymd > todayYmd.value;
    cells.push({
      ymd,
      day: d.getDate(),
      inCurrentMonth,
      disabled,
      selected: selectedSet.has(ymd),
    });
  }
  return cells;
});
function resetBackfillDatePicker() {
  backfillSelectedDates.value = [];
  backfillCalendarMonth.value = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
}
function shiftBackfillCalendarMonth(delta) {
  const base = backfillCalendarMonth.value;
  backfillCalendarMonth.value = new Date(base.getFullYear(), base.getMonth() + delta, 1);
}
function toggleBackfillDate(ymd) {
  if (!ymd || ymd > todayYmd.value) return;
  const set = new Set(backfillSelectedDates.value);
  if (set.has(ymd)) set.delete(ymd);
  else set.add(ymd);
  backfillSelectedDates.value = [...set].sort();
}
function clearBackfillDates() {
  backfillSelectedDates.value = [];
}
function openBackfillCsvImporter() {
  if (!props.branchId) {
    alert('請先選擇分校，再匯入補登課程');
    return;
  }
  if (backfillCsvInputRef.value) {
    backfillCsvInputRef.value.click();
  }
}
function parseCsvLine(line) {
  const out = [];
  let cur = '';
  let inQuotes = false;
  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    if (ch === '"') {
      if (inQuotes && line[i + 1] === '"') {
        cur += '"';
        i++;
      } else {
        inQuotes = !inQuotes;
      }
      continue;
    }
    if (ch === ',' && !inQuotes) {
      out.push(cur);
      cur = '';
      continue;
    }
    cur += ch;
  }
  out.push(cur);
  return out;
}
function normalizeStudentName(name) {
  return String(name || '').replace(/\u3000/g, ' ').replace(/\s+/g, '').trim();
}
function normalizeTeacherName(name) {
  return normalizeStudentName(name).replace(/老師/g, '').replace(/teacher/ig, '');
}
function mapSubjectFromCsv(text) {
  const key = normalizeStudentName(text).toLowerCase();
  if (!key) return null;
  if (key.includes('數學') || key === 'math') return 'Math';
  if (key.includes('英文') || key === 'english') return 'English';
  if (key.includes('國文') || key.includes('中文') || key === 'chinese') return 'Chinese';
  if (key.includes('理化') || key.includes('自然') || key === 'science') return 'Science';
  if (key.includes('化學') || key === 'chemistry') return 'Chemistry';
  if (key.includes('物理') || key === 'physics') return 'Physics';
  if (key.includes('生物') || key === 'biology') return 'Biology';
  if (key.includes('社會') || key === 'social') return 'Social';
  return null;
}
function parseStudentCell(cell) {
  const raw = String(cell || '').replace(/\u3000/g, ' ').trim();
  if (!raw) return null;
  const typeMatch = raw.match(/1\s*對\s*([123])/);
  let classType = 'one_on_one';
  if (typeMatch?.[1] === '2') classType = 'one_on_two';
  else if (typeMatch?.[1] === '3') classType = 'one_on_three';

  let studentName = raw
    .replace(/1\s*對\s*[123]/g, '')
    .replace(/\d+(\.\d+)?\s*[Hh]/g, '')
    .replace(/[()（）]/g, ' ')
    .trim();
  studentName = normalizeStudentName(studentName);
  if (!studentName) return null;
  return { student_name: studentName, class_type: classType };
}
function parseBackfillCourseCsv(text) {
  const normalized = String(text || '').replace(/^\uFEFF/, '');
  const lines = normalized.split(/\r?\n/);
  const entries = [];
  for (const line of lines) {
    if (!line || !line.trim()) continue;
    const cols = parseCsvLine(line).map((c) => String(c || '').trim());
    const subjectRaw = cols[0] || '';
    const teacherRaw = cols[1] || '';
    if (!subjectRaw && !teacherRaw) continue;
    if (subjectRaw === '科目' || subjectRaw.includes('備註')) continue;
    const subject = mapSubjectFromCsv(subjectRaw);
    for (let i = 2; i < cols.length; i++) {
      const parsed = parseStudentCell(cols[i]);
      if (!parsed) continue;
      entries.push({
        subject,
        teacher_name: teacherRaw,
        student_name: parsed.student_name,
        class_type: parsed.class_type,
      });
    }
  }
  return entries;
}
async function importBackfillCoursesFromCsv(event) {
  const file = event?.target?.files?.[0];
  if (!file) return;
  if (!props.branchId) {
    alert('請先選擇分校，再匯入補登課程');
    event.target.value = '';
    return;
  }

  backfillImporting.value = true;
  try {
    await Promise.all([loadStudents(), loadTeachers()]);

    const text = await file.text();
    const parsedEntries = parseBackfillCourseCsv(text);
    if (!parsedEntries.length) {
      alert('檔案沒有可匯入的課程資料，請確認 CSV 格式');
      return;
    }

    const studentMap = new Map();
    (allStudents.value || []).forEach((s) => {
      studentMap.set(normalizeStudentName(s.name), s);
    });
    const teacherMap = new Map();
    (teachers.value || []).forEach((t) => {
      teacherMap.set(normalizeTeacherName(t.username), t);
    });

    const token = (await supabase.auth.getSession())?.data?.session?.access_token;
    if (!token) {
      alert('請重新登入後再試');
      return;
    }

    const sessionsPurchased = Math.max(0, Number(backfillForm.value.sessions_purchased) || 0);
    if (sessionsPurchased <= 0) {
      alert('請先設定「已購買堂數」大於 0，再進行 CSV 匯入');
      return;
    }
    const selectedDays = (backfillForm.value.days_of_week || []).map(Number).filter((d) => d >= 1 && d <= 7);
    const startTime = normalizeTo30Min(backfillForm.value.start_time || '16:00');
    const durationHours = Number(backfillForm.value.duration_hours) || 2;
    const endTime = computeEndTime(startTime, durationHours);
    const selectedLegacyDates = [...new Set((backfillSelectedDates.value || []).filter(Boolean))].sort();
    const firstClassDate = selectedLegacyDates[0] || null;

    let created = 0;
    const skipped = [];
    const failed = [];

    const toImport = parsedEntries.slice(0, 500);
    for (const row of toImport) {
      if (!row.subject) {
        skipped.push(`科目無法辨識：${row.student_name}`);
        continue;
      }
      const student = studentMap.get(normalizeStudentName(row.student_name));
      if (!student) {
        skipped.push(`找不到學生：${row.student_name}`);
        continue;
      }
      const teacher = teacherMap.get(normalizeTeacherName(row.teacher_name));
      if (!teacher) {
        skipped.push(`找不到老師（分校內）：${row.teacher_name}`);
        continue;
      }

      const payload = {
        student_id: student.id,
        subject: row.subject,
        teacher_id: teacher.id,
        class_type: row.class_type || backfillForm.value.class_type || 'one_on_one',
        rate_per_30min: Number(backfillForm.value.rate_per_30min) || 500,
        duration_hours: durationHours,
        payment_type: 'session',
        sessions_purchased: sessionsPurchased,
        remaining_sessions: sessionsPurchased,
        settlement_day: null,
        monthly_sessions: null,
        first_class_date: firstClassDate,
        days_of_week: selectedDays,
        start_time: startTime,
        end_time: endTime,
        room_id: backfillForm.value.room_id || null,
        Memo: [backfillForm.value.memo, `CSV匯入:${file.name}`].filter(Boolean).join(' | '),
        skip_auto_sessions: true,
      };

      const res = await fetch('/api/v1/student-classes', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify(payload),
      });
      if (res.ok) {
        created += 1;
      } else {
        const err = await res.json().catch(() => ({}));
        failed.push(`${row.student_name}/${row.teacher_name}: ${err?.message || '建立失敗'}`);
      }
    }

    const summary = [
      `CSV 匯入完成：${file.name}`,
      `成功建立課程：${created} 筆`,
      `略過：${skipped.length} 筆`,
      `失敗：${failed.length} 筆`,
    ];
    if (skipped.length) {
      summary.push('', `略過（前 10 筆）：`, ...skipped.slice(0, 10).map((x) => `- ${x}`));
    }
    if (failed.length) {
      summary.push('', `失敗（前 10 筆）：`, ...failed.slice(0, 10).map((x) => `- ${x}`));
    }
    alert(summary.join('\n'));
    await loadCourses();
  } catch (e) {
    alert('CSV 匯入補登失敗：' + (e?.message || '請稍後再試'));
  } finally {
    backfillImporting.value = false;
    if (event?.target) event.target.value = '';
  }
}

function openBackfillModal() {
  resetBackfillDatePicker();
  showBackfillModal.value = true;
  loadRoomsForBranch();
}

async function handleUniversalBackfillSuccess() {
  showBackfillModal.value = false;
  await loadCourses();
}

// Edit
const showEditModal = ref(false);
const editingId = ref(null);
const editingCourseFromLaravel = ref(false);
const editForm = ref({});
const rooms = ref([]);
const settlementDayOptions = Array.from({ length: 31 }, (_, i) => i + 1);
const showPurchaseModal = ref(false);
const purchaseCourse = ref(null);
const purchaseForm = ref({
  sessions: 8,
  start_date: '',
  student_name: '',
  subject: 'Math',
});
const showQuickAddSessionModal = ref(false);
const quickAddSessionCourse = ref(null);
const quickAddSessionForm = ref({
  session_date: '',
  start_time: '16:00',
  duration_minutes: 120,
  note: '',
  auto_approve: true,
  student_name: '',
  subject: 'Math',
});

// Session dates expansion
const expandedDates = ref(new Set());
const toggleDates = (c) => {
  const s = new Set(expandedDates.value);
  if (s.has(c.id)) s.delete(c.id);
  else {
    s.add(c.id);
    ensureCompletedSessionDatesLoaded(c).catch(() => {});
  }
  expandedDates.value = s;
};
const LEAVE_STATUSES = new Set(['leave', 'leave_adjusted', 'excused']);
const sessions = (c) => {
  const cid = String(c?.id ?? '');
  const rows = classSessionsByCourse.value[cid];
  if (Array.isArray(rows) && rows.length > 0) {
    const dates = rows
      .filter((row) => {
        const status = String(row?.status || '').toLowerCase();
        return status !== 'cancelled';
      })
      .map((row) => String(row?.session_date || '').slice(0, 10))
      .filter(Boolean);
    return [...new Set(dates)].sort();
  }
  const effective = effectiveSessionDatesByCourse.value[cid];
  if (Array.isArray(effective)) {
    return [...new Set(effective.map((d) => String(d || '').slice(0, 10)).filter(Boolean))].sort();
  }
  return [];
};
const getSessionNumber = (course, dateYmd) => {
  const allDates = sessions(course);
  let num = 0;
  for (const d of allDates) {
    const state = getSessionState(course, d);
    const isLeave = state && LEAVE_STATUSES.has(state.className);
    if (d === dateYmd) {
      return isLeave ? null : num + 1;
    }
    if (!isLeave) num++;
  }
  return null;
};
const countNonLeaveSessions = (course) => {
  const allDates = sessions(course);
  let count = 0;
  for (const d of allDates) {
    const state = getSessionState(course, d);
    if (!state || !LEAVE_STATUSES.has(state.className)) count++;
  }
  return count;
};
const ATTENDED_SESSION_STATUSES = new Set(['completed', 'attended', 'late']);
const getCourseSessionRows = (course) => {
  const key = String(course?.id ?? '');
  const rows = classSessionsByCourse.value[key];
  return Array.isArray(rows) ? rows : [];
};
const getSessionRowsForDate = (course, dateYmd) => {
  const target = String(dateYmd || '').slice(0, 10);
  if (!target) return [];
  return getCourseSessionRows(course).filter((row) => String(row?.session_date || '').slice(0, 10) === target);
};
const formatAttendanceTooltipTime = (value) => {
  if (!value) return '';
  const text = String(value);
  if (text.includes('T')) {
    const d = new Date(text);
    if (!Number.isNaN(d.getTime())) {
      const y = d.getFullYear();
      const m = String(d.getMonth() + 1).padStart(2, '0');
      const day = String(d.getDate()).padStart(2, '0');
      const hh = String(d.getHours()).padStart(2, '0');
      const mm = String(d.getMinutes()).padStart(2, '0');
      return `${y}-${m}-${day} ${hh}:${mm}`;
    }
  }
  return text.replace('T', ' ').slice(0, 16);
};
const getSessionDisplayRow = (course, dateYmd) => {
  const rows = getSessionRowsForDate(course, dateYmd);
  if (!rows.length) return null;
  const priority = ['completed', 'attended', 'late', 'excused', 'absent', 'leave_adjusted', 'leave', 'cancelled', 'scheduled'];
  const sorted = [...rows].sort((a, b) => {
    const aStatus = String(a?.status || '').toLowerCase();
    const bStatus = String(b?.status || '').toLowerCase();
    return priority.indexOf(aStatus) - priority.indexOf(bStatus);
  });
  return sorted[0] || null;
};
const resolveRecordedByLabel = (row) => {
  const memo = String(row?.attendance_memo || '').toLowerCase();
  if (memo === 'swipe' || memo === 'swipe-rfid') return 'RFID 刷卡';
  if (memo === 'manual-match') return row?.recorded_by_name || '人工配對';
  return row?.recorded_by_name || row?.teacher_name || '';
};
const getSessionState = (course, dateYmd) => {
  const rows = getSessionRowsForDate(course, dateYmd);
  if (!rows.length) {
    return isCompletedDate(course, dateYmd) ? { label: '已上', className: 'completed' } : null;
  }

  const statuses = new Set(rows.map((row) => String(row?.status || '').toLowerCase()).filter(Boolean));

  if (statuses.has('leave_adjusted')) {
    return { label: '補請假', className: 'leave' };
  }
  if (statuses.has('excused') || statuses.has('leave')) {
    return { label: '請假', className: 'leave' };
  }
  if (statuses.has('cancelled')) {
    return { label: '取消', className: 'cancelled' };
  }
  if (statuses.has('absent')) {
    return { label: '缺席', className: 'absent' };
  }

  if ([...statuses].some((status) => ATTENDED_SESSION_STATUSES.has(status))) {
    return { label: '已上', className: 'completed' };
  }
  if (rows.some((row) => String(row?.learning_record_status || '').toLowerCase() === 'approved')) {
    return { label: '已上', className: 'completed' };
  }
  return null;
};
const getCourseCompletedDates = (course) => {
  const key = String(course?.id ?? '');
  const rows = getCourseSessionRows(course);
  if (Array.isArray(rows) && rows.length > 0) {
    const dates = rows
      .filter((row) => {
        const learningRecordStatus = String(row?.learning_record_status || '').toLowerCase();
        const sessionStatus = String(row?.status || '').toLowerCase();
        return learningRecordStatus === 'approved' || ATTENDED_SESSION_STATUSES.has(sessionStatus);
      })
      .map((row) => String(row?.session_date || '').slice(0, 10))
      .filter(Boolean);
    return [...new Set(dates)].sort();
  }
  const dates = completedSessionDatesByCourse.value[key];
  return Array.isArray(dates) ? dates : [];
};
const isCompletedDate = (course, dateYmd) => getCourseCompletedDates(course).includes(String(dateYmd || ''));
const getSessionStateLabel = (course, dateYmd) => getSessionState(course, dateYmd)?.label || '';
const getSessionStateClass = (course, dateYmd) => getSessionState(course, dateYmd)?.className || '';
const getSessionTooltip = (course, dateYmd) => {
  const row = getSessionDisplayRow(course, dateYmd);
  const stateLabel = getSessionStateLabel(course, dateYmd) || '未上';
  if (!row) return `狀態：${stateLabel}`;

  const lines = [
    `狀態：${stateLabel}`,
    `時段：${String(row?.start_time || '').slice(0, 5)}-${String(row?.end_time || '').slice(0, 5)}`,
  ];

  const attendanceTime = formatAttendanceTooltipTime(row?.attendance_sign_in_at);
  if (attendanceTime) {
    lines.push(`點名時間：${attendanceTime}`);
  }

  const recordedBy = resolveRecordedByLabel(row);
  if (recordedBy) {
    lines.push(`點名人：${recordedBy}`);
  }

  if (!attendanceTime && !recordedBy && row?.teacher_name) {
    lines.push(`授課老師：${row.teacher_name}`);
  }

  return lines.join('\n');
};
const displaySessions = (course) => sessions(course);
const isSessionMode = (course) => {
  const paymentType = String(course?.payment_type || '').trim();
  if (paymentType) return paymentType === 'session';
  return Number(course?.sessions_purchased ?? course?.SessionCount ?? 0) > 0;
};
const getPurchasedSessions = (course) => Math.max(0, Number(course?.sessions_purchased ?? course?.SessionCount ?? 0) || 0);
const getRawRemainingSessions = (course) => {
  const v = course?.remaining_sessions ?? course?.RemainingSessions;
  return Number.isFinite(Number(v)) ? Number(v) : null;
};
const getUsedSessions = (course) => {
  const purchased = getPurchasedSessions(course);
  const remaining = getRawRemainingSessions(course);
  if (remaining != null) return Math.max(0, purchased - remaining);
  const used = course?.sessions_used ?? course?.UsedSessions;
  if (Number.isFinite(Number(used))) return Math.max(0, Number(used));
  return Math.max(0, getCourseCompletedDates(course).length);
};
const displayRemainingSessions = (course) => {
  if (!isSessionMode(course)) return null;
  const purchased = getPurchasedSessions(course);
  const used = Math.min(purchased, getUsedSessions(course));
  return Math.max(0, purchased - used);
};

const localTodayYmd = () => {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
};

async function toggleCoursePause(course) {
  const isPaused = course.status === 'inactive';
  const studentName = course.student_name || '學生';
  const subject = getSubjectLabel(course.subject);
  const action = isPaused ? '恢復' : '暫停';

  if (!confirm(`確定要${action}「${studentName}」的 ${subject} 課程嗎？\n${isPaused ? '恢復後可繼續排課。' : '暫停後，未來未上課的堂次將被取消。'}`)) return;

  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { alert('請重新登入'); return; }

    const res = await fetch(`/api/v1/student-classes/${course.id}/pause`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ action: isPaused ? 'resume' : 'pause' }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      alert(`${action}失敗：` + (json.message || res.statusText));
      return;
    }
    alert(json.message || `已${action}`);
    await loadCourses();
  } catch (e) {
    alert('操作失敗：' + (e?.message || '請稍後再試'));
  }
}

function duplicateCourseForTeacher(course) {
  const teacherName = course.teacher_name || '目前老師';
  const studentName = course.student_name || '學生';
  if (!confirm(`將為「${studentName}」複製此課程設定並指定另一位老師。\n（原課程 ${teacherName} 不受影響）\n\n確定要開啟新增課程介面嗎？`)) return;
  backfillForm.value = {
    ...backfillForm.value,
    student_id: course.student_id || '',
    subject: course.subject || 'Math',
    teacher_id: '',
    class_type: course.class_type || 'one_on_one',
    rate_per_30min: course.rate_per_30min ?? 500,
    duration_hours: course.duration_hours ?? 2,
    payment_type: course.payment_type || 'session',
    sessions_purchased: course.sessions_purchased || 8,
    days_of_week: [],
    start_time: course.start_time || '16:00',
    room_id: course.room_id || null,
    memo: `雙師課程（同學生另一位老師，原課程#${course.id}）`,
  };
  showBackfillModal.value = true;
}

function openPurchaseModal(course) {
  purchaseCourse.value = course;
  purchaseForm.value = {
    sessions: 8,
    start_date: localTodayYmd(),
    student_name: course?.student_name || '—',
    subject: course?.subject || 'Math',
  };
  showPurchaseModal.value = true;
}

async function submitPurchaseSessions() {
  const course = purchaseCourse.value;
  if (!course?.id) return;
  if (!Number.isFinite(Number(purchaseForm.value.sessions)) || Number(purchaseForm.value.sessions) <= 0) {
    alert('請輸入正確堂數');
    return;
  }
  if (!purchaseForm.value.start_date) {
    alert('請選擇新批次開始日期');
    return;
  }
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) {
      alert('請重新登入後再試');
      return;
    }
    const res = await fetch(`/api/v1/student-classes/${course.id}/purchase-batch`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({
        sessions: Number(purchaseForm.value.sessions),
        start_date: purchaseForm.value.start_date,
        mode: 'new_purchase',
      }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      const details = json?.errors ? Object.values(json.errors || {}).flat().join(' ') : '';
      alert(details || json?.message || '加購失敗');
      return;
    }
    showPurchaseModal.value = false;
    alert('加購成功，已建立新批次課程。');
    await loadCourses();
  } catch (e) {
    alert('加購失敗：' + (e?.message || '請稍後再試'));
  }
}

function openQuickAddSessionModal(course) {
  quickAddSessionCourse.value = course;
  quickAddSessionForm.value = {
    session_date: localTodayYmd(),
    start_time: normalizeTo30Min(course?.start_time || '16:00'),
    duration_minutes: Math.max(30, Math.round((Number(course?.duration_hours) || 2) * 60)),
    note: '',
    auto_approve: true,
    student_name: course?.student_name || '—',
    subject: course?.subject || 'Math',
  };
  showQuickAddSessionModal.value = true;
}

async function submitQuickAddSession() {
  const course = quickAddSessionCourse.value;
  if (!course?.id) return;
  if (!quickAddSessionForm.value.session_date) {
    alert('請選擇上課日期');
    return;
  }
  if (!quickAddSessionForm.value.start_time) {
    alert('請選擇開始時間');
    return;
  }
  const durationMinutes = Number(quickAddSessionForm.value.duration_minutes || 0);
  if (!Number.isFinite(durationMinutes) || durationMinutes < 30) {
    alert('時長至少 30 分鐘');
    return;
  }
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) {
      alert('請重新登入後再試');
      return;
    }
    const res = await fetch(`/api/v1/student-classes/${course.id}/add-session`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({
        session_date: quickAddSessionForm.value.session_date,
        start_time: quickAddSessionForm.value.start_time,
        duration_minutes: durationMinutes,
        note: quickAddSessionForm.value.note || null,
        auto_approve: !!quickAddSessionForm.value.auto_approve,
      }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      const details = json?.errors ? Object.values(json.errors || {}).flat().join(' ') : '';
      alert(details || json?.message || '加課失敗');
      return;
    }
    showQuickAddSessionModal.value = false;
    const movedFrom = String(json?.moved_from_date || '').slice(0, 10);
    const defaultMsg = movedFrom
      ? `已補登完成，已將原 ${movedFrom} 的堂次調整到新日期（總堂數不變）。`
      : (json?.no_total_increase ? '已補登完成（總堂數不變）。' : '已補登完成。');
    alert(json?.message ? `${json.message}\n${defaultMsg}` : defaultMsg);
    await loadCourses();
  } catch (e) {
    alert('加課失敗：' + (e?.message || '請稍後再試'));
  }
}
async function ensureCompletedSessionDatesLoaded(course) {
  const cid = String(course?.id ?? '');
  if (!cid || completedSessionDatesByCourse.value[cid]) return;

  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) return;

    const { byClass } = await fetchClassSessions({
      token,
      branchId: props.branchId,
      studentClassId: cid,
      perPage: 2000,
    });
    const rows = Array.isArray(byClass?.[cid]) ? byClass[cid] : [];
    classSessionsByCourse.value = {
      ...classSessionsByCourse.value,
      [cid]: rows,
    };
    const dates = [...new Set(rows
      .filter((row) => String(row?.learning_record_status || '') === 'approved')
      .map((row) => String(row?.session_date || '').slice(0, 10))
      .filter(Boolean))].sort();

    completedSessionDatesByCourse.value = {
      ...completedSessionDatesByCourse.value,
      [cid]: dates,
    };
  } catch (_) {
    // ignore completed-date fetch failures
  }
}

async function loadClassSessionsForCourses(courseRows = [], token = '') {
  const ids = (courseRows || []).map((c) => Number(c?.id || c?.ID || 0)).filter((id) => id > 0);
  if (!props.branchId || ids.length === 0 || !token) {
    classSessionsByCourse.value = {};
    return;
  }
  try {
    const { byClass } = await fetchClassSessions({
      token,
      branchId: props.branchId,
      studentClassIds: ids,
      perPage: 2000,
    });
    classSessionsByCourse.value = byClass || {};
  } catch (_) {
    classSessionsByCourse.value = {};
  }
}

async function loadEffectiveSessionDates(courseRows = [], token = '') {
  const rows = Array.isArray(courseRows) ? courseRows : [];
  if (!props.branchId || rows.length === 0 || !token) {
    effectiveSessionDatesByCourse.value = {};
    return;
  }

  const payloadCourses = rows
    .map((c) => ({
      id: Number(c?.id || c?.ID || 0),
      first_class_date: c?.first_class_date || null,
      sessions_purchased: Number(c?.sessions_purchased ?? c?.SessionCount ?? 0) || 0,
      days_of_week: Array.isArray(c?.days_of_week) && c.days_of_week.length
        ? c.days_of_week.map((d) => Number(d)).filter((d) => d >= 1 && d <= 7)
        : ((Number(c?.day_of_week || 0) >= 1 && Number(c?.day_of_week || 0) <= 7)
          ? [Number(c.day_of_week)]
          : []),
    }))
    .filter((c) => c.id > 0);

  if (payloadCourses.length === 0) {
    effectiveSessionDatesByCourse.value = {};
    return;
  }

  try {
    const params = new URLSearchParams({ branch_id: String(props.branchId) });
    const res = await fetch(`/api/v1/student-classes/session-dates?${params.toString()}`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': `Bearer ${token}`,
      },
      body: JSON.stringify({
        branch_id: Number(props.branchId),
        courses: payloadCourses,
      }),
    });
    if (!res.ok) return;
    const json = await res.json().catch(() => ({}));
    const mapped = {};
    Object.keys(json || {}).forEach((key) => {
      const list = Array.isArray(json[key]) ? json[key] : [];
      mapped[String(key)] = [...new Set(list.map((d) => String(d || '').slice(0, 10)).filter(Boolean))].sort();
    });
    effectiveSessionDatesByCourse.value = mapped;
  } catch (_) {
    // ignore and fall back to classSessionsByCourse
  }
}

// ----- Session Edit Modal (單堂課編輯) -----
const showSessionEditModal = ref(false);
const sessionEditMode = ref('menu'); // 'menu' | 'retro-leave' | 'reschedule'
const sessionEditSubmitting = ref(false);
const sessionEditForm = ref({
  session_id: null,
  student_class_id: null,
  session_date: '',
  start_time: '',
  end_time: '',
  current_status: '',
  student_name: '',
  teacher_name: '',
  subject: '',
  attendance_time: '',
  lr_status: '',
  course: null,
  reason: '',
  new_date: '',
  new_start: '16:00',
  duration_hours: 2,
});

const SESSION_STATUS_TRANSITIONS = {
  scheduled:      ['attended', 'late', 'absent', 'excused', 'leave', 'cancelled'],
  attended:       ['leave', 'leave_adjusted', 'scheduled', 'absent', 'late', 'excused', 'cancelled'],
  completed:      ['leave', 'leave_adjusted', 'scheduled', 'absent', 'late', 'excused', 'cancelled'],
  late:           ['leave', 'leave_adjusted', 'scheduled', 'attended', 'absent', 'excused', 'cancelled'],
  absent:         ['leave', 'leave_adjusted', 'scheduled', 'attended', 'late', 'excused', 'cancelled'],
  excused:        ['leave', 'leave_adjusted', 'scheduled', 'attended', 'late', 'absent', 'cancelled'],
  leave:          ['scheduled', 'cancelled'],
  leave_adjusted: ['cancelled'],
  cancelled:      ['scheduled'],
};

const SESSION_STATUS_LABELS = {
  scheduled: '排課中', attended: '已上', completed: '已上', late: '遲到', absent: '缺席',
  excused: '請假', leave: '請假', leave_adjusted: '請假',
  cancelled: '已取消',
};

function sessionStatusLabel(status) {
  return SESSION_STATUS_LABELS[status] || status || '—';
}

function canTransitionTo(target) {
  const current = sessionEditForm.value.current_status || '';
  const allowed = SESSION_STATUS_TRANSITIONS[current] || [];
  return allowed.includes(target);
}

const secondaryStatusSelection = ref('');
const secondaryStatusOptions = computed(() => {
  const current = sessionEditForm.value.current_status || '';
  const allowed = SESSION_STATUS_TRANSITIONS[current] || [];
  const hiddenPrimary = new Set(['scheduled', 'leave', 'leave_adjusted']);
  return allowed.filter((s) => !hiddenPrimary.has(s));
});

function applySecondaryStatus() {
  if (!secondaryStatusSelection.value) return;
  const next = secondaryStatusSelection.value;
  secondaryStatusSelection.value = '';
  doStatusChange(next);
}

function openSessionEdit(course, dateYmd) {
  const row = getSessionDisplayRow(course, dateYmd);
  if (!row) return;
  sessionEditForm.value = {
    session_id: row.id,
    student_class_id: row.student_class_id || course.id,
    session_date: dateYmd,
    start_time: row.start_time || '',
    end_time: row.end_time || '',
    current_status: String(row.status || '').toLowerCase(),
    student_name: course.student_name || row.student_name || '—',
    teacher_name: course.teacher_name || row.teacher_name || '—',
    subject: course.subject || '',
    attendance_time: formatAttendanceTooltipTime(row.attendance_sign_in_at) || '',
    lr_status: row.learning_record_status || '',
    course,
    reason: '',
    new_date: '',
    new_start: row.start_time || '16:00',
    duration_hours: course.duration_hours ?? 2,
  };
  sessionEditMode.value = 'menu';
  secondaryStatusSelection.value = '';
  sessionEditSubmitting.value = false;
  showSessionEditModal.value = true;
}

async function openSessionEditFromAction(course) {
  await ensureCompletedSessionDatesLoaded(course);
  const dates = displaySessions(course);
  if (!Array.isArray(dates) || dates.length === 0) {
    alert('此課程目前沒有可操作的上課日期。');
    return;
  }
  // Prefer nearest upcoming session; fallback to first.
  const today = todayYmd.value;
  const upcoming = dates.find((d) => String(d) >= today);
  openSessionEdit(course, upcoming || dates[0]);
}

function closeSessionEdit() {
  showSessionEditModal.value = false;
  sessionEditMode.value = 'menu';
}

function addSessionFromModal() {
  const course = sessionEditForm.value?.course;
  if (course) {
    closeSessionEdit();
    openQuickAddSessionModal(course);
  }
}

async function doStatusChange(newStatus) {
  const form = sessionEditForm.value;
  if (!form.session_id) return;
  if (!confirm(`確定要將此堂狀態改為「${sessionStatusLabel(newStatus)}」嗎？`)) return;

  sessionEditSubmitting.value = true;
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { alert('請重新登入'); return; }

    const res = await fetch(`/api/v1/class-sessions/${form.session_id}`, {
      method: 'PATCH',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ status: newStatus, reason: form.reason || '' }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      alert('狀態更新失敗：' + (json.message || res.statusText));
      return;
    }
    if (json.session) {
      updateLocalSessionRow(form.student_class_id || form.course?.id, json.session);
    }
    closeSessionEdit();
    alert(json.message || '狀態已更新');
    await loadCourses();
  } catch (e) {
    alert('操作失敗：' + (e?.message || '請稍後再試'));
  } finally {
    sessionEditSubmitting.value = false;
  }
}

function startRetroLeave() {
  sessionEditMode.value = 'retro-leave';
}

async function doRetroLeave() {
  const form = sessionEditForm.value;
  if (!form.session_id) return;
  if (!confirm('此堂已上課/已點名，確認要執行補請假嗎？\n（將沖回堂數、作廢出缺勤與評量記錄）')) return;

  sessionEditSubmitting.value = true;
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { alert('請重新登入'); return; }

    const res = await fetch(`/api/v1/class-sessions/${form.session_id}`, {
      method: 'PATCH',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ status: 'leave_adjusted', reason: form.reason || '' }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      alert('補請假失敗：' + (json.message || res.statusText));
      return;
    }
    if (json.session) {
      updateLocalSessionRow(form.student_class_id || form.course?.id, json.session);
    }
    closeSessionEdit();
    alert(json.message || '補請假完成');
    await loadCourses();
  } catch (e) {
    alert('操作失敗：' + (e?.message || '請稍後再試'));
  } finally {
    sessionEditSubmitting.value = false;
  }
}

function startSessionReschedule() {
  sessionEditMode.value = 'reschedule';
  sessionEditForm.value.new_date = '';
  sessionEditForm.value.new_start = sessionEditForm.value.start_time || '16:00';
}

async function fetchMakeupSlotsForEdit() {
  const form = sessionEditForm.value;
  if (!form.course) return;
  rescheduleCourse.value = form.course;
  rescheduleForm.value = {
    ...rescheduleForm.value,
    student_id: form.course.student_id,
    student_name: form.student_name,
    subject: form.subject,
    teacher_id: form.course.teacher_id,
    class_type: form.course.class_type || 'one_on_one',
    duration_hours: form.duration_hours,
    course_id: form.student_class_id || form.course.id,
    original_date: form.session_date,
    original_start: form.start_time,
    original_end: form.end_time,
  };
  await fetchMakeupSlots();
}

async function doSessionReschedule() {
  const form = sessionEditForm.value;
  if (!form.new_date || !form.session_id) return;
  if (!confirm(`確定要將 ${form.session_date} 的課程調到 ${form.new_date} ${form.new_start} 嗎？`)) return;

  sessionEditSubmitting.value = true;
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { alert('請重新登入'); return; }

    const branchId = Number(props.branchId) || 0;
    const course = form.course;
    const newEnd = computeEndTime(form.new_start, form.duration_hours);
    const newDayOfWeek = dayOfWeekFromDate(form.new_date);

    const payload1 = {
      student_id: course.student_id,
      teacher_id: course.teacher_id || null,
      subject: form.subject,
      day_of_week: dayOfWeekFromDate(form.session_date),
      start_time: form.start_time,
      end_time: form.end_time,
      duration_hours: form.duration_hours,
      class_type: course.class_type || 'one_on_one',
      status: 'rescheduled',
      type: 'normal',
      deduction: 0,
      branch_id: branchId,
      student_course_id: form.student_class_id || course.id,
      schedule_date: form.session_date,
    };
    const payload2 = (originalId) => ({
      student_id: course.student_id,
      teacher_id: course.teacher_id || null,
      subject: form.subject,
      day_of_week: newDayOfWeek,
      start_time: normalizeTo30Min(form.new_start),
      end_time: newEnd,
      duration_hours: form.duration_hours,
      class_type: course.class_type || 'one_on_one',
      status: 'scheduled',
      type: 'normal',
      deduction: 1,
      branch_id: branchId,
      schedule_date: form.new_date,
      original_schedule_id: originalId,
      student_course_id: form.student_class_id || course.id,
    });

    let originalId = null;
    const existingRes = await fetch(
      `/api/v1/schedules?branch_id=${branchId}&student_course_id=${form.student_class_id || course.id}&schedule_date=${form.session_date}&status=rescheduled&__limit=1`,
      { credentials: 'include', headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } }
    );
    if (existingRes.ok) {
      const existingList = await existingRes.json();
      const arr = Array.isArray(existingList) ? existingList : existingList?.data ?? [];
      if (arr.length > 0 && arr[0].id) originalId = arr[0].id;
    }
    if (originalId == null) {
      const r1 = await fetch('/api/v1/schedules', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify(payload1),
      });
      if (!r1.ok) {
        const err = await r1.json().catch(() => ({}));
        alert('調課失敗：' + (err.message || '無法寫入原堂次紀錄'));
        return;
      }
      const created = await r1.json();
      originalId = created?.id ?? null;
    }
    const r2 = await fetch('/api/v1/schedules', {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify(payload2(originalId)),
    });
    if (!r2.ok) {
      const err = await r2.json().catch(() => ({}));
      alert('調課失敗：' + (err.message || '無法寫入新堂次'));
      return;
    }
    if (form.student_class_id || course.id) {
      await fetch('/api/v1/learning-records/reschedule-session', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({
          student_class_id: form.student_class_id || course.id,
          old_date: form.session_date,
          new_date: form.new_date,
          start_time: normalizeTo30Min(form.new_start),
          end_time: newEnd,
        }),
      }).catch(() => {});
    }

    closeSessionEdit();
    alert('調課完成');
    await loadCourses();
  } catch (e) {
    alert('調課失敗：' + (e?.message || '請稍後再試'));
  } finally {
    sessionEditSubmitting.value = false;
  }
}

function updateLocalSessionRow(courseId, sessionData) {
  const key = String(courseId || '');
  if (!key) return;
  const rows = classSessionsByCourse.value[key];
  if (!Array.isArray(rows)) return;
  const idx = rows.findIndex((r) => r.id === sessionData.id);
  if (idx >= 0) {
    const leaveStatuses = new Set(['leave', 'leave_adjusted', 'cancelled']);
    const updated = { ...rows[idx], status: sessionData.status, start_time: sessionData.start_time, end_time: sessionData.end_time };
    if (leaveStatuses.has(sessionData.status)) {
      updated.learning_record_status = null;
      updated.attendance_sign_in_at = null;
    }
    rows[idx] = updated;
    classSessionsByCourse.value = { ...classSessionsByCourse.value, [key]: [...rows] };
  }
}

// ----- Leave (請假) -----
const showLeaveModal = ref(false);
const leaveCourse = ref(null);
const leaveForm = ref({
  student_id: '', student_name: '', subject: '', teacher_id: '', day_of_week: 1,
  start_time: '', end_time: '', duration_hours: 2, class_type: 'one_on_one',
  schedule_date: '', course_id: null, reason: ''
});
const RETRO_LEAVE_STATUSES = new Set(['completed', 'attended', 'late', 'excused', 'absent']);
const getLeaveSessionOptionsForCourse = (course) => {
  const cid = String(course?.id ?? '');
  const rows = classSessionsByCourse.value[cid];
  if (!Array.isArray(rows) || rows.length === 0) {
    const fallbackDates = sessions(course);
    return fallbackDates.map((date, i) => ({ date, index: i + 1, isRetro: false }));
  }

  const options = [];
  const seenDates = new Set();
  rows.forEach((row, idx) => {
    const status = String(row?.status || '').toLowerCase();
    if (['cancelled', 'leave', 'leave_adjusted'].includes(status)) return;
    const date = String(row?.session_date || '').slice(0, 10);
    if (!date || seenDates.has(date)) return;
    seenDates.add(date);
    const isRetro = RETRO_LEAVE_STATUSES.has(status);
    options.push({ date, index: idx + 1, isRetro });
  });
  return options;
};
const leaveSessionOptions = computed(() => {
  const c = leaveCourse.value;
  if (!c) return [];
  return getLeaveSessionOptionsForCourse(c);
});
const isSelectedRetroLeave = computed(() => {
  const date = leaveForm.value.schedule_date;
  if (!date) return false;
  return leaveSessionOptions.value.some((opt) => opt.date === date && opt.isRetro);
});
async function openLeave(c) {
  await ensureCompletedSessionDatesLoaded(c);
  const list = getLeaveSessionOptionsForCourse(c).map((opt) => opt.date);
  if (!list || list.length === 0) {
    alert('此課程無可請假堂次（請確認開課日與排課設定）。');
    return;
  }
  leaveCourse.value = c;
  leaveForm.value = {
    student_id: c.student_id,
    student_name: c.student_name || '—',
    subject: c.subject,
    teacher_id: c.teacher_id || null,
    day_of_week: dayOfWeekFromDate(list[0]),
    start_time: c.start_time || '16:00',
    end_time: c.end_time || computeEndTime(c.start_time || '16:00', c.duration_hours ?? 2),
    duration_hours: c.duration_hours ?? 2,
    class_type: c.class_type || 'one_on_one',
    schedule_date: list[0] || '',
    course_id: c.id,
    reason: ''
  };
  showLeaveModal.value = true;
}
async function submitLeave() {
  if (!leaveForm.value.schedule_date) return;
  const branchId = Number(props.branchId) || 0;
  if (!branchId) { alert('請先選擇分校'); return; }
  const form = leaveForm.value;
  const isRetro = isSelectedRetroLeave.value;

  if (isRetro && !confirm('此堂已上課/已點名，確認要執行補請假嗎？\n（將沖回堂數、作廢出缺勤與評量記錄）')) {
    return;
  }

  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) {
      alert('請假登記失敗：請重新登入後再試');
      return;
    }

    if (isRetro) {
      const retroPayload = {
        student_course_id: form.course_id,
        session_date: form.schedule_date,
        reason: form.reason || '',
      };
      const res = await fetch('/api/v1/schedules/retro-leave', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify(retroPayload)
      });
      if (res.ok) {
        const json = await res.json().catch(() => ({}));
        const classKey = String(form.course_id || '');
        const normalized = normalizeClassSessionsPayload({ data: json?.class_sessions || [] });
        if (classKey && Array.isArray(normalized.byClass[classKey])) {
          classSessionsByCourse.value = {
            ...classSessionsByCourse.value,
            [classKey]: normalized.byClass[classKey]
          };
        }
        showLeaveModal.value = false;
        leaveCourse.value = null;
        alert('補請假完成：堂數已沖回');
        await loadCourses();
        return;
      }
      const errBody = await res.json().catch(() => ({}));
      alert('補請假失敗：' + (errBody.message || res.statusText || '請稍後再試'));
      return;
    }

    const payload = {
      student_id: form.student_id,
      teacher_id: form.teacher_id || null,
      subject: form.subject,
      day_of_week: form.day_of_week,
      start_time: form.start_time,
      end_time: form.end_time,
      duration_hours: form.duration_hours || 2,
      class_type: form.class_type || 'one_on_one',
      status: 'leave',
      type: 'normal',
      deduction: 0,
      branch_id: branchId,
      schedule_date: form.schedule_date,
      student_course_id: form.course_id
    };
    const res = await fetch('/api/v1/schedules', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify(payload)
    });
    if (res.ok) {
      const json = await res.json().catch(() => ({}));
      const classKey = String(form.course_id || '');
      const normalized = normalizeClassSessionsPayload({ data: json?.class_sessions || [] });
      if (classKey && Array.isArray(normalized.byClass[classKey])) {
        classSessionsByCourse.value = {
          ...classSessionsByCourse.value,
          [classKey]: normalized.byClass[classKey]
        };
      }
      showLeaveModal.value = false;
      leaveCourse.value = null;
      alert('請假登記完成');
      await loadCourses();
      return;
    }
    const errBody = await res.json().catch(() => ({}));
    alert('請假登記失敗：' + (errBody.message || res.statusText || '請稍後再試'));
    return;
  } catch (e) {
    alert('請假登記失敗：' + (e?.message || '請稍後再試'));
    return;
  }
}

// ----- Reschedule (調課) -----
async function submitBulkLeave() {
  const branchId = Number(props.branchId) || 0;
  if (!branchId) { alert('請先選擇分校'); return; }
  if (!bulkLeaveForm.value.start_date || !bulkLeaveForm.value.end_date) return;
  if (!confirm(`確定要將「${bulkLeaveForm.value.start_date}」至「${bulkLeaveForm.value.end_date}」區間所有課程批次請假嗎？`)) return;
  bulkLeaveSubmitting.value = true;
  bulkLeaveResult.value = null;
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { alert('請重新登入'); bulkLeaveSubmitting.value = false; return; }
    const res = await fetch('/api/v1/schedules/bulk-leave', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({
        branch_id: branchId,
        start_date: bulkLeaveForm.value.start_date,
        end_date: bulkLeaveForm.value.end_date,
      })
    });
    const json = await res.json().catch(() => ({}));
    if (res.ok) {
      bulkLeaveResult.value = json;
      await loadCourses();
    } else {
      alert('批次請假失敗：' + (json.message || res.statusText));
    }
  } catch (e) {
    alert('批次請假失敗：' + (e?.message || '請稍後再試'));
  } finally {
    bulkLeaveSubmitting.value = false;
  }
}

const showRescheduleModal = ref(false);
const rescheduleCourse = ref(null);
const rescheduleForm = ref({
  student_id: '', student_name: '', subject: '', teacher_id: '', class_type: 'one_on_one',
  duration_hours: 2, course_id: null,
  original_date: '', original_day: 1, original_start: '', original_end: '',
  new_date: '', new_start: '16:00'
});
const rescheduleSessionOptions = computed(() => {
  const c = rescheduleCourse.value;
  if (!c) return [];
  const cid = String(c?.id ?? '');
  const rows = classSessionsByCourse.value[cid];
  if (Array.isArray(rows) && rows.length > 0) {
    const options = [];
    const seenDates = new Set();
    rows.forEach((row, idx) => {
      const status = String(row?.status || '').toLowerCase();
      if (['completed', 'attended', 'late', 'excused', 'absent', 'cancelled', 'leave', 'leave_adjusted'].includes(status)) return;
      const date = String(row?.session_date || '').slice(0, 10);
      if (!date || seenDates.has(date)) return;
      seenDates.add(date);
      options.push({ date, index: idx + 1 });
    });
    return options;
  }
  const list = sessions(c);
  return list.map((date, i) => ({ date, index: i + 1 }));
});
async function openReschedule(c) {
  await ensureCompletedSessionDatesLoaded(c);
  const list = sessions(c);
  if (!list || list.length === 0) {
    alert('此課程無可調課堂次（請確認開課日與排課設定）。');
    return;
  }
  rescheduleCourse.value = c;
  const first = list[0];
  rescheduleForm.value = {
    student_id: c.student_id,
    student_name: c.student_name || '—',
    subject: c.subject,
    teacher_id: c.teacher_id || null,
    class_type: c.class_type || 'one_on_one',
    duration_hours: c.duration_hours ?? 2,
    course_id: c.id,
    original_date: first,
    original_day: dayOfWeekFromDate(first),
    original_start: c.start_time || '16:00',
    original_end: c.end_time || computeEndTime(c.start_time || '16:00', c.duration_hours ?? 2),
    new_date: '',
    new_start: normalizeTo30Min(c.start_time || '16:00')
  };
  showRescheduleModal.value = true;
}
watch(() => leaveForm.value.schedule_date, (date) => {
  if (!date) return;
  leaveForm.value.day_of_week = dayOfWeekFromDate(date);
});
watch(() => rescheduleForm.value.original_date, (date) => {
  if (!date) return;
  rescheduleForm.value.original_day = dayOfWeekFromDate(date);
});
function onRescheduleNewStartChange() {
  // optional: sync end time display; computed already handles it
}
async function submitReschedule() {
  const form = rescheduleForm.value;
  if (!form.new_date) return;
  const branchId = Number(props.branchId) || 0;
  if (!branchId) { alert('請先選擇分校'); return; }
  const newEnd = computeEndTime(form.new_start, form.duration_hours);
  const newDayOfWeek = dayOfWeekFromDate(form.new_date);

  const payload1 = {
    student_id: form.student_id,
    teacher_id: form.teacher_id || null,
    subject: form.subject,
    day_of_week: form.original_day,
    start_time: form.original_start,
    end_time: form.original_end,
    duration_hours: form.duration_hours,
    class_type: form.class_type,
    status: 'rescheduled',
    type: 'normal',
    deduction: 0,
    branch_id: branchId,
    student_course_id: form.course_id,
    schedule_date: form.original_date
  };

  const payload2 = (originalId) => ({
    student_id: form.student_id,
    teacher_id: form.teacher_id || null,
    subject: form.subject,
    day_of_week: newDayOfWeek,
    start_time: normalizeTo30Min(form.new_start),
    end_time: newEnd,
    duration_hours: form.duration_hours,
    class_type: form.class_type,
    status: 'scheduled',
    type: 'normal',
    deduction: 1,
    branch_id: branchId,
    schedule_date: form.new_date,
    original_schedule_id: originalId,
    student_course_id: form.course_id
  });

  // 優先使用 Laravel API
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (token) {
      let originalId = null;
      const existingRes = await fetch(
        `/api/v1/schedules?branch_id=${branchId}&student_course_id=${form.course_id}&schedule_date=${form.original_date}&status=rescheduled&__limit=1`,
        { credentials: 'include', headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${token}` } }
      );
      if (existingRes.ok) {
        const existingList = await existingRes.json();
        const arr = Array.isArray(existingList) ? existingList : existingList?.data ?? [];
        if (arr.length > 0 && arr[0].id) originalId = arr[0].id;
      }
      if (originalId == null) {
        const r1 = await fetch('/api/v1/schedules', {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
          body: JSON.stringify(payload1)
        });
        if (!r1.ok) {
          const err = await r1.json().catch(() => ({}));
          alert('調課失敗：' + (err.message || '無法寫入原堂次紀錄'));
          return;
        }
        const created = await r1.json();
        originalId = created?.id ?? null;
      }
      const r2 = await fetch('/api/v1/schedules', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify(payload2(originalId))
      });
      if (!r2.ok) {
        const err = await r2.json().catch(() => ({}));
        alert('調課失敗：' + (err.message || '無法寫入新堂次'));
        return;
      }
      if (form.course_id) {
        await fetch('/api/v1/learning-records/reschedule-session', {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
          body: JSON.stringify({
            student_class_id: form.course_id,
            old_date: form.original_date || null,
            new_date: form.new_date,
            start_time: normalizeTo30Min(form.new_start),
            end_time: newEnd
          })
        }).catch(() => {});
      }
      showRescheduleModal.value = false;
      rescheduleCourse.value = null;
      alert('調課完成');
      loadCourses();
      return;
    }
  } catch (_) { /* fallback to Supabase */ }

  let originalId = null;
  const { data: existing } = await supabase
    .from('schedules')
    .select('id')
    .eq('student_course_id', form.course_id)
    .eq('schedule_date', form.original_date)
    .eq('status', 'rescheduled')
    .maybeSingle();
  if (existing?.id) {
    originalId = existing.id;
  } else {
    const { data: ins, error: e1 } = await supabase.from('schedules').insert([payload1]).select('id').single();
    if (e1) {
      alert('調課失敗：' + (e1.message || '無法寫入原堂次紀錄'));
      return;
    }
    originalId = ins?.id ?? null;
  }
  const { error: e2 } = await supabase.from('schedules').insert([payload2(originalId)]);
  if (e2) {
    alert('調課失敗：' + (e2.message || '無法寫入新堂次'));
    return;
  }
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (token && form.course_id) {
      await fetch('/api/v1/learning-records/reschedule-session', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify({
          student_class_id: form.course_id,
          old_date: form.original_date || null,
          new_date: form.new_date,
          start_time: normalizeTo30Min(form.new_start),
          end_time: newEnd
        })
      }).catch(() => {});
    }
  } catch (_) {}
  showRescheduleModal.value = false;
  rescheduleCourse.value = null;
  alert('調課完成');
  loadCourses();
}

// ----- Makeup Slots (補課空檔查詢) -----
const showMakeupSlotsModal = ref(false);
const makeupLoading = ref(false);
const makeupDateRange = ref(30);
const availableMakeupSlots = ref([]);

function timeToSlotIndex(timeStr) {
  const [h, m] = (timeStr || '00:00').split(':').map(Number);
  return h * 2 + Math.floor((m || 0) / 30);
}
function slotIndexToTime(idx) {
  const h = Math.floor(idx / 2);
  const m = (idx % 2) * 30;
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

const makeupSlotsGrouped = computed(() => {
  const map = {};
  for (const s of availableMakeupSlots.value) {
    if (!map[s.date]) map[s.date] = { date: s.date, day_of_week: s.day_of_week, slots: [] };
    map[s.date].slots.push(s);
  }
  return Object.values(map).sort((a, b) => a.date.localeCompare(b.date));
});

async function fetchMakeupSlots() {
  const form = rescheduleForm.value;
  if (!form.teacher_id) { alert('此課程未指定老師，無法查詢空檔'); return; }
  const branchId = Number(props.branchId) || 0;
  if (!branchId) { alert('請先選擇分校'); return; }

  makeupLoading.value = true;
  showMakeupSlotsModal.value = true;
  availableMakeupSlots.value = [];

  const now = new Date();
  now.setHours(12, 0, 0, 0);
  const startDate = new Date(now.getTime() + 86400000).toISOString().slice(0, 10);
  const endDate = new Date(now.getTime() + makeupDateRange.value * 86400000).toISOString().slice(0, 10);

  let teacherCourses = [];
  let schedExceptions = [];

  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (token) {
      const [cRes, sRes] = await Promise.all([
        fetch(`/api/v1/student-classes?${new URLSearchParams({
          branch_id: String(branchId), teacher_id: String(form.teacher_id), per_page: '1000'
        })}`, {
          credentials: 'include',
          headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${token}` }
        }),
        fetch(`/api/v1/schedules?${new URLSearchParams({
          branch_id: String(branchId), teacher_id: String(form.teacher_id), per_page: '1000'
        })}`, {
          credentials: 'include',
          headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${token}` }
        })
      ]);
      if (cRes.ok) {
        const j = await cRes.json();
        const a = Array.isArray(j) ? j : (j?.data ?? []);
        teacherCourses = Array.isArray(a) ? a : [];
      }
      if (sRes.ok) {
        const j = await sRes.json();
        const a = Array.isArray(j) ? j : (j?.data ?? []);
        schedExceptions = Array.isArray(a) ? a : [];
      }
    }
  } catch (_) {}

  if (teacherCourses.length === 0) {
    const { data } = await supabase.from('student-classes').select('*')
      .eq('branch_id', branchId).eq('teacher_id', form.teacher_id);
    teacherCourses = data || [];
  }
  if (schedExceptions.length === 0) {
    const { data } = await supabase.from('schedules').select('*')
      .eq('teacher_id', form.teacher_id).eq('branch_id', branchId);
    schedExceptions = data || [];
  }

  const leaveSet = new Set();
  const reschFromSet = new Set();
  const reschToList = [];
  for (const ex of schedExceptions) {
    const d = ex.schedule_date ? String(ex.schedule_date).slice(0, 10) : '';
    const cid = String(ex.student_course_id || '');
    if (ex.status === 'leave') leaveSet.add(`${cid}_${d}`);
    else if (ex.status === 'rescheduled') reschFromSet.add(`${cid}_${d}`);
    else if (ex.status === 'scheduled' && ex.original_schedule_id) reschToList.push(ex);
  }

  const maxStudentsPerSlot = getCapacityForClassType(form.class_type || 'one_on_one');

  const occMap = {};
  const slotStudentsMap = {};

  function markOcc(date, st, et, studentName) {
    if (!st || !et) return;
    if (!occMap[date]) occMap[date] = {};
    if (!slotStudentsMap[date]) slotStudentsMap[date] = {};
    const s = timeToSlotIndex(st), e = timeToSlotIndex(et);
    for (let i = s; i < e; i++) {
      occMap[date][i] = (occMap[date][i] || 0) + 1;
      if (studentName) {
        if (!slotStudentsMap[date][i]) slotStudentsMap[date][i] = [];
        slotStudentsMap[date][i].push(studentName);
      }
    }
  }

  const dEnd = new Date(endDate + 'T12:00:00');
  let cursor = new Date(startDate + 'T12:00:00');
  while (cursor <= dEnd) {
    const ymd = cursor.toISOString().slice(0, 10);
    const dow = cursor.getDay() === 0 ? 7 : cursor.getDay();
    for (const c of teacherCourses) {
      const cDays = Array.isArray(c.days_of_week) && c.days_of_week.length
        ? c.days_of_week.map(Number) : (c.day_of_week ? [Number(c.day_of_week)] : []);
      if (!cDays.includes(dow)) continue;
      const cid = String(c.id || '');
      if (leaveSet.has(`${cid}_${ymd}`) || reschFromSet.has(`${cid}_${ymd}`)) continue;
      const fcd = c.first_class_date ? String(c.first_class_date).slice(0, 10) : null;
      if (fcd && ymd < fcd) continue;
      const st = c.start_time || '16:00';
      markOcc(ymd, st, c.end_time || computeEndTime(st, c.duration_hours || 2), c.student_name || '');
    }
    cursor.setDate(cursor.getDate() + 1);
  }

  for (const ex of reschToList) {
    const d = ex.schedule_date ? String(ex.schedule_date).slice(0, 10) : '';
    if (d >= startDate && d <= endDate) markOcc(d, ex.start_time, ex.end_time, ex.student_name || '');
  }

  const durSlots = Math.ceil((form.duration_hours || 2) * 2);
  const tStart = timeToSlotIndex('09:00');
  const tEnd = timeToSlotIndex('21:00');
  const result = [];

  cursor = new Date(startDate + 'T12:00:00');
  while (cursor <= dEnd) {
    const ymd = cursor.toISOString().slice(0, 10);
    const dow = cursor.getDay() === 0 ? 7 : cursor.getDay();
    const occ = occMap[ymd] || {};
    const stuMap = slotStudentsMap[ymd] || {};
    for (let i = tStart; i <= tEnd - durSlots; i++) {
      let available = true;
      let maxOcc = 0;
      for (let j = 0; j < durSlots; j++) {
        const cnt = occ[i + j] || 0;
        if (cnt >= maxStudentsPerSlot) { available = false; break; }
        if (cnt > maxOcc) maxOcc = cnt;
      }
      if (available) {
        const studentsSet = new Set();
        for (let j = 0; j < durSlots; j++) {
          for (const name of (stuMap[i + j] || [])) { if (name) studentsSet.add(name); }
        }
        result.push({
          date: ymd, start_time: slotIndexToTime(i),
          end_time: slotIndexToTime(i + durSlots), day_of_week: dow,
          currentStudentCount: maxOcc, capacity: maxStudentsPerSlot,
          existingStudents: [...studentsSet]
        });
      }
    }
    cursor.setDate(cursor.getDate() + 1);
  }

  availableMakeupSlots.value = result;
  makeupLoading.value = false;
}

function selectMakeupSlot(slot) {
  rescheduleForm.value.new_date = slot.date;
  rescheduleForm.value.new_start = slot.start_time;
  showMakeupSlotsModal.value = false;
}

const getSubjectLabel = (val) => getSubjectText(val);
const classTypeLabel = (type) => {
  const map = { one_on_one: '一對一', one_on_two: '一對二', one_on_three: '一對三', tutoring: '輔導' };
  return map[type] || type;
};

const CLASS_CAPACITY = { one_on_one: 1, one_on_two: 2, one_on_three: 3, tutoring: 4 };
function getCapacityForClassType(type) { return CLASS_CAPACITY[type] ?? 1; }
const dayLabel = (d) => ['', '週一', '週二', '週三', '週四', '週五', '週六', '週日'][d] || '';

const formatDayTimeSlots = (course) => {
  const slots = Array.isArray(course?.day_time_slots) ? course.day_time_slots : [];
  const globalDur = Number(course?.duration_hours) || 2;
  const normalized = slots
    .map((s) => ({ day: Number(s?.day || 0), start: String(s?.start_time || '').slice(0, 5), dur: Number(s?.duration_hours || 0) || globalDur }))
    .filter((s) => s.day >= 1 && s.day <= 7 && s.start)
    .sort((a, b) => a.day - b.day);
  const allSameDur = new Set(normalized.map((s) => s.dur)).size <= 1;
  return normalized
    .map((s) => {
      const end = computeEndTime(s.start, s.dur) || '';
      const durSuffix = !allSameDur ? ` ${s.dur}h` : '';
      return `${dayLabel(s.day)} ${s.start}~${end}${durSuffix}`;
    })
    .join('、');
};

// 與學生管理共用單一費用邏輯（Single Source of Truth）
const sessionPrice = (c) => getPerSessionFee(c);
const totalPrice = (c) => getCourseTotalFee(c);

const groupCoursesByStudent = (list = []) => {
  const grouped = [];
  const groupedMap = new Map();
  for (const c of list) {
    const studentName = String(c?.student_name || '').trim() || '未命名學生';
    const studentId = c?.student_id ?? c?.StudentID ?? null;
    const key = studentId != null && studentId !== ''
      ? `sid:${studentId}`
      : `name:${studentName}`;
    if (!groupedMap.has(key)) {
      const group = { key, student_name: studentName, courses: [] };
      groupedMap.set(key, group);
      grouped.push(group);
    }
    groupedMap.get(key).courses.push(c);
  }
  return grouped;
};

const groupedCourses = computed(() => groupCoursesByStudent(courses.value));

const resetExpandedStudentGroups = (groups = groupedCourses.value) => {
  expandedStudentGroups.value = new Set(groups.map((g) => g.key));
};

const toggleStudentGroup = (groupKey) => {
  const next = new Set(expandedStudentGroups.value);
  if (next.has(groupKey)) next.delete(groupKey);
  else next.add(groupKey);
  expandedStudentGroups.value = next;
};
const expandAllGroups = () => {
  resetExpandedStudentGroups(groupedCourses.value);
};
const collapseAllGroups = () => {
  expandedStudentGroups.value = new Set();
};

const coursesByType = computed(() => {
  const c = courses.value;
  return {
    one_on_one: c.filter(x => x.class_type === 'one_on_one').length,
    one_on_two: c.filter(x => x.class_type === 'one_on_two').length,
    one_on_three: c.filter(x => x.class_type === 'one_on_three').length,
    tutoring: c.filter(x => x.class_type === 'tutoring').length
  };
});

const coursesBySubject = computed(() => {
  const map = {};
  for (const c of courses.value) {
    const s = c.subject || '';
    if (!s) continue;
    map[s] = (map[s] || 0) + 1;
  }
  return Object.entries(map).map(([subject, count]) => ({
    subject,
    label: getSubjectLabel(subject),
    count
  })).sort((a, b) => b.count - a.count);
});

const paymentStatusButtonClass = (course) => {
  return course?.payment_status === 'paid' ? 'tag-paid' : 'tag-unpaid';
};
const paymentStatusButtonLabel = (course) => {
  return course?.payment_status === 'paid' ? '已繳費' : '未繳費';
};

const loadCourses = async () => {
  if (!props.branchId) {
    courses.value = [];
    completedSessionDatesByCourse.value = {};
    classSessionsByCourse.value = {};
    effectiveSessionDatesByCourse.value = {};
    expandedStudentGroups.value = new Set();
    return;
  }
  completedSessionDatesByCourse.value = {};
  classSessionsByCourse.value = {};
  effectiveSessionDatesByCourse.value = {};
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (token) {
      const params = new URLSearchParams({
        branch_id: String(props.branchId),
        per_page: '1000'
      });
      if (filters.value.class_type) params.set('class_type', filters.value.class_type);
      if (filters.value.teacher_id) params.set('teacher_id', filters.value.teacher_id);
      if (filters.value.course_status) params.set('status', filters.value.course_status);
      const res = await fetch(`/api/v1/student-classes?${params}`, {
        credentials: 'include',
        headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${token}` }
      });
      if (res.ok) {
        const json = await res.json();
        const list = json?.data ?? json;
        const arr = Array.isArray(list) ? list : (list?.data ?? []);
        let result = arr.map(c => ({
          ...c,
          data_source: 'laravel',
          student_name: c.student_name ?? '—',
          teacher_name: c.teacher_name ?? '',
          sessions_used: c.sessions_used ?? c.UsedSessions ?? null,
          remaining_sessions: c.remaining_sessions ?? c.RemainingSessions ?? null
        }));
        if (filters.value.name) {
          const q = filters.value.name.toLowerCase();
          result = result.filter(c => (c.student_name || '').toLowerCase().includes(q));
        }
        courses.value = result;
        resetExpandedStudentGroups(groupCoursesByStudent(result));
        await loadClassSessionsForCourses(result, token);
        await loadEffectiveSessionDates(result, token);
        return;
      }
    }
  } catch (_) {}
  let query = supabase
    .from('student-classes')
    .select('*, student:students(name, remaining_lessons), teacher:profiles(username)')
    .eq('branch_id', props.branchId);

  if (filters.value.class_type) query = query.eq('class_type', filters.value.class_type);
  if (filters.value.teacher_id) query = query.eq('teacher_id', filters.value.teacher_id);

  const { data } = await query;
  let result = (data || []).map(c => ({
    ...c,
    data_source: 'supabase',
    student_name: c.student?.name || '—',
    teacher_name: c.teacher_name || c.teacher?.username || '',
    sessions_used: c.sessions_used ?? c.used_sessions ?? c.UsedSessions ?? null,
    remaining_sessions: c.remaining_sessions ?? c.RemainingSessions ?? null,
    branch_name: null,
    room_name: null,
    settlement_day: null
  }));

  if (filters.value.name) {
    const q = filters.value.name.toLowerCase();
    result = result.filter(c => c.student_name.toLowerCase().includes(q));
  }

  courses.value = result;
  resetExpandedStudentGroups(groupCoursesByStudent(result));
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    await loadClassSessionsForCourses(result, token || '');
    await loadEffectiveSessionDates(result, token || '');
  } catch (_) {
    classSessionsByCourse.value = {};
    effectiveSessionDatesByCourse.value = {};
  }
};

const togglePaymentStatus = async (c) => {
  if (!c?.id) return;
  const newStatus = c.payment_status === 'paid' ? 'unpaid' : 'paid';
  const fromLabel = c.payment_status === 'paid' ? '已繳費' : '未繳費';
  const toLabel = newStatus === 'paid' ? '已繳費' : '未繳費';
  if (!confirm(`確定將「${c.student_name || '此學生'}」課程由「${fromLabel}」改為「${toLabel}」嗎？`)) {
    return;
  }
  if (c.data_source === 'laravel' || c.branch_name != null || c.room_name != null || c.settlement_day != null) {
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (token) {
        const res = await fetch(`/api/v1/student-classes/${c.id}`, {
          method: 'PUT',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
          body: JSON.stringify({ payment_status: newStatus })
        });
        if (res.ok) {
          c.payment_status = newStatus;
          return;
        }
      }
    } catch (_) {}
  }
  await supabase.from('student-classes').update({ payment_status: newStatus }).eq('id', c.id);
  c.payment_status = newStatus;
};

const loadStudents = async () => {
  const branchId = props.branchId != null ? String(props.branchId) : '';
  if (!branchId) {
    allStudents.value = [];
    return;
  }
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (token) {
      const params = new URLSearchParams({
        branch_id: branchId,
        per_page: '1000',
      });
      const res = await fetch(`/api/v1/students?${params.toString()}`, {
        credentials: 'include',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
      if (res.ok) {
        const json = await res.json().catch(() => ({}));
        const list = json?.data ?? json;
        const arr = Array.isArray(list) ? list : (Array.isArray(list?.data) ? list.data : []);
        allStudents.value = arr.map((s) => ({
          id: Number(s?.id ?? 0),
          name: String(s?.name ?? '').trim(),
        })).filter((s) => s.id > 0 && s.name);
        return;
      }
    }
  } catch (_) {
    // fallback below
  }

  // Legacy fallback for older local data sources.
  const { data } = await supabase.from('students').select('id, name').eq('branch_id', props.branchId).order('name');
  allStudents.value = data || [];
};

const loadTeachers = async () => {
  const currentBranchId = props.branchId != null ? String(props.branchId) : '';
  if (!currentBranchId) {
    teachers.value = [];
    return;
  }
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { teachers.value = []; return; }
    const params = new URLSearchParams({
      per_page: 'all',
      status: 'active',
      branch_id: currentBranchId,
    });
    const res = await fetch(`/api/v1/teachers?${params.toString()}`, {
      headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${token}` }
    });
    const data = await res.json().catch(() => ({}));
    const list = Array.isArray(data) ? data : (data?.data ?? []);
    const filteredRows = (Array.isArray(list) ? list : []).filter((teacher) => {
      if ((teacher?.status || 'active') !== 'active') return false;
      const branchIds = Array.isArray(teacher?.branch_ids)
        ? teacher.branch_ids.map((id) => String(id))
        : [];
      if (branchIds.length > 0) return branchIds.includes(currentBranchId);
      if (teacher?.branch_id == null) return false;
      return String(teacher.branch_id) === currentBranchId;
    });
    const dedupById = new Map();
    filteredRows.forEach((teacher) => dedupById.set(String(teacher.id), teacher));
    teachers.value = Array.from(dedupById.values()).map((t) => ({
      id: t.id,
      username: t.username,
      branch_ids: Array.isArray(t.branch_ids) ? t.branch_ids : [],
    }));

    const teacherIdSet = new Set(teachers.value.map((t) => String(t.id)));
    if (backfillForm.value.teacher_id && !teacherIdSet.has(String(backfillForm.value.teacher_id))) {
      backfillForm.value.teacher_id = '';
    }
    if (editForm.value?.teacher_id && !teacherIdSet.has(String(editForm.value.teacher_id))) {
      editForm.value.teacher_id = '';
    }
    if (filters.value.teacher_id && !teacherIdSet.has(String(filters.value.teacher_id))) {
      filters.value.teacher_id = '';
    }
  } catch (_) {
    teachers.value = [];
  }
};

const debouncedLoad = () => setTimeout(loadCourses, 300);

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
    } else rooms.value = [];
  } catch (_) { rooms.value = []; }
};

const editCourse = (c) => {
  editingId.value = c.id;
  editingCourseFromLaravel.value = !!(
    c.data_source === 'laravel'
    || c.branch_name != null
    || c.room_name != null
    || c.settlement_day != null
  );
  const existingDays = Array.isArray(c.days_of_week) && c.days_of_week.length
    ? c.days_of_week.map(Number)
    : (c.day_of_week ? [Number(c.day_of_week)] : []);
  editForm.value = {
    subject: c.subject,
    teacher_id: c.teacher_id || '',
    class_type: c.class_type,
    rate_per_30min: c.rate_per_30min,
    duration_hours: c.duration_hours ?? 2,
    sessions_purchased: c.sessions_purchased ?? 8,
    remaining_sessions: c.remaining_sessions ?? 0,
    days_of_week: existingDays,
    start_time: normalizeTo30Min(c.start_time || '16:00'),
    end_time: c.end_time || '',
    payment_type: c.payment_type || 'session',
    settlement_day: c.settlement_day ?? null,
    monthly_sessions: c.monthly_sessions ?? null,
    first_class_date: c.first_class_date || '',
    room_id: c.room_id ?? null,
    memo: c.memo ?? c.Memo ?? ''
  };
  loadRoomsForBranch();
  showEditModal.value = true;
};

const submitEdit = async () => {
  const id = editingId.value;
  const form = editForm.value;
  if (editingCourseFromLaravel.value) {
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (token) {
        const endTime = computeEndTime(form.start_time, form.duration_hours);
        const body = {
          subject: form.subject,
          teacher_id: form.teacher_id || null,
          class_type: form.class_type,
          rate_per_30min: form.rate_per_30min,
          duration_hours: form.duration_hours,
          sessions_purchased: form.sessions_purchased,
          remaining_sessions: form.remaining_sessions,
          days_of_week: (form.days_of_week || []).length ? form.days_of_week : [],
          start_time: form.start_time,
          end_time: endTime,
          payment_type: form.payment_type,
          settlement_day: form.payment_type === 'monthly' ? form.settlement_day : null,
          monthly_sessions: form.payment_type === 'monthly' ? form.monthly_sessions : null,
          first_class_date: form.first_class_date || null,
          force_rebuild_if_mismatch: true,
          room_id: form.room_id || null,
          Memo: form.memo || null
        };
        const res = await fetch(`/api/v1/student-classes/${id}`, {
          method: 'PUT',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
          body: JSON.stringify(body)
        });
        if (res.ok) {
          const payload = await res.json().catch(() => ({}));
          const sync = payload?.session_sync || {};
          let successMsg = '課程已更新。';
          if (sync?.rebuilt) {
            successMsg += ` 已依新開課日重排 ${Number(sync.created_sessions || 0)} 堂。`;
            if (sync?.reason === 'start_date_aligned') {
              successMsg += '（堂次首日已與開課日重新對齊）';
            }
          } else if (sync?.reason === 'history_exists') {
            successMsg += ' 本課已有出缺勤/核准紀錄，為保留歷史資料未重排堂次。';
          } else if (sync?.reason === 'start_date_unchanged') {
            successMsg += ' 開課日未變更，故未重排堂次。';
          } else if (sync?.reason === 'start_date_not_updated') {
            successMsg += ' 本次未更新開課日，故未重排堂次。';
          }
          showEditModal.value = false;
          alert(successMsg);
          loadCourses();
          return;
        }
        const err = await res.json().catch(() => ({}));
        alert(err?.message || '更新失敗');
        return;
      }
    } catch (e) {
      alert('連線失敗：' + (e?.message || '請稍後再試'));
      return;
    }
  }
  const endTime = computeEndTime(form.start_time, form.duration_hours);
  const { error } = await supabase.from('student-classes').update({
    subject: form.subject, teacher_id: form.teacher_id || null, class_type: form.class_type,
    rate_per_30min: form.rate_per_30min, remaining_sessions: form.remaining_sessions,
    days_of_week: form.days_of_week, start_time: form.start_time, end_time: endTime,
    payment_type: form.payment_type,
    first_class_date: form.first_class_date || null
  }).eq('id', id);
  if (error) {
    alert('更新失敗：' + (error?.message || '請稍後再試'));
    return;
  }
  showEditModal.value = false;
  alert('課程已更新。');
  loadCourses();
};

const deleteCourse = async (c) => {
  if (!confirm('確定要刪除此課程？刪除後無法復原。')) return;
  const fromLaravel = c.data_source === 'laravel' || c.branch_name != null || c.room_name != null || c.settlement_day != null;
  if (fromLaravel) {
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (token) {
        const res = await fetch(`/api/v1/student-classes/${c.id}`, {
          method: 'DELETE',
          credentials: 'include',
          headers: { 'Authorization': `Bearer ${token}` }
        });
        if (res.ok) {
          courses.value = courses.value.filter(x => x.id !== c.id);
          return;
        }
        const err = await res.json().catch(() => ({}));
        alert(err?.message || '刪除失敗');
        return;
      }
    } catch (e) {
      alert('刪除失敗：' + (e?.message || '請稍後再試'));
      return;
    }
  }
  await supabase.from('student-classes').delete().eq('id', c.id);
  courses.value = courses.value.filter(x => x.id !== c.id);
};

const submitBackfill = async () => {
  if (!backfillForm.value.student_id) { alert('請選擇學生'); return; }
  if (!backfillForm.value.teacher_id) { alert('請選擇老師'); return; }

  const form = backfillForm.value;
  const isSessionPayment = (form.payment_type || 'session') === 'session';
  const selectedLegacyDates = [...new Set((backfillSelectedDates.value || []).filter(Boolean))].sort();
  const derivedFirstClassDate = selectedLegacyDates[0] || null;
  const sessionsPurchased = Math.max(0, Number(form.sessions_purchased) || 0);
  const sessionsUsed = selectedLegacyDates.length;
  if (isSessionPayment && sessionsUsed === 0) {
    alert('請先在月曆勾選至少 1 個已上課日期');
    return;
  }
  if (isSessionPayment && sessionsPurchased <= 0) {
    alert('購買堂數需大於 0');
    return;
  }
  if (isSessionPayment && sessionsUsed > sessionsPurchased) {
    alert('已選上課日期不可超過購買堂數');
    return;
  }

  const endTime = computeEndTime(form.start_time, form.duration_hours);
  const remaining = Math.max(0, sessionsPurchased - sessionsUsed);
  const daysOfWeek = (form.days_of_week || []).length ? form.days_of_week : [];

  // 1. Create course record: try Laravel API first, then Supabase
  let created = false;
  let createdCourseId = null;
  let usedApi = false;
  let token = null;
  let directorId = null;
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    token = sess?.access_token;
    directorId = sess?.user?.id || null;
    if (token && props.branchId) {
      usedApi = true;
      const body = {
        student_id: form.student_id,
        subject: form.subject,
        teacher_id: form.teacher_id || null,
        class_type: form.class_type,
        rate_per_30min: form.rate_per_30min,
        duration_hours: form.duration_hours,
        payment_type: form.payment_type || 'session',
        sessions_purchased: sessionsPurchased,
        remaining_sessions: isSessionPayment ? sessionsPurchased : remaining,
        settlement_day: form.payment_type === 'monthly' ? form.settlement_day : null,
        monthly_sessions: form.payment_type === 'monthly' ? form.monthly_sessions : null,
        first_class_date: derivedFirstClassDate,
        days_of_week: daysOfWeek,
        start_time: form.start_time,
        end_time: endTime,
        room_id: form.room_id || null,
        Memo: form.memo || null,
        skip_auto_sessions: isSessionPayment,
      };
      const res = await fetch('/api/v1/student-classes', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify(body)
      });
      if (res.ok) {
        const createdBody = await res.json().catch(() => ({}));
        const createdEntity = createdBody?.data ?? createdBody?.course ?? createdBody ?? {};
        createdCourseId = createdEntity?.ID ?? createdEntity?.id ?? createdBody?.ID ?? createdBody?.id ?? null;
        created = true;
      } else {
        const err = await res.json().catch(() => ({}));
        alert('補登失敗: ' + (err?.message || '請稍後再試'));
        return;
      }
    }
  } catch (e) {
    alert('補登失敗：' + (e?.message || '請稍後再試'));
    return;
  }

  if (!created) {
    const insertPayload = {
      student_id: form.student_id,
      branch_id: props.branchId,
      subject: form.subject,
      teacher_id: form.teacher_id || null,
      class_type: form.class_type,
      rate_per_30min: form.rate_per_30min,
      duration_hours: form.duration_hours,
      payment_type: form.payment_type || 'session',
      sessions_purchased: sessionsPurchased,
      remaining_sessions: remaining,
      start_time: form.start_time,
      end_time: endTime,
      days_of_week: daysOfWeek.length ? daysOfWeek : undefined,
      day_of_week: daysOfWeek.length ? daysOfWeek[0] : 0
    };
    if (derivedFirstClassDate) insertPayload.first_class_date = derivedFirstClassDate;
    if (form.room_id != null) insertPayload.room_id = form.room_id;
    if (form.memo) insertPayload.memo = form.memo;
    const { error } = await supabase.from('student-classes').insert([insertPayload]);
    if (error) {
      alert('補登失敗: ' + error.message);
      return;
    }
  }

  // 2. 若走 Laravel API，將勾選的歷史日期直接補登為已核准並扣堂
  if (usedApi && isSessionPayment && selectedLegacyDates.length > 0 && (!createdCourseId || !token)) {
    alert('課程已建立，但無法取得課程 ID，請重新整理後確認剩餘堂數。');
    return;
  }

  if (usedApi && isSessionPayment && selectedLegacyDates.length > 0 && createdCourseId && token) {
    if (!directorId) {
      const meRes = await fetch('/api/v1/me', {
        credentials: 'include',
        headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
      }).catch(() => null);
      if (meRes?.ok) {
        const me = await meRes.json().catch(() => ({}));
        directorId = me?.id ?? null;
      }
    }
    if (!directorId) {
      alert('課程已建立，但無法取得操作者身分，請重新登入後再補登上課日期。');
      return;
    }
    const bulkRes = await fetch('/api/v1/learning-records/bulk-backdoor-approve', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({
        StudentClassID: createdCourseId,
        TeacherID: Number(form.teacher_id),
        DirectorID: Number(directorId),
        session_dates: selectedLegacyDates,
      })
    });
    if (!bulkRes.ok) {
      const bulkErr = await bulkRes.json().catch(() => ({}));
      alert('課程已建立，但補登上課日期失敗：' + (bulkErr?.message || '請到學習評量頁補登'));
      return;
    }

    // 以使用者勾選日期為最終依據，確保剩餘堂數與補登堂數一致。
    const syncRemainingRes = await fetch(`/api/v1/student-classes/${createdCourseId}`, {
      method: 'PUT',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ remaining_sessions: remaining })
    }).catch(() => null);
    if (!syncRemainingRes?.ok) {
      alert('課程與補登日期已建立，但剩餘堂數同步失敗，請重新整理後檢查。');
      return;
    }
  }

  // 3. Update student remaining lessons
  await supabase.from('students')
    .update({ remaining_lessons: remaining })
    .eq('id', form.student_id);

  alert('補登成功！');
  showBackfillModal.value = false;
  resetBackfillDatePicker();
  backfillForm.value = {
    student_id: '', subject: 'Math', teacher_id: '', class_type: 'one_on_one',
    rate_per_30min: 500, duration_hours: 2,
    payment_type: 'session', sessions_purchased: 8, sessions_used: 0,
    settlement_day: null, monthly_sessions: null,
    days_of_week: [],
    start_time: '16:00', end_time: '18:00',
    room_id: null, memo: ''
  };
  loadCourses();
};

watch(() => props.branchId, () => { loadCourses(); loadStudents(); loadTeachers(); });
watch(() => props.initialTeacherId, (id) => {
  if (id != null && id !== '') {
    filters.value.teacher_id = Number(id) || id;
    loadCourses();
    emit('clear-initial-teacher');
  }
}, { immediate: true });
onMounted(() => { loadCourses(); loadStudents(); loadTeachers(); });
</script>

<style scoped>
.course-page {
  width: 100%;
  max-width: 1280px;
  margin: 0 auto;
  padding: 0 12px 24px;
  box-sizing: border-box;
}

/* ----- Page header ----- */
.course-header-card {
  padding: 18px;
  border-radius: 16px;
  border: 1px solid rgba(99, 102, 241, 0.18);
  background:
    radial-gradient(140% 120% at 0% 0%, rgba(99, 102, 241, 0.12) 0%, rgba(255, 255, 255, 0) 48%),
    var(--card-bg);
  box-shadow: 0 12px 30px rgba(15, 23, 42, 0.07);
}

.header-actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
}

.page-title-block {
  min-width: 0;
}

.page-title {
  font-size: 1.35rem;
  font-weight: 700;
  color: var(--text);
  margin-bottom: 4px;
  letter-spacing: 0.02em;
}

.ref-hint {
  color: var(--text-light);
  font-size: 13px;
  margin-top: 3px;
}

.meta-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 10px;
}

.meta-pill {
  display: inline-flex;
  align-items: center;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
  color: #334155;
  background: rgba(255, 255, 255, 0.7);
  border: 1px solid rgba(148, 163, 184, 0.35);
}

.header-buttons {
  display: flex;
  gap: 8px;
  flex-shrink: 0;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.btn-soft {
  border: 1px solid rgba(100, 116, 139, 0.28);
  background: rgba(255, 255, 255, 0.86);
  color: #334155;
  border-radius: 10px;
  padding: 9px 14px;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: var(--transition);
}

.btn-soft:hover {
  border-color: rgba(79, 70, 229, 0.35);
  color: #3730a3;
  transform: translateY(-1px);
}

.btn-accent {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: linear-gradient(135deg, var(--accent), var(--primary));
  color: #fff;
  border: none;
  padding: 10px 18px;
  border-radius: 8px;
  font-weight: 600;
  font-size: 14px;
  cursor: pointer;
  transition: var(--transition);
  box-shadow: 0 2px 8px rgba(230, 81, 0, 0.25);
}

.btn-accent:hover {
  opacity: 0.95;
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(230, 81, 0, 0.35);
}

.btn-icon {
  font-size: 1em;
}

/* ----- Filters ----- */
.filter-bar {
  margin-top: 16px;
  padding: 14px;
  border: 1px solid rgba(148, 163, 184, 0.22);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.82);
}

.filter-bar.grid {
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
}

.filter-field label {
  font-size: 12px;
  font-weight: 600;
  color: var(--text-light);
  margin-bottom: 6px;
}

.filter-field input,
.filter-field select {
  padding: 9px 12px;
  border-radius: 10px;
  font-size: 14px;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: #fff;
}

/* ----- Summary cards ----- */
.summary-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 14px;
  margin: 16px 0 18px;
}

.summary-card {
  background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
  border-radius: 14px;
  padding: 16px 14px;
  text-align: center;
  box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
  border: 1px solid rgba(148, 163, 184, 0.18);
  transition: var(--transition);
}

.summary-card:hover {
  box-shadow: var(--shadow-hover);
}

.summary-card.summary-total {
  border-color: rgba(99, 102, 241, 0.35);
  background: linear-gradient(135deg, #ffffff 0%, #eef2ff 100%);
}

.summary-label {
  display: block;
  font-size: 12px;
  color: var(--text-light);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.summary-value {
  display: block;
  font-size: 1.6rem;
  font-weight: 800;
  color: var(--text);
  margin-top: 6px;
}

.summary-total .summary-value {
  color: var(--primary);
}

/* ----- Subject stats ----- */
.subject-stats-card {
  margin-bottom: 16px;
  padding: 14px 16px;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.18);
}

.subject-stats-header {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  margin-bottom: 8px;
}

.subject-stats-title {
  font-size: 13px;
  font-weight: 700;
  color: var(--text);
}

.subject-stats-total {
  font-size: 12px;
  color: var(--text-light);
}

.subject-stats-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.subject-stat-chip {
  font-size: 12px;
  padding: 4px 10px;
  border-radius: 999px;
  background: var(--primary-bg);
  color: var(--primary);
}

/* ----- Table ----- */
.table-card {
  padding: 0;
  overflow: hidden;
  border-radius: 16px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  box-shadow: 0 14px 34px rgba(15, 23, 42, 0.07);
}

.table-wrap {
  overflow-x: auto;
  max-height: 70vh;
  overflow-y: auto;
}

.grouped-course-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 10px;
}

.student-group-card {
  border: 1px solid rgba(148, 163, 184, 0.22);
  border-radius: 14px;
  overflow: hidden;
  background: var(--card-bg);
  box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
}

.student-group-header {
  width: 100%;
  border: none;
  background: linear-gradient(180deg, #eef2ff 0%, #fff 92%);
  padding: 12px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  cursor: pointer;
}

.student-group-header:hover {
  background: var(--primary-bg);
}

.student-group-left {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.expand-indicator {
  color: var(--text-light);
  font-size: 12px;
  width: 12px;
  text-align: center;
}

.student-group-meta {
  font-size: 12px;
  color: var(--text-light);
  font-weight: 600;
  white-space: nowrap;
}

.group-table-wrap {
  border-top: 1px solid var(--border);
  max-height: 56vh;
}

.course-table {
  width: 100%;
  min-width: 840px;
  border-collapse: collapse;
  font-size: 12.5px;
}

.course-table thead {
  position: sticky;
  top: 0;
  z-index: 2;
  background: rgba(248, 250, 252, 0.95);
  backdrop-filter: blur(6px);
  border-bottom: 1px solid rgba(99, 102, 241, 0.25);
}

.course-table th {
  padding: 12px 10px;
  text-align: left;
  font-weight: 700;
  color: #334155;
  white-space: nowrap;
}

.course-table td {
  padding: 9px 10px;
  border-bottom: 1px solid rgba(226, 232, 240, 0.85);
  vertical-align: middle;
  word-break: keep-all;
  line-height: 1.4;
}

.course-row:hover {
  background: #f8fafc;
}

.cell-student {
  font-weight: 600;
  color: var(--text);
}

.subject-tag {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  background: var(--primary-bg);
  color: var(--primary);
}

.cell-fee {
  font-weight: 600;
  color: var(--text);
}

.cell-total {
  font-weight: 700;
  color: var(--primary);
}

.cell-schedule {
  font-size: 12px;
  color: var(--text);
  word-break: keep-all;
  min-width: 100px;
}

.cell-remaining {
  font-weight: 700;
}

.cell-remaining.low {
  color: var(--danger);
}

.col-actions,
.cell-actions {
  white-space: nowrap;
  min-width: 130px;
}

.action-btns {
  display: flex;
  gap: 4px 6px;
  flex-wrap: nowrap;
  align-items: center;
}

.action-btns-compact .small {
  padding: 5px 9px;
  font-size: 11.5px;
  white-space: nowrap;
  border-radius: 999px;
  border: 1px solid rgba(148, 163, 184, 0.26);
  background: #fff;
}

.action-btns-compact .action-main {
  border-color: rgba(59, 130, 246, 0.32);
  color: #1d4ed8;
}

.btn-status {
  cursor: pointer;
}

.btn-toggle {
  white-space: nowrap;
  border-radius: 999px;
  border: 1px solid rgba(148, 163, 184, 0.28);
  background: #fff;
  color: #334155;
}

/* ----- Empty state ----- */
.empty-state {
  padding: 48px 24px;
  text-align: center;
}

.empty-icon {
  font-size: 3rem;
  margin-bottom: 12px;
  opacity: 0.6;
}

.empty-title {
  font-size: 1.1rem;
  font-weight: 700;
  color: var(--text);
  margin-bottom: 8px;
}

.empty-desc {
  font-size: 14px;
  color: var(--text-light);
  max-width: 360px;
  margin: 0 auto;
  line-height: 1.6;
}

/* ----- Modals ----- */
.course-modal {
  width: 100%;
  max-width: 560px;
  max-height: 90vh;
  overflow-y: auto;
}

.modal-title {
  font-size: 1.15rem;
  font-weight: 700;
  color: var(--text);
  margin-bottom: 8px;
}

.modal-desc {
  color: var(--text-light);
  font-size: 13px;
  margin-bottom: 20px;
  line-height: 1.6;
}

.form-section {
  margin-bottom: 20px;
}

.form-section-title {
  font-size: 12px;
  font-weight: 700;
  color: var(--text-light);
  text-transform: uppercase;
  letter-spacing: 0.06em;
  margin-bottom: 12px;
  padding-bottom: 6px;
  border-bottom: 1px solid var(--border);
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 16px;
}

.form-textarea {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid var(--border);
  border-radius: 8px;
  resize: vertical;
  font-size: 14px;
  font-family: inherit;
}

.text-red { color: var(--danger) !important; }
.text-secondary { color: var(--text-light); font-size: 0.9rem; }
.computed-end-time { margin: 0; font-weight: 600; font-size: 1rem; }

.small.danger {
  background: #fef2f2;
  color: #b91c1c;
  border: 1px solid #fecaca;
}
.small.danger:hover { background: #fee2e2; color: #991b1b; }

.status-tag.one_on_one { background: #FFF3E0; color: #E65100; }
.status-tag.one_on_two { background: #FFF8E1; color: #F57F17; }
.status-tag.one_on_three { background: #FBE9E7; color: #BF360C; }
.status-tag.tutoring { background: #E8F5E9; color: #2E7D32; }

.legacy-box {
  background: #FFF8E1;
  border: 1px solid #FFE082;
  border-radius: 10px;
  padding: 16px;
  margin-top: 16px;
}

.legacy-box h4 {
  font-size: 13px;
  font-weight: 700;
  margin-bottom: 12px;
  color: #5D4037;
}
.backfill-import-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}

.legacy-grid {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 16px;
}

.remaining-display {
  font-size: 28px;
  font-weight: 800;
  color: var(--primary);
  padding: 6px 0;
  text-align: center;
}

.preview-dates-box {
  margin-top: 14px;
  padding: 12px;
  background: #E8F5E9;
  border: 1px solid #A5D6A7;
  border-radius: 8px;
}
.preview-dates-box h4 { font-size: 13px; margin-bottom: 6px; color: #2E7D32; }
.backfill-calendar-box {
  margin-top: 12px;
  border: 1px solid #ffd180;
  background: #fffdf7;
  border-radius: 10px;
  padding: 10px;
}
.backfill-calendar-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.backfill-calendar-weekdays,
.backfill-calendar-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 6px;
}
.backfill-calendar-weekdays {
  font-size: 12px;
  color: var(--text-light);
  text-align: center;
  margin-bottom: 6px;
}
.calendar-day {
  border: 1px solid #ffe0b2;
  border-radius: 8px;
  background: #fff;
  min-height: 34px;
  font-size: 12px;
  color: var(--text);
  cursor: pointer;
}
.calendar-day.muted {
  opacity: 0.55;
}
.calendar-day.selected {
  background: #e8f5e9;
  border-color: #81c784;
  color: #2e7d32;
  font-weight: 700;
}
.calendar-day.disabled {
  background: #f5f5f5;
  color: #9e9e9e;
  border-color: #eeeeee;
  cursor: not-allowed;
}
.backfill-calendar-actions {
  margin-top: 8px;
  display: flex;
  justify-content: flex-end;
}

@media (max-width: 640px) {
  .course-page {
    padding: 0 8px 16px;
  }

  .header-actions {
    flex-direction: column;
    align-items: stretch;
  }

  .header-buttons {
    justify-content: flex-start;
  }

  .summary-row {
    grid-template-columns: repeat(2, 1fr);
  }
  .form-grid {
    grid-template-columns: 1fr;
  }
  .legacy-grid {
    grid-template-columns: 1fr;
  }
  .col-actions,
  .cell-actions {
    min-width: 110px;
  }
  .action-btns-compact .small {
    padding: 4px 6px;
    font-size: 11px;
  }
}
.preview-dates-box .hint-small { font-size: 12px; margin-top: 6px; color: #558B2F; }
.preview-dates-list {
  font-size: 13px;
  color: var(--text);
  word-break: break-all;
  margin-top: 6px;
}

.day-chips {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  padding: 6px 0;
}
.day-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border: 2px solid var(--border);
  cursor: pointer;
  font-weight: 700;
  font-size: 14px;
  color: var(--text-light);
  transition: var(--transition);
  user-select: none;
}
.day-chip.selected {
  background: var(--primary);
  color: #fff;
  border-color: var(--primary);
}

.dates-row td { padding: 0; }
.dates-panel {
  background: #f8fbff;
  border-top: 1px solid rgba(148, 163, 184, 0.24);
  padding: 12px 16px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  font-size: 13px;
}
.date-chip {
  background: #fff;
  border: 1px solid #bfdbfe;
  border-radius: 999px;
  padding: 4px 10px;
  font-size: 12px;
  color: #1d4ed8;
  white-space: nowrap;
  cursor: help;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.date-chip-clickable {
  cursor: pointer;
}
.date-chip:hover {
  transform: translateY(-1px);
  box-shadow: 0 6px 14px rgba(15, 23, 42, 0.08);
}
/* Session Edit Modal */
.session-edit-modal .session-edit-info {
  background: #f8fafc; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; display: flex; flex-direction: column; gap: 6px;
}
.session-edit-modal .se-row { display: flex; align-items: center; gap: 8px; font-size: 0.93em; }
.session-edit-modal .se-label { font-weight: 600; color: #475569; min-width: 70px; }
.se-status-badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 0.85em; font-weight: 600; }
.se-st-scheduled { background: #e0f2fe; color: #0369a1; }
.se-st-attended, .se-st-late { background: #dcfce7; color: #166534; }
.se-st-absent { background: #fee2e2; color: #991b1b; }
.se-st-excused, .se-st-leave { background: #fef3c7; color: #92400e; }
.se-st-leave_adjusted { background: #ffedd5; color: #9a3412; }
.se-st-cancelled { background: #f3f4f6; color: #6b7280; }
.se-section-title { font-size: 0.95em; font-weight: 600; color: #334155; margin: 0 0 10px; }
.se-action-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; }
.se-action-grid-compact { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.se-secondary-action { margin-top: 10px; }
.se-secondary-action label { display: block; font-size: 0.85em; color: #64748b; margin-bottom: 6px; }
.se-secondary-row { display: flex; gap: 8px; align-items: center; }
.se-secondary-row select { flex: 1; }
.se-action-btn {
  padding: 8px 12px; border-radius: 8px; border: 1px solid #e2e8f0; background: #fff;
  font-size: 0.88em; font-weight: 500; cursor: pointer; text-align: center;
  transition: all 0.15s ease;
}
.se-action-btn:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.se-btn-attended { border-color: #86efac; color: #166534; } .se-btn-attended:hover { background: #dcfce7; }
.se-btn-late { border-color: #fbbf24; color: #92400e; } .se-btn-late:hover { background: #fef3c7; }
.se-btn-absent { border-color: #fca5a5; color: #991b1b; } .se-btn-absent:hover { background: #fee2e2; }
.se-btn-excused { border-color: #fde68a; color: #78350f; } .se-btn-excused:hover { background: #fef9c3; }
.se-btn-leave { border-color: #fcd34d; color: #b45309; } .se-btn-leave:hover { background: #fef3c7; }
.se-btn-retro-leave { border-color: #fb923c; color: #9a3412; } .se-btn-retro-leave:hover { background: #ffedd5; }
.se-btn-scheduled { border-color: #93c5fd; color: #1d4ed8; } .se-btn-scheduled:hover { background: #dbeafe; }
.se-btn-cancelled { border-color: #d1d5db; color: #6b7280; } .se-btn-cancelled:hover { background: #f3f4f6; }
.se-btn-reschedule { border-color: #a78bfa; color: #6d28d9; } .se-btn-reschedule:hover { background: #ede9fe; }
.se-loading { text-align: center; color: #64748b; padding: 8px 0; font-size: 0.9em; }
.session-edit-reschedule .se-reschedule-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.session-edit-reschedule .se-reschedule-grid .form-group:last-child { grid-column: 1 / -1; }
.date-chip.completed {
  background: #e8f5e9;
  border-color: #a5d6a7;
  color: #2e7d32;
}
.date-chip.leave {
  background: #fff1e0;
  border-color: #fb923c;
  color: #9a3412;
  font-weight: 600;
}
.date-chip.absent {
  background: #ffebee;
  border-color: #ef9a9a;
  color: #c62828;
}
.date-chip.cancelled {
  background: #f3f4f6;
  border-color: #d1d5db;
  color: #6b7280;
}
.course-paused {
  opacity: 0.55;
}
.course-paused:hover {
  opacity: 0.85;
}
.tag-paused {
  background: #fef3c7;
  color: #92400e;
  border: 1px solid #fbbf24;
  border-radius: 6px;
  font-size: 11px;
  padding: 1px 6px;
  margin-left: 4px;
  font-weight: 600;
}
.tag-paid {
  background: #e8f5e9;
  color: #2e7d32;
  border: 1px solid #a5d6a7;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  padding: 3px 10px;
}
.tag-unpaid {
  background: #fff3e0;
  color: #e65100;
  border: 1px solid #ffcc80;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  padding: 3px 10px;
}
.tag-armed {
  background: #ffebee;
  color: #c62828;
  border: 1px solid #ef9a9a;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  padding: 3px 10px;
}

/* ----- Makeup Slots ----- */
.btn-makeup-query {
  width: 100%;
  padding: 8px 12px !important;
  font-size: 13px !important;
  font-weight: 600;
  border: 1px dashed var(--primary) !important;
  color: var(--primary) !important;
  border-radius: 8px;
  transition: var(--transition);
}
.btn-makeup-query:hover:not(:disabled) {
  background: var(--primary-bg) !important;
}
.btn-makeup-query:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.makeup-range-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 14px;
}
.makeup-range-bar label {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-light);
  white-space: nowrap;
}
.makeup-range-bar select {
  padding: 6px 10px;
  border-radius: 6px;
  font-size: 13px;
  flex: 1;
}
.makeup-status {
  text-align: center;
  padding: 28px 16px;
  color: var(--text-light);
  font-size: 14px;
}
.makeup-slots-list {
  max-height: 380px;
  overflow-y: auto;
  border: 1px solid var(--border);
  border-radius: 8px;
  margin-bottom: 16px;
}
.makeup-date-group + .makeup-date-group {
  border-top: 1px solid var(--border);
}
.makeup-date-header {
  padding: 8px 12px;
  font-size: 13px;
  font-weight: 700;
  background: var(--primary-bg);
  color: var(--text);
  position: sticky;
  top: 0;
  z-index: 1;
}
.makeup-slot-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 12px;
  border-top: 1px solid #f0f0f0;
  font-size: 13px;
}
.makeup-slot-row:first-child {
  border-top: none;
}
.makeup-slot-row:hover {
  background: #fafafa;
}
.slot-info {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  min-width: 0;
  flex: 1;
}
.slot-time {
  color: var(--text);
  font-weight: 500;
}
.slot-capacity {
  font-size: 12px;
  font-weight: 600;
  padding: 1px 8px;
  border-radius: 10px;
}
.slot-capacity.cap-free {
  background: #e8f5e9;
  color: #2e7d32;
}
.slot-capacity.cap-partial {
  background: #fff3e0;
  color: #e65100;
}
.slot-students {
  font-size: 11px;
  color: var(--text-light);
  flex-basis: 100%;
}
.slot-has-students {
  background: #fffde7;
}
</style>
