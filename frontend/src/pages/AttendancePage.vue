<template>
  <div class="att-page">
    <div class="page-header att-header">
      <div>
        <h2>出缺勤管理</h2>
        <p class="page-desc">
          {{ isTeacher ? '查看你今日堂次並即時點名，也可補登過去堂次' : '追蹤學生到班狀態、點名核課、補登過往堂次' }}
        </p>
      </div>
      <div class="att-header-btns">
        <button class="primary" @click="refreshAll">重新整理今日堂次</button>
      </div>
    </div>

    <!-- Tab Switcher（director/super_admin 才顯示） -->
    <div v-if="!isTeacher" class="att-tabs">
      <button
        class="att-tab-btn"
        :class="{ active: activeTab === 'student' }"
        @click="switchTab('student')"
      >學生出缺勤</button>
      <button
        class="att-tab-btn"
        :class="{ active: activeTab === 'teacher' }"
        @click="switchTab('teacher')"
      >老師打卡</button>
    </div>

    <!-- ═══ Teacher Attendance Tab ═══ -->
    <template v-if="activeTab === 'teacher' && !isTeacher">
      <!-- Teacher Stats -->
      <div class="att-stats">
        <div class="att-stat-card">
          <div class="att-stat-num">{{ teacherStats.total }}</div>
          <div class="att-stat-label">今日到班</div>
        </div>
        <div class="att-stat-card">
          <div class="att-stat-num">{{ teacherOnDuty.length }}</div>
          <div class="att-stat-label">行政出勤</div>
        </div>
        <div class="att-stat-card stat-late">
          <div class="att-stat-num">{{ teacherStats.late }}</div>
          <div class="att-stat-label">遲到</div>
        </div>
        <div class="att-stat-card stat-absent">
          <div class="att-stat-num">{{ teacherStats.anomaly }}</div>
          <div class="att-stat-label">課表異常</div>
        </div>
      </div>

      <!-- Anomaly List：只顯示 late / missed（真正需要人工介入的） -->
      <div class="card att-checkin-card">
        <div class="att-checkin-header">
          <div class="att-section-title">課表異常待處理</div>
          <span v-if="teacherAnomalies.length" class="att-badge">{{ teacherAnomalies.length }}</span>
        </div>
        <div v-if="teacherLoading" class="att-empty">載入中…</div>
        <div v-else-if="teacherAnomalies.length === 0" class="att-empty">
          今日無課表異常 ✓
        </div>
        <div v-else class="ta-anomaly-list">
          <div v-for="r in teacherAnomalies" :key="r.id" class="ta-anomaly-row">
            <div class="ta-row-info">
              <span class="ta-name">{{ r.teacher_name }}</span>
              <span class="ta-time">{{ r.sign_in_dt?.slice(11, 16) ?? '—' }}</span>
              <span class="att-status-badge" :class="teacherStatusClass(r.status)">{{ teacherStatusLabel(r.status) }}</span>
            </div>
            <button class="primary small" @click="openAdjust(r)">補卡</button>
          </div>
        </div>

        <!-- 行政出勤區：有刷卡但無排課，正常到班，不需處理 -->
        <div v-if="!teacherLoading && teacherOnDuty.length" class="ta-onduty-section">
          <div class="ta-onduty-title">
            <span class="material-symbols-outlined" style="font-size:15px;vertical-align:-3px">badge</span>
            行政出勤（{{ teacherOnDuty.length }} 人，無排課，自動記錄）
          </div>
          <div class="ta-onduty-list">
            <span v-for="r in teacherOnDuty" :key="r.id" class="ta-onduty-chip">
              {{ r.teacher_name }} {{ r.sign_in_dt?.slice(11, 16) }}
            </span>
          </div>
        </div>

        <!-- 系統待確認：排課資料查詢失敗，不是人工缺失 -->
        <div v-if="!teacherLoading && teacherSystemPending.length" class="ta-sys-pending">
          <span class="material-symbols-outlined" style="font-size:14px;vertical-align:-3px">info</span>
          {{ teacherSystemPending.length }} 筆排課資料待比對（系統自動確認，無需人工操作）
        </div>
      </div>

      <!-- Full Records Table -->
      <div class="card att-checkin-card" style="margin-top:12px">
        <div class="att-checkin-header">
          <div class="att-section-title">今日完整打卡記錄</div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <input
              type="month"
              v-model="exportMonthRef"
              class="att-date-input"
              style="width:140px"
              aria-label="選擇匯出月份"
            />
            <button
              class="ghost small"
              :disabled="exportMonthLoading"
              @click="exportTeacherMonthly"
              title="匯出老師月度出缺勤 XLSX"
            >
              <span v-if="!exportMonthLoading" class="material-symbols-outlined" style="font-size:18px">calendar_month</span>
              <span v-else class="att-spinner" style="display:inline-block;width:16px;height:16px"></span>
              月報
            </button>
            <input type="date" v-model="teacherDate" class="att-date-input" @change="fetchTeacherRecords" />
            <button class="ghost small" @click="exportTeacherCsv" title="匯出 CSV">
              <span class="material-symbols-outlined" style="font-size:18px">download</span>
            </button>
          </div>
        </div>
        <div v-if="teacherLoading" class="att-empty">載入中…</div>
        <div v-else-if="teacherRecords.length === 0" class="att-empty">今日無老師打卡紀錄</div>
        <div v-else class="att-table-scroll">
          <table>
            <thead>
              <tr>
                <th>老師</th>
                <th>簽到</th>
                <th>簽退</th>
                <th>狀態</th>
                <th>第一堂</th>
                <th>操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in teacherRecords" :key="r.id">
                <td>{{ r.teacher_name }}</td>
                <td>{{ r.sign_in_dt?.slice(11, 16) ?? '—' }}</td>
                <td :class="{ 'ta-cell-warn': !r.sign_out_dt }">
                  {{ r.sign_out_dt ? r.sign_out_dt.slice(11, 16) : '未簽退' }}
                </td>
                <td>
                  <span class="att-status-badge" :class="teacherStatusClass(r.status)">
                    {{ teacherStatusLabel(r.status) }}
                  </span>
                </td>
                <td class="ta-cell-muted">{{ r.first_class_start_time ?? '—' }}</td>
                <td>
                  <button class="ghost small" @click="openAdjust(r)">補卡</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Unclosed reminder -->
      <div v-if="teacherUnclosed.length" class="card" style="margin-top:12px;padding:14px 16px">
        <div class="att-section-title" style="margin-bottom:8px">
          <span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:#e65100">warning</span>
          截止時間前未簽退（{{ teacherUnclosed.length }}）
        </div>
        <div v-for="u in teacherUnclosed" :key="u.teacher_id" class="ta-unclosed-row">
          {{ u.teacher_name }}
          <span class="ta-time">簽到 {{ u.sign_in_dt?.slice(11, 16) }}</span>
          <button class="ghost small" @click="openAdjust(u)">補卡</button>
        </div>
      </div>

      <!-- Adjust Modal -->
      <TeacherAdjustModal
        :visible="adjustModal.visible"
        :record="adjustModal.record"
        @close="adjustModal.visible = false"
        @submitted="onAdjustSubmitted"
      />
    </template>

    <!-- ═══ Student Attendance Tab（原有內容） ═══ -->
    <template v-if="activeTab === 'student' || isTeacher">

    <!-- Stats Summary -->
    <div class="att-stats">
      <div class="att-stat-card">
        <div class="att-stat-num">{{ markedSessionsCount }}</div>
        <div class="att-stat-label">已點名 / 今日課表 {{ todaySessionTotal }}</div>
      </div>
      <div class="att-stat-card stat-present">
        <div class="att-stat-num">{{ stats.present }}</div>
        <div class="att-stat-label">到班</div>
      </div>
      <div class="att-stat-card stat-late">
        <div class="att-stat-num">{{ stats.late }}</div>
        <div class="att-stat-label">遲到</div>
      </div>
      <div class="att-stat-card stat-absent">
        <div class="att-stat-num">{{ stats.absent + stats.excused }}</div>
        <div class="att-stat-label">缺席/請假</div>
      </div>
    </div>

    <div v-if="fetchError" class="att-msg error" style="margin-bottom:12px">{{ fetchError }}</div>

    <!-- Unified Check-in Panel -->
    <div class="card att-checkin-card">
      <div class="att-checkin-header">
        <div class="att-section-title">今日待點名堂次</div>
        <span v-if="pendingSessions.length > 0" class="att-badge">{{ pendingSessions.length }}</span>
      </div>
      <p class="att-hint">
        {{ isTeacher
          ? '你今天尚未點名的堂次。點名後立即核課並扣堂。'
          : '該分校今日已結束但尚未點名的堂次。點名後到班/遲到會自動扣堂。' }}
      </p>
      <div v-if="!isTeacher && !branchId" class="att-empty">請先選擇分校</div>
      <div v-else-if="pendingLoading" class="att-empty">載入中…</div>
      <div v-else-if="pendingSessions.length === 0" class="att-empty">今日沒有待點名堂次</div>
      <template v-else>
        <!-- Batch action bar -->
        <div class="att-batch-bar">
          <label class="att-check-all">
            <input type="checkbox" :checked="allSelected" @change="toggleSelectAll" />
            <span>全選</span>
          </label>
          <button
            v-if="selectedIds.length > 0"
            class="primary small"
            :disabled="batchSubmitting"
            @click="batchMarkAllPresent"
          >
            {{ batchSubmitting ? '送出中…' : `全部到班（${selectedIds.length}）` }}
          </button>
          <span v-if="selectedIds.length > 0" class="att-batch-hint">
            或逐列選擇其他狀態
          </span>
        </div>

        <!-- Desktop table (hidden on mobile) -->
        <div class="att-table-scroll att-desktop-only">
          <table>
            <thead>
              <tr>
                <th style="width:36px"></th>
                <th>時段</th>
                <th>學生</th>
                <th>科目</th>
                <th>老師</th>
                <th>狀態</th>
                <th style="text-align:right">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in pendingSessions" :key="s.class_session_id" :class="{ 'att-row-selected': selectedSet.has(s.class_session_id) }">
                <td>
                  <input type="checkbox" :checked="selectedSet.has(s.class_session_id)" @change="toggleSelect(s.class_session_id)" />
                </td>
                <td class="att-time-range">{{ s.start_time }}–{{ s.end_time }}</td>
                <td>
                  <span class="att-person-name">{{ s.student_name || '—' }}</span>
                  <span
                    v-if="getSessionDiscrepancy(s.class_session_id)"
                    class="att-report-badge"
                    :class="`att-report-badge-${getSessionDiscrepancy(s.class_session_id).status}`"
                  >
                    <span class="material-symbols-outlined" aria-hidden="true">flag</span>
                    {{ discrepancyBadgeLabel(getSessionDiscrepancy(s.class_session_id)) }}
                  </span>
                </td>
                <td>{{ s.subject_name || '—' }}</td>
                <td>{{ s.teacher_name || '—' }}</td>
                <td>
                  <div class="att-status-group">
                    <button
                      v-for="opt in statusOptions" :key="opt.value"
                      :class="['att-status-btn', `att-st-${opt.value}`, { active: pendingMarkStatus[s.class_session_id] === opt.value }]"
                      @click="setStatus(s.class_session_id, opt.value)"
                    >{{ opt.short }}</button>
                  </div>
                </td>
                <td style="text-align:right">
                  <div class="att-ops-stack">
                    <button
                      class="primary small"
                      :disabled="pendingMarkSubmitting[s.class_session_id]"
                      @click="submitPendingMark(s)"
                    >
                      {{ pendingMarkSubmitting[s.class_session_id] ? '送出中…' : '點名' }}
                    </button>
                    <button
                      class="att-report-btn"
                      :class="{ 'att-report-btn-active': !!getSessionDiscrepancy(s.class_session_id) }"
                      type="button"
                      @click="openReportModalForSession(s)"
                      :title="getSessionDiscrepancy(s.class_session_id) ? '已回報 — 點此查看' : '課表與實際不符？點此回報'"
                    >
                      <span class="material-symbols-outlined" aria-hidden="true">flag</span>
                      <span>{{ getSessionDiscrepancy(s.class_session_id) ? '已回報' : '回報出入' }}</span>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile cards (hidden on desktop) -->
        <div class="att-mobile-only att-cards" :style="selectedIds.length > 0 ? { paddingBottom: '72px' } : {}">
          <div
            v-for="s in pendingSessions" :key="'m-' + s.class_session_id"
            class="att-card"
            :class="{ 'att-card-selected': selectedSet.has(s.class_session_id) }"
          >
            <div class="att-card-top">
              <input type="checkbox" :checked="selectedSet.has(s.class_session_id)" @change="toggleSelect(s.class_session_id)" class="att-card-check" />
              <div class="att-card-info">
                <div class="att-card-student">
                  {{ s.student_name || '—' }}
                  <span
                    v-if="getSessionDiscrepancy(s.class_session_id)"
                    class="att-report-badge att-report-badge-mobile"
                    :class="`att-report-badge-${getSessionDiscrepancy(s.class_session_id).status}`"
                  >
                    <span class="material-symbols-outlined" aria-hidden="true">flag</span>
                    {{ discrepancyBadgeLabel(getSessionDiscrepancy(s.class_session_id)) }}
                  </span>
                </div>
                <div class="att-card-meta">
                  <span class="att-card-time">{{ s.start_time }}–{{ s.end_time }}</span>
                  <span class="att-card-subject">{{ s.subject_name || '—' }}</span>
                  <span v-if="s.teacher_name" class="att-card-teacher">{{ s.teacher_name }}</span>
                </div>
              </div>
            </div>
            <div class="att-card-actions">
              <div class="att-status-group att-status-group-mobile">
                <button
                  v-for="opt in statusOptions" :key="opt.value"
                  :class="['att-status-btn', `att-st-${opt.value}`, { active: pendingMarkStatus[s.class_session_id] === opt.value }]"
                  @click="setStatus(s.class_session_id, opt.value)"
                >{{ opt.label }}</button>
              </div>
              <div class="att-card-cta-row">
                <button
                  class="att-report-btn att-report-btn-mobile"
                  :class="{ 'att-report-btn-active': !!getSessionDiscrepancy(s.class_session_id) }"
                  type="button"
                  @click="openReportModalForSession(s)"
                >
                  <span class="material-symbols-outlined" aria-hidden="true">flag</span>
                  <span>{{ getSessionDiscrepancy(s.class_session_id) ? '已回報' : '回報出入' }}</span>
                </button>
                <button
                  class="primary small att-card-submit"
                  :disabled="pendingMarkSubmitting[s.class_session_id]"
                  @click="submitPendingMark(s)"
                >
                  {{ pendingMarkSubmitting[s.class_session_id] ? '…' : '確認' }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Sticky batch bar (mobile) -->
        <div v-if="selectedIds.length > 0" class="att-sticky-batch att-mobile-only">
          <span>已選 {{ selectedIds.length }} 堂</span>
          <button class="primary small" :disabled="batchSubmitting" @click="batchMarkAllPresent">
            {{ batchSubmitting ? '送出中…' : '全部到班' }}
          </button>
        </div>
      </template>

      <!-- "Class missing from system" entry point (always visible per FR-004) -->
      <div class="att-missing-cta">
        <span class="material-symbols-outlined" aria-hidden="true">help</span>
        <span>有課不在列表中？</span>
        <div class="att-missing-cta-actions">
          <button
            v-if="isTeacher"
            class="att-build-btn"
            type="button"
            :class="{ active: quickAttendOpen }"
            @click="quickAttendOpen = !quickAttendOpen"
          >
            <span class="material-symbols-outlined">add_task</span>
            補建並點名
          </button>
          <button class="att-missing-link" type="button" @click="openReportModalMissing">點此回報</button>
        </div>
      </div>

      <!-- Teacher quick-attend inline form -->
      <div v-if="isTeacher" class="att-quick-attend-wrap" :class="{ open: quickAttendOpen }">
        <div class="att-quick-attend-form">
          <div class="att-quick-grid">
            <div class="form-group">
              <label>課程 <span class="att-required">*</span></label>
              <div v-if="teacherCoursesLoading" class="att-skeleton-bar"></div>
              <SearchableSelect
                v-else
                v-model="quickForm.studentClassId"
                :options="teacherCourseOptions"
                placeholder="搜尋課程（學生/科目）..."
              />
              <p v-if="teacherCoursesError" class="att-field-err">{{ teacherCoursesError }}</p>
            </div>
            <div class="form-group">
              <label>上課日期 <span class="att-required">*</span></label>
              <input
                v-model="quickForm.date"
                type="date"
                :min="quickMinDate"
                :max="localTodayYmd()"
              />
              <p v-if="quickForm.date < quickMinDate" class="att-field-err">超出可補登範圍（14 天），請聯絡管理員</p>
            </div>
            <div class="form-group">
              <label>開始時間 <span class="att-required">*</span></label>
              <input v-model="quickForm.startTime" type="time" step="1800" />
            </div>
            <div class="form-group">
              <label>結束時間 <span class="att-required">*</span></label>
              <input v-model="quickForm.endTime" type="time" step="1800" />
              <p v-if="quickTimeError" class="att-field-err">{{ quickTimeError }}</p>
            </div>
            <div class="form-group">
              <label>點名狀態 <span class="att-required">*</span></label>
              <select v-model="quickForm.status">
                <option value="present">到班</option>
                <option value="late">遲到</option>
                <option value="leave">請假</option>
                <option value="absent">缺席</option>
              </select>
            </div>
          </div>
          <div class="att-quick-actions">
            <button class="ghost small" type="button" @click="quickAttendOpen = false">取消</button>
            <button
              class="primary small"
              type="button"
              :disabled="quickSubmitting"
              @click="submitQuickAttend"
            >
              <span v-if="quickSubmitting" class="material-symbols-outlined att-spin">progress_activity</span>
              {{ quickSubmitting ? '送出中…' : '補建並點名' }}
            </button>
          </div>
        </div>
      </div>

      <p v-if="pendingMarkMsg" class="att-msg" :class="pendingMarkMsgType">{{ pendingMarkMsg }}</p>

      <!-- Batch result detail -->
      <div v-if="batchResults.length > 0" class="att-batch-results">
        <div v-for="r in batchResults" :key="r.class_session_id" :class="['att-batch-result-item', r.success ? 'success' : 'error']">
          <span>{{ r.student_name || `#${r.class_session_id}` }}</span>
          <span>{{ r.success ? '✓' : ('✕ ' + (r.error || '')) }}</span>
        </div>
      </div>

      <!-- Manual Entry (collapsed) -->
      <details v-if="!isTeacher" class="att-manual-details">
        <summary class="att-manual-toggle">+ 手動登記</summary>

        <!-- Mode tabs -->
        <div class="att-dir-mode-tabs" role="tablist">
          <button
            type="button"
            role="tab"
            :class="['att-dir-mode-tab', { active: dirMode === 'system' }]"
            @click="switchDirMode('system')"
          >已排課程學生</button>
          <button
            type="button"
            role="tab"
            :class="['att-dir-mode-tab', { active: dirMode === 'external' }]"
            @click="switchDirMode('external')"
          >系統外人員</button>
        </div>

        <!-- System student mode -->
        <div v-if="dirMode === 'system'" class="att-manual-grid">
          <div class="form-group">
            <label>學生 <span class="att-required">*</span></label>
            <SearchableSelect
              v-model="dirForm.studentId"
              :options="studentOptions"
              placeholder="搜尋學生姓名..."
              @update:modelValue="onDirStudentChange"
            />
          </div>
          <div class="form-group">
            <label>課程 <span class="att-required">*</span></label>
            <div v-if="dirCoursesLoading" class="att-skeleton-bar"></div>
            <select
              v-else
              v-model="dirForm.studentClassId"
              :disabled="!dirForm.studentId || dirCourses.length === 0"
            >
              <option value="">{{ !dirForm.studentId ? '請先選擇學生' : (dirCourses.length === 0 ? '此學生無進行中的課程' : '選擇課程') }}</option>
              <option v-for="c in dirCourses" :key="c.value" :value="c.value">{{ c.label }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>日期 <span class="att-required">*</span></label>
            <input v-model="dirForm.date" type="date" />
          </div>
          <div class="form-group">
            <label>開始時間 <span class="att-required">*</span></label>
            <input v-model="dirForm.startTime" type="time" step="1800" />
          </div>
          <div class="form-group">
            <label>結束時間 <span class="att-required">*</span></label>
            <input v-model="dirForm.endTime" type="time" step="1800" />
            <p v-if="dirTimeError" class="att-field-err">{{ dirTimeError }}</p>
          </div>
          <div class="form-group">
            <label>狀態 <span class="att-required">*</span></label>
            <select v-model="dirForm.status">
              <option value="present">到班</option>
              <option value="late">遲到</option>
              <option value="leave">請假</option>
              <option value="absent">缺席</option>
            </select>
          </div>
          <div class="form-group">
            <label>備注</label>
            <input v-model="dirForm.memo" type="text" placeholder="選填…" />
          </div>
          <div class="form-group att-submit-wrap">
            <label>&nbsp;</label>
            <button class="primary" :disabled="dirSubmitting" @click="submitDirQuick">
              <span v-if="dirSubmitting" class="material-symbols-outlined att-spin">progress_activity</span>
              {{ dirSubmitting ? '送出中…' : '補建並點名' }}
            </button>
          </div>
        </div>

        <!-- External (original) mode -->
        <div v-else class="att-manual-grid">
          <div class="form-group">
            <label>選擇學生 <span class="att-required">*</span></label>
            <SearchableSelect
              v-model="manualForm.personKey"
              :options="personOptions"
              placeholder="搜尋學生姓名..."
            />
          </div>
          <div class="form-group">
            <label>日期</label>
            <input v-model="manualForm.date" type="date" />
          </div>
          <div class="form-group">
            <label>時間</label>
            <input v-model="manualForm.time" type="time" />
          </div>
          <div class="form-group">
            <label>狀態</label>
            <select v-model="manualForm.status">
              <option value="present">到班</option>
              <option value="late">遲到</option>
              <option value="leave">請假</option>
              <option value="absent">缺席</option>
            </select>
          </div>
          <div class="form-group">
            <label>備註</label>
            <input v-model="manualForm.memo" type="text" placeholder="選填…" />
          </div>
          <div class="form-group att-submit-wrap">
            <label>&nbsp;</label>
            <button class="primary" @click="submitManual">登記</button>
          </div>
        </div>

        <p v-if="manualMsg" class="att-msg" :class="manualMsgType">{{ manualMsg }}</p>
      </details>
    </div>

    <!-- Records (today / last 7 days / selected date) -->
    <div class="card att-records-card">
      <div class="att-records-header">
        <div class="att-section-title">
          出缺勤紀錄
          <span v-if="!isTeacher && recordsMode === 'week'" class="att-records-date-badge">最近 7 天</span>
          <span v-else-if="recordsDate !== localTodayYmd()" class="att-records-date-badge">{{ recordsDate }}</span>
        </div>
        <div class="att-records-controls">
          <!-- Admin/Director: today by default, with recent 7 days for review. -->
          <template v-if="!isTeacher">
            <div class="att-mode-toggle">
              <button
                :class="['att-mode-btn', { active: recordsMode === 'day' }]"
                @click="recordsMode = 'day'; recordsDate = localTodayYmd(); fetchRecords()"
              >今天</button>
              <button
                :class="['att-mode-btn', { active: recordsMode === 'week' }]"
                @click="recordsMode = 'week'; recordsDate = localTodayYmd(); fetchRecords()"
              >最近 7 天</button>
            </div>
            <input
              v-if="recordsMode === 'day'"
              v-model="recordsDate"
              type="date"
              :max="localTodayYmd()"
              class="att-date-input"
              @change="fetchRecords"
              title="查詢指定日期的出缺勤紀錄"
            />
          </template>
          <input
            v-else
            v-model="recordsDate"
            type="date"
            :max="localTodayYmd()"
            class="att-date-input"
            @change="fetchRecords"
            title="查詢指定日期的出缺勤紀錄"
          />
          <input v-model="searchName" type="text" placeholder="搜尋姓名…" class="att-search-input" />
          <select v-model="filterStatus" class="att-filter-select">
            <option value="">全部</option>
            <option value="present">到班</option>
            <option value="late">遲到</option>
            <option value="leave">請假</option>
            <option value="absent">缺席</option>
            <option value="self_study">自修</option>
          </select>
        </div>
      </div>

      <div class="att-table-scroll">
        <table>
          <thead>
            <tr>
              <th>日期</th>
              <th>時段</th>
              <th>學生</th>
              <th>科目</th>
              <th>老師</th>
              <th>分校</th>
              <th>狀態</th>
              <th style="text-align:right">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="record in filteredRecords" :key="record.id" class="att-record-row">
              <td>
                <span class="att-record-date">{{ formatRecordDate(record.SignInDT) }}</span>
              </td>
              <td>
                <span class="att-time-range">{{ formatTime(record.SignInDT) }}</span>
                <span v-if="record.SignOutDT" class="att-time-sep">–{{ formatTime(record.SignOutDT) }}</span>
              </td>
              <td><span class="att-person-name">{{ record.person_name }}</span></td>
              <td>{{ record.subject_name || '—' }}</td>
              <td>{{ record.teacher_name || record.course_teacher_name || '—' }}</td>
              <td>{{ record.campus_name || '—' }}</td>
              <td>
                <span
                  v-if="record.Memo === 'self_study'"
                  class="status-tag att-self-study-tag"
                  aria-label="自修記錄"
                >自修</span>
                <span v-else class="status-tag" :class="statusTagClass(record.Status)">
                  {{ record.status_label }}
                </span>
              </td>
              <td style="text-align:right">
                <!-- 自修記錄 -->
                <template v-if="record.Memo === 'self_study'">
                  <button
                    v-if="isDirectorOrAdmin"
                    class="ghost xs"
                    style="color:var(--color-primary)"
                    @click="openConvertModal(record)"
                  >轉換為到班</button>
                  <button
                    v-if="isDirectorOrAdmin"
                    class="ghost xs"
                    style="color:var(--color-danger,#d32f2f);margin-left:4px"
                    :title="'刪除此記錄'"
                    @click="openDeleteDialog(record)"
                  ><span class="material-symbols-outlined" style="font-size:16px;vertical-align:-3px">delete</span></button>
                </template>
                <!-- 一般記錄，未進入編輯 -->
                <template v-else-if="!record._editing">
                  <button class="ghost xs" @click="record._editing = true; record._newStatus = record.Status">修改</button>
                  <button
                    v-if="isDirectorOrAdmin"
                    class="ghost xs"
                    style="color:var(--color-danger,#d32f2f);margin-left:4px"
                    :title="'刪除此記錄'"
                    @click="openDeleteDialog(record)"
                  ><span class="material-symbols-outlined" style="font-size:16px;vertical-align:-3px">delete</span></button>
                </template>
                <!-- 編輯狀態 -->
                <div v-else class="att-inline-edit">
                  <select v-model="record._newStatus" class="att-status-select">
                    <option value="present">到班</option>
                    <option value="late">遲到</option>
                    <option value="leave">請假</option>
                    <option value="absent">缺席</option>
                  </select>
                  <button class="primary xs" :disabled="record._saving" @click="saveStatusEdit(record)">
                    {{ record._saving ? '…' : '✓' }}
                  </button>
                  <button class="ghost xs" @click="record._editing = false">✕</button>
                </div>
              </td>
            </tr>
            <tr v-if="filteredRecords.length === 0">
              <td colspan="8" class="empty-text">
                <span v-if="filterStatus === 'self_study'">{{ recordsMode === 'week' ? '最近 7 天暫無自修記錄' : '今日暫無自修記錄' }}</span>
                <span v-else>{{ recordsMode === 'week' ? '最近 7 天尚無出缺勤紀錄' : '今日尚無出缺勤紀錄' }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="att-refresh-hint">
        每 30 秒自動更新 · 上次更新：{{ lastRefresh }}
      </div>
    </div>

    <!-- Makeup Attendance (事後補點名) -->
    <div class="card att-checkin-card att-makeup-card">
      <div class="att-checkin-header">
        <div class="att-section-title">待補點名（已結束節次）</div>
        <span v-if="makeupSessions.length > 0" class="att-badge att-badge-warn">{{ makeupTotal }}</span>
      </div>
      <p class="att-hint">
        {{ isTeacher
          ? '你過去尚未點名的已結束堂次。選擇日期範圍查詢，補登後會依狀態自動扣堂或請假順延。'
          : '過去尚未點名的已結束堂次。可選擇日期範圍查詢，補登後會依狀態自動扣堂或請假順延。' }}
      </p>
      <div class="att-makeup-filters">
        <div class="form-group">
          <label>起始日期</label>
          <input v-model="makeupStartDate" type="date" />
        </div>
        <div class="form-group">
          <label>結束日期</label>
          <input v-model="makeupEndDate" type="date" />
        </div>
        <div class="form-group att-submit-wrap">
          <label>&nbsp;</label>
          <button class="primary" :disabled="makeupLoading" @click="fetchMakeupSessions">查詢</button>
        </div>
      </div>
      <div v-if="!isTeacher && !branchId" class="att-empty">請先選擇分校</div>
      <div v-else-if="makeupLoading" class="att-empty">載入中…</div>
      <div v-else-if="makeupSessions.length === 0" class="att-empty">此期間沒有尚未點名的已結束節次；已點名的課不會出現在這裡</div>
      <div v-else class="att-table-scroll">
        <table>
          <thead>
            <tr>
              <th>日期</th>
              <th>時段</th>
              <th>學生</th>
              <th>科目</th>
              <th>老師</th>
              <th>狀態</th>
              <th style="text-align:right">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in makeupSessions" :key="s.class_session_id">
              <td>{{ s.session_date }}</td>
              <td class="att-time-range">{{ s.start_time }}–{{ s.end_time }}</td>
              <td><span class="att-person-name">{{ s.student_name || '—' }}</span></td>
              <td>{{ s.subject_name || '—' }}</td>
              <td>{{ s.teacher_name || '—' }}</td>
              <td>
                <select v-model="makeupMarkStatus[s.class_session_id]" class="att-status-select">
                  <option value="present">到班</option>
                  <option value="late">遲到</option>
                  <option v-if="s.session_status === 'scheduled'" value="leave">請假</option>
                  <option value="absent">缺席</option>
                </select>
              </td>
              <td style="text-align:right">
                <button
                  class="primary small"
                  :disabled="makeupMarkSubmitting[s.class_session_id]"
                  @click="submitMakeupMark(s)"
                >
                  {{ makeupMarkSubmitting[s.class_session_id] ? '送出中…' : '補登' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="makeupHasMore" class="att-load-more">
          <button class="ghost small" :disabled="makeupLoading" @click="fetchMakeupSessions(makeupPage + 1)">載入更多</button>
        </div>
      </div>
      <p v-if="makeupMsg" class="att-msg" :class="makeupMsgType">{{ makeupMsg }}</p>
    </div>

    <!-- Pending Swipes -->
    <div v-if="!isTeacher && pendingSwipes.length > 0" class="card att-pending-card">
      <div class="att-section-title">未識別刷卡紀錄</div>
      <div class="att-table-scroll">
        <table>
          <thead>
            <tr>
              <th>時間</th>
              <th>卡片 UID</th>
              <th>原因</th>
              <th style="text-align:right">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="swipe in pendingSwipes" :key="swipe.id">
              <td>{{ formatTime(swipe.SwipeAt) }}</td>
              <td class="att-rfid">{{ maskRfid(swipe.RFID) }}</td>
              <td>{{ reasonLabel(swipe.Reason) }}</td>
              <td style="text-align:right">
                <button class="ghost xs" @click="handleDismiss(swipe.id)">忽略</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    </template><!-- end student tab wrapper -->
  </div>

  <!-- Teleport to body so z-index beats the fixed bottom nav (z:10000) -->
  <Teleport to="body">
    <div v-if="confirmDialog.visible" class="att-confirm-overlay" @click.self="!confirmDialog.submitting && (confirmDialog.visible = false)">
      <div class="att-confirm-sheet">
        <div class="att-confirm-title">{{ confirmDialog.title }}</div>
        <div class="att-confirm-body">{{ confirmDialog.body }}</div>
        <div v-if="confirmDialog.error" class="att-msg error" style="margin-bottom:12px;font-size:13px">{{ confirmDialog.error }}</div>
        <div class="att-confirm-actions">
          <button class="ghost" :disabled="confirmDialog.submitting" @click="confirmDialog.visible = false">取消</button>
          <button class="primary" :disabled="confirmDialog.submitting" @click="handleConfirmSubmit">
            {{ confirmDialog.submitting ? '送出中…' : '確認送出' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>

  <!-- Schedule-discrepancy report modal -->
  <ReportDiscrepancyModal
    v-if="discrepancyModal.visible"
    :mode="discrepancyModal.mode"
    :branch-id="discrepancyModal.branchId || props.branchId || 0"
    :class-session-id="discrepancyModal.sessionId"
    :session-context="discrepancyModal.sessionContext"
    :existing="discrepancyModal.existing"
    @close="closeDiscrepancyModal"
    @submitted="onDiscrepancySubmitted"
    @withdrawn="onDiscrepancyWithdrawn"
  />

  <!-- Toast notifications for discrepancy actions (top-right per §5b) -->
  <Teleport to="body">
    <Transition name="sd-toast">
      <div
        v-if="discrepancyToast.visible"
        class="sd-toast"
        :class="`sd-toast-${discrepancyToast.tone}`"
        role="status"
      >
        <span class="material-symbols-outlined" aria-hidden="true">
          {{ discrepancyToast.tone === 'success' ? 'check_circle' : (discrepancyToast.tone === 'error' ? 'error' : 'info') }}
        </span>
        <span>{{ discrepancyToast.text }}</span>
      </div>
    </Transition>
    <Transition name="sd-toast">
      <div v-if="teacherToast.visible" class="sd-toast" role="status">
        <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
        <span>{{ teacherToast.text }}</span>
      </div>
    </Transition>
    <Transition name="sd-toast">
      <div
        v-if="attToast.visible"
        class="sd-toast"
        :class="`sd-toast-${attToast.tone}`"
        role="status"
        aria-live="polite"
      >
        <span class="material-symbols-outlined" aria-hidden="true">
          {{ attToast.tone === 'success' ? 'check_circle' : 'error' }}
        </span>
        <span>{{ attToast.text }}</span>
      </div>
    </Transition>
  </Teleport>

  <!-- ── Delete Attendance Dialog ── -->
  <Teleport to="body">
    <div v-if="deleteDialog.visible" class="att-overlay" @click.self="closeDeleteDialog" role="dialog" aria-modal="true" aria-labelledby="delete-dialog-title">
      <div class="att-dialog">
        <h3 id="delete-dialog-title" style="margin:0 0 12px;font-size:16px;color:var(--color-danger,#d32f2f)">確認刪除出缺勤紀錄</h3>
        <div class="att-dialog-summary">
          <div><span class="att-dialog-label">學生：</span>{{ deleteDialog.record?.person_name ?? deleteDialog.record?.student_name ?? '—' }}</div>
          <div><span class="att-dialog-label">時間：</span>{{ deleteDialog.record ? formatTime(deleteDialog.record.SignInDT) : '—' }}</div>
          <div><span class="att-dialog-label">狀態：</span>{{ deleteDialog.record?.status_label ?? deleteDialog.record?.Memo ?? '—' }}</div>
        </div>
        <div class="att-dialog-field">
          <label for="delete-reason" style="font-size:14px;font-weight:500">
            刪除原因 <span style="color:var(--color-danger,#d32f2f)">*</span>
          </label>
          <textarea
            id="delete-reason"
            v-model="deleteDialog.reason"
            rows="3"
            class="att-dialog-textarea"
            placeholder="請說明刪除原因，例如：測試資料、誤刷"
            style="margin-top:6px"
          ></textarea>
        </div>
        <div class="att-dialog-actions">
          <button class="ghost small" @click="closeDeleteDialog" :disabled="deleteDialog.loading">取消</button>
          <button
            class="primary small danger"
            :disabled="deleteDialog.reason.trim().length < 2 || deleteDialog.loading"
            @click="confirmDelete"
          >
            {{ deleteDialog.loading ? '刪除中…' : '確認刪除' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>

  <!-- ── Convert Self-Study Modal ── -->
  <Teleport to="body">
    <div v-if="convertModal.visible" class="att-overlay" @click.self="closeConvertModal" role="dialog" aria-modal="true" aria-labelledby="convert-modal-title">
      <div class="att-dialog" style="max-width:480px">
        <h3 id="convert-modal-title" style="margin:0 0 12px;font-size:16px">將自修轉換為到班</h3>
        <div class="att-dialog-summary">
          <div><span class="att-dialog-label">學生：</span>{{ convertModal.record?.person_name ?? '—' }}</div>
          <div><span class="att-dialog-label">時間：</span>{{ convertModal.record ? formatTime(convertModal.record.SignInDT) : '—' }}</div>
        </div>
        <div v-if="convertModal.coursesLoading" class="att-empty" style="padding:24px 0">載入課程中…</div>
        <template v-else-if="convertModal.courses.length === 0">
          <div class="att-empty" style="flex-direction:column;padding:24px 0;gap:8px">
            <span class="material-symbols-outlined" style="font-size:48px;color:var(--text-secondary,#888)">book</span>
            <div>此學生目前無進行中的課程合約</div>
            <div style="font-size:13px;color:var(--text-secondary,#888)">請先在「學生課程」建立課程後再操作</div>
          </div>
        </template>
        <template v-else>
          <div style="font-size:14px;font-weight:500;margin-bottom:8px">請選擇要套用的課程：</div>
          <div class="att-course-list">
            <label
              v-for="course in convertModal.courses"
              :key="course.ID"
              class="att-course-item"
              :class="{ disabled: course.remaining_sessions <= 0 }"
            >
              <input
                type="radio"
                :value="course.ID"
                v-model="convertModal.selectedId"
                :disabled="course.remaining_sessions <= 0"
              />
              <div class="att-course-info">
                <span class="att-course-name">{{ course.subject_name || '—' }}</span>
                <span class="att-course-teacher">{{ course.teacher_name || '—' }}</span>
                <span class="att-course-sessions" :class="{ 'sessions-empty': course.remaining_sessions <= 0 }">
                  剩餘：{{ course.remaining_sessions }} 堂{{ course.remaining_sessions <= 0 ? '（堂數已滿）' : '' }}
                </span>
              </div>
            </label>
          </div>
          <div style="font-size:13px;color:var(--color-warning,#e65100);margin-top:12px">
            <span class="material-symbols-outlined" style="font-size:14px;vertical-align:-2px">warning</span>
            轉換後將自動扣除一堂，此操作無法直接復原
          </div>
        </template>
        <div class="att-dialog-actions" style="margin-top:16px">
          <button class="ghost small" @click="closeConvertModal" :disabled="convertModal.loading">取消</button>
          <button
            v-if="convertModal.courses.length > 0"
            class="primary small"
            :disabled="!convertModal.selectedId || convertModal.loading"
            @click="confirmConvert"
          >
            {{ convertModal.loading ? '轉換中…' : '確認轉換' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, reactive, onMounted, onBeforeUnmount, watch } from 'vue';
import { supabase } from '../supabase';
import SearchableSelect from '../components/SearchableSelect.vue';
import ReportDiscrepancyModal from '../components/ReportDiscrepancyModal.vue';
import TeacherAdjustModal from '../components/TeacherAdjustModal.vue';
import { fetchMyDiscrepancies, STATUS_LABELS as DISCREPANCY_STATUS_LABELS } from '../lib/scheduleDiscrepanciesApi';

const props = defineProps({
  branchId: [String, Number],
  userRole: String,
  userId: [String, Number],
});
const isTeacher = computed(() => props.userRole === 'teacher');
const isDirectorOrAdmin = computed(() => props.userRole === 'director' || props.userRole === 'super_admin');

// ── Tab state ──
const activeTab = ref('student');

function switchTab(tab) {
  if (activeTab.value === tab) return;
  activeTab.value = tab;
  if (tab === 'teacher') fetchTeacherRecords();
}

// ── Teacher Attendance state ──
const teacherRecords  = ref([]);
const teacherUnclosed = ref([]);
const teacherLoading  = ref(false);
const teacherDate     = ref(new Date().toISOString().slice(0, 10));

// 真正需要人工介入的異常：有課表但遲到 / 有課表但完全未刷
const teacherAnomalies = computed(() =>
  teacherRecords.value.filter(r => ['late', 'missed'].includes(r.status))
);

// 行政出勤：有刷卡但當天無排課，屬正常到班，不需人工處理
const teacherOnDuty = computed(() =>
  teacherRecords.value.filter(r => r.status === 'source_only')
);

// 系統待確認：排課查詢失敗（資料問題），與人工異常分開顯示
const teacherSystemPending = computed(() =>
  teacherRecords.value.filter(r => r.status === 'pending_review')
);

const teacherStats = computed(() => ({
  total:   teacherRecords.value.length,
  late:    teacherRecords.value.filter(r => r.status === 'late').length,
  anomaly: teacherAnomalies.value.length,   // 課表異常（需人工確認）
}));

const adjustModal = reactive({ visible: false, record: null });

const TEACHER_STATUS_LABEL = {
  normal:         '準時到班',
  late:           '遲到',
  early_leave:    '早退',
  missed:         '漏刷',
  adjusted:       '已補卡',
  pending_review: '系統待確認',
  source_only:    '行政出勤',
  no_record:      '未打卡',
};
const TEACHER_STATUS_CLASS = {
  normal:         'ts-badge-ok',
  late:           'ts-badge-late',
  early_leave:    'ts-badge-warn',
  missed:         'ts-badge-error',
  adjusted:       'ts-badge-muted',
  pending_review: 'ts-badge-muted',
  source_only:    'ts-badge-ok',
};

function teacherStatusLabel(s) { return TEACHER_STATUS_LABEL[s] ?? s; }
function teacherStatusClass(s) { return TEACHER_STATUS_CLASS[s] ?? 'ts-badge-muted'; }

function openAdjust(record) {
  adjustModal.record  = record;
  adjustModal.visible = true;
}

async function onAdjustSubmitted() {
  adjustModal.visible = false;
  showTeacherToast('補卡成功');
  await fetchTeacherRecords();
}

const teacherToast = reactive({ visible: false, text: '' });
let teacherToastTimer = null;
function showTeacherToast(text) {
  clearTimeout(teacherToastTimer);
  teacherToast.text    = text;
  teacherToast.visible = true;
  teacherToastTimer = setTimeout(() => { teacherToast.visible = false; }, 3000);
}

// ── 月報匯出 refs ──
const exportMonthRef    = ref(new Date().toISOString().slice(0, 7));
const exportMonthLoading = ref(false);

async function exportTeacherMonthly() {
  if (exportMonthLoading.value) return;
  exportMonthLoading.value = true;
  try {
    const token = await getToken();
    if (!token) return;
    const url = `/api/v1/teacher-attendance/export-monthly?year_month=${exportMonthRef.value}`;
    const res = await fetch(url, { headers: { Authorization: `Bearer ${token}` } });
    if (!res.ok) {
      showAttToast('匯出失敗，請稍後再試', 'error');
      return;
    }
    const blob = await res.blob();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `teacher-attendance-${exportMonthRef.value}.xlsx`;
    a.click();
    URL.revokeObjectURL(a.href);
  } catch {
    showAttToast('匯出失敗，請稍後再試', 'error');
  } finally {
    exportMonthLoading.value = false;
  }
}

// ── 出缺勤記錄通用 toast ──
const attToast = reactive({ visible: false, text: '', tone: 'success' });
let attToastTimer = null;
function showAttToast(text, tone = 'success') {
  clearTimeout(attToastTimer);
  attToast.text    = text;
  attToast.tone    = tone;
  attToast.visible = true;
  attToastTimer = setTimeout(() => { attToast.visible = false; }, tone === 'error' ? 3000 : 2000);
}

// ── 刪除記錄 Dialog ──
const deleteDialog = reactive({ visible: false, record: null, reason: '', loading: false });

function openDeleteDialog(record) {
  deleteDialog.record  = record;
  deleteDialog.reason  = '';
  deleteDialog.loading = false;
  deleteDialog.visible = true;
}
function closeDeleteDialog() {
  if (deleteDialog.loading) return;
  deleteDialog.visible = false;
}
async function confirmDelete() {
  if (deleteDialog.reason.trim().length < 2 || deleteDialog.loading) return;
  deleteDialog.loading = true;
  try {
    const token = await getToken();
    const res = await fetch(`/api/v1/attendance/${deleteDialog.record.id}`, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({ void_reason: deleteDialog.reason.trim() }),
    });
    const json = await res.json().catch(() => ({}));
    if (res.ok) {
      deleteDialog.visible = false;
      showAttToast('已刪除記錄', 'success');
      await fetchRecords();
    } else {
      showAttToast('刪除失敗：' + (json.message || '未知錯誤'), 'error');
    }
  } catch {
    showAttToast('刪除失敗：網路錯誤', 'error');
  } finally {
    deleteDialog.loading = false;
  }
}

// ── 自修轉到班 Modal ──
const convertModal = reactive({
  visible: false, record: null,
  courses: [], coursesLoading: false,
  selectedId: null, loading: false,
});

async function openConvertModal(record) {
  convertModal.record       = record;
  convertModal.selectedId   = null;
  convertModal.loading      = false;
  convertModal.courses      = [];
  convertModal.coursesLoading = true;
  convertModal.visible      = true;
  try {
    const token = await getToken();
    const studentId = record.StudentID;
    const res = await fetch(`/api/v1/student-classes?student_id=${studentId}`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    if (res.ok) {
      const json = await res.json();
      const list = json.data ?? json ?? [];
      convertModal.courses = list.filter(c => (c.remaining_sessions ?? 0) >= 0);
    }
  } catch { /* ignore, empty list */ }
  finally {
    convertModal.coursesLoading = false;
  }
}
function closeConvertModal() {
  if (convertModal.loading) return;
  convertModal.visible = false;
}
async function confirmConvert() {
  if (!convertModal.selectedId || convertModal.loading) return;
  convertModal.loading = true;
  try {
    const token = await getToken();
    const res = await fetch(`/api/v1/attendance/${convertModal.record.id}/convert-to-attended`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({ student_class_id: convertModal.selectedId }),
    });
    const json = await res.json().catch(() => ({}));
    if (res.ok) {
      convertModal.visible = false;
      showAttToast(`已轉換為到班，已扣除一堂（剩餘 ${json.remaining_sessions} 堂）`, 'success');
      await fetchRecords();
    } else {
      showAttToast('轉換失敗：' + (json.message || '未知錯誤'), 'error');
    }
  } catch {
    showAttToast('轉換失敗：網路錯誤', 'error');
  } finally {
    convertModal.loading = false;
  }
}

async function fetchTeacherRecords() {
  if (!props.branchId) return;
  teacherLoading.value = true;
  try {
    const { data: { session } } = await supabase.auth.getSession();
    const token = session?.access_token;
    const [recRes, unclosedRes] = await Promise.all([
      fetch(`/api/v1/teacher-attendance?date=${teacherDate.value}&campus_id=${props.branchId}&per_page=100`, {
        headers: { Authorization: `Bearer ${token}` },
      }),
      fetch(`/api/v1/teacher-attendance/unclosed?date=${teacherDate.value}&campus_id=${props.branchId}`, {
        headers: { Authorization: `Bearer ${token}` },
      }),
    ]);
    if (recRes.ok) {
      const json = await recRes.json();
      teacherRecords.value = json.data ?? [];
    }
    if (unclosedRes.ok) {
      const json = await unclosedRes.json();
      teacherUnclosed.value = json.data ?? [];
    }
  } catch (_) { /* silent */ } finally {
    teacherLoading.value = false;
  }
}

async function exportTeacherCsv() {
  if (!props.branchId) return;
  const { data: { session } } = await supabase.auth.getSession();
  const token = session?.access_token;
  const url = `/api/v1/teacher-attendance/export?date_from=${teacherDate.value}&date_to=${teacherDate.value}&format=csv&campus_id=${props.branchId}`;
  const res = await fetch(url, { headers: { Authorization: `Bearer ${token}` } });
  if (!res.ok) return;
  const blob = await res.blob();
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `teacher-attendance-${teacherDate.value}.csv`;
  a.click();
  URL.revokeObjectURL(a.href);
}

const statusOptions = [
  { value: 'present', label: '到班', short: '到' },
  { value: 'late', label: '遲到', short: '遲' },
  { value: 'leave', label: '請假', short: '假' },
  { value: 'absent', label: '缺席', short: '缺' },
];
const statusLabelMap = { present: '到班', late: '遲到', leave: '請假', excused: '請假', absent: '缺席' };

const records = ref([]);
const pendingSwipes = ref([]);
const studentList = ref([]);
const searchName = ref('');
const filterStatus = ref('');
const lastRefresh = ref('');
const manualMsg = ref('');
const manualMsgType = ref('');

const pendingSessions = ref([]);
const pendingLoading = ref(false);
const pendingMarkStatus = ref({});
const pendingMarkSubmitting = ref({});
const pendingMarkMsg = ref('');
const pendingMarkMsgType = ref('');
const todaySessionTotal = ref(0);
const fetchError = ref('');

// Batch selection
const selectedIds = ref([]);
const selectedSet = computed(() => new Set(selectedIds.value));
const allSelected = computed(() => pendingSessions.value.length > 0 && selectedIds.value.length === pendingSessions.value.length);
const batchSubmitting = ref(false);
const batchResults = ref([]);

const confirmDialog = reactive({ visible: false, title: '', body: '', onConfirm: () => {}, submitting: false, error: '' });

async function handleConfirmSubmit() {
  confirmDialog.submitting = true;
  confirmDialog.error = '';
  try {
    await confirmDialog.onConfirm();
    confirmDialog.visible = false;
  } catch (e) {
    confirmDialog.error = e?.message || '送出失敗，請稍後再試';
  } finally {
    confirmDialog.submitting = false;
  }
}

const makeupSessions = ref([]);
const makeupLoading = ref(false);
const makeupMarkStatus = ref({});
const makeupMarkSubmitting = ref({});
const makeupMsg = ref('');
const makeupMsgType = ref('');
const makeupPage = ref(1);
const makeupHasMore = ref(false);
const makeupTotal = ref(0);
const makeupStartDate = ref((() => {
  const d = new Date(); d.setDate(d.getDate() - 7);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
})());
const makeupEndDate = ref((() => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
})());

let refreshTimer = null;

const manualForm = ref({
  personKey: '',
  date: new Date().toISOString().split('T')[0],
  time: new Date().toTimeString().slice(0, 5),
  status: 'present',
  memo: ''
});

// ── Director: system-student quick-attend ──────────────────────────────
const dirMode = ref('system'); // 'system' | 'external'
const dirCourses = ref([]);
const dirCoursesLoading = ref(false);
const dirSubmitting = ref(false);
const dirTimeError = ref('');
const dirForm = ref({
  studentId: '',
  studentClassId: '',
  date: new Date().toISOString().split('T')[0],
  startTime: (() => { const d = new Date(); return `${String(d.getHours()).padStart(2,'0')}:${d.getMinutes() < 30 ? '00' : '30'}`; })(),
  endTime: (() => { const d = new Date(); const h = d.getMinutes() < 30 ? d.getHours() : (d.getHours() + 1) % 24; return `${String(h).padStart(2,'0')}:${d.getMinutes() < 30 ? '30' : '00'}`; })(),
  status: 'present',
  memo: '',
});

const studentOptions = computed(() =>
  studentList.value.map(s => ({ value: String(s.id), label: `${s.name}（學生）` }))
);

function switchDirMode(mode) {
  dirMode.value = mode;
  manualMsg.value = '';
  dirTimeError.value = '';
}

async function onDirStudentChange(studentId) {
  dirForm.value.studentClassId = '';
  dirCourses.value = [];
  dirTimeError.value = '';
  if (!studentId) return;
  dirCoursesLoading.value = true;
  try {
    const token = await getToken();
    const res = await fetch(`/api/v1/student-classes?student_id=${studentId}&per_page=100&status=active`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (res.ok) {
      const data = await res.json();
      const list = data.data || data || [];
      dirCourses.value = list.map(c => ({
        value: String(c.ID || c.id),
        label: `${c.student_name || ''}・${c.subject_name || c.subject || ''}（${c.teacher_name || ''}）`,
      }));
    }
  } catch (e) {
    console.error('onDirStudentChange', e);
  } finally {
    dirCoursesLoading.value = false;
  }
}

async function submitDirQuick() {
  dirTimeError.value = '';
  manualMsg.value = '';
  if (!dirForm.value.studentId || !dirForm.value.studentClassId) {
    manualMsg.value = '請選擇學生與課程';
    manualMsgType.value = 'error';
    return;
  }
  if (dirForm.value.startTime >= dirForm.value.endTime) {
    dirTimeError.value = '結束時間須晚於開始時間';
    return;
  }
  dirSubmitting.value = true;
  try {
    const token = await getToken();
    const res = await fetch('/api/v1/attendance', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({
        StudentClassID: Number(dirForm.value.studentClassId),
        SessionDate: dirForm.value.date,
        StartTime: dirForm.value.startTime + ':00',
        EndTime: dirForm.value.endTime + ':00',
        Status: dirForm.value.status,
        Memo: dirForm.value.memo || '',
        mark_mode: 'arrival',
      }),
    });
    if (res.ok) {
      manualMsg.value = '已補建堂次並完成點名';
      manualMsgType.value = 'success';
      dirForm.value.studentId = '';
      dirForm.value.studentClassId = '';
      dirForm.value.memo = '';
      dirCourses.value = [];
      fetchRecords();
    } else {
      const err = await res.json().catch(() => ({}));
      manualMsg.value = '補建失敗：' + (err.message || '未知錯誤');
      manualMsgType.value = 'error';
    }
  } catch (e) {
    manualMsg.value = '補建失敗：網路錯誤';
    manualMsgType.value = 'error';
  } finally {
    dirSubmitting.value = false;
  }
}

// ── Teacher: quick-attend inline form ─────────────────────────────────
const quickAttendOpen = ref(false);
const quickSubmitting = ref(false);
const quickTimeError = ref('');
const teacherCourses = ref([]);
const teacherCoursesLoading = ref(false);
const teacherCoursesError = ref('');

const roundToHalfHour = () => {
  const d = new Date();
  const mins = d.getMinutes();
  const rounded = mins < 30 ? 0 : 30;
  return `${String(d.getHours()).padStart(2,'0')}:${String(rounded).padStart(2,'0')}`;
};
const halfHourLater = () => {
  const d = new Date();
  const mins = d.getMinutes();
  if (mins < 30) { return `${String(d.getHours()).padStart(2,'0')}:30`; }
  const next = (d.getHours() + 1) % 24;
  return `${String(next).padStart(2,'0')}:00`;
};

const localTodayYmd = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

const quickMinDate = (() => {
  const d = new Date();
  d.setDate(d.getDate() - 14);
  return d.toISOString().slice(0, 10);
})();

const quickForm = ref({
  studentClassId: '',
  date: localTodayYmd(),
  startTime: roundToHalfHour(),
  endTime: halfHourLater(),
  status: 'present',
});

const teacherCourseOptions = computed(() =>
  teacherCourses.value.map(c => ({
    value: String(c.ID || c.id),
    label: `${c.student_name || ''}・${c.subject_name || c.subject || ''}`,
  }))
);

async function fetchTeacherCourses() {
  if (!isTeacher.value) return;
  teacherCoursesLoading.value = true;
  teacherCoursesError.value = '';
  try {
    const token = await getToken();
    const res = await fetch('/api/v1/student-classes?per_page=200&status=active', {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (res.ok) {
      const data = await res.json();
      teacherCourses.value = data.data || data || [];
    } else {
      teacherCoursesError.value = '課程載入失敗，請重試';
    }
  } catch (e) {
    teacherCoursesError.value = '課程載入失敗：網路錯誤';
  } finally {
    teacherCoursesLoading.value = false;
  }
}

async function submitQuickAttend() {
  quickTimeError.value = '';
  if (!quickForm.value.studentClassId) {
    quickTimeError.value = '請選擇課程';
    return;
  }
  if (!quickForm.value.date) {
    quickTimeError.value = '請選擇上課日期';
    return;
  }
  if (quickForm.value.date < quickMinDate) {
    quickTimeError.value = '超出可補登範圍（14 天），請聯絡管理員補建';
    return;
  }
  if (quickForm.value.startTime >= quickForm.value.endTime) {
    quickTimeError.value = '結束時間須晚於開始時間';
    return;
  }

  // BUG-B fix: resolve StudentID from the selected course; required by backend.
  const selectedCourse = teacherCourses.value.find(
    c => String(c.ID || c.id) === String(quickForm.value.studentClassId)
  );
  const studentId = selectedCourse
    ? Number(selectedCourse.StudentID || selectedCourse.student_id || 0)
    : 0;
  if (!studentId) {
    quickTimeError.value = '缺少學生資訊，請重新選擇課程';
    return;
  }

  quickSubmitting.value = true;
  try {
    const token = await getToken();
    const res = await fetch('/api/v1/attendance', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({
        StudentID: studentId,
        StudentClassID: Number(quickForm.value.studentClassId),
        SessionDate: quickForm.value.date,   // BUG-C fix: use selected date, not hardcoded today
        StartTime: quickForm.value.startTime + ':00',
        EndTime: quickForm.value.endTime + ':00',
        Status: quickForm.value.status,
        mark_mode: 'arrival',
      }),
    });
    if (res.ok) {
      quickAttendOpen.value = false;
      quickForm.value.studentClassId = '';
      quickForm.value.date = localTodayYmd();
      quickForm.value.startTime = roundToHalfHour();
      quickForm.value.endTime = halfHourLater();
      quickForm.value.status = 'present';
      showDiscrepancyToast('已補建堂次並完成點名', 'success');
      fetchRecords();
    } else {
      const err = await res.json().catch(() => ({}));
      quickTimeError.value = '補建失敗：' + (err.message || `HTTP ${res.status}`);
    }
  } catch (e) {
    quickTimeError.value = '補建失敗：網路錯誤';
  } finally {
    quickSubmitting.value = false;
  }
}

const getToken = async () => {
  const { data: { session } } = await supabase.auth.getSession();
  return session?.access_token;
};

const personOptions = computed(() =>
  studentList.value.map(s => ({ value: `student:${s.id}`, label: `${s.name}（學生）` }))
);

const stats = computed(() => {
  const list = records.value;
  return {
    total: list.length,
    present: list.filter(r => r.Status === 'present').length,
    late: list.filter(r => r.Status === 'late').length,
    absent: list.filter(r => r.Status === 'absent').length,
    excused: list.filter(r => r.Status === 'leave' || r.Status === 'excused').length,
  };
});

const markedSessionsCount = computed(() => {
  const ids = new Set(
    records.value
      .map((r) => Number(r?.ClassSessionID || 0))
      .filter((id) => id > 0)
  );
  return ids.size;
});

const filteredRecords = computed(() => {
  let list = records.value;
  if (searchName.value) {
    const q = searchName.value.toLowerCase();
    list = list.filter(r => (r.person_name || '').toLowerCase().includes(q));
  }
  if (filterStatus.value === 'self_study') {
    list = list.filter(r => r.Memo === 'self_study');
  } else if (filterStatus.value) {
    list = list.filter(r => r.Status === filterStatus.value && r.Memo !== 'self_study');
  }
  return list;
});

// --- Selection helpers ---
function toggleSelectAll() {
  if (allSelected.value) {
    selectedIds.value = [];
  } else {
    selectedIds.value = pendingSessions.value.map(s => s.class_session_id);
  }
}

function toggleSelect(id) {
  const idx = selectedIds.value.indexOf(id);
  if (idx >= 0) {
    selectedIds.value = selectedIds.value.filter(x => x !== id);
  } else {
    selectedIds.value = [...selectedIds.value, id];
  }
}

function setStatus(sessionId, status) {
  pendingMarkStatus.value = { ...pendingMarkStatus.value, [sessionId]: status };
}

// --- API calls ---
const recordsDate = ref(localTodayYmd());
// Admin/Director default to today; recent 7 days remains available for review.
const recordsMode = ref('day');

const fetchRecords = async () => {
  try {
    const token = await getToken();
    if (!token) return;
    const params = new URLSearchParams({ per_page: '200' });
    if (isTeacher.value) {
      // Teacher always queries a specific date (today by default, retroactive via date picker).
      params.set('date', recordsDate.value);
    } else {
      if (recordsMode.value === 'day') {
        params.set('date', recordsDate.value);
      } else {
        // 'week' mode: send explicit start/end so the backend window is fixed to
        // today-6 … today regardless of when the server's "now" is evaluated.
        const end   = localTodayYmd();
        const start = (() => {
          const d = new Date(); d.setDate(d.getDate() - 6);
          return d.toISOString().slice(0, 10);
        })();
        params.set('start_date', start);
        params.set('end_date',   end);
      }
      if (props.branchId) params.set('branch_id', String(props.branchId));
    }
    const res = await fetch(`/api/v1/attendance?${params.toString()}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (res.ok) {
      const data = await res.json();
      records.value = (data.data || []).map(r => ({ ...r, _editing: false, _newStatus: r.Status, _saving: false }));
    } else if (res.status === 403) {
      fetchError.value = '無此分校的存取權限，請確認分校設定';
    } else {
      fetchError.value = `載入出缺勤記錄失敗（HTTP ${res.status}），請重新整理`;
    }
  } catch (e) {
    console.error('fetchRecords', e);
  }
  lastRefresh.value = new Date().toLocaleTimeString('zh-TW');
};

// Single API call for both todaySessionTotal and pendingSessions (fixes duplicate fetch)
const fetchPendingSessions = async () => {
  if (!isTeacher.value && !props.branchId) { pendingSessions.value = []; return; }
  pendingLoading.value = true;
  pendingMarkMsg.value = '';
  fetchError.value = '';
  try {
    const token = await getToken();
    if (!token) return;
    const today = localTodayYmd();
    const qs = new URLSearchParams({ start: today, end: today, per_page: '500' });
    if (!isTeacher.value && props.branchId) qs.set('branch_id', String(props.branchId));

    const res = await fetch(`/api/v1/class-sessions?${qs}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });

    if (!res.ok) {
      pendingSessions.value = [];
      todaySessionTotal.value = 0;
      if (res.status === 403) {
        fetchError.value = '無此分校的存取權限，請確認分校設定';
      } else {
        fetchError.value = `載入待點名堂次失敗（HTTP ${res.status}），請重新整理`;
      }
      return;
    }

    const json = await res.json().catch(() => ({}));
    const rows = Array.isArray(json?.data) ? json.data : [];

    const totalSlotKeys = new Set();
    todaySessionTotal.value = rows.filter((row) => {
      const status = String(row?.status || '').toLowerCase();
      return !['cancelled', 'leave', 'leave_adjusted'].includes(status);
    }).filter((row) => {
      const key = [
        Number(row?.student_class_id || row?.StudentClassID || 0),
        String(row?.session_date || row?.SessionDate || '').slice(0, 10),
        String(row?.start_time || row?.StartTime || '').slice(0, 5),
      ].join('|');
      if (totalSlotKeys.has(key)) return false;
      totalSlotKeys.add(key);
      return true;
    }).length;

    const pendingRows = rows
      .filter(r => String(r?.status || '').toLowerCase() === 'scheduled')
      .map(r => ({
        class_session_id: Number(r.id || 0),
        student_id: Number(r.student_id || 0),
        student_class_id: Number(r.student_class_id || 0),
        teacher_id: Number(r.teacher_id || (isTeacher.value ? props.userId : 0) || 0),
        branch_id: Number(r.branch_id || r.CampusID || 0),
        session_date: String(r.session_date || '').slice(0, 10),
        start_time: String(r.start_time || '').slice(0, 5),
        end_time: String(r.end_time || '').slice(0, 5),
        student_name: r.student_name || '',
        subject_name: r.subject_name || '',
        teacher_name: r.teacher_name || '',
      }))
      .filter(r => r.class_session_id > 0 && r.student_id > 0 && r.student_class_id > 0)
      .sort((a, b) => (a.start_time || '').localeCompare(b.start_time || ''));

    const pendingSlotKeys = new Set();
    const pending = pendingRows.filter((r) => {
      const key = `${r.student_class_id}|${r.session_date}|${r.start_time}`;
      if (pendingSlotKeys.has(key)) return false;
      pendingSlotKeys.add(key);
      return true;
    });

    pendingSessions.value = pending;
    const next = {};
    pending.forEach(r => { next[r.class_session_id] = pendingMarkStatus.value[r.class_session_id] || 'present'; });
    pendingMarkStatus.value = next;

    // Prune selection for removed sessions
    const validIds = new Set(pending.map(s => s.class_session_id));
    selectedIds.value = selectedIds.value.filter(id => validIds.has(id));
  } catch (e) {
    console.error('fetchPendingSessions', e);
    pendingSessions.value = [];
  } finally {
    pendingLoading.value = false;
  }
};

// Single-item submit with confirmation for non-present
const submitPendingMark = async (s) => {
  const status = pendingMarkStatus.value[s.class_session_id] ?? 'present';

  if (status !== 'present' && status !== 'late') {
    confirmDialog.title = `確認${statusLabelMap[status]}`;
    confirmDialog.body = `${s.student_name}（${s.start_time}–${s.end_time} ${s.subject_name}）\n狀態：${statusLabelMap[status]}\n${status === 'leave' ? '請假將不扣堂並順延課程。' : '缺席將扣堂。'}`;
    confirmDialog.onConfirm = () => doSubmitPendingMark(s, status);
    confirmDialog.visible = true;
    return;
  }

  await doSubmitPendingMark(s, status).catch(() => {});
};

function extractApiError(err) {
  if (err.errors) {
    const first = Object.values(err.errors)[0];
    if (Array.isArray(first) && first.length) return first[0];
  }
  return err.message || '未知錯誤';
}

async function doSubmitPendingMark(s, status) {
  pendingMarkMsg.value = '';
  pendingMarkSubmitting.value = { ...pendingMarkSubmitting.value, [s.class_session_id]: true };
  try {
    const token = await getToken();
    if (!token) {
      const msg = '請先登入';
      pendingMarkMsg.value = msg; pendingMarkMsgType.value = 'error';
      throw new Error(msg);
    }
    const res = await fetch('/api/v1/attendance', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({
        StudentID: s.student_id,
        StudentClassID: s.student_class_id,
        TeacherID: s.teacher_id || props.userId || null,
        ClassSessionID: s.class_session_id,
        Status: status,
        mark_mode: 'arrival',
      })
    });
    if (res.ok) {
      const json = await res.json().catch(() => ({}));
      const label = statusLabelMap[status] || status;
      if (status === 'leave' && json.extended_end_date) {
        pendingMarkMsg.value = `已請假並順延：${s.student_name}，課程延至 ${json.extended_end_date}`;
      } else {
        pendingMarkMsg.value = `已核課：${s.student_name} ${label}`;
      }
      pendingMarkMsgType.value = 'success';
      await Promise.all([fetchPendingSessions(), fetchRecords()]);
    } else {
      const err = await res.json().catch(() => ({}));
      let msg;
      if (res.status === 428 && err.code === 'PASSWORD_CHANGE_REQUIRED') {
        msg = '請先至帳號設定變更密碼後再操作';
      } else if (res.status === 403) {
        msg = err.message === 'Forbidden' ? '無此課程的操作權限（非授課或代課老師）' : (err.message || '權限不足');
      } else {
        msg = extractApiError(err);
      }
      pendingMarkMsg.value = '核課失敗：' + msg;
      pendingMarkMsgType.value = 'error';
      throw new Error(msg);
    }
  } catch (e) {
    if (!pendingMarkMsg.value) {
      pendingMarkMsg.value = '核課失敗：網路錯誤';
      pendingMarkMsgType.value = 'error';
    }
    throw e;
  } finally {
    pendingMarkSubmitting.value = { ...pendingMarkSubmitting.value, [s.class_session_id]: false };
  }
}

// Batch mark all selected as "present" using the backend batch API
async function batchMarkAllPresent() {
  if (selectedIds.value.length === 0) return;
  batchSubmitting.value = true;
  batchResults.value = [];
  pendingMarkMsg.value = '';
  try {
    const token = await getToken();
    if (!token) { pendingMarkMsg.value = '請先登入'; pendingMarkMsgType.value = 'error'; return; }

    const sessionMap = {};
    pendingSessions.value.forEach(s => { sessionMap[s.class_session_id] = s; });

    const items = selectedIds.value.map(id => {
      const s = sessionMap[id];
      return {
        ClassSessionID: id,
        StudentID: s.student_id,
        StudentClassID: s.student_class_id,
        TeacherID: s.teacher_id || props.userId || null,
        Status: 'present',
        mark_mode: 'arrival',
      };
    });

    const res = await fetch('/api/v1/attendance/batch-mark', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ items }),
    });

    const json = await res.json().catch(() => ({}));

    if (json.results) {
      batchResults.value = json.results.map(r => ({
        ...r,
        student_name: sessionMap[r.class_session_id]?.student_name || '',
        error: r.success ? '' : (r.data?.message || '未知錯誤'),
      }));
    }

    if (json.success_count > 0) {
      pendingMarkMsg.value = `批次完成：${json.success_count} 成功` + (json.fail_count > 0 ? `，${json.fail_count} 失敗` : '');
      pendingMarkMsgType.value = json.fail_count > 0 ? 'error' : 'success';
    } else {
      pendingMarkMsg.value = '批次送出失敗';
      pendingMarkMsgType.value = 'error';
    }

    selectedIds.value = [];
    await Promise.all([fetchPendingSessions(), fetchRecords()]);
  } catch (e) {
    pendingMarkMsg.value = '批次送出失敗：網路錯誤';
    pendingMarkMsgType.value = 'error';
  } finally {
    batchSubmitting.value = false;
  }
}

const fetchMakeupSessions = async (page = 1) => {
  if (!isTeacher.value && !props.branchId) { makeupSessions.value = []; return; }
  makeupLoading.value = true;
  makeupMsg.value = '';
  try {
    const token = await getToken();
    if (!token) return;
    const qs = new URLSearchParams({
      start_date: makeupStartDate.value,
      end_date: makeupEndDate.value,
      per_page: '50',
      page: String(page),
    });
    if (props.branchId) qs.set('branch_id', String(props.branchId));
    const res = await fetch(`/api/v1/attendance/ended-sessions?${qs}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (res.ok) {
      const json = await res.json().catch(() => ({}));
      const rows = (Array.isArray(json?.data) ? json.data : []).map(r => ({
        class_session_id: Number(r.class_session_id || r.id || 0),
        student_id: Number(r.student_id || 0),
        student_class_id: Number(r.student_class_id || 0),
        teacher_id: Number(r.teacher_id || 0),
        session_date: String(r.session_date || '').slice(0, 10),
        start_time: String(r.start_time || '').slice(0, 5),
        end_time: String(r.end_time || '').slice(0, 5),
        student_name: r.student_name || '',
        subject_name: r.subject_name || '',
        teacher_name: r.teacher_name || '',
        session_status: String(r.session_status || 'scheduled').toLowerCase(),
      })).filter(r => r.class_session_id > 0 && r.student_id > 0 && r.student_class_id > 0);

      if (page === 1) {
        makeupSessions.value = rows;
      } else {
        makeupSessions.value = [...makeupSessions.value, ...rows];
      }
      makeupPage.value = page;
      makeupTotal.value = json.total ?? makeupSessions.value.length;
      makeupHasMore.value = json.current_page < json.last_page;
      const next = {};
      makeupSessions.value.forEach(r => { next[r.class_session_id] = makeupMarkStatus.value[r.class_session_id] || 'present'; });
      makeupMarkStatus.value = next;
    } else if (res.status === 403) {
      makeupMsg.value = '無此分校的存取權限';
      makeupMsgType.value = 'error';
    } else {
      const err = await res.json().catch(() => ({}));
      makeupMsg.value = '查詢失敗：' + (err.message || `HTTP ${res.status}`);
      makeupMsgType.value = 'error';
    }
  } catch (e) {
    console.error('fetchMakeupSessions', e);
    makeupMsg.value = '查詢失敗：網路錯誤';
    makeupMsgType.value = 'error';
  } finally {
    makeupLoading.value = false;
  }
};

const submitMakeupMark = async (s) => {
  makeupMsg.value = '';
  const status = makeupMarkStatus.value[s.class_session_id] ?? 'present';
  makeupMarkSubmitting.value = { ...makeupMarkSubmitting.value, [s.class_session_id]: true };
  try {
    const token = await getToken();
    if (!token) { makeupMsg.value = '請先登入'; makeupMsgType.value = 'error'; return; }
    const res = await fetch('/api/v1/attendance', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({
        StudentID: s.student_id,
        StudentClassID: s.student_class_id,
        TeacherID: s.teacher_id || null,
        ClassSessionID: s.class_session_id,
        Status: status,
      })
    });
    if (res.ok) {
      const json = await res.json().catch(() => ({}));
      const label = statusLabelMap[status] || status;
      if (status === 'leave' && json.extended_end_date) {
        makeupMsg.value = `已補登請假並順延：${s.student_name}，課程延至 ${json.extended_end_date}`;
      } else {
        makeupMsg.value = `已補登：${s.student_name} ${label}`;
      }
      makeupMsgType.value = 'success';
      makeupSessions.value = makeupSessions.value.filter(r => r.class_session_id !== s.class_session_id);
      makeupTotal.value = Math.max(0, makeupTotal.value - 1);
      fetchRecords();
    } else {
      const err = await res.json().catch(() => ({}));
      let msg;
      const staleKeywords = ['找不到可請假的堂次', '課程尚無堂次可請假', '該堂已完成請假登記', '已完成堂次不可請假', 'Attendance already recorded'];
      const errText = err.message || '';
      const isStale = (res.status === 422 || res.status === 409) && staleKeywords.some(k => errText.includes(k));
      if (res.status === 428 && err.code === 'PASSWORD_CHANGE_REQUIRED') {
        msg = '請先至帳號設定變更密碼後再操作';
      } else if (res.status === 403) {
        msg = err.message === 'Forbidden' ? '無此課程的操作權限（非授課或代課老師）' : (err.message || '權限不足');
      } else if (isStale) {
        msg = '此堂次狀態已變更，清單已自動更新';
      } else {
        msg = extractApiError(err);
      }
      makeupMsg.value = '補登失敗：' + msg;
      makeupMsgType.value = 'error';
      if (isStale) {
        fetchMakeupSessions();
      }
    }
  } catch (e) {
    if (!makeupMsg.value) {
      makeupMsg.value = '補登失敗：網路錯誤';
      makeupMsgType.value = 'error';
    }
  } finally {
    makeupMarkSubmitting.value = { ...makeupMarkSubmitting.value, [s.class_session_id]: false };
  }
};

const saveStatusEdit = async (record) => {
  if (record._newStatus === record.Status) { record._editing = false; return; }
  record._saving = true;
  try {
    const token = await getToken();
    if (!token) return;
    if (!record.ClassSessionID) {
      alert('此記錄缺少堂次關聯，無法修改狀態');
      record._saving = false;
      return;
    }

    if (record._newStatus === 'leave') {
      const isAttended = ['present', 'late'].includes(String(record.Status || '').toLowerCase());
      const confirmMsg = isAttended
        ? `此堂已登記到班，確定要補請假？\n（將作廢出缺勤記錄與評量記錄、沖回堂數，並補回一堂）`
        : `確定要將此堂標記為「請假」？\n系統將自動順延後續課程並補回一堂。`;
      if (!confirm(confirmMsg)) {
        record._saving = false;
        return;
      }

      let res;
      if (isAttended) {
        const sessionDate = String(record.SignInDT || '').slice(0, 10);
        res = await fetch('/api/v1/schedules/retro-leave', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
          body: JSON.stringify({
            student_course_id: record.StudentClassID,
            class_session_id: record.ClassSessionID,
            session_date: sessionDate,
            reason: '出缺勤頁補請假',
          }),
        });
      } else {
        res = await fetch('/api/v1/schedules/leave-by-session', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
          body: JSON.stringify({ class_session_id: record.ClassSessionID }),
        });
      }

      const json = await res.json().catch(() => ({}));
      if (res.ok) {
        record.Status = 'leave';
        record.status_label = '請假';
        record._editing = false;
        const endDate = json.extended_end_date ? `，課程延至 ${json.extended_end_date}` : '';
        pendingMarkMsg.value = isAttended
          ? `補請假完成，堂數已沖回${endDate}`
          : `已請假並順延後續課程${endDate}`;
        pendingMarkMsgType.value = 'success';
        await Promise.all([fetchPendingSessions(), fetchRecords()]);
      } else {
        alert('請假失敗：' + (json.message || '未知錯誤'));
      }
      return;
    }

    const statusMap = { present: 'attended', late: 'late', absent: 'absent' };
    const csStatus = statusMap[record._newStatus] || 'attended';
    const res = await fetch(`/api/v1/class-sessions/${record.ClassSessionID}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ status: csStatus }),
    });
    if (res.ok) {
      record.Status = record._newStatus;
      record.status_label = { present: '到班', late: '遲到', absent: '缺席' }[record._newStatus] || record._newStatus;
      record._editing = false;
      // TD-005: re-fetch so si.Status from DB is in sync (avoids 30s rollback)
      await fetchRecords();
    } else {
      const err = await res.json().catch(() => ({}));
      alert('修改失敗：' + (err.message || '未知錯誤'));
    }
  } catch (e) {
    alert('修改失敗：' + (e?.message || '網路錯誤'));
  } finally {
    record._saving = false;
  }
};

const submitManual = async () => {
  manualMsg.value = '';
  if (!manualForm.value.personKey) {
    manualMsg.value = '請選擇人員';
    manualMsgType.value = 'error';
    return;
  }
  const [personType, personIdStr] = manualForm.value.personKey.split(':');
  const personId = parseInt(personIdStr);
  const signInDT = `${manualForm.value.date} ${manualForm.value.time}:00`;
  try {
    const token = await getToken();
    const res = await fetch('/api/v1/attendance', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({
        PersonType: personType,
        PersonID: personId,
        SignInDT: signInDT,
        Status: manualForm.value.status,
        Memo: manualForm.value.memo
      })
    });
    if (res.ok) {
      const data = await res.json();
      manualMsg.value = `已登記：${data.person_name || ''}`;
      manualMsgType.value = 'success';
      manualForm.value.personKey = '';
      manualForm.value.memo = '';
      fetchRecords();
    } else {
      const err = await res.json();
      manualMsg.value = '登記失敗：' + (err.message || '未知錯誤');
      manualMsgType.value = 'error';
    }
  } catch (e) {
    manualMsg.value = '登記失敗：網路錯誤';
    manualMsgType.value = 'error';
  }
};

const fetchPending = async () => {
  if (isTeacher.value) { pendingSwipes.value = []; return; }
  try {
    const token = await getToken();
    if (!token) return;
    const res = await fetch('/api/v1/pending-swipes', { headers: { 'Authorization': `Bearer ${token}` } });
    if (res.ok) {
      const data = await res.json();
      pendingSwipes.value = data.data || data || [];
    }
  } catch (e) { console.error('fetchPending', e); }
};

const fetchStudents = async () => {
  if (isTeacher.value) { studentList.value = []; return; }
  try {
    const token = await getToken();
    if (!token) return;
    const res = await fetch(`/api/v1/students?per_page=500&branch_id=${props?.branchId || ''}`, { headers: { 'Authorization': `Bearer ${token}` } });
    if (res.ok) {
      const data = await res.json();
      studentList.value = data.data || data || [];
    }
  } catch (e) { console.error('fetchStudents', e); }
};

const handleDismiss = async (id) => {
  if (!confirm('確定忽略此刷卡紀錄？')) return;
  try {
    const token = await getToken();
    await fetch(`/api/v1/pending-swipes/${id}`, { method: 'DELETE', headers: { 'Authorization': `Bearer ${token}` } });
    fetchPending();
  } catch (e) { console.error(e); }
};

const refreshAll = () => {
  fetchError.value = '';
  batchResults.value = [];
  fetchRecords();
  fetchPendingSessions();
  fetchMyDiscrepanciesMap();
  if (!isTeacher.value) fetchPending();
};

// ── Schedule-discrepancy reporting (課表出入回報) ────────────────────
// Map of class_session_id → most recent active/resolved discrepancy authored by this user.
// Drives the "已回報" badge and the duplicate-guard behaviour on the report button.
const discrepancyMap = ref({});
const discrepancyModal = reactive({
  visible: false,
  mode: 'session',         // 'session' | 'missing'
  sessionId: null,
  sessionContext: null,
  existing: null,
  branchId: null,          // session's own campus — overrides currentBranch for cross-campus teachers
});
const discrepancyToast = reactive({ visible: false, text: '', tone: 'success' });
let toastTimer = null;

function showDiscrepancyToast(text, tone = 'success') {
  if (toastTimer) clearTimeout(toastTimer);
  discrepancyToast.text = text;
  discrepancyToast.tone = tone;
  discrepancyToast.visible = true;
  toastTimer = setTimeout(() => { discrepancyToast.visible = false; }, 3000);
}

async function fetchMyDiscrepanciesMap() {
  if (!isTeacher.value && !props.branchId) return;
  try {
    const branchId = props.branchId ? Number(props.branchId) : null;
    const resp = await fetchMyDiscrepancies({ branchId, perPage: 100 });
    const rows = Array.isArray(resp?.data) ? resp.data : [];
    const map = {};
    rows.forEach((r) => {
      const sid = Number(r?.class_session_id || 0);
      if (!sid) return;
      // Only the most recent active report (pending/acknowledged/resolved) per session.
      // Withdrawn / archived reports should not block a new report.
      if (r.status === 'withdrawn' || r.archived_at) return;
      const existing = map[sid];
      const created = new Date(r.created_at || 0).getTime();
      if (!existing || created > new Date(existing.created_at || 0).getTime()) {
        map[sid] = r;
      }
    });
    discrepancyMap.value = map;
  } catch (e) {
    console.warn('fetchMyDiscrepanciesMap', e);
  }
}

function getSessionDiscrepancy(sessionId) {
  return discrepancyMap.value[Number(sessionId) || 0] || null;
}

function openReportModalForSession(session) {
  const existing = getSessionDiscrepancy(session.class_session_id);
  discrepancyModal.mode = 'session';
  discrepancyModal.sessionId = session.class_session_id;
  discrepancyModal.branchId = session.branch_id || null;
  discrepancyModal.sessionContext = {
    date: session.session_date || localTodayYmd(),
    time: `${session.start_time || ''}${session.end_time ? '–' + session.end_time : ''}`,
    subject: session.subject_name || '',
    student: session.student_name || '',
  };
  discrepancyModal.existing = existing;
  discrepancyModal.visible = true;
}

function openReportModalMissing() {
  discrepancyModal.mode = 'missing';
  discrepancyModal.sessionId = null;
  discrepancyModal.sessionContext = null;
  discrepancyModal.existing = null;
  discrepancyModal.branchId = null;
  discrepancyModal.visible = true;
}

function closeDiscrepancyModal() {
  discrepancyModal.visible = false;
}

function onDiscrepancySubmitted(result) {
  // Server returns { duplicate: bool, discrepancy | existing }
  const record = result?.discrepancy || result?.existing || null;
  if (record && record.class_session_id) {
    discrepancyMap.value = { ...discrepancyMap.value, [Number(record.class_session_id)]: record };
  }
  if (result?.duplicate) {
    showDiscrepancyToast('此堂次已有待處理回報', 'info');
  } else {
    showDiscrepancyToast('已送出回報，主任會盡快處理', 'success');
  }
  closeDiscrepancyModal();
  // Refresh map to pick up reports that don't have a class_session_id (missing_session case).
  fetchMyDiscrepanciesMap();
}

function onDiscrepancyWithdrawn(result) {
  const existing = discrepancyModal.existing;
  const sid = Number(result?.discrepancy?.class_session_id || existing?.class_session_id || 0);
  if (sid) {
    const next = { ...discrepancyMap.value };
    delete next[sid];
    discrepancyMap.value = next;
  }
  showDiscrepancyToast('已撤銷回報', 'success');
  closeDiscrepancyModal();
}

function discrepancyBadgeLabel(disc) {
  if (!disc) return '';
  return DISCREPANCY_STATUS_LABELS[disc.status] || '已回報';
}

const formatTime = (dt) => {
  if (!dt) return '—';
  try {
    const d = new Date(dt);
    return d.toLocaleTimeString('zh-TW', { hour: '2-digit', minute: '2-digit' });
  } catch { return dt; }
};

const formatRecordDate = (dt) => {
  if (!dt) return '—';
  try {
    const d = new Date(dt);
    return d.toLocaleDateString('zh-TW', {
      month: '2-digit',
      day: '2-digit',
      weekday: 'short',
    });
  } catch { return String(dt).slice(0, 10) || '—'; }
};

const maskRfid = (rfid) => {
  if (!rfid || rfid.length <= 4) return rfid || '-';
  return rfid.slice(0, 2) + '****' + rfid.slice(-2);
};

const statusTagClass = (status) => {
  const map = { present: 'active', late: 'pending', leave: 'excused', excused: 'excused', absent: 'rejected' };
  return map[status] || '';
};

const reasonLabel = (reason) => {
  const map = {
    unknown_rfid: '未綁定卡片', student_not_found: '查無此學生',
    campus_mismatch: '分校不符', no_session: '無排課',
    no_match_in_window: '無匹配時段', ambiguous_session: '多堂課衝突'
  };
  return map[reason] || reason;
};

onMounted(() => {
  document.addEventListener('keydown', handleEsc);
  fetchRecords();
  fetchPendingSessions();
  fetchMakeupSessions();
  fetchMyDiscrepanciesMap();
  if (!isTeacher.value) {
    fetchPending();
    fetchStudents();
  } else {
    fetchTeacherCourses();
  }
  refreshTimer = setInterval(() => {
    fetchRecords();
    fetchPendingSessions();
    if (!isTeacher.value) fetchPending();
  }, 30000);
});

function handleEsc(e) {
  if (e.key !== 'Escape') return;
  if (deleteDialog.visible) closeDeleteDialog();
  else if (convertModal.visible) closeConvertModal();
}

onBeforeUnmount(() => {
  if (refreshTimer) clearInterval(refreshTimer);
  document.removeEventListener('keydown', handleEsc);
});

watch(() => props.branchId, () => {
  fetchRecords();
  fetchPendingSessions();
  fetchMakeupSessions();
  fetchMyDiscrepanciesMap();
  if (!isTeacher.value) {
    fetchPending();
    if (activeTab.value === 'teacher') fetchTeacherRecords();
  }
});
</script>

<style scoped>
.att-page { max-width: 1200px; }

.att-header {
  display: flex; justify-content: space-between; align-items: flex-start;
  flex-wrap: wrap; gap: 12px;
}
.att-header-btns { display: flex; gap: 8px; flex-wrap: wrap; }

/* Stats */
.att-stats {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px;
}
.att-stat-card {
  background: var(--card-bg, #fff); border-radius: 12px; padding: 16px 20px; text-align: center;
  border: 1px solid rgba(148,163,184,0.18); box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.att-stat-num { font-size: 28px; font-weight: 800; color: var(--text, #334155); }
.att-stat-label { font-size: 12px; font-weight: 600; color: #94a3b8; margin-top: 2px; text-transform: uppercase; letter-spacing: 0.5px; }
.stat-present .att-stat-num { color: #16a34a; }
.stat-late .att-stat-num { color: #d97706; }
.stat-absent .att-stat-num { color: #dc2626; }

/* Section */
.att-section-title {
  font-size: 15px; font-weight: 700; color: var(--primary);
  letter-spacing: 0.3px;
}
.att-checkin-header {
  display: flex; align-items: center; gap: 8px; margin-bottom: 12px;
}
.att-badge {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 22px; height: 22px; border-radius: 999px; padding: 0 6px;
  font-size: 12px; font-weight: 700; color: #fff; background: var(--primary);
}
.att-hint {
  font-size: 0.88rem; color: var(--text-light, #666); margin-bottom: 12px;
}
.att-empty {
  padding: 24px; text-align: center; font-size: 14px; color: #94a3b8;
}
.att-required { color: var(--danger); }

/* Check-in card */
.att-checkin-card { padding: 20px 24px; }

/* Batch action bar */
.att-batch-bar {
  display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
  margin-bottom: 14px; padding: 10px 12px;
  background: var(--primary-bg, rgba(232,121,36,0.06)); border-radius: 10px;
}
.att-check-all {
  display: flex; align-items: center; gap: 6px; cursor: pointer;
  font-size: 13px; font-weight: 600; color: var(--text);
  user-select: none;
}
.att-check-all input { width: 16px; height: 16px; accent-color: var(--primary); }
.att-batch-hint { font-size: 12px; color: var(--text-light); }

/* Status button group (replaces dropdown) */
.att-status-group {
  display: inline-flex; border-radius: 8px; overflow: hidden;
  border: 1px solid var(--border-color, #ddd);
}
.att-status-btn {
  padding: 4px 10px; font-size: 12px; font-weight: 600;
  border: none; background: var(--card-bg, #fff); color: var(--text-light);
  cursor: pointer; transition: all 0.15s; min-height: 30px;
  border-right: 1px solid var(--border-color, #ddd);
}
.att-status-btn:last-child { border-right: none; }
.att-status-btn:hover { background: rgba(0,0,0,0.04); }
.att-status-btn.active.att-st-present { background: #16a34a; color: #fff; }
.att-status-btn.active.att-st-late { background: #d97706; color: #fff; }
.att-status-btn.active.att-st-excused, .att-status-btn.active.att-st-leave { background: #1565C0; color: #fff; }
.att-status-btn.active.att-st-absent { background: #dc2626; color: #fff; }

/* Row selected highlight */
.att-row-selected { background: rgba(232,121,36,0.04); }
.att-row-selected td { background: transparent; }

/* Mobile card layout */
.att-cards { display: flex; flex-direction: column; gap: 10px; }
.att-card {
  border: 1.5px solid var(--border, rgba(148,163,184,0.2)); border-radius: 12px;
  padding: 14px; background: var(--card-bg, #fff); transition: border-color 0.15s;
}
.att-card-selected { border-color: var(--primary); background: var(--primary-bg, rgba(232,121,36,0.04)); }
.att-card-top { display: flex; align-items: flex-start; gap: 10px; }
.att-card-check { width: 18px; height: 18px; margin-top: 2px; accent-color: var(--primary); flex-shrink: 0; }
.att-card-info { flex: 1; min-width: 0; }
.att-card-student { font-size: 15px; font-weight: 700; color: var(--text); }
.att-card-meta { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; font-size: 13px; color: var(--text-light); }
.att-card-time { font-weight: 600; }
.att-card-actions { display: flex; align-items: center; gap: 10px; margin-top: 12px; }
.att-status-group-mobile { flex: 1; }
.att-status-group-mobile .att-status-btn { flex: 1; padding: 8px 4px; font-size: 13px; min-height: 40px; }
.att-card-submit { min-height: 40px; min-width: 56px; }

/* Sticky batch bar (mobile) */
.att-sticky-batch {
  position: fixed; bottom: calc(56px + env(safe-area-inset-bottom, 0px)); left: 0; right: 0; z-index: 50;
  display: flex; align-items: center; justify-content: space-between;
  padding: 12px 20px;
  background: var(--card-bg, #fff); border-top: 1px solid var(--border);
  box-shadow: 0 -2px 12px rgba(0,0,0,0.08);
  font-size: 14px; font-weight: 600; color: var(--text);
  will-change: transform; transform: translateZ(0);
}
.att-sticky-batch button { min-width: 100px; }

/* Batch results */
.att-batch-results {
  margin-top: 10px; display: flex; flex-direction: column; gap: 4px;
  max-height: 200px; overflow-y: auto;
}
.att-batch-result-item {
  display: flex; justify-content: space-between; padding: 6px 10px;
  border-radius: 6px; font-size: 13px;
}
.att-batch-result-item.success { background: var(--success-bg); color: var(--success); }
.att-batch-result-item.error { background: var(--danger-bg); color: var(--danger); }

/* Confirm dialog styles moved to non-scoped block (Teleport renders outside component root) */

/* Desktop / Mobile visibility */
.att-desktop-only { display: block; }
.att-mobile-only { display: none; }

.att-status-select {
  padding: 4px 8px; border-radius: 6px; border: 1px solid var(--border-color, #ddd);
  font-size: 13px;
}

/* Manual entry (collapsed) */
.att-manual-details {
  margin-top: 16px; border-top: 1px solid rgba(148,163,184,0.15); padding-top: 12px;
}
.att-manual-toggle {
  cursor: pointer; font-size: 13px; font-weight: 600; color: var(--primary);
  padding: 6px 0; user-select: none;
}
.att-manual-toggle:hover { text-decoration: underline; }
.att-manual-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 10px 14px; align-items: end; margin-top: 12px;
}
.att-submit-wrap { display: flex; flex-direction: column; }
.att-submit-wrap button { width: 100%; }

/* Records card */
.att-records-card { padding: 20px 24px; }
.att-records-header {
  display: flex; justify-content: space-between; align-items: center;
  flex-wrap: wrap; gap: 12px; margin-bottom: 16px;
}
.att-records-controls { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.att-search-input { width: 150px; padding: 7px 12px; font-size: 13px; }
.att-filter-select { width: 100px; padding: 7px 10px; font-size: 13px; }
.att-date-input { width: 140px; padding: 7px 10px; font-size: 13px; }
.att-records-date-badge { font-size: 12px; font-weight: 400; color: var(--color-primary, #4f46e5); background: #eef2ff; border-radius: 6px; padding: 2px 8px; margin-left: 8px; }
.att-mode-toggle { display: inline-flex; border-radius: 8px; overflow: hidden; border: 1px solid var(--border-color, #ddd); }
.att-mode-btn { padding: 5px 12px; font-size: 12px; font-weight: 600; border: none; background: var(--card-bg, #fff); color: var(--text-light); cursor: pointer; transition: all 0.15s; border-right: 1px solid var(--border-color, #ddd); }
.att-mode-btn:last-child { border-right: none; }
.att-mode-btn:hover { background: rgba(0,0,0,0.04); }
.att-mode-btn.active { background: var(--primary, #4f46e5); color: #fff; }

/* Table */
.att-table-scroll { overflow-x: auto; }
.att-time-range { font-weight: 600; font-size: 13.5px; white-space: nowrap; }
.att-time-sep { color: var(--text-light); font-size: 13px; }
.att-person-name { font-weight: 600; font-size: 13.5px; }
.att-rfid { font-family: 'Courier New', monospace; font-size: 13px; letter-spacing: 1px; color: var(--text-light); }

.att-record-row:hover { background: rgba(59,130,246,0.03); }

/* Inline edit */
.att-inline-edit { display: flex; gap: 4px; align-items: center; justify-content: flex-end; }
.att-inline-edit .att-status-select { font-size: 12px; padding: 2px 4px; }

/* Tags */
.status-tag.excused, .status-tag.leave { background: #E3F2FD; color: #1565C0; }
.status-tag.rejected { background: var(--danger-bg); color: var(--danger); }
.att-self-study-tag { background: #FEF3C7; color: #92400E; border: 1px solid #F59E0B; }

/* Messages */
.att-msg {
  margin-top: 10px; padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 500;
}
.att-msg.success { background: var(--success-bg); color: var(--success); }
.att-msg.error { background: var(--danger-bg); color: var(--danger); }

/* Refresh hint */
.att-refresh-hint {
  margin-top: 12px; font-size: 12px; color: var(--text-light); text-align: right;
}

/* Makeup attendance filters */
.att-makeup-filters {
  display: flex; gap: 12px; align-items: end; flex-wrap: wrap; margin-bottom: 16px;
}
.att-makeup-filters .form-group { min-width: 140px; }
.att-makeup-filters .att-submit-wrap { min-width: 112px; }
.att-badge-warn { background: #d97706; }
.att-load-more { text-align: center; padding: 12px 0; }

/* Pending card */
.att-pending-card { padding: 20px 24px; border-left: 4px solid var(--warning); }

/* ──────── Responsive ──────── */
@media (max-width: 768px) {
  .att-stats { grid-template-columns: repeat(2, 1fr); }
  .att-manual-grid { grid-template-columns: 1fr; }
  .att-records-header { flex-direction: column; align-items: stretch; }
  .att-records-controls { flex-direction: column; }
  .att-search-input, .att-filter-select { width: 100%; }
  .att-header { flex-direction: column; }
  .att-header-btns button { width: 100%; }

  .att-desktop-only { display: none; }
  .att-mobile-only { display: flex; }
  .att-sticky-batch { display: flex; }

  .att-makeup-card {
    padding-bottom: calc(96px + env(safe-area-inset-bottom, 0px));
  }
  .att-makeup-filters {
    display: grid;
    grid-template-columns: 1fr;
    align-items: stretch;
    gap: 10px;
  }
  .att-makeup-filters .form-group,
  .att-makeup-filters .att-submit-wrap {
    min-width: 0;
    width: 100%;
  }
  .att-makeup-filters .att-submit-wrap label {
    display: none;
  }
  .att-makeup-filters .att-submit-wrap button {
    min-height: 46px;
    width: 100%;
  }
  .att-makeup-card .att-table-scroll {
    margin-bottom: 16px;
  }

}

@media (max-width: 480px) {
  .att-stats { grid-template-columns: repeat(2, 1fr); gap: 8px; }
  .att-stat-card { padding: 12px; }
  .att-stat-num { font-size: 22px; }
  .att-checkin-card, .att-records-card, .att-pending-card { padding: 16px; }
  .att-card { padding: 12px; }
  .att-status-group-mobile .att-status-btn { padding: 8px 2px; font-size: 12px; }
}

/* ── Missing CTA actions ─────────────────────────────────────── */
.att-missing-cta-actions {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-left: auto;
  flex-wrap: wrap;
}
.att-build-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 6px 12px;
  min-height: 44px;
  background: var(--primary, #2563eb);
  color: #fff;
  border: none;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s;
}
.att-build-btn:hover { background: var(--primary-hover, #1d4ed8); }
.att-build-btn.active { background: var(--primary-hover, #1d4ed8); }
.att-build-btn .material-symbols-outlined { font-size: 17px; }

/* ── Teacher quick-attend inline form ───────────────────────── */
.att-quick-attend-wrap {
  max-height: 0;
  overflow: hidden;
  transition: max-height 0.15s ease;
}
.att-quick-attend-wrap.open {
  max-height: 500px;
}
.att-quick-attend-form {
  border: 1px solid var(--border-soft, #cbd5e1);
  border-radius: 10px;
  padding: 16px;
  margin-top: 10px;
  background: var(--surface-muted, #f8fafc);
}
.att-quick-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 10px 14px;
  margin-bottom: 12px;
}
.att-quick-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
.att-field-err {
  font-size: 12px;
  color: var(--danger, #dc2626);
  margin: 4px 0 0;
}
.att-skeleton-bar {
  height: 36px;
  background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%);
  background-size: 200% 100%;
  animation: att-shimmer 1.2s infinite;
  border-radius: 6px;
}
@keyframes att-shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}
.att-spin {
  animation: att-spin-anim 0.8s linear infinite;
  font-size: 16px;
  vertical-align: middle;
}
@keyframes att-spin-anim { to { transform: rotate(360deg); } }

/* ── Director mode tabs ─────────────────────────────────────── */
.att-dir-mode-tabs {
  display: flex;
  gap: 0;
  margin-top: 14px;
  margin-bottom: 12px;
  border: 1px solid var(--border-color, #ddd);
  border-radius: 8px;
  overflow: hidden;
  width: fit-content;
}
.att-dir-mode-tab {
  padding: 6px 16px;
  border: none;
  background: #fff;
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  color: #64748b;
  transition: background 0.12s, color 0.12s;
  min-height: 36px;
}
.att-dir-mode-tab + .att-dir-mode-tab { border-left: 1px solid var(--border-color, #ddd); }
.att-dir-mode-tab.active { background: var(--primary, #2563eb); color: #fff; font-weight: 600; }

@media (max-width: 768px) {
  .att-quick-grid { grid-template-columns: 1fr; }
  .att-missing-cta { flex-wrap: wrap; }
  .att-missing-cta-actions { width: 100%; justify-content: flex-end; }
}
@media (max-width: 480px) {
  .att-missing-cta-actions { flex-direction: column; align-items: stretch; }
  .att-build-btn, .att-missing-link { width: 100%; justify-content: center; }
}
</style>

<!-- Non-scoped: Teleport'd confirm dialog renders outside component root -->
<style>
.att-confirm-overlay {
  position: fixed; inset: 0; z-index: 10100; background: rgba(0,0,0,0.4);
  display: flex; align-items: flex-end; justify-content: center;
}
.att-confirm-sheet {
  background: var(--card-bg, #fff); border-radius: 16px 16px 0 0;
  padding: 24px; padding-bottom: calc(24px + env(safe-area-inset-bottom, 0px));
  width: 100%; max-width: 480px;
  box-shadow: 0 -4px 24px rgba(0,0,0,0.12);
}
.att-confirm-title { font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 12px; }
.att-confirm-body { font-size: 14px; color: var(--text-light); white-space: pre-line; margin-bottom: 20px; line-height: 1.6; }
.att-confirm-actions { display: flex; gap: 10px; justify-content: flex-end; }
.att-confirm-actions button { min-width: 80px; min-height: 40px; }

@media (max-width: 768px) {
  .att-confirm-overlay { align-items: flex-end; }
}
@media (min-width: 769px) {
  .att-confirm-overlay { align-items: center; }
  .att-confirm-sheet { border-radius: 16px; }
}

/* ── Schedule-discrepancy UI ─────────────────────────────────── */
.att-ops-stack {
  display: flex;
  flex-direction: column;
  gap: 6px;
  align-items: flex-end;
}

.att-report-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 6px 10px;
  min-height: 36px;
  border: 1px solid var(--warning-border, #fde68a);
  background: var(--warning-soft, #fffbeb);
  color: var(--warning-strong, #b45309);
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: background 120ms ease, border-color 120ms ease;
}
.att-report-btn:hover { background: var(--warning-soft-hover, #fef3c7); }
.att-report-btn .material-symbols-outlined { font-size: 16px; }
.att-report-btn-active {
  border-color: var(--warning, #f59e0b);
  background: var(--warning, #f59e0b);
  color: #fff;
}
.att-report-btn-active:hover { background: #d97706; border-color: #d97706; }

.att-report-btn-mobile {
  min-height: 44px;
  padding: 10px 14px;
  font-size: 13px;
}

.att-card-cta-row {
  display: flex;
  gap: 6px;
  align-items: center;
}

.att-report-badge {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 8px;
  font-size: 11px;
  font-weight: 600;
  border-radius: 999px;
  margin-left: 6px;
  vertical-align: middle;
  border: 1px solid transparent;
  white-space: nowrap;
}
.att-report-badge .material-symbols-outlined { font-size: 13px; }
.att-report-badge-pending,
.att-report-badge-acknowledged {
  background: var(--warning-soft, #fffbeb);
  color: var(--warning-strong, #b45309);
  border-color: var(--warning-border, #fde68a);
}
.att-report-badge-acknowledged {
  background: var(--info-soft, #eff6ff);
  color: var(--info-strong, #1d4ed8);
  border-color: var(--info-border, #bfdbfe);
}
.att-report-badge-resolved {
  background: var(--success-soft, #ecfdf5);
  color: var(--success-strong, #047857);
  border-color: var(--success-border, #a7f3d0);
}
.att-report-badge-mobile {
  margin-left: 0;
  margin-top: 4px;
  display: inline-flex;
}

.att-missing-cta {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 12px;
  padding: 10px 14px;
  border: 1px dashed var(--border-soft, #cbd5e1);
  border-radius: 8px;
  background: var(--surface-muted, #f8fafc);
  color: var(--text-light, #64748b);
  font-size: 13px;
}
.att-missing-cta .material-symbols-outlined { font-size: 18px; color: var(--warning, #f59e0b); }
.att-missing-link {
  margin-left: auto;
  background: none;
  border: 0;
  color: var(--primary, #2563eb);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  padding: 6px 10px;
  min-height: 44px;
  text-decoration: underline;
}
.att-missing-link:hover { color: var(--primary-hover, #1d4ed8); }

/* Toast */
.sd-toast {
  position: fixed;
  top: 24px;
  right: 24px;
  z-index: 10060;
  background: var(--success-strong, #047857);
  color: #fff;
  padding: 12px 18px;
  border-radius: 8px;
  box-shadow: 0 6px 16px rgba(15, 23, 42, 0.2);
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  max-width: calc(100vw - 48px);
}
.sd-toast-error { background: var(--danger, #ef4444); }
.sd-toast-info { background: var(--info-strong, #1d4ed8); }
.sd-toast .material-symbols-outlined { font-size: 20px; }
.sd-toast-enter-active,
.sd-toast-leave-active { transition: all 200ms ease; }
.sd-toast-enter-from { opacity: 0; transform: translateY(-8px); }
.sd-toast-leave-to { opacity: 0; transform: translateY(-8px); }

@media (max-width: 480px) {
  .sd-toast { top: 12px; right: 12px; left: 12px; }
}

/* ──────── Tab Switcher ──────── */
.att-tabs {
  display: flex;
  border-bottom: 2px solid var(--border);
  margin-bottom: 16px;
}
.att-tab-btn {
  padding: 10px 20px;
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  font-size: 14px;
  font-weight: 500;
  color: var(--text-muted, #64748b);
  cursor: pointer;
  transition: color 0.15s, border-color 0.15s;
  min-height: 44px;
}
.att-tab-btn:hover { color: var(--text-primary, #0f172a); }
.att-tab-btn.active {
  color: var(--primary, #2563eb);
  border-bottom-color: var(--primary, #2563eb);
  font-weight: 700;
}

/* ──────── Teacher Tab Content ──────── */
.ta-anomaly-list { display: flex; flex-direction: column; gap: 8px; margin-top: 8px; }
.ta-anomaly-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 12px;
  background: var(--surface-muted, #f8fafc);
  border-radius: 8px; gap: 8px;
}
.ta-row-info { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; flex: 1; }
.ta-name { font-weight: 600; font-size: 14px; }
.ta-time { font-size: 13px; color: var(--text-secondary, #475569); }
.ta-cell-warn { color: var(--color-warning, #e65100); font-weight: 500; }
.ta-cell-muted { color: var(--text-muted, #94a3b8); font-size: 13px; }
.ta-unclosed-row {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 0;
  border-top: 1px solid var(--border);
  font-size: 14px;
}
.att-date-input {
  border: 1px solid var(--border); border-radius: 6px;
  padding: 4px 8px; font-size: 13px;
}

/* Teacher Status Badges */
.ts-badge-ok    { background: #e6f4ea; color: #1b7c3d; padding: 2px 8px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.ts-badge-late  { background: #fce8e6; color: #c62828; padding: 2px 8px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.ts-badge-warn  { background: #fff3e0; color: #e65100; padding: 2px 8px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.ts-badge-error { background: #fce8e6; color: #c62828; padding: 2px 8px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.ts-badge-muted { background: #f1f5f9; color: #64748b; padding: 2px 8px; border-radius: 20px; font-size: 12px; font-weight: 600; }

/* 行政出勤區（source_only — 到班但無排課，正常狀態） */
.ta-onduty-section {
  margin-top: 12px;
  padding: 10px 12px;
  background: #f0fdf4;
  border-radius: 8px;
  border: 1px solid #bbf7d0;
}
.ta-onduty-title {
  font-size: 12px;
  color: #15803d;
  font-weight: 600;
  margin-bottom: 8px;
}
.ta-onduty-list { display: flex; flex-wrap: wrap; gap: 6px; }
.ta-onduty-chip {
  font-size: 12px;
  background: #dcfce7;
  color: #166534;
  padding: 2px 10px;
  border-radius: 20px;
}

/* 系統待確認提示（pending_review — 資料問題，非人工缺失） */
.ta-sys-pending {
  margin-top: 10px;
  padding: 8px 12px;
  background: #f8fafc;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
  font-size: 12px;
  color: #64748b;
}

/* ── DeleteDialog / ConvertModal 共用 overlay ── */
.att-overlay {
  position: fixed; inset: 0; z-index: 10200;
  background: rgba(0,0,0,0.45);
  display: flex; align-items: center; justify-content: center;
  padding: 16px;
}
.att-dialog {
  background: var(--card-bg, #fff);
  border-radius: 12px;
  padding: 24px;
  width: 100%; max-width: 420px;
  box-shadow: 0 8px 32px rgba(0,0,0,0.18);
}
.att-dialog-summary {
  background: var(--bg-secondary, #f5f5f5);
  border-radius: 8px;
  padding: 10px 14px;
  font-size: 14px;
  display: flex; flex-direction: column; gap: 4px;
  margin-bottom: 16px;
}
.att-dialog-label { font-weight: 600; margin-right: 6px; }
.att-dialog-field { display: flex; flex-direction: column; gap: 4px; }
.att-dialog-textarea {
  width: 100%; resize: vertical; min-height: 72px;
  border: 1px solid var(--border, #d1d5db);
  border-radius: 8px; padding: 8px 10px;
  font-size: 14px; font-family: inherit;
  background: var(--card-bg, #fff);
  color: var(--text-primary, #111);
}
.att-dialog-textarea:focus { outline: 2px solid var(--color-primary, #1a73e8); outline-offset: 1px; }
.att-dialog-actions {
  display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px;
}
button.danger { background: var(--color-danger, #d32f2f) !important; color: #fff !important; }
button.danger:hover:not(:disabled) { background: #b71c1c !important; }

/* ── 課程選擇列表 ── */
.att-course-list {
  display: flex; flex-direction: column; gap: 8px;
  max-height: 240px; overflow-y: auto;
  padding: 4px 2px;
}
.att-course-item {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 12px;
  border: 1px solid var(--border, #e0e0e0);
  border-radius: 8px; cursor: pointer;
  transition: border-color .15s, background .15s;
}
.att-course-item:hover:not(.disabled) { border-color: var(--color-primary, #1a73e8); background: #f0f4ff; }
.att-course-item.disabled { opacity: 0.5; cursor: not-allowed; }
.att-course-info { display: flex; flex-direction: column; gap: 2px; }
.att-course-name { font-size: 14px; font-weight: 500; }
.att-course-teacher { font-size: 12px; color: var(--text-secondary, #666); }
.att-course-sessions { font-size: 12px; color: var(--text-secondary, #666); }
.att-course-sessions.sessions-empty { color: var(--color-danger, #d32f2f); }

/* ── spinner for month export loading ── */
.att-spinner {
  border: 2px solid rgba(0,0,0,0.15);
  border-top-color: var(--color-primary, #1a73e8);
  border-radius: 50%; animation: spin .7s linear infinite;
  vertical-align: -2px;
}
@keyframes spin { to { transform: rotate(360deg); } }
</style>
