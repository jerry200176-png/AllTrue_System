<template>
  <div class="lr-page">
    <!-- Page Header -->
    <div class="page-header lr-header" data-guide="learning-header">
      <div>
        <h2>{{ isTeacher ? '我的課表 & 評量' : '學習評量表' }}</h2>
        <p class="page-desc">{{ isTeacher ? '查看本週課表，填寫學習評量' : '查看、新增與審核學生每堂課的學習評量' }}</p>
      </div>
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button v-if="isTeacher" class="ghost lr-draft-list-btn" @click="openDraftPanel">
          <span class="material-symbols-outlined" style="font-size:16px;vertical-align:-3px">drafts</span>
          草稿
          <span v-if="draftList.length > 0" class="lr-draft-badge">{{ draftList.length }}</span>
        </button>
        <button class="ghost" @click="openExportModal">匯出評量圖</button>
        <button v-if="isTeacher" class="primary" @click="focusTeacherSchedule">從課表填寫</button>
      </div>
    </div>

    <!-- Teacher quick-filter tabs -->
    <div v-if="isTeacher" class="lr-review-tabs card" data-guide="learning-teacher-tabs">
      <div class="lr-tabs-row">
        <button :class="['lr-tab', { active: teacherFilterTab === 'all' }]" @click="teacherFilterTab = 'all'">
          全部 <span class="lr-tab-count">{{ (records || []).length }}</span>
        </button>
        <button :class="['lr-tab', { active: teacherFilterTab === 'pending' }]" @click="teacherFilterTab = 'pending'">
          待審核
        </button>
        <button :class="['lr-tab', { active: teacherFilterTab === 'changes_requested' }]" @click="teacherFilterTab = 'changes_requested'">
          需修改 <span v-if="changesRequestedCount > 0" class="lr-tab-count warn">{{ changesRequestedCount }}</span>
        </button>
        <button :class="['lr-tab', { active: teacherFilterTab === 'approved' }]" @click="teacherFilterTab = 'approved'">
          已核准 <span class="lr-tab-count ok">{{ approvedCount }}</span>
        </button>
      </div>
      <div class="lr-tab-hint">從課表點擊堂次 → 填寫或編輯評量。已核准的評量僅供檢視。</div>
    </div>

    <!-- Director review queue tabs -->
    <div v-if="isDirectorRole" class="lr-review-tabs card" data-guide="learning-director-review-tabs">
      <div class="lr-tabs-row">
        <button :class="['lr-tab', { active: reviewTab === 'pending' }]" @click="reviewTab = 'pending'; selectedRecordIds = new Set()">
          待審佇列 <span v-if="pendingCount > 0" class="lr-tab-count warn">{{ pendingCount }}</span>
        </button>
        <button :class="['lr-tab', { active: reviewTab === 'changes_requested' }]" @click="reviewTab = 'changes_requested'; selectedRecordIds = new Set()">
          需修改追蹤 <span v-if="changesRequestedCount > 0" class="lr-tab-count warn">{{ changesRequestedCount }}</span>
        </button>
        <button :class="['lr-tab', { active: reviewTab === 'approved' }]" @click="reviewTab = 'approved'; selectedRecordIds = new Set()">
          已核准 <span class="lr-tab-count ok">{{ approvedCount }}</span>
        </button>
        <button :class="['lr-tab', { active: reviewTab === 'rejected' }]" @click="reviewTab = 'rejected'; selectedRecordIds = new Set()">
          已退回
        </button>
        <button :class="['lr-tab', { active: reviewTab === 'all' }]" @click="reviewTab = 'all'; selectedRecordIds = new Set()">
          全部
        </button>
      </div>

      <label v-if="reviewTab === 'pending' || reviewTab === 'changes_requested' || reviewTab === 'all'" class="lr-unfilled-toggle">
        <input type="checkbox" v-model="onlyUnfilled"> 只看未填
      </label>

      <!-- Batch action bar -->
      <div v-if="selectedRecordIds.size > 0" class="lr-batch-bar">
        <span class="lr-batch-count">已選 {{ selectedRecordIds.size }} 筆</span>
        <button class="primary xs" :disabled="batchOperating" @click="batchApproveSelected">批次核准</button>
        <button class="ghost xs" :disabled="batchOperating" @click="batchRequestChangesSelected">批次需修改</button>
        <button class="danger xs" :disabled="batchOperating" @click="batchRejectSelected">批次退回</button>
        <button class="ghost xs" @click="selectedRecordIds = new Set()">取消選取</button>
      </div>
    </div>

    <div v-if="!isTeacher && !isDirectorRole" class="card lr-teacher-entry" data-guide="learning-teacher-login-entry">
      <div class="lr-teacher-entry__text">
        <h3>老師填寫入口</h3>
        <p>評量與課程綁定，老師登入後可直接從課表點堂次填寫，不需手動輸入學生、老師與時段。</p>
      </div>
      <button class="primary" @click="switchToTeacherLogin">切換到老師登入</button>
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
          :class="{ 'ts-event-leave': ev.isLeave, 'ts-event-cancelled': ev.isCancelled }"
        >
          <div class="ts-time">{{ ev.timeRange }}</div>
          <div class="ts-info">
            <div class="ts-student">{{ ev.studentName }}</div>
            <div class="ts-subject-row">
              <span class="ts-subject">{{ ev.subjectName }}</span>
              <span :class="['ts-status-chip', `status-${ev.formStatus}`]">{{ ev.formStatusLabel }}</span>
            </div>
          </div>
          <button
            class="ts-fill-btn"
            :disabled="(!ev.recordId && ev.fillLocked) || ev.isLeave || ev.isCancelled"
            :title="(ev.isLeave || ev.isCancelled) ? ev.fillLockReason : (!ev.recordId && ev.fillLocked ? ev.fillLockReason : '')"
            @click="openFromScheduleMaybe(ev)"
          >{{ scheduleActionLabel(ev) }}</button>
        </div>
      </div>

      <!-- Week view: summary when no items need filling -->
      <div
        v-if="scheduleView === 'week' && weekHasEvents && weekTotalMissingCount === 0"
        class="ts-week-allclear"
      >本週無待填評量</div>

      <!-- Week view -->
      <div v-if="scheduleView === 'week'" class="ts-week-scroll">
      <div class="ts-week">
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
            :class="{
              locked: !ev.recordId && ev.fillLocked && !ev.isLeave && !ev.isCancelled,
              substituted: ev.isSubstituted,
              'ts-event-leave': ev.isLeave,
              'ts-event-cancelled': ev.isCancelled,
            }"
            @click="openFromScheduleMaybe(ev)"
          >
            <div class="ts-time">{{ ev.timeRange }}</div>
            <div class="ts-info">
              <div class="ts-student">{{ ev.studentName }}</div>
              <div class="ts-subject-row">
                <span class="ts-subject">{{ ev.subjectName }}</span>
                <span :class="['ts-status-chip', `status-${ev.formStatus}`]">{{ ev.formStatusLabel }}</span>
              </div>
            </div>
            <span class="ts-fill-hint">{{ scheduleActionLabel(ev) }}</span>
          </div>
        </div>
      </div>
      </div>
    </div>

    <!-- ===== 一鍵補登 Modal ===== -->
    <div v-if="showBulkModal" class="modal-overlay">
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
        <div class="form-group">
          <label>指定日期</label>
          <div class="lr-date-filter-wrap">
            <input v-model="filters.date" type="date" class="lr-date-input" @change="fetchRecords">
            <button v-if="filters.date" class="ghost xs lr-date-clear" @click="filters.date = ''; fetchRecords()" title="清除日期篩選">✕</button>
          </div>
        </div>
        <div class="form-group lr-filter-btn-wrap">
          <label>&nbsp;</label>
          <button class="ghost" @click="fetchRecords">搜尋</button>
        </div>
      </div>
    </div>

    <!-- ===== Records Grouped By Student ===== -->
    <div class="card lr-table-card" data-guide="learning-table">
      <div v-if="filteredGroupedRecords.length === 0" class="empty-text" style="padding: 24px;">
        {{ isDirectorRole && reviewTab === 'pending' ? '目前沒有待審評量 🎉' : '尚無評量資料' }}
      </div>

      <div v-else class="lr-groups">
        <details
          v-for="(group, groupIndex) in filteredGroupedRecords"
          :key="group.key"
          class="lr-group"
          :open="groupIndex === 0 || filteredGroupedRecords.length <= 5"
        >
          <summary class="lr-group-summary">
            <div class="lr-group-title">
              <span class="lr-group-student">{{ group.student_name }}</span>
              <span class="lr-group-count">{{ group.records.length }} 筆</span>
              <span v-if="group.pending_count > 0" class="lr-group-pending">{{ group.pending_count }} 待處理</span>
              <span v-if="group.unfilled_body_count > 0" class="lr-group-unfilled">{{ group.unfilled_body_count }} 未填</span>
            </div>
            <span class="lr-group-hint">展開 / 收合</span>
          </summary>

          <div class="lr-table-scroll">
            <table>
              <thead>
                <tr>
                  <th v-if="isDirectorRole && (reviewTab === 'pending' || reviewTab === 'changes_requested')" style="width:36px">
                    <input type="checkbox" :checked="allSelected" @change="toggleSelectAll" title="全選">
                  </th>
                  <th>日期</th>
                  <th>學生 / 班級</th>
                  <th>科目</th>
                  <th v-if="!isTeacher">授課老師</th>
                  <th>填寫</th>
                  <th>狀態</th>
                  <th style="text-align:right">操作</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="record in group.records" :key="record.id" class="lr-table-row" :class="{ 'lr-row-unfilled': fillLabelClass(record) === 'fill-missing' }" @click="viewRecord(record)">
                  <td v-if="isDirectorRole && (reviewTab === 'pending' || reviewTab === 'changes_requested')" @click.stop>
                    <input
                      v-if="record.Status === 'pending' || record.Status === 'changes_requested'"
                      type="checkbox"
                      :checked="selectedRecordIds.has(record.id)"
                      @change="toggleRecordSelection(record.id)"
                    >
                  </td>
                  <td>
                    <span class="lr-date">{{ record.SessionDate }}</span>
                    <span class="lr-time">{{ record.StartTime }}</span>
                    <span v-if="record.session_number" class="lr-session-num">第{{ record.session_number }}堂</span>
                  </td>
                  <td>
                    <div class="lr-student-name">{{ record.student_name }}</div>
                    <div class="lr-class-label">{{ record.student_class_label || record.Subject }}</div>
                  </td>
                  <td>
                    <span class="tag">{{ record.student_class_label || record.Subject }}</span>
                  </td>
                  <td v-if="!isTeacher">{{ record.teacher_name }}</td>
                  <td>
                    <span v-if="fillLabel(record)" :class="['fill-badge', fillLabelClass(record)]">{{ fillLabel(record) }}</span>
                    <span v-else class="fill-badge-na">—</span>
                  </td>
                  <td>
                    <span :class="statusTagClass(record.Status)" class="status-tag">
                      {{ statusLabel(record.Status) }}
                    </span>
                  </td>
                  <td class="lr-actions" @click.stop>
                    <div class="lr-actions-inner">
                      <button class="ghost xs" @click="openRecordAction(record)">{{ primaryActionLabel(record) }}</button>
                      <button v-if="canChangeTeacher(record)" class="ghost xs" @click="openChangeTeacherModal(record)">換老師</button>
                      <span v-if="showTimeLockHint(record)" class="lr-lock-hint">未開放</span>
                      <button v-if="canApprove(record)" class="primary xs" @click="approveRecord(record)">核准</button>
                      <button v-if="canRequestChanges(record)" class="ghost xs" @click="requestChangesRecord(record)">需修改</button>
                      <button v-if="canReject(record)" class="danger xs" @click="rejectRecord(record)">退回</button>
                      <button v-if="canRollbackApproval(record)" class="ghost xs" @click="rollbackApproval(record)">退回待審</button>
                      <button v-if="canDelete(record)" class="danger xs" @click="deleteRecord(record)">刪除</button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </details>
      </div>

      <!-- Load More -->
      <div v-if="recordsPagination.currentPage < recordsPagination.lastPage" class="lr-load-more">
        <button class="ghost" :disabled="recordsPagination.loading" @click="loadMoreRecords">
          {{ recordsPagination.loading ? '載入中...' : `載入更多（已顯示 ${records.length} / ${recordsPagination.total} 筆）` }}
        </button>
      </div>
    </div>

    <div v-if="showChangeTeacherModal" class="modal-overlay">
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
                <textarea v-model="teacherChangeForm.reason" rows="3" placeholder="例如：轉由陳老師接手授課"></textarea>
              </div>
              <div class="form-group" style="grid-column: 1 / -1;">
                <small style="color: #888;">預設僅代課此堂，不影響後續排課</small>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin-top: 4px;">
                  <input type="checkbox" v-model="teacherChangeForm.update_class" style="width: auto;">
                  同時更換此課程的授課老師（影響後續所有堂次）
                </label>
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
    <div v-if="showModal" class="modal-overlay">
      <div class="modal lr-modal">
        <!-- Modal Header -->
        <div class="lr-modal-header">
          <h3>{{ isEditing ? (isReadOnly ? '檢視評量' : '編輯評量') : '新增學習評量' }}<span v-if="_activeRecordRef?.session_number" class="lr-modal-session-num">第{{ _activeRecordRef.session_number }}堂</span></h3>
          <div class="lr-modal-header-actions">
            <button
              v-if="isReadOnly"
              class="lr-download-btn"
              :disabled="downloadingPng"
              @click="downloadSingleRecord"
              title="下載圖檔"
            >
              <span class="material-symbols-outlined">download</span>
              <span class="lr-download-label">{{ downloadingPng ? '下載中…' : '下載圖檔' }}</span>
            </button>
            <button class="lr-modal-close" @click="closeModal">&times;</button>
          </div>
          <Transition name="lr-toast">
            <div v-if="downloadToast" class="lr-download-toast" :class="{ 'lr-toast-error': downloadToast.includes('失敗') }">
              <span class="material-symbols-outlined">{{ downloadToast.includes('失敗') ? 'error' : 'check_circle' }}</span>
              {{ downloadToast }}
            </div>
          </Transition>
        </div>

        <form @submit.prevent="submitForm" class="lr-form">
          <!-- Draft status bar -->
          <div v-if="draftStatusText && !isReadOnly" class="lr-draft-bar" :class="{ 'lr-draft-bar--error': draftSaveError }">
            <span class="material-symbols-outlined lr-draft-bar-icon">{{ draftSaveError ? 'warning' : 'edit_note' }}</span>
            <span class="lr-draft-bar-text">{{ draftStatusText }}</span>
            <button v-if="!draftSaveError" type="button" class="lr-draft-bar-clear" @click="clearDraft" title="清除草稿">清除草稿</button>
          </div>

          <!-- Section 1: 基本資訊 -->
          <div class="lr-form-section">
            <div class="lr-form-section-title">基本資訊</div>
            <div class="lr-form-grid">
              <div class="form-group">
                <label>{{ isTeacher ? '學生' : '選擇學生' }} <span class="lr-required">*</span></label>
                <template v-if="isTeacher">
                  <div class="lr-readonly-field">{{ currentStudentName }}</div>
                </template>
                <SearchableSelect
                  v-else
                  v-model="form.StudentID"
                  :options="studentOptions"
                  :disabled="isReadOnly || isEditing"
                  placeholder="搜尋並選擇學生..."
                />
              </div>
              <div class="form-group">
                <label>授課老師 <span class="lr-required">*</span></label>
                <template v-if="isTeacher">
                  <div class="lr-readonly-field">{{ currentTeacherName }}</div>
                </template>
                <SearchableSelect
                  v-else
                  v-model="form.TeacherID"
                  :options="teacherOptions"
                  :disabled="isReadOnly"
                  placeholder="搜尋並選擇老師..."
                />
              </div>
              <div class="form-group">
                <label>授課科目 <span class="lr-required">*</span></label>
                <div v-if="isTeacher" class="lr-readonly-field">{{ form.Subject || '—' }}</div>
                <select v-else v-model="form.Subject" :disabled="isReadOnly">
                  <option value="">請選擇科目</option>
                  <option v-for="s in subjectList" :key="s" :value="s">{{ s }}</option>
                </select>
              </div>
              <div class="form-group">
                <label>上課日期 <span class="lr-required">*</span></label>
                <div v-if="isTeacher" class="lr-readonly-field">{{ form.SessionDate || '—' }}</div>
                <input v-else v-model="form.SessionDate" type="date" :disabled="isReadOnly || isSessionDateLocked">
              </div>
              <template v-if="isSessionTimeLocked">
                <div class="form-group">
                  <label>開始時間 <span class="lr-lock-badge"><span class="material-symbols-outlined" style="font-size:12px;vertical-align:-1px">lock</span> 已帶入</span></label>
                  <div class="lr-readonly-time" :title="formatTimeForDisplay(form.StartTime)">
                    <span class="material-symbols-outlined" style="font-size:15px;color:#FB8C00;flex-shrink:0">schedule</span>
                    {{ formatTimeForDisplay(form.StartTime) }}
                  </div>
                </div>
                <div class="form-group">
                  <label>結束時間 <span class="lr-lock-badge"><span class="material-symbols-outlined" style="font-size:12px;vertical-align:-1px">lock</span> 已帶入</span></label>
                  <div class="lr-readonly-time" :title="formatTimeForDisplay(form.EndTime)">
                    <span class="material-symbols-outlined" style="font-size:15px;color:#FB8C00;flex-shrink:0">schedule</span>
                    {{ formatTimeForDisplay(form.EndTime) }}
                  </div>
                </div>
              </template>
              <template v-else>
                <div class="form-group">
                  <label>開始時間</label>
                  <input type="time" v-model="form.StartTime" @change="onStartTimeChange" :disabled="isReadOnly">
                </div>
                <div class="form-group">
                  <label>結束時間</label>
                  <input type="time" v-model="form.EndTime" :disabled="isReadOnly">
                </div>
              </template>
              <p v-if="isSessionTimeLocked" class="lr-time-lock-note lr-time-lock-note--inline">
                <span class="material-symbols-outlined" style="font-size:13px;vertical-align:-2px;margin-right:3px">info</span>
                上課時間已依課程／排課堂次帶入，無法手動更改。
              </p>
            </div>
          </div>

          <!-- Section 2: 作業與進度 -->
          <div class="lr-form-section">
            <div class="lr-form-section-title">作業與進度</div>
            <div class="form-group">
              <label>上次作業</label>
              <div class="lr-radio-group" data-group="homework">
                <label class="lr-radio" data-value="completed"><input v-model="form.HomeworkStatus" type="radio" value="completed" :disabled="isReadOnly"><span class="lr-radio-emoji">✓</span> 已完成</label>
                <label class="lr-radio" data-value="partial"><input v-model="form.HomeworkStatus" type="radio" value="partial" :disabled="isReadOnly"><span class="lr-radio-emoji">◐</span> 部分完成</label>
                <label class="lr-radio" data-value="incomplete"><input v-model="form.HomeworkStatus" type="radio" value="incomplete" :disabled="isReadOnly"><span class="lr-radio-emoji">✕</span> 未完成</label>
                <label class="lr-radio" data-value="missing"><input v-model="form.HomeworkStatus" type="radio" value="missing" :disabled="isReadOnly"><span class="lr-radio-emoji">?</span> 未攜帶</label>
              </div>
            </div>
            <div class="form-group">
              <label>週考成績</label>
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
              <div v-if="!isReadOnly" class="lr-phrase-row">
                <button v-for="p in templatePhrases.Progress" :key="p" class="lr-phrase-btn" type="button" @click="insertPhrase('Progress', p)">{{ p }}</button>
              </div>
            </div>
            <div class="form-group">
              <label>下次作業範圍</label>
              <textarea v-model="form.NextHomework" rows="2" :disabled="isReadOnly" placeholder="指定下次作業..."></textarea>
              <div v-if="!isReadOnly" class="lr-phrase-row">
                <button v-for="p in templatePhrases.NextHomework" :key="p" class="lr-phrase-btn" type="button" @click="insertPhrase('NextHomework', p)">{{ p }}</button>
              </div>
            </div>
            <div class="form-group">
              <label>下次週考範圍</label>
              <textarea v-model="form.NextWeekTestScope" rows="2" :disabled="isReadOnly" placeholder="指定下次週考範圍..."></textarea>
              <div v-if="!isReadOnly" class="lr-phrase-row">
                <button v-for="p in templatePhrases.NextWeekTestScope" :key="p" class="lr-phrase-btn" type="button" @click="insertPhrase('NextWeekTestScope', p)">{{ p }}</button>
              </div>
            </div>
          </div>

          <!-- Section 3: 上課狀況 -->
          <div class="lr-form-section">
            <div class="lr-form-section-title">上課狀況與評語</div>
            <div class="form-group">
              <label>上課狀況</label>
              <div class="lr-radio-group" data-group="performance">
                <label class="lr-radio" data-value="good"><input v-model="form.Performance" type="radio" value="good" :disabled="isReadOnly"><span class="lr-radio-emoji">😊</span> 良好</label>
                <label class="lr-radio" data-value="average"><input v-model="form.Performance" type="radio" value="average" :disabled="isReadOnly"><span class="lr-radio-emoji">😐</span> 普通</label>
                <label class="lr-radio" data-value="bad"><input v-model="form.Performance" type="radio" value="bad" :disabled="isReadOnly"><span class="lr-radio-emoji">😟</span> 不良</label>
              </div>
            </div>
            <div class="form-group">
              <label>學習進度與家長溝通</label>
              <textarea v-model="form.Comment" rows="4" :disabled="isReadOnly" placeholder="綜合評語與聯絡事項..."></textarea>
              <div v-if="!isReadOnly" class="lr-phrase-row">
                <button v-for="p in templatePhrases.Comment" :key="p" class="lr-phrase-btn" type="button" @click="insertPhrase('Comment', p)">{{ p }}</button>
              </div>
            </div>
          </div>

          <!-- Status Context -->
          <div v-if="form.Status === 'rejected' || form.Status === 'changes_requested'" class="lr-reject-note">
            <div class="lr-reject-note-title">
              {{ form.Status === 'rejected' ? '⚠️ 退回原因' : '📝 需修改說明' }}
            </div>
            <p>{{ form.ReviewNote || '（無說明）' }}</p>
          </div>

          <div v-if="form.Status === 'approved' && form.id" class="lr-approved-note">
            <span class="lr-approved-badge">✓ 已核准</span>
            <span v-if="isTeacher">此評量已由主任核准，無法再修改。</span>
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
    <div v-if="showExportModal" class="modal-overlay">
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

    <!-- ===== Draft List Panel ===== -->
    <div v-if="showDraftPanel" class="modal-overlay" @click.self="closeDraftPanel">
      <div class="lr-modal lr-draft-panel" style="max-width: 480px;">
        <div class="lr-modal-header">
          <h3>
            <span class="material-symbols-outlined" style="font-size:20px;vertical-align:-4px;margin-right:4px">drafts</span>
            未完成草稿
          </h3>
          <button class="lr-modal-close" @click="closeDraftPanel">&times;</button>
        </div>
        <div class="lr-draft-panel-body">
          <div v-if="draftList.length === 0" class="lr-draft-empty">
            <span class="material-symbols-outlined lr-draft-empty-icon">note_stack</span>
            <p class="lr-draft-empty-text">目前沒有未完成的草稿</p>
            <p class="lr-draft-empty-hint">從課表點一筆課程開始填寫評量，中途離開時系統會自動儲存草稿。</p>
          </div>
          <div v-else class="lr-draft-items">
            <div v-for="d in draftList" :key="d.key" class="lr-draft-item">
              <div class="lr-draft-item-info" @click="closeDraftPanel">
                <div class="lr-draft-item-student">{{ d.studentName }}</div>
                <div class="lr-draft-item-meta">
                  <span>{{ d.subject }}</span>
                  <span v-if="d.sessionDate"> · {{ d.sessionDate }}</span>
                  <span v-if="d.startTime"> · {{ d.startTime }}–{{ d.endTime }}</span>
                </div>
                <div class="lr-draft-item-time">儲存於 {{ formatDraftTime(d.savedAt) }}</div>
              </div>
              <button class="lr-draft-item-delete" @click.stop="deleteDraftFromList(d)" title="清除此草稿">
                <span class="material-symbols-outlined">delete_outline</span>
              </button>
            </div>
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
import { exportStudentCards, generateStudentCardPng, downloadBlob } from '../lib/learningRecordExport';
import { createPerfTracker } from '../lib/usePerformanceMetrics';
import perfFlags from '../lib/perfFlags';
import {
  saveDraft as _saveDraftToStorage,
  loadDraft as _loadDraftFromStorage,
  applyDraftToForm,
  clearDraft as _clearDraftFromStorage,
  listDrafts as _listDraftsFromStorage,
  removeDraftByKey,
  pruneOldDrafts,
  migrateLegacyDrafts,
} from '../lib/learningRecordDrafts';

const props = defineProps(['branchId', 'userRole', 'userId', 'targetRecordId']);

const perf = createPerfTracker('LearningRecordsPage');

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
const recordsPagination = ref({ currentPage: 1, lastPage: 1, total: 0, loading: false });
const showModal = ref(false);
const isEditing = ref(false);
const showChangeTeacherModal = ref(false);
const showDraftPanel = ref(false);
const draftList = ref([]);
const draftStatusText = ref('');
const draftSaveError = ref(false);
const _draftThrottleTimer = ref(null);
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
const filters = reactive({ status: '', student_name: '', teacher_id: '', date: '' });

const reviewTab = ref('pending');
const teacherFilterTab = ref('all');
const onlyUnfilled = ref(false);
const selectedRecordIds = ref(new Set());
const batchOperating = ref(false);
const draftAutoSaveKey = ref('');

const templatePhrases = {
  Progress: ['課本 p.XX ~ p.XX', '複習上次範圍', '練習題本 第X回'],
  NextHomework: ['課本 p.XX ~ p.XX', '題本 第X回', '背單字 Unit X'],
  NextWeekTestScope: ['課本 p.XX ~ p.XX', 'Unit X 單字', '第X章'],
  Comment: ['表現優良，繼續保持', '需加強練習', '建議每日複習 30 分鐘'],
};

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

const filteredRecords = computed(() => {
  let list = records.value || [];
  if (isTeacher.value) {
    if (teacherFilterTab.value === 'changes_requested') {
      list = list.filter(r => r.Status === 'changes_requested');
    } else if (teacherFilterTab.value === 'approved') {
      list = list.filter(r => r.Status === 'approved');
    } else if (teacherFilterTab.value === 'pending') {
      list = list.filter(r => r.Status === 'pending');
    }
  } else if (isDirectorRole.value) {
    if (reviewTab.value === 'pending') {
      list = list.filter(r => r.Status === 'pending' || r.Status === 'changes_requested');
    } else if (reviewTab.value === 'changes_requested') {
      list = list.filter(r => r.Status === 'changes_requested');
    } else if (reviewTab.value === 'approved') {
      list = list.filter(r => r.Status === 'approved');
    } else if (reviewTab.value === 'rejected') {
      list = list.filter(r => r.Status === 'rejected');
    }
  }
  if (onlyUnfilled.value) {
    list = list.filter(r =>
      (r.Status === 'pending' || r.Status === 'changes_requested') && !hasLearningRecordBody(r)
    );
  }
  return list;
});

const filteredGroupedRecords = computed(() => {
  const groups = new Map();
  for (const record of filteredRecords.value) {
    const studentId = Number(record?.student_id || 0) || null;
    const studentName = String(record?.student_name || '').trim() || '未命名學生';
    const key = studentId ? `student-${studentId}` : `name-${studentName}`;
    if (!groups.has(key)) {
      groups.set(key, { key, student_id: studentId, student_name: studentName, pending_count: 0, unfilled_body_count: 0, records: [] });
    }
    const group = groups.get(key);
    group.records.push(record);
    if (record?.Status === 'pending' || record?.Status === 'changes_requested') {
      group.pending_count += 1;
      if (!hasLearningRecordBody(record)) group.unfilled_body_count += 1;
    }
  }
  const collator = new Intl.Collator('zh-Hant');
  return Array.from(groups.values())
    .map(group => {
      group.records.sort((a, b) => {
        const isPendingA = (a?.Status === 'pending' || a?.Status === 'changes_requested') && !hasLearningRecordBody(a) ? 0 : 1;
        const isPendingB = (b?.Status === 'pending' || b?.Status === 'changes_requested') && !hasLearningRecordBody(b) ? 0 : 1;
        if (isPendingA !== isPendingB) return isPendingA - isPendingB;
        const aDate = String(a?.SessionDate || '');
        const bDate = String(b?.SessionDate || '');
        if (aDate !== bDate) return bDate.localeCompare(aDate);
        return String(b?.StartTime || '').localeCompare(String(a?.StartTime || ''));
      });
      return group;
    })
    .sort((a, b) => collator.compare(a.student_name, b.student_name));
});

const pendingCount = computed(() => (records.value || []).filter(r => r.Status === 'pending' || r.Status === 'changes_requested').length);
const changesRequestedCount = computed(() => (records.value || []).filter(r => r.Status === 'changes_requested').length);
const approvedCount = computed(() => (records.value || []).filter(r => r.Status === 'approved').length);
const rejectedCount = computed(() => (records.value || []).filter(r => r.Status === 'rejected').length);

const allSelected = computed(() => {
  const selectable = filteredRecords.value.filter(r => r.Status === 'pending' || r.Status === 'changes_requested');
  return selectable.length > 0 && selectable.every(r => selectedRecordIds.value.has(r.id));
});

const toggleSelectAll = () => {
  const selectable = filteredRecords.value.filter(r => r.Status === 'pending' || r.Status === 'changes_requested');
  if (allSelected.value) {
    selectedRecordIds.value = new Set();
  } else {
    selectedRecordIds.value = new Set(selectable.map(r => r.id));
  }
};

const toggleRecordSelection = (id) => {
  const next = new Set(selectedRecordIds.value);
  if (next.has(id)) next.delete(id); else next.add(id);
  selectedRecordIds.value = next;
};

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

const subjectList = ref([]);


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
  NextWeekTestScope: '',
  Performance: 'good',
  Comment: '',
  Status: '',
  ReviewNote: ''
});

const forceReadOnly = ref(false);
const _activeRecordRef = ref(null);
const teacherChangeForm = reactive({
  record_id: null,
  teacher_id: '',
  reason: '',
  student_name: '',
  current_teacher_name: '',
  session_date: '',
  update_class: false,
});

const isSessionStarted = (sessionDate, startTime) => {
  const date = String(sessionDate || '').slice(0, 10);
  const time = normalizeTime(startTime);
  if (!date || !time) return true;
  const startAt = new Date(`${date}T${time}:00`);
  if (Number.isNaN(startAt.getTime())) return true;
  return Date.now() >= startAt.getTime();
};

const resolveTimeLockMessage = (sessionDate, startTime) => (
  isSessionStarted(sessionDate, startTime) ? '' : '上課開始後開放填寫'
);

const timeLockMessage = computed(() => resolveTimeLockMessage(form.SessionDate, form.StartTime));
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

const currentStudentName = computed(() => {
  const studentId = String(form.StudentID || '');
  if (!studentId) return '未綁定堂次';
  const fromList = studentList.value.find((item) => String(item.id) === studentId)?.name;
  if (fromList) return fromList;
  const fromRecord = _activeRecordRef.value?.student_name;
  if (fromRecord) return fromRecord;
  return `學生 #${studentId}`;
});

const currentTeacherName = computed(() => {
  const teacherId = String(form.TeacherID || props.userId || '');
  if (!teacherId) return '未指派老師';
  const matched = teacherList.value.find((item) => String(item.id) === teacherId);
  return matched?.username || matched?.T_Name || matched?.Name || `老師 #${teacherId}`;
});

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
    NextWeekTestScope: form.NextWeekTestScope ?? savedRecord?.NextWeekTestScope ?? '',
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
    const params = new URLSearchParams({ per_page: String(perfFlags.STUDENTS_PER_PAGE) });
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

// ── Fetch subject list from campus subject management ──
const fetchSubjects = async () => {
  try {
    const token = await getToken();
    if (!token) return;
    const params = new URLSearchParams();
    if (props.branchId) params.set('branch_id', String(props.branchId));
    const res = await fetch(`/api/v1/subjects?${params}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (res.ok) {
      const list = await res.json();
      if (Array.isArray(list) && list.length > 0) {
        subjectList.value = list.map(s => s.name);
      }
    }
  } catch (e) { console.error('fetchSubjects', e); }
};

// ── Teacher: fetch own student-classes for schedule widget ──
const fetchTeacherClasses = async () => {
  if (!isTeacher.value) return;
  try {
    const token = await getToken();
    if (!token) return;
    const params = new URLSearchParams({ per_page: 200, status: 'active' });
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
  if (!isTeacher.value) {
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

    const now = new Date();
    const rangeStart = new Date(now.getFullYear(), now.getMonth() - 2, 1);
    const rangeEnd = new Date(now.getFullYear(), now.getMonth() + 2, 0);
    const startStr = `${rangeStart.getFullYear()}-${String(rangeStart.getMonth() + 1).padStart(2, '0')}-01`;
    const endStr = `${rangeEnd.getFullYear()}-${String(rangeEnd.getMonth() + 1).padStart(2, '0')}-${String(rangeEnd.getDate()).padStart(2, '0')}`;

    const { byClass } = await fetchClassSessions({
      token,
      studentClassIds: classIds,
      start: startStr,
      end: endStr,
      perPage: perfFlags.SESSION_MAX_PER_PAGE,
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
    const now = new Date();
    const rangeStart = new Date(now.getFullYear(), now.getMonth() - 2, 1);
    const rangeEnd = new Date(now.getFullYear(), now.getMonth() + 2, 0);
    const startStr = `${rangeStart.getFullYear()}-${String(rangeStart.getMonth() + 1).padStart(2, '0')}-01`;
    const endStr = `${rangeEnd.getFullYear()}-${String(rangeEnd.getMonth() + 1).padStart(2, '0')}-${String(rangeEnd.getDate()).padStart(2, '0')}`;

    const { byClass } = await fetchClassSessions({
      token,
      branchId: props.branchId,
      studentClassIds: classIds,
      start: startStr,
      end: endStr,
      perPage: perfFlags.SESSION_MAX_PER_PAGE,
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
  if (status === 'leave' || status === 'leave_adjusted' || status === 'excused') return '請假';
  if (status === 'cancelled') return '取消';
  return '未填';
};

// 與 SmartCalendar.vue 的 LEAVE_STATUSES 保持一致（請假類堂次不需填評量）。
// 若未來新增請假類 ClassSession.Status，前後端（LearningRecord::scopeExcludeLeaveSessionPendingReview）
// 必須同步更新，避免語意漂移。
const LEAVE_STATUSES = new Set(['leave', 'leave_adjusted', 'excused']);

const SESSION_STATUS_PRIORITY = {
  attended: 0, completed: 0, late: 0, absent: 0,
  scheduled: 1,
  leave: 2, leave_adjusted: 2, excused: 2,
  cancelled: 3,
};

function pickBestSession(candidates) {
  if (!candidates.length) return null;
  if (candidates.length === 1) return candidates[0];
  return candidates.slice().sort((a, b) => {
    const sa = String(a?.status || a?.Status || '').toLowerCase();
    const sb = String(b?.status || b?.Status || '').toLowerCase();
    const pa = SESSION_STATUS_PRIORITY[sa] ?? 2;
    const pb = SESSION_STATUS_PRIORITY[sb] ?? 2;
    if (pa !== pb) return pa - pb;
    return (Number(b.id) || 0) - (Number(a.id) || 0);
  })[0];
}

function deduplicateSessionsBySlot(sessions) {
  const groups = {};
  for (const s of sessions) {
    const date = String(s?.session_date || s?.SessionDate || '').slice(0, 10);
    const time = normalizeTime(s?.start_time || s?.StartTime) || '';
    const key = `${date}|${time}`;
    if (!groups[key]) groups[key] = [];
    groups[key].push(s);
  }
  return Object.values(groups).map((g) => pickBestSession(g));
}

const recordLookup = computed(() => {
  const map = new Map();
  for (const record of records.value || []) {
    const classId = Number(record.StudentClassID || 0);
    const date = String(record.SessionDate || '').slice(0, 10);
    if (!classId || !date) continue;
    const csId = Number(record.ClassSessionID || 0);
    if (csId > 0) {
      const exactKey = `cs:${csId}`;
      const prev = map.get(exactKey);
      if (!prev || scheduleStatusPriority(record.Status) > scheduleStatusPriority(prev.Status)) {
        map.set(exactKey, record);
      }
    }
    const dateKey = `${classId}|${date}`;
    const startTime = normalizeTime(record.StartTime || '');
    if (startTime) {
      const timeKey = `${classId}|${date}|${startTime}`;
      const prevT = map.get(timeKey);
      if (!prevT || scheduleStatusPriority(record.Status) > scheduleStatusPriority(prevT.Status)) {
        map.set(timeKey, record);
      }
    }
    const prev = map.get(dateKey);
    if (!prev || scheduleStatusPriority(record.Status) > scheduleStatusPriority(prev.Status)) {
      map.set(dateKey, record);
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
  const myId = Number(props.userId || 0);
  const events = [];
  for (const sc of teacherClassList.value) {
    if (sc.Stop == 1) continue;
    const classId = Number(sc.id || sc.ID || 0);
    if (!classId) continue;
    const allSessions = sessionDatesByClassId.value[String(classId)] || [];
    const rawSessions = deduplicateSessionsBySlot(allSessions);
    for (const rawSession of rawSessions) {
      const dateStr = String(rawSession?.session_date || rawSession?.SessionDate || rawSession).slice(0, 10);
      if (!targetSet.has(dateStr)) continue;
      const startTime = normalizeTime(rawSession?.start_time || rawSession?.StartTime) || resolveCourseStartTime(sc, dateStr);
      const endTime = normalizeTime(rawSession?.end_time || rawSession?.EndTime) || computedEndTimeForClass(sc, startTime);
      const csId = Number(rawSession?.id || 0);
      const record = (csId > 0 ? recordLookup.value.get(`cs:${csId}`) : null)
        || (startTime ? recordLookup.value.get(`${classId}|${dateStr}|${startTime}`) : null)
        || recordLookup.value.get(`${classId}|${dateStr}`);
      const rowStatus = String(rawSession?.learning_record_status || '');
      const sessionStatus = String(rawSession?.status || rawSession?.Status || '').toLowerCase();
      // 請假／取消堂次：一律不需填評量；與 SmartCalendar.evalBadge 的 LEAVE_STATUSES 行為對齊。
      // 後端 LR 已被 CourseLeaveCascadeService 作廢（VoidedAt），learning_record_status 回 'missing'，
      // 若未於此處攔截，評量頁會誤顯示「未填」並開放填寫 → 2026-04-17 修正。
      const isLeaveSession = LEAVE_STATUSES.has(sessionStatus);
      const isCancelledSession = sessionStatus === 'cancelled';
      const baseFormStatus = rowStatus || record?.Status || 'missing';
      const formStatus = isLeaveSession ? 'leave' : (isCancelledSession ? 'cancelled' : baseFormStatus);
      const recordId = rawSession?.learning_record_id != null
        ? Number(rawSession.learning_record_id)
        : (record?.id || null);

      const lrTeacherId = Number(rawSession?.learning_record_teacher_id || 0);
      const isSubstituted = lrTeacherId > 0 && lrTeacherId !== myId && myId > 0;

      // 請假／取消：永遠鎖定不可填；其他沿用既有規則（代課 or 尚未開始）。
      const fillLocked = isLeaveSession || isCancelledSession || isSubstituted || !isSessionStarted(dateStr, startTime);
      const student = studentList.value.find(s => String(s.id) === String(sc.student_id || sc.StudentID));
      const studentName = student?.name || sc.student_name || `學生#${sc.student_id || sc.StudentID}`;
      const eventKey = csId > 0 ? `cs-${csId}` : `${classId}-${dateStr}-${startTime || ''}`;
      let fillLockReason = '';
      if (isLeaveSession) fillLockReason = '此堂已請假，無需填寫評量';
      else if (isCancelledSession) fillLockReason = '此堂已取消，無需填寫評量';
      else if (isSubstituted) fillLockReason = '此堂已由代課老師處理';
      else if (fillLocked) fillLockReason = '上課開始後開放填寫';

      events.push({
        key: eventKey,
        classSessionId: csId || null,
        classId,
        studentId: sc.student_id || sc.StudentID,
        studentName,
        subject: sc.subject || sc.Subject || '?',
        subjectName: sc.subject_name || sc.subject || sc.Subject || '?',
        date: dateStr,
        startTime,
        endTime,
        timeRange: endTime ? `${startTime}~${endTime}` : startTime,
        // 請假／取消：不綁 recordId，避免誤觸 canEdit 分支開啟評量 modal。
        recordId: (isLeaveSession || isCancelledSession || isSubstituted) ? null : (recordId || null),
        formStatus: isSubstituted ? 'substituted' : formStatus,
        formStatusLabel: isSubstituted ? '代課' : scheduleStatusLabel(formStatus),
        fillLocked,
        fillLockReason,
        isSubstituted,
        isLeave: isLeaveSession,
        isCancelled: isCancelledSession,
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
      // 只計算真正「未填」（請假／取消因 formStatus 被改為 leave/cancelled，已自動排除）。
      missingCount: events.filter((ev) => ev.formStatus === 'missing').length,
    });
  }
  return days;
});

const weekHasEvents = computed(() => weekDays.value.some((day) => day.events.length > 0));
const weekTotalMissingCount = computed(() => weekDays.value.reduce((sum, day) => sum + day.missingCount, 0));

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
  const daySessions = sessions.filter((s) => {
    if (String(s.session_date).slice(0, 10) !== dateStr) return false;
    const st = String(s.status || '').toLowerCase();
    return st !== 'cancelled' && st !== 'leave';
  });
  let daySession = daySessions[0] || null;
  if (daySessions.length > 1 && form.StartTime) {
    const byTime = daySessions.find((s) => normalizeTime(s.start_time) === normalizeTime(form.StartTime));
    if (byTime) daySession = byTime;
  }

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
      openRecordAction(existing);
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
  const hasDraft = loadDraft();
  if (hasDraft) {
    console.log('[LR] Restored auto-saved draft');
  }
};

const openFromScheduleMaybe = (ev) => {
  if (ev?.isSubstituted) {
    alert('此堂已由代課老師處理');
    return;
  }
  if (ev?.isLeave || ev?.isCancelled) {
    // 請假／取消堂次不需填評量；即使使用者誤觸也不開啟 modal。
    return;
  }
  if (!ev?.recordId && ev?.fillLocked) {
    alert(ev.fillLockReason || '上課開始後開放填寫');
    return;
  }
  openFromSchedule(ev);
};

// ── Fetch Records ──
const _buildRecordsParams = (page = 1) => {
  const params = new URLSearchParams();
  if (props.branchId) params.set('branch_id', props.branchId);
  if (filters.student_name) params.set('student_name', filters.student_name);
  if (filters.teacher_id) params.set('teacher_id', filters.teacher_id);
  if (filters.status) params.set('status', filters.status);
  if (filters.date) { params.set('start_date', filters.date); params.set('end_date', filters.date); }
  params.set('sort', 'session_date');
  params.set('per_page', String(perfFlags.LR_DEFAULT_PER_PAGE));
  params.set('page', String(page));
  return params;
};

const fetchRecords = async () => {
  try {
    const token = await getToken();
    if (!token) return;

    const params = _buildRecordsParams(1);
    const res = await fetch(`/api/v1/learning-records?${params.toString()}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });

    if (!res.ok) throw new Error('Fetch failed');

    const data = await res.json();
    records.value = data.data || [];
    recordsPagination.value = {
      currentPage: data.current_page || 1,
      lastPage: data.last_page || 1,
      total: data.total || 0,
      loading: false,
    };
  } catch (e) {
    console.error(e);
  }
};

const loadMoreRecords = async () => {
  const pg = recordsPagination.value;
  if (pg.loading || pg.currentPage >= pg.lastPage) return;
  pg.loading = true;

  try {
    const token = await getToken();
    if (!token) return;

    const params = _buildRecordsParams(pg.currentPage + 1);
    const res = await fetch(`/api/v1/learning-records?${params.toString()}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (!res.ok) throw new Error('Load more failed');

    const data = await res.json();
    const newRows = data.data || [];
    const existingIds = new Set(records.value.map(r => r.id));
    const deduped = newRows.filter(r => !existingIds.has(r.id));
    records.value = [...records.value, ...deduped];
    pg.currentPage = data.current_page || pg.currentPage + 1;
    pg.lastPage = data.last_page || pg.lastPage;
    pg.total = data.total || pg.total;
  } catch (e) {
    console.error(e);
  } finally {
    pg.loading = false;
  }
};

// ── Modal ──
const _fillForm = (record) => {
  isEditing.value = true;
  formTimesFromBinding.value = false;
  _activeRecordRef.value = record;
  Object.assign(form, {
    id: record.id,
    StudentID: Number(record.student_id) || '',
    TeacherID: Number(record.TeacherID),
    ClassSessionID: Number(record.ClassSessionID) || 0,
    Subject: canonicalSubjectLabel(record.Subject) || record.Subject || '',
    SessionDate: record.SessionDate,
    StartTime: normalizeTime(record.StartTime) || String(record.StartTime || '').match(/(\d{1,2}:\d{2})/)?.[1] || '',
    EndTime: normalizeTime(record.EndTime) || String(record.EndTime || '').match(/(\d{1,2}:\d{2})/)?.[1] || '',
    HomeworkStatus: record.HomeworkStatus || 'completed',
    QuizScore: record.QuizScore || '',
    Progress: record.Progress || '',
    NextHomework: record.NextHomework || '',
    NextWeekTestScope: record.NextWeekTestScope || '',
    Performance: record.Performance || 'good',
    Comment: record.Comment || '',
    Status: record.Status,
    ReviewNote: record.ReviewNote || ''
  });
};

const _clearForm = () => {
  isEditing.value = false;
  formTimesFromBinding.value = false;
  _activeRecordRef.value = null;
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
    NextWeekTestScope: '',
    Performance: 'good',
    Comment: '',
    Status: 'pending',
    ReviewNote: ''
  });
  if (isTeacher.value) {
    applyTeacherFormDefaults();
  }
};

const _attachTextareaResize = () => {
  nextTick(() => {
    document.querySelectorAll('.lr-modal textarea').forEach(el => {
      el.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = this.scrollHeight + 'px';
      });
    });
  });
};

const openModal = (record = null) => {
  if (!record && isTeacher.value) {
    alert('請從上方課表點選堂次後填寫評量。');
    return;
  }
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
  _attachTextareaResize();
};

const viewRecord = (record) => {
  forceReadOnly.value = true;
  _fillForm(record);
  showModal.value = true;
  _attachTextareaResize();
};

const editRecord = (record) => {
  forceReadOnly.value = false;
  _fillForm(record);
  showModal.value = true;
  if (record.Status !== 'approved') {
    loadDraft();
  }
  _attachTextareaResize();
};

const closeModal = () => {
  if (_draftThrottleTimer.value) {
    clearTimeout(_draftThrottleTimer.value);
    _draftThrottleTimer.value = null;
    saveDraft();
  }
  draftStatusText.value = '';
  draftSaveError.value = false;
  showModal.value = false;
  if (isTeacher.value) refreshDraftList();
};

const downloadingPng = ref(false);
const downloadToast = ref('');

const downloadSingleRecord = async () => {
  if (downloadingPng.value) return;
  downloadingPng.value = true;
  downloadToast.value = '';
  try {
    const rec = _activeRecordRef.value;
    if (!rec) throw new Error('無評量記錄');

    const studentName = currentStudentName.value || rec.student_name || '未命名學生';
    const teacherName = currentTeacherName.value || rec.teacher_name || '未指派';
    const sessionDate = form.SessionDate || rec.SessionDate || '';
    const branchNames = { 1: '興隆校', 2: '新店校', 3: '大安校', 4: '木柵校' };
    const branchName = branchNames[Number(props.branchId)] || '台北全真一對一補習班';

    const recordForExport = {
      ...rec,
      Subject: form.Subject || rec.Subject || '',
      SessionDate: sessionDate,
      StartTime: form.StartTime || rec.StartTime || '',
      EndTime: form.EndTime || rec.EndTime || '',
      HomeworkStatus: form.HomeworkStatus || rec.HomeworkStatus || '',
      QuizScore: form.QuizScore ?? rec.QuizScore ?? '',
      Progress: form.Progress ?? rec.Progress ?? '',
      NextHomework: form.NextHomework ?? rec.NextHomework ?? '',
      NextWeekTestScope: form.NextWeekTestScope ?? rec.NextWeekTestScope ?? '',
      Performance: form.Performance || rec.Performance || '',
      Comment: form.Comment || rec.Comment || '',
      Content: rec.Content || form.Comment || '',
      Status: form.Status || rec.Status || '',
    };

    const blob = await generateStudentCardPng(
      studentName,
      [recordForExport],
      teacherName,
      sessionDate,
      branchName,
    );

    const safeName = studentName.replace(/[\\/:*?"<>|]/g, '_');
    const safeDate = sessionDate.replace(/\//g, '-');
    downloadBlob(blob, `評量_${safeName}_${safeDate}.png`);

    downloadToast.value = '已下載圖檔';
    setTimeout(() => { downloadToast.value = ''; }, 2000);
  } catch (err) {
    console.error('Single record export failed:', err);
    downloadToast.value = '下載失敗，請稍後再試';
    setTimeout(() => { downloadToast.value = ''; }, 3000);
  } finally {
    downloadingPng.value = false;
  }
};

const focusTeacherSchedule = () => {
  const scheduleEl = document.querySelector('[data-guide="learning-teacher-schedule"]');
  if (!scheduleEl) return;
  scheduleEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

const switchToTeacherLogin = async () => {
  const confirmed = window.confirm('將先登出目前帳號，並回到登入頁供老師登入。是否繼續？');
  if (!confirmed) return;
  try {
    await supabase.auth.signOut();
  } catch (e) {
    console.error('signOut failed', e);
  }
  localStorage.removeItem('alltrue_session');
  window.location.reload();
};

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
    clearDraft();
    await fetchRecords();
    if (localRecord?.id) {
      upsertRecordInList(localRecord);
    }
    if (isTeacher.value) {
      await fetchTeacherClasses();
    }
    closeModal();
  } else if (res.status === 409) {
    const err = await res.json().catch(() => ({}));
    alert(err.message || '此堂評量表已存在，請重新整理頁面後查看。');
    clearDraft();
    await fetchRecords();
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
  teacherChangeForm.update_class = false;
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
        update_class: teacherChangeForm.update_class,
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

// 「已填」僅以授課進度（Progress）是否有內容判定；與表單「授課進度」欄一致。
const hasLearningRecordBody = (record) => {
  if (!record) return false;
  return String(record.Progress || '').trim() !== '';
};

const fillLabel = (record) => (hasLearningRecordBody(record) ? '已填' : '未填');

const fillLabelClass = (record) => (hasLearningRecordBody(record) ? 'fill-done' : 'fill-missing');

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

const canRequestChanges = (record) => {
  if (!isDirectorRole.value) return false;
  return record.Status === 'pending';
};

const canRollbackApproval = (record) => {
  if (!isDirectorRole.value) return false;
  return record.Status === 'approved';
};

const isWriteLockedBeforeSessionStart = (record) => {
  if (!record) return false;
  return !isSessionStarted(record.SessionDate, record.StartTime);
};

const canEdit = (record) => {
  if (isWriteLockedBeforeSessionStart(record)) return false;
  if (isDirectorRole.value) return true;
  if (record.Status === 'approved') return false;
  return true;
};

const canChangeTeacher = (record) => {
  if (!isDirectorRole.value) return false;
  return Boolean(record?.id);
};

const showTimeLockHint = (record) => isWriteLockedBeforeSessionStart(record);

const canDelete = (record) => {
  return isDirectorRole.value;
};

const primaryActionLabel = (record) => {
  if (!record) return '檢視評量';
  return canEdit(record) ? '編輯評量' : '檢視評量';
};

const openRecordAction = (record) => {
  if (!record) return;
  if (canEdit(record)) {
    editRecord(record);
    return;
  }
  viewRecord(record);
};

const findRecordById = (recordId) => {
  const rid = Number(recordId || 0);
  if (!rid) return null;
  return records.value.find((item) => Number(item?.id || 0) === rid) || null;
};

const canEditScheduleEvent = (ev) => {
  if (!ev) return false;
  if (!ev.recordId) return !ev.fillLocked;
  const existing = findRecordById(ev.recordId);
  if (existing) return canEdit(existing);
  if (ev.fillLocked) return false;
  if (isTeacher.value && String(ev.formStatus || '') === 'approved') return false;
  return true;
};

const scheduleActionLabel = (ev) => {
  if (ev?.isSubstituted) return '代課中';
  if (ev?.isLeave) return '已請假';
  if (ev?.isCancelled) return '已取消';
  if (!ev?.recordId) return ev?.fillLocked ? '未開放' : '填評量';
  return canEditScheduleEvent(ev) ? '編輯評量' : '檢視評量';
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

const batchApproveSelected = async () => {
  const ids = [...selectedRecordIds.value];
  if (ids.length === 0) return;
  if (!confirm(`確定要批次核准 ${ids.length} 筆評量？`)) return;
  batchOperating.value = true;
  try {
    const token = await getToken();
    const res = await fetch('/api/v1/learning-records/batch-approve', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ DirectorID: props.userId, branch_id: props.branchId })
    });
    if (res.ok) {
      const json = await res.json();
      alert(json.message || '批次核准完成');
      selectedRecordIds.value = new Set();
      await fetchRecords();
    } else {
      const err = await res.json().catch(() => ({}));
      alert('批次核准失敗: ' + (err.message || ''));
    }
  } catch (e) { alert('批次核准失敗: ' + e.message); }
  finally { batchOperating.value = false; }
};

const batchRejectSelected = async () => {
  const ids = [...selectedRecordIds.value];
  if (ids.length === 0) return;
  const note = prompt(`請輸入退回原因（將套用到 ${ids.length} 筆）：`);
  if (!note) return;
  batchOperating.value = true;
  try {
    const token = await getToken();
    const res = await fetch('/api/v1/learning-records/batch-reject', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ ids, ReviewNote: note, DirectorID: props.userId })
    });
    if (res.ok) {
      const json = await res.json();
      alert(json.message || '批次退回完成');
      selectedRecordIds.value = new Set();
      await fetchRecords();
    } else {
      const err = await res.json().catch(() => ({}));
      alert('批次退回失敗: ' + (err.message || ''));
    }
  } catch (e) { alert('批次退回失敗: ' + e.message); }
  finally { batchOperating.value = false; }
};

const batchRequestChangesSelected = async () => {
  const ids = [...selectedRecordIds.value];
  if (ids.length === 0) return;
  const note = prompt(`請輸入修改說明（將套用到 ${ids.length} 筆）：`);
  if (!note) return;
  batchOperating.value = true;
  try {
    const token = await getToken();
    const res = await fetch('/api/v1/learning-records/batch-request-changes', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ ids, ReviewNote: note, DirectorID: props.userId })
    });
    if (res.ok) {
      const json = await res.json();
      alert(json.message || '批次標記需修改完成');
      selectedRecordIds.value = new Set();
      await fetchRecords();
    } else {
      const err = await res.json().catch(() => ({}));
      alert('操作失敗: ' + (err.message || ''));
    }
  } catch (e) { alert('操作失敗: ' + e.message); }
  finally { batchOperating.value = false; }
};

const requestChangesRecord = async (record) => {
  const note = prompt('請輸入修改說明：');
  if (!note) return;
  const token = await getToken();
  const res = await fetch(`/api/v1/learning-records/${record.id}/request-changes`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
    body: JSON.stringify({ ReviewNote: note, DirectorID: props.userId })
  });
  if (res.ok) {
    fetchRecords();
  } else {
    const err = await res.json().catch(() => ({}));
    alert('操作失敗: ' + (err.message || ''));
  }
};

const insertPhrase = (field, phrase) => {
  const current = form[field] || '';
  form[field] = current ? `${current}\n${phrase}` : phrase;
};

const _draftKeyParams = () => ({
  teacherId: props.userId,
  classSessionId: form.ClassSessionID,
  fallback: { studentClassId: form.StudentID, sessionDate: form.SessionDate },
});

const _draftMeta = () => ({
  studentName: currentStudentName.value,
  subject: form.Subject,
  sessionDate: form.SessionDate,
  startTime: form.StartTime,
  endTime: form.EndTime,
});

const saveDraft = () => {
  if (!showModal.value || forceReadOnly.value) return;
  if (isEditing.value && form.Status === 'approved') return;
  const result = _saveDraftToStorage({
    ..._draftKeyParams(),
    form,
    meta: _draftMeta(),
  });
  if (result.saved) {
    const now = new Date();
    draftStatusText.value = `草稿已於 ${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')} 自動儲存`;
    draftSaveError.value = false;
  } else if (result.error === 'quota_exceeded') {
    draftStatusText.value = '儲存空間不足，草稿無法保存';
    draftSaveError.value = true;
  }
};

const saveDraftThrottled = () => {
  if (_draftThrottleTimer.value) clearTimeout(_draftThrottleTimer.value);
  _draftThrottleTimer.value = setTimeout(() => {
    saveDraft();
    _draftThrottleTimer.value = null;
  }, 1500);
};

const loadDraft = () => {
  const { draft } = _loadDraftFromStorage(_draftKeyParams());
  if (!draft) return false;
  applyDraftToForm(draft, form);
  const savedDate = new Date(draft._savedAt);
  draftStatusText.value = `已載入草稿（${savedDate.getMonth()+1}/${savedDate.getDate()} ${String(savedDate.getHours()).padStart(2,'0')}:${String(savedDate.getMinutes()).padStart(2,'0')} 儲存）`;
  draftSaveError.value = false;
  return true;
};

const clearDraft = () => {
  _clearDraftFromStorage(_draftKeyParams());
  draftStatusText.value = '';
  draftSaveError.value = false;
};

const refreshDraftList = () => {
  if (!props.userId) { draftList.value = []; return; }
  draftList.value = _listDraftsFromStorage(props.userId);
};

const openDraftPanel = () => {
  refreshDraftList();
  showDraftPanel.value = true;
};

const closeDraftPanel = () => {
  showDraftPanel.value = false;
};

const deleteDraftFromList = (draftItem) => {
  if (!confirm(`確定清除「${draftItem.studentName} — ${draftItem.sessionDate}」的草稿嗎？`)) return;
  removeDraftByKey(draftItem.key);
  refreshDraftList();
};

const formatDraftTime = (ts) => {
  if (!ts) return '';
  const d = new Date(ts);
  return `${d.getMonth()+1}/${d.getDate()} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
};

const openTargetRecord = () => {
  const targetId = Number(props.targetRecordId || 0);
  if (!targetId) return;
  const record = records.value.find(r => Number(r.id) === targetId);
  if (record) {
    openRecordAction(record);
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

watch(
  () => [form.Progress, form.NextHomework, form.NextWeekTestScope, form.Comment, form.HomeworkStatus, form.QuizScore, form.Performance],
  () => { saveDraftThrottled(); },
  { deep: false }
);

watch(() => props.targetRecordId, (newId) => {
  if (newId) {
    nextTick(() => openTargetRecord());
  }
});

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
    const branchName = branchNames[Number(props.branchId)] || '台北全真一對一補習班';

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
  migrateLegacyDrafts();
  if (props.userId) pruneOldDrafts(props.userId);

  await perf.trackAsync('ensurePastRecords', () => ensurePastRecords());
  await perf.trackAsync('fetchRecords', () => fetchRecords());

  // Secondary data: fire in parallel, don't block TTI
  const secondaryLoads = [
    perf.trackAsync('fetchTeachers', () => fetchTeachers()),
    perf.trackAsync('fetchSubjects', () => fetchSubjects()),
    perf.trackAsync('fetchStudents', () => fetchStudents()),
  ];
  if (props.branchId && isDirectorRole.value) {
    secondaryLoads.push(perf.trackAsync('fetchCourses', () => fetchCourses()));
  }
  if (isTeacher.value) {
    secondaryLoads.push(perf.trackAsync('fetchTeacherClasses', () => fetchTeacherClasses()));
  }
  await Promise.all(secondaryLoads);

  if (isTeacher.value) refreshDraftList();

  const tti = perf.markTTI();
  perf.flushReport();
  if (tti > 3000) {
    console.warn(`[perf] LearningRecordsPage TTI=${tti}ms — exceeds 3s target`);
  }

  nextTick(() => openTargetRecord());
});

watch(() => props.branchId, () => {
  fetchRecords();
  fetchTeachers();
  fetchSubjects();
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

/* ── Review / Filter Tabs ── */
.lr-review-tabs {
  margin-bottom: 12px;
  padding: 12px 16px;
}

.lr-tabs-row {
  display: flex;
  gap: 4px;
  flex-wrap: wrap;
}

.lr-tab {
  padding: 6px 14px;
  border-radius: 20px;
  border: 1px solid #e0e0e0;
  background: #fff;
  font-size: 13px;
  cursor: pointer;
  color: #555;
  transition: all 0.15s;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.lr-tab:hover {
  background: #f5f5f5;
  border-color: #ccc;
}

.lr-tab.active {
  background: #1a73e8;
  color: #fff;
  border-color: #1a73e8;
}

.lr-tab-count {
  font-size: 11px;
  padding: 1px 6px;
  border-radius: 10px;
  background: rgba(0,0,0,0.08);
  font-weight: 600;
}

.lr-tab.active .lr-tab-count {
  background: rgba(255,255,255,0.25);
  color: #fff;
}

.lr-tab-count.warn {
  background: #fff3e0;
  color: #e65100;
}

.lr-tab.active .lr-tab-count.warn {
  background: rgba(255,255,255,0.3);
  color: #fff;
}

.lr-tab-count.ok {
  background: #e8f5e9;
  color: #2e7d32;
}

.lr-tab.active .lr-tab-count.ok {
  background: rgba(255,255,255,0.3);
  color: #fff;
}

.lr-tab-hint {
  margin-top: 8px;
  font-size: 12px;
  color: #888;
}

.lr-unfilled-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin: 8px 0 0 4px;
  font-size: 13px;
  cursor: pointer;
  color: #555;
  user-select: none;
}
.lr-unfilled-toggle input { width: auto; margin: 0; }

/* ── Batch Action Bar ── */
.lr-batch-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 10px;
  padding: 8px 12px;
  background: #e3f2fd;
  border-radius: 8px;
  flex-wrap: wrap;
}

.lr-batch-count {
  font-size: 13px;
  font-weight: 600;
  color: #1565c0;
  margin-right: 4px;
}

/* ── Template Phrases ── */
.lr-phrase-row {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 6px;
}

.lr-phrase-btn {
  font-size: 11px;
  padding: 3px 10px;
  border-radius: 12px;
  border: 1px solid #e0e0e0;
  background: #fafafa;
  color: #555;
  cursor: pointer;
  transition: all 0.15s;
}

.lr-phrase-btn:hover {
  background: #e3f2fd;
  border-color: #90caf9;
  color: #1565c0;
}

/* ── Approved Note ── */
.lr-approved-note {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  background: #e8f5e9;
  border-radius: 8px;
  font-size: 13px;
  color: #2e7d32;
  margin-bottom: 12px;
}

.lr-approved-badge {
  font-weight: 700;
  white-space: nowrap;
}

.teacher-flow-guide {
  margin-bottom: 12px;
  padding: 12px 16px;
  border: 1px solid #dbeafe;
  background: #f8fbff;
}

.teacher-flow-guide__title {
  font-size: 13px;
  font-weight: 700;
  color: #1d4ed8;
  margin-bottom: 8px;
}

.teacher-flow-guide__steps {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 8px;
}

.teacher-flow-guide__steps span {
  font-size: 12px;
  color: #334155;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 8px 10px;
}

.lr-teacher-entry {
  margin-bottom: 16px;
  padding: 16px 20px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  border-left: 4px solid #f59e0b;
  background: #fff7ed;
}

.lr-teacher-entry h3 {
  margin: 0 0 4px;
  font-size: 15px;
}

.lr-teacher-entry p {
  margin: 0;
  color: var(--text-light);
  font-size: 13px;
}

.lr-teacher-entry__text {
  flex: 1;
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
  padding: 8px 16px;
  font-size: 14px;
  min-height: 40px;
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

.lr-readonly-field {
  width: 100%;
  min-height: 40px;
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: #f8fafc;
  color: var(--text);
  font-size: 14px;
  display: flex;
  align-items: center;
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
  min-height: 48px;
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

.ts-status-chip.status-substituted {
  background: #f0f4f8;
  color: #64748b;
}

.ts-status-chip.status-leave {
  background: #eef2f7;
  color: #475569;
}

.ts-status-chip.status-cancelled {
  background: #f1f5f9;
  color: #64748b;
  text-decoration: line-through;
}

.ts-fill-btn {
  background: var(--primary);
  color: #fff;
  border: none;
  padding: 10px 16px;
  border-radius: 8px;
  font-size: 14px;
  min-height: 44px;
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
.ts-week-scroll {
  width: 100%;
}

.ts-week {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 6px;
}

.ts-week-allclear {
  margin: 0 0 10px;
  padding: 10px 14px;
  background: #ecfdf5;
  color: #065f46;
  border: 1px solid #a7f3d0;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  text-align: center;
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

.ts-event-sm.substituted {
  opacity: 0.6;
  background: #f8fafc;
  border-left: 3px solid #94a3b8;
}

.ts-event-sm.ts-event-leave,
.ts-event-sm.ts-event-cancelled {
  opacity: 0.72;
  background: #f8fafc;
  border-left: 3px solid #cbd5e1;
  cursor: not-allowed;
}

.ts-event-sm.ts-event-leave:hover,
.ts-event-sm.ts-event-cancelled:hover {
  background: #f8fafc;
}

.ts-event-sm.ts-event-leave .ts-fill-hint,
.ts-event-sm.ts-event-cancelled .ts-fill-hint {
  color: #64748b;
  font-weight: 500;
}

.ts-event.ts-event-leave,
.ts-event.ts-event-cancelled {
  background: #f8fafc;
  border-left: 4px solid #cbd5e1;
}

.ts-event.ts-event-leave .ts-fill-btn,
.ts-event.ts-event-cancelled .ts-fill-btn {
  background: #cbd5e1;
  color: #475569;
  cursor: not-allowed;
}

.ts-event.ts-event-leave .ts-fill-btn:hover,
.ts-event.ts-event-cancelled .ts-fill-btn:hover {
  background: #cbd5e1;
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

.lr-date-filter-wrap {
  display: flex;
  align-items: center;
  gap: 4px;
}

.lr-date-input {
  flex: 1;
  min-width: 0;
}

.lr-date-clear {
  flex-shrink: 0;
  padding: 4px 8px !important;
  font-size: 12px !important;
  line-height: 1;
  color: #888;
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
.lr-load-more {
  display: flex;
  justify-content: center;
  padding: 12px 10px 16px;
}
.lr-load-more button {
  min-width: 200px;
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

.lr-group-unfilled {
  display: inline-flex;
  align-items: center;
  border-radius: 999px;
  padding: 2px 8px;
  font-size: 12px;
  font-weight: 600;
  background: #FFF3E0;
  color: #E65100;
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
.lr-table-row.lr-row-unfilled {
  border-left: 3px solid #FB8C00;
}
.lr-table-row:hover {
  background: #f7f9ff;
}

.fill-badge {
  display: inline-block;
  font-size: 12px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 10px;
  line-height: 1.4;
}
.fill-badge.fill-missing {
  background: #FFF3E0;
  color: #E65100;
}
.fill-badge.fill-done {
  background: #E8F5E9;
  color: #2E7D32;
}
.fill-badge-na {
  color: var(--text-light);
  font-size: 12px;
}

.lr-date {
  font-weight: 600;
  display: block;
}

.lr-time {
  font-size: 12px;
  color: var(--text-light);
}

.lr-session-num {
  display: inline-block;
  font-size: 11px;
  font-weight: 600;
  color: #1e40af;
  background: #dbeafe;
  padding: 1px 6px;
  border-radius: 8px;
  margin-left: 4px;
  vertical-align: middle;
  white-space: nowrap;
}

.lr-modal-session-num {
  font-size: 14px;
  font-weight: 600;
  color: #1e40af;
  background: #dbeafe;
  padding: 2px 8px;
  border-radius: 10px;
  margin-left: 8px;
  vertical-align: middle;
  white-space: nowrap;
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
}

.lr-actions-inner {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 4px;
}

/* Unified xs button sizing inside action cell */
.lr-actions-inner button {
  margin: 0;
  padding: 4px 10px;
  font-size: 12px;
  border-radius: 6px;
  line-height: 1.5;
  white-space: nowrap;
}

/* Muted danger variant for destructive actions (退回, 刪除) */
.lr-actions-inner button.danger {
  background: #fef2f2;
  color: #b91c1c;
  border: 1px solid #fecaca;
  font-weight: 500;
  box-shadow: none;
}
.lr-actions-inner button.danger:hover {
  background: #fee2e2;
  color: #991b1b;
  border-color: #fca5a5;
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
  gap: 6px;
  padding: 8px 12px;
  border: 1px solid #fed7aa;
  border-left: 3px solid #FB8C00;
  border-radius: 8px;
  background: #fffbf7;
  color: #92400e;
  font-size: 14px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.lr-lock-badge {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  font-size: 11px;
  font-weight: 600;
  color: #92400e;
  background: #fff7ed;
  border: 1px solid #fed7aa;
  border-radius: 4px;
  padding: 1px 5px;
  margin-left: 6px;
  vertical-align: middle;
}

.lr-time-lock-note--inline {
  grid-column: 1 / -1;
  margin-top: -2px;
}

/* emoji 圖示預設隱藏，手機版才顯示 */
.lr-radio-emoji {
  display: none;
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
  flex-wrap: wrap;
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

.lr-modal-header-actions {
  display: flex;
  align-items: center;
  gap: 4px;
}

.lr-download-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 6px 14px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--card-bg);
  color: var(--text);
  font-size: 13px;
  cursor: pointer;
  transition: background 0.15s, border-color 0.15s;
  white-space: nowrap;
}
.lr-download-btn:hover:not(:disabled) {
  background: var(--bg);
  border-color: var(--primary);
  color: var(--primary);
}
.lr-download-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.lr-download-btn .material-symbols-outlined {
  font-size: 18px;
}

.lr-download-toast {
  position: absolute;
  top: 100%;
  right: 28px;
  margin-top: 4px;
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 6px 14px;
  border-radius: 8px;
  background: var(--success);
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  box-shadow: 0 2px 8px rgba(0,0,0,0.15);
  z-index: 2;
}
.lr-download-toast .material-symbols-outlined { font-size: 16px; }
.lr-download-toast.lr-toast-error { background: var(--danger); }

.lr-toast-enter-active, .lr-toast-leave-active {
  transition: opacity 0.25s, transform 0.25s;
}
.lr-toast-enter-from, .lr-toast-leave-to {
  opacity: 0;
  transform: translateY(-4px);
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
  .ts-week-scroll {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }
  .ts-week {
    grid-template-columns: repeat(7, minmax(86px, 1fr));
    min-width: 602px;
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
    max-height: 100dvh;
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
  .form-group input,
  .form-group select,
  .form-group textarea {
    font-size: 16px;
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

  .lr-download-btn .lr-download-label {
    display: none;
  }
  .lr-download-btn {
    padding: 6px 8px;
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
    min-height: 90px;
    resize: vertical;
    field-sizing: content; /* Chrome 123+ / iOS 17.4+ auto-height */
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

  /* HomeworkStatus：2x2 大卡片 */
  .lr-radio-group[data-group="homework"] {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
  }

  .lr-radio-group[data-group="homework"] .lr-radio {
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 12px 8px;
    min-height: 64px;
    font-size: 13px;
    border-radius: 10px;
  }

  /* Performance：3 欄橫排 */
  .lr-radio-group[data-group="performance"] {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 8px;
  }

  .lr-radio-group[data-group="performance"] .lr-radio {
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 12px 6px;
    min-height: 64px;
    font-size: 13px;
    border-radius: 10px;
  }

  /* Emoji 圖示手機版顯示 */
  .lr-radio-emoji {
    display: block;
    font-size: 22px;
    line-height: 1;
  }

  /* 選中語意顏色 */
  .lr-radio-group[data-group="homework"] .lr-radio[data-value="completed"]:has(input:checked),
  .lr-radio-group[data-group="performance"] .lr-radio[data-value="good"]:has(input:checked) {
    border-color: var(--success);
    background: #f0fdf4;
    color: var(--success);
  }

  .lr-radio-group[data-group="homework"] .lr-radio[data-value="partial"]:has(input:checked),
  .lr-radio-group[data-group="performance"] .lr-radio[data-value="average"]:has(input:checked) {
    border-color: #FB8C00;
    background: #fff7ed;
    color: #92400e;
  }

  .lr-radio-group[data-group="homework"] .lr-radio[data-value="incomplete"]:has(input:checked),
  .lr-radio-group[data-group="homework"] .lr-radio[data-value="missing"]:has(input:checked),
  .lr-radio-group[data-group="performance"] .lr-radio[data-value="bad"]:has(input:checked) {
    border-color: var(--danger);
    background: #fff0f0;
    color: var(--danger);
  }

  /* Sticky 提交按鈕 */
  .lr-form-actions {
    position: sticky;
    bottom: 0;
    background: var(--card-bg);
    margin: 0 -20px;
    padding: 12px 20px env(safe-area-inset-bottom, 12px);
    border-top: 1px solid var(--border);
    z-index: 10;
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

/* ── Draft Status Bar (inside modal) ── */
.lr-draft-bar {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  margin: 0 0 8px;
  border-radius: 6px;
  background: #f0f7ff;
  border: 1px solid #c8ddf5;
  font-size: 12px;
  color: #3d6ba8;
}
.lr-draft-bar--error {
  background: #fff4e5;
  border-color: #f0c36d;
  color: #a67c00;
}
.lr-draft-bar-icon {
  font-size: 16px;
  flex-shrink: 0;
}
.lr-draft-bar-text {
  flex: 1;
  min-width: 0;
}
.lr-draft-bar-clear {
  all: unset;
  cursor: pointer;
  font-size: 11px;
  color: #c0392b;
  padding: 2px 8px;
  border-radius: 4px;
  white-space: nowrap;
}
.lr-draft-bar-clear:hover {
  background: rgba(192, 57, 43, 0.08);
}

/* ── Draft List Button (header) ── */
.lr-draft-list-btn {
  position: relative;
}
.lr-draft-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  border-radius: 9px;
  background: #FB8C00;
  color: #fff;
  font-size: 11px;
  font-weight: 600;
  padding: 0 5px;
  margin-left: 4px;
  line-height: 1;
}

/* ── Draft Panel Modal ── */
.lr-draft-panel {
  max-height: 80vh;
  display: flex;
  flex-direction: column;
}
.lr-draft-panel-body {
  flex: 1;
  overflow-y: auto;
  padding: 0 16px 16px;
}

/* Empty state */
.lr-draft-empty {
  text-align: center;
  padding: 32px 16px;
}
.lr-draft-empty-icon {
  font-size: 48px;
  color: #bbb;
  margin-bottom: 12px;
}
.lr-draft-empty-text {
  font-size: 15px;
  font-weight: 600;
  color: #555;
  margin: 0 0 6px;
}
.lr-draft-empty-hint {
  font-size: 13px;
  color: #888;
  margin: 0;
  line-height: 1.5;
}

/* Draft items */
.lr-draft-items {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding-top: 8px;
}
.lr-draft-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  border-radius: 8px;
  background: #fafafa;
  border: 1px solid #eee;
  transition: background 0.15s;
}
.lr-draft-item:hover {
  background: #f0f7ff;
  border-color: #c8ddf5;
}
.lr-draft-item-info {
  flex: 1;
  min-width: 0;
  cursor: default;
}
.lr-draft-item-student {
  font-size: 14px;
  font-weight: 600;
  color: #333;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.lr-draft-item-meta {
  font-size: 12px;
  color: #777;
  margin-top: 2px;
}
.lr-draft-item-time {
  font-size: 11px;
  color: #aaa;
  margin-top: 2px;
}
.lr-draft-item-delete {
  all: unset;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 6px;
  color: #c0392b;
  flex-shrink: 0;
}
.lr-draft-item-delete:hover {
  background: rgba(192, 57, 43, 0.08);
}
.lr-draft-item-delete .material-symbols-outlined {
  font-size: 18px;
}

@media (max-width: 600px) {
  .lr-draft-panel {
    max-width: 100% !important;
    margin: 0;
    border-radius: 12px 12px 0 0;
    max-height: 85vh;
    align-self: flex-end;
    padding-bottom: env(safe-area-inset-bottom, 0);
  }
  .lr-draft-bar {
    font-size: 11px;
    padding: 5px 10px;
  }
  .lr-draft-item {
    padding: 12px;
  }
  .lr-draft-item-delete {
    width: 44px;
    height: 44px;
  }
  .lr-draft-list-btn {
    min-height: 44px;
  }
}
</style>
