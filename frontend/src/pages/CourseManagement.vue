<template>
  <div>
    <HelpGuide
      title="課程管理 — 使用說明"
      :items="[
        '此頁為<strong>所有學生課程</strong>總覽，可依學生姓名、上課類型、老師篩選。',
        '「補登舊資料」：系統上線前已上課的學生，可在此快速登錄課程與剩餘堂數。',
        '每筆課程可編輯費率、排課時段、繳費方式；堂數制剩餘堂數會隨出缺勤點名自動扣除。'
      ]"
      tip="新系統上線時建議先用「補登舊資料」將既有學生課程與堂數補齊。"
    />

    <!-- Top Bar -->
    <div class="card course-header-card">
      <div class="header-actions">
        <div class="page-title-block">
          <h2 class="page-title">課程管理</h2>
          <p class="ref-hint">管理所有學生的課程安排，支援舊資料補登</p>
        </div>
        <div class="header-buttons">
          <button class="btn-accent" @click="openBackfillModal">
            <span class="btn-icon">📥</span> 補登舊資料
          </button>
        </div>
      </div>

      <!-- Filters -->
      <div class="filter-bar grid">
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
    <div class="card table-card">
      <div v-if="courses.length" class="table-wrap">
        <table class="course-table">
          <thead>
            <tr>
              <th>學生</th>
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
            <template v-for="c in courses" :key="c.id">
              <tr class="course-row">
                <td><span class="cell-student">{{ c.student_name }}</span></td>
                <td><span class="tag subject-tag">{{ getSubjectLabel(c.subject) }}</span></td>
                <td>{{ c.teacher_name || '待指派' }}</td>
                <td>
                  <span class="status-tag" :class="c.class_type">{{ classTypeLabel(c.class_type) }}</span>
                </td>
                <td class="cell-fee">${{ sessionPrice(c) }}</td>
                <td class="cell-total">${{ totalPrice(c) }}</td>
                <td class="cell-schedule">
                  <span v-if="(c.days_of_week || []).length > 0">
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
                    :class="['small', 'btn-status', c.payment_status === 'paid' ? 'tag-paid' : 'tag-unpaid']"
                    @click="togglePaymentStatus(c)"
                  >{{ c.payment_status === 'paid' ? '已繳費' : '未繳費' }}</button>
                </td>
                <td :class="{ 'cell-remaining': true, 'low': (c.remaining_sessions ?? 0) <= 2 }">
                  {{ c.remaining_sessions ?? '—' }}
                </td>
                <td>
                  <button class="small ghost btn-toggle" @click="toggleDates(c)">
                    {{ expandedDates.has(c.id) ? '收起' : '查看' }}
                  </button>
                </td>
                <td class="cell-actions">
                  <div class="action-btns">
                    <button class="small ghost" @click="openLeave(c)">請假</button>
                    <button class="small ghost" @click="openReschedule(c)">調課</button>
                    <button class="small ghost" @click="editCourse(c)">編輯</button>
                    <button class="small danger" @click="deleteCourse(c)">刪除</button>
                  </div>
                </td>
              </tr>
              <tr v-if="expandedDates.has(c.id)" class="dates-row">
                <td colspan="13">
                  <div class="dates-panel">
                    <strong>上課日期（共 {{ sessions(c).length }} 堂）：</strong>
                    <span v-if="sessions(c).length === 0" class="hint">無法計算（請確認排課設定）</span>
                    <span v-for="(d, i) in sessions(c)" :key="d" class="date-chip">
                      第{{ i + 1 }}堂 {{ d }}
                    </span>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
      <div v-else class="empty-state">
        <div class="empty-icon">📋</div>
        <p class="empty-title">目前尚無課程資料</p>
        <p class="empty-desc">請在「學生管理」為學生建立課程，或使用上方「補登舊資料」快速登錄既有課程。</p>
      </div>
    </div>

    <!-- Backfill Modal (aligned with edit form + 堂數資料 block) -->
    <div v-if="showBackfillModal" class="modal-overlay" @click.self="showBackfillModal = false">
      <div class="modal course-modal">
        <h3 class="modal-title">補登舊資料（系統建立前）</h3>
        <p class="modal-desc">
          適用於系統上線前已在上課的學生。快速登錄課程資訊與目前剩餘堂數，不需逐筆補寫評量表。
        </p>

        <div class="form-section">
          <h4 class="form-section-title">基本資料</h4>
          <div class="form-grid">
          <div class="form-group">
            <label>學生</label>
            <select v-model="backfillForm.student_id">
              <option value="">請選擇</option>
              <option v-for="s in allStudents" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>科目</label>
            <select v-model="backfillForm.subject">
              <option v-for="s in SUBJECTS" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>老師</label>
            <select v-model="backfillForm.teacher_id">
              <option value="">請選擇</option>
              <option v-for="t in teachers" :key="t.id" :value="t.id">{{ t.username }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>類型</label>
            <select v-model="backfillForm.class_type">
              <option value="one_on_one">一對一</option>
              <option value="one_on_two">一對二</option>
              <option value="one_on_three">一對三</option>
              <option value="tutoring">輔導</option>
            </select>
          </div>
          <div class="form-group">
            <label>開課日</label>
            <input v-model="backfillForm.first_class_date" type="date" />
          </div>
          <div class="form-group">
            <label>每堂費用（元）</label>
            <input v-model.number="backfillForm.rate_per_30min" type="number" placeholder="500" />
          </div>
          <div class="form-group">
            <label>上課時長（小時）</label>
            <select v-model.number="backfillForm.duration_hours">
              <option :value="1">1 小時</option>
              <option :value="1.5">1.5 小時</option>
              <option :value="2">2 小時</option>
              <option :value="2.5">2.5 小時</option>
              <option :value="3">3 小時</option>
            </select>
          </div>
          <div class="form-group">
            <label>繳費方式</label>
            <select v-model="backfillForm.payment_type">
              <option value="session">堂數制</option>
              <option value="monthly">月結</option>
            </select>
          </div>
          <div class="form-group" v-if="backfillForm.payment_type === 'session'">
            <label>購買堂數</label>
            <input v-model.number="backfillForm.sessions_purchased" type="number" placeholder="8" />
          </div>
          <template v-if="backfillForm.payment_type === 'monthly'">
            <div class="form-group">
              <label>結算日（每月幾號）</label>
              <select v-model.number="backfillForm.settlement_day">
                <option :value="null">請選擇</option>
                <option v-for="d in settlementDayOptions" :key="d" :value="d">每月 {{ d }} 號</option>
              </select>
            </div>
            <div class="form-group">
              <label>每月堂數（選填）</label>
              <input v-model.number="backfillForm.monthly_sessions" type="number" placeholder="依學生" min="0" />
            </div>
          </template>
          </div>
          <h4 class="form-section-title">排課與地點</h4>
          <div class="form-grid">
          <div class="form-group" style="grid-column: 1 / -1;">
            <label>排課日（可多選）</label>
            <div class="day-chips">
              <label v-for="d in DAY_OPTIONS" :key="d.value"
                :class="['day-chip', { selected: (backfillForm.days_of_week || []).includes(d.value) }]">
                <input type="checkbox" :value="d.value" v-model="backfillForm.days_of_week"
                  style="position:absolute;opacity:0;width:0;height:0;pointer-events:none;" />
                {{ d.label }}
              </label>
            </div>
          </div>
          <div class="form-group">
            <label>開始時間</label>
            <select v-model="backfillForm.start_time">
              <option v-for="t in TIME_OPTIONS_30" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
          <div class="form-group">
            <label class="text-secondary">結束時間</label>
            <p class="computed-end-time">{{ computeEndTime(backfillForm.start_time, backfillForm.duration_hours) || '—' }}</p>
          </div>
          <div class="form-group" style="grid-column: span 2;">
            <label>上課地點（教室）</label>
            <select v-model="backfillForm.room_id">
              <option :value="null">請選擇教室</option>
              <option v-for="r in rooms" :key="r.id" :value="r.id">{{ r.name }}{{ r.memo ? ' — ' + r.memo : '' }}</option>
            </select>
          </div>
          <div class="form-group" style="grid-column: span 2;">
            <label>備註（選填）</label>
            <textarea v-model="backfillForm.memo" rows="2" placeholder="課程或地點補充" class="form-textarea"></textarea>
          </div>
          </div>
        </div>

        <!-- Legacy Sessions Section (補登專用) -->
        <div class="legacy-box">
          <h4>堂數資料（補登用）</h4>
          <div class="legacy-grid">
            <div class="form-group">
              <label>已購買堂數</label>
              <input v-model.number="backfillForm.sessions_purchased" type="number" placeholder="8" />
            </div>
            <div class="form-group">
              <label>已上堂數</label>
              <input v-model.number="backfillForm.sessions_used" type="number" placeholder="3" />
            </div>
            <div class="form-group">
              <label>剩餘堂數（自動計算）</label>
              <div class="remaining-display">
                {{ Math.max(0, (backfillForm.sessions_purchased || 0) - (backfillForm.sessions_used || 0)) }}
              </div>
            </div>
          </div>
          <p class="hint">系統會直接將剩餘堂數寫入學生資料，不需補寫歷史評量表。</p>
          <!-- 過去上課日預覽：依開課日+排課日+已上堂數 -->
          <div v-if="backfillPreviewDates.length > 0" class="preview-dates-box">
            <h4>📅 過去上課日預覽（系統上線前）</h4>
            <p class="hint">依上方「開課日」與「排課日」、以及「已上堂數」，推算出的前 {{ backfillPreviewDates.length }} 堂上課日期，可對照手邊紀錄確認補登是否正確。<strong>此為固定排課推算，不含請假／調課。</strong></p>
            <p class="hint hint-small">若當時有請假或調課：可先在此完成課程補登，之後到「評量／學習紀錄」頁使用「一鍵補登」，系統會依該課程的請假與調課紀錄列出實際可補登的日期，再勾選送出即可。</p>
            <div class="preview-dates-list">{{ backfillPreviewDates.join('、') }}</div>
          </div>
        </div>

        <div class="actions">
          <button class="ghost" @click="showBackfillModal = false">取消</button>
          <button class="primary" @click="submitBackfill">確認補登</button>
        </div>
      </div>
    </div>

    <!-- Edit Course Modal -->
    <div v-if="showEditModal" class="modal-overlay" @click.self="showEditModal = false">
      <div class="modal course-modal">
        <h3 class="modal-title">編輯課程</h3>
        <div class="form-section">
          <div class="form-grid">
          <div class="form-group">
            <label>科目</label>
            <select v-model="editForm.subject">
              <option v-for="s in SUBJECTS" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>老師</label>
            <select v-model="editForm.teacher_id">
              <option value="">請選擇</option>
              <option v-for="t in teachers" :key="t.id" :value="t.id">{{ t.username }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>開課日</label>
            <input v-model="editForm.first_class_date" type="date" />
          </div>
          <div class="form-group">
            <label>類型</label>
            <select v-model="editForm.class_type">
              <option value="one_on_one">一對一</option>
              <option value="one_on_two">一對二</option>
              <option value="one_on_three">一對三</option>
              <option value="tutoring">輔導</option>
            </select>
          </div>
          <div class="form-group">
            <label>每堂費用（元）</label>
            <input v-model.number="editForm.rate_per_30min" type="number" placeholder="每堂費用" />
          </div>
          <div class="form-group">
            <label>上課時長（小時）</label>
            <select v-model.number="editForm.duration_hours">
              <option :value="1">1 小時</option>
              <option :value="1.5">1.5 小時</option>
              <option :value="2">2 小時</option>
              <option :value="2.5">2.5 小時</option>
              <option :value="3">3 小時</option>
            </select>
          </div>
          <div class="form-group">
            <label>繳費方式</label>
            <select v-model="editForm.payment_type">
              <option value="session">堂數制</option>
              <option value="monthly">月結</option>
            </select>
          </div>
          <div class="form-group" v-if="editForm.payment_type === 'session'">
            <label>購買堂數</label>
            <input v-model.number="editForm.sessions_purchased" type="number" placeholder="8" />
          </div>
          <template v-if="editForm.payment_type === 'monthly'">
            <div class="form-group">
              <label>結算日（每月幾號）</label>
              <select v-model.number="editForm.settlement_day">
                <option :value="null">請選擇</option>
                <option v-for="d in settlementDayOptions" :key="d" :value="d">每月 {{ d }} 號</option>
              </select>
            </div>
            <div class="form-group">
              <label>每月堂數（選填）</label>
              <input v-model.number="editForm.monthly_sessions" type="number" placeholder="依學生" min="0" />
            </div>
          </template>
          <div class="form-group">
            <label>剩餘堂數</label>
            <input v-model.number="editForm.remaining_sessions" type="number" />
          </div>
          <div class="form-group" style="grid-column: 1 / -1;">
            <label>排課日（可多選）</label>
            <div class="day-chips">
              <label v-for="d in DAY_OPTIONS" :key="d.value"
                :class="['day-chip', { selected: (editForm.days_of_week || []).includes(d.value) }]">
                <input type="checkbox" :value="d.value" v-model="editForm.days_of_week"
                  style="position:absolute;opacity:0;width:0;height:0;pointer-events:none;" />
                {{ d.label }}
              </label>
            </div>
          </div>
          <div class="form-group">
            <label>開始時間</label>
            <select v-model="editForm.start_time">
              <option v-for="t in TIME_OPTIONS_30" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
          <div class="form-group">
            <label class="text-secondary">結束時間</label>
            <p class="computed-end-time">{{ computeEndTime(editForm.start_time, editForm.duration_hours) || '—' }}</p>
          </div>
          <div class="form-group" style="grid-column: span 2;">
            <label>上課地點（教室）</label>
            <select v-model="editForm.room_id">
              <option :value="null">請選擇教室</option>
              <option v-for="r in rooms" :key="r.id" :value="r.id">{{ r.name }}{{ r.memo ? ' — ' + r.memo : '' }}</option>
            </select>
          </div>
          <div class="form-group" style="grid-column: span 2;">
            <label>備註（選填）</label>
            <textarea v-model="editForm.memo" rows="2" placeholder="課程或地點補充" class="form-textarea"></textarea>
          </div>
          </div>
        </div>
        <div class="actions">
          <button class="ghost" @click="showEditModal = false">取消</button>
          <button class="primary" @click="submitEdit">儲存</button>
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
                第{{ opt.index }}堂 {{ opt.date }}
              </option>
            </select>
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
            <button class="primary" @click="submitLeave" :disabled="!leaveForm.schedule_date">確認請假</button>
          </div>
        </template>
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
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { supabase } from '../supabase';
import { SUBJECTS } from '../lib/constants';
import { computeSessionDatesForCourse, previewSessionDates } from '../lib/sessionDates';
import HelpGuide from '../components/HelpGuide.vue';

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

const props = defineProps({ branchId: [String, Number], initialTeacherId: [String, Number] });
const emit = defineEmits(['clear-initial-teacher']);

const courses = ref([]);
const allStudents = ref([]);
const teachers = ref([]);
const filters = ref({ name: '', class_type: '', teacher_id: '' });

// Backfill (aligned with edit form fields)
const showBackfillModal = ref(false);
const backfillForm = ref({
  student_id: '', subject: 'Math', teacher_id: '', class_type: 'one_on_one',
  rate_per_30min: 500, duration_hours: 2,
  payment_type: 'session', sessions_purchased: 8, sessions_used: 0,
  settlement_day: null, monthly_sessions: null,
  first_class_date: '', days_of_week: [],
  start_time: '16:00', end_time: '18:00',
  room_id: null, memo: ''
});

function openBackfillModal() {
  showBackfillModal.value = true;
  loadRoomsForBranch();
}

// Edit
const showEditModal = ref(false);
const editingId = ref(null);
const editingCourseFromLaravel = ref(false);
const editForm = ref({});
const rooms = ref([]);
const settlementDayOptions = Array.from({ length: 28 }, (_, i) => i + 1);

// Session dates expansion
const expandedDates = ref(new Set());
const toggleDates = (c) => {
  const s = new Set(expandedDates.value);
  if (s.has(c.id)) s.delete(c.id); else s.add(c.id);
  expandedDates.value = s;
};
const sessions = (c) => [...computeSessionDatesForCourse(c)].sort();

// ----- Leave (請假) -----
const showLeaveModal = ref(false);
const leaveCourse = ref(null);
const leaveForm = ref({
  student_id: '', student_name: '', subject: '', teacher_id: '', day_of_week: 1,
  start_time: '', end_time: '', duration_hours: 2, class_type: 'one_on_one',
  schedule_date: '', course_id: null
});
const leaveSessionOptions = computed(() => {
  const c = leaveCourse.value;
  if (!c) return [];
  const list = sessions(c);
  return list.map((date, i) => ({ date, index: i + 1 }));
});
function openLeave(c) {
  const list = sessions(c);
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
    course_id: c.id
  };
  showLeaveModal.value = true;
}
async function submitLeave() {
  if (!leaveForm.value.schedule_date) return;
  const branchId = Number(props.branchId) || 0;
  if (!branchId) { alert('請先選擇分校'); return; }
  const form = leaveForm.value;
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
  // 優先使用 Laravel API（正式環境多為 Laravel + MySQL）
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (token) {
      const res = await fetch('/api/v1/schedules', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify(payload)
      });
      if (res.ok) {
        showLeaveModal.value = false;
        leaveCourse.value = null;
        alert('請假登記完成');
        loadCourses();
        return;
      }
      const errBody = await res.json().catch(() => ({}));
      alert('請假登記失敗：' + (errBody.message || res.statusText || '請稍後再試'));
      return;
    }
  } catch (e) {
    // 無 token 或 API 失敗時改走 Supabase
  }
  const { error } = await supabase.from('schedules').insert([payload]);
  if (error) {
    alert('請假登記失敗：' + (error.message || '請稍後再試'));
    return;
  }
  showLeaveModal.value = false;
  leaveCourse.value = null;
  alert('請假登記完成');
  loadCourses();
}

// ----- Reschedule (調課) -----
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
  const list = sessions(c);
  return list.map((date, i) => ({ date, index: i + 1 }));
});
function openReschedule(c) {
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

// 補登 modal：依開課日+排課日+已上堂數預覽「過去上課日」
const backfillPreviewDates = computed(() => {
  const form = backfillForm.value;
  const first = form.first_class_date || '';
  const days = (form.days_of_week || []).length ? form.days_of_week : [];
  const count = Math.max(0, Number(form.sessions_used) || 0);
  return previewSessionDates(first, days, count);
});

const getSubjectLabel = (val) => SUBJECTS.find(s => s.value === val)?.label || val;
const classTypeLabel = (type) => {
  const map = { one_on_one: '一對一', one_on_two: '一對二', one_on_three: '一對三', tutoring: '輔導' };
  return map[type] || type;
};

const CLASS_CAPACITY = { one_on_one: 1, one_on_two: 2, one_on_three: 3, tutoring: 4 };
function getCapacityForClassType(type) { return CLASS_CAPACITY[type] ?? 1; }
const dayLabel = (d) => ['', '週一', '週二', '週三', '週四', '週五', '週六', '週日'][d] || '';

// 每堂費用 — 直接使用儲存的費率值（Rate 欄位代表每堂收費）
const sessionPrice = (c) => Number(c.rate_per_30min) || 0;
// 總費用 = 每堂費用 × 已購堂數
const totalPrice = (c) => sessionPrice(c) * (Number(c.sessions_purchased) || 0);

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

const loadCourses = async () => {
  if (!props.branchId) {
    courses.value = [];
    return;
  }
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
          student_name: c.student_name ?? '—',
          teacher_name: c.teacher_name ?? ''
        }));
        if (filters.value.name) {
          const q = filters.value.name.toLowerCase();
          result = result.filter(c => (c.student_name || '').toLowerCase().includes(q));
        }
        courses.value = result;
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
    student_name: c.student?.name || '—',
    teacher_name: c.teacher_name || c.teacher?.username || '',
    branch_name: null,
    room_name: null,
    settlement_day: null
  }));

  if (filters.value.name) {
    const q = filters.value.name.toLowerCase();
    result = result.filter(c => c.student_name.toLowerCase().includes(q));
  }

  courses.value = result;
};

const togglePaymentStatus = async (c) => {
  const newStatus = c.payment_status === 'paid' ? 'unpaid' : 'paid';
  if (c.branch_name != null || c.room_name != null || c.settlement_day != null) {
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
  const { data } = await supabase.from('students').select('id, name').eq('branch_id', props.branchId).order('name');
  allStudents.value = data || [];
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
  editingCourseFromLaravel.value = !!(c.branch_name != null || c.room_name != null);
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
          showEditModal.value = false;
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
  await supabase.from('student-classes').update({
    subject: form.subject, teacher_id: form.teacher_id || null, class_type: form.class_type,
    rate_per_30min: form.rate_per_30min, remaining_sessions: form.remaining_sessions,
    days_of_week: form.days_of_week, start_time: form.start_time, end_time: endTime,
    payment_type: form.payment_type
  }).eq('id', id);
  showEditModal.value = false;
  loadCourses();
};

const deleteCourse = async (c) => {
  if (!confirm('確定要刪除此課程？刪除後無法復原。')) return;
  const fromLaravel = c.branch_name != null || c.room_name != null;
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

  const form = backfillForm.value;
  const endTime = computeEndTime(form.start_time, form.duration_hours);
  const remaining = Math.max(0, (form.sessions_purchased || 0) - (form.sessions_used || 0));
  const daysOfWeek = (form.days_of_week || []).length ? form.days_of_week : [];

  // 1. Create course record: try Laravel API first, then Supabase
  let created = false;
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (token && props.branchId) {
      const body = {
        student_id: form.student_id,
        subject: form.subject,
        teacher_id: form.teacher_id || null,
        class_type: form.class_type,
        rate_per_30min: form.rate_per_30min,
        duration_hours: form.duration_hours,
        payment_type: form.payment_type || 'session',
        sessions_purchased: form.sessions_purchased,
        remaining_sessions: remaining,
        settlement_day: form.payment_type === 'monthly' ? form.settlement_day : null,
        monthly_sessions: form.payment_type === 'monthly' ? form.monthly_sessions : null,
        first_class_date: form.first_class_date || null,
        days_of_week: daysOfWeek,
        start_time: form.start_time,
        end_time: endTime,
        room_id: form.room_id || null,
        Memo: form.memo || null
      };
      const res = await fetch('/api/v1/student-classes', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify(body)
      });
      if (res.ok) {
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
      sessions_purchased: form.sessions_purchased,
      start_time: form.start_time,
      end_time: endTime,
      days_of_week: daysOfWeek.length ? daysOfWeek : undefined,
      day_of_week: daysOfWeek.length ? daysOfWeek[0] : 0
    };
    if (form.first_class_date) insertPayload.first_class_date = form.first_class_date;
    if (form.room_id != null) insertPayload.room_id = form.room_id;
    if (form.memo) insertPayload.memo = form.memo;
    const { error } = await supabase.from('student-classes').insert([insertPayload]);
    if (error) {
      alert('補登失敗: ' + error.message);
      return;
    }
  }

  // 2. Update student remaining lessons
  await supabase.from('students')
    .update({ remaining_lessons: remaining })
    .eq('id', form.student_id);

  alert('補登成功！');
  showBackfillModal.value = false;
  backfillForm.value = {
    student_id: '', subject: 'Math', teacher_id: '', class_type: 'one_on_one',
    rate_per_30min: 500, duration_hours: 2,
    payment_type: 'session', sessions_purchased: 8, sessions_used: 0,
    settlement_day: null, monthly_sessions: null,
    first_class_date: '', days_of_week: [],
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
/* ----- Page header ----- */
.course-header-card {
  padding-bottom: 20px;
}

.header-actions {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
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
  margin-top: 2px;
}

.header-buttons {
  display: flex;
  gap: 8px;
  flex-shrink: 0;
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
  margin-top: 20px;
  padding-top: 20px;
  border-top: 1px solid var(--border);
}

.filter-bar.grid {
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
}

.filter-field label {
  font-size: 12px;
  font-weight: 600;
  color: var(--text-light);
  margin-bottom: 6px;
}

.filter-field input,
.filter-field select {
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 14px;
}

/* ----- Summary cards ----- */
.summary-row {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 20px;
}

.summary-card {
  background: var(--card-bg);
  border-radius: var(--radius);
  padding: 18px 16px;
  text-align: center;
  box-shadow: var(--shadow);
  border-left: 4px solid var(--border);
  transition: var(--transition);
}

.summary-card:hover {
  box-shadow: var(--shadow-hover);
}

.summary-card.summary-total {
  border-left-color: var(--primary);
  background: linear-gradient(135deg, #fff 0%, var(--primary-bg) 100%);
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
  margin-bottom: 20px;
  padding: 14px 16px;
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
}

.table-wrap {
  overflow-x: auto;
  max-height: 70vh;
  overflow-y: auto;
}

.course-table {
  width: 100%;
  min-width: 920px;
  border-collapse: collapse;
  font-size: 13px;
}

.course-table thead {
  position: sticky;
  top: 0;
  z-index: 2;
  background: linear-gradient(180deg, var(--primary-bg) 0%, #fff 100%);
  border-bottom: 2px solid var(--primary);
}

.course-table th {
  padding: 14px 12px;
  text-align: left;
  font-weight: 700;
  color: var(--text);
  white-space: nowrap;
}

.course-table td {
  padding: 10px 12px;
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
  word-break: keep-all;
  line-height: 1.4;
}

.course-row:hover {
  background: var(--primary-bg);
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
  min-width: 180px;
}

.action-btns {
  display: flex;
  gap: 4px;
  flex-wrap: nowrap;
  align-items: center;
}

.action-btns .small {
  padding: 5px 8px;
  font-size: 12px;
  white-space: nowrap;
}

.btn-status {
  cursor: pointer;
}

.btn-toggle {
  white-space: nowrap;
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

@media (max-width: 640px) {
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
    min-width: 140px;
  }
  .action-btns {
    flex-wrap: wrap;
  }
  .action-btns .small {
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
  background: #F3F8FF;
  border-top: 1px solid var(--border);
  padding: 12px 20px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  font-size: 13px;
}
.date-chip {
  background: #fff;
  border: 1px solid #90CAF9;
  border-radius: 20px;
  padding: 3px 10px;
  font-size: 12px;
  color: #1565C0;
  white-space: nowrap;
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
