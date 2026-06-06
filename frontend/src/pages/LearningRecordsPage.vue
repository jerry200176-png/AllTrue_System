<template>
  <div :class="['lr-page', { 'lr-page--teacher': isTeacher }]">
    <!-- Page Header -->
    <div class="page-header lr-header enterprise-page-header" data-guide="learning-header">
      <div>
        <h2>{{ isTeacher ? '我的課表 & 評量' : '學習評量表' }}</h2>
        <p class="page-desc">{{ isTeacher ? '查看本週課表，填寫學習評量' : '查看、新增與審核學生每堂課的學習評量' }}</p>
      </div>
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button v-if="isTeacher" class="ghost lr-draft-list-btn enterprise-touch-target" @click="openDraftPanel">
          <span class="material-symbols-outlined" style="font-size:16px;vertical-align:-3px">drafts</span>
          草稿
          <span v-if="draftList.length > 0" class="lr-draft-badge">{{ draftList.length }}</span>
        </button>
        <button class="ghost enterprise-touch-target" @click="openExportModal">匯出評量圖</button>
        <button v-if="isTeacher" class="primary enterprise-touch-target" @click="focusTeacherSchedule">從課表填寫</button>
      </div>
    </div>

    <!-- Teacher quick-filter tabs -->
    <div v-if="isTeacher" class="lr-review-tabs card" data-guide="learning-teacher-tabs">
      <div class="lr-tabs-row">
        <button :class="['lr-tab', { active: teacherFilterTab === 'all' }]" @click="teacherFilterTab = 'all'">
          全部 <span class="lr-tab-count">{{ (records || []).length }}</span>
        </button>
        <button :class="['lr-tab', { active: teacherFilterTab === 'pending' }]" @click="teacherFilterTab = 'pending'">
          待審核 <span v-if="kpiPendingOnlyCount > 0" class="lr-tab-count">{{ kpiPendingOnlyCount }}</span>
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
          待審佇列 <span v-if="serverPendingBadge > 0" class="lr-tab-count warn">{{ serverPendingBadge }}</span>
        </button>
        <button :class="['lr-tab', { active: reviewTab === 'changes_requested' }]" @click="reviewTab = 'changes_requested'; selectedRecordIds = new Set()">
          需修改追蹤 <span v-if="serverChangesBadge > 0" class="lr-tab-count warn">{{ serverChangesBadge }}</span>
        </button>
        <button :class="['lr-tab', { active: reviewTab === 'approved' }]" @click="reviewTab = 'approved'; selectedRecordIds = new Set()">
          已核准 <span class="lr-tab-count ok">{{ serverApprovedBadge }}</span>
        </button>
        <button :class="['lr-tab', { active: reviewTab === 'rejected' }]" @click="reviewTab = 'rejected'; selectedRecordIds = new Set()">
          已退回
        </button>
        <button :class="['lr-tab', { active: reviewTab === 'all' }]" @click="reviewTab = 'all'; selectedRecordIds = new Set()">
          全部
        </button>
      </div>

      <label
        v-if="reviewTab === 'pending' || reviewTab === 'changes_requested' || reviewTab === 'approved' || reviewTab === 'all'"
        class="lr-unfilled-toggle"
        title="待審／需修改且正文未填；已核准但正文仍空白者僅在「已核准」或「全部」分頁會被篩出"
      >
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

    <div v-if="isTeacher || isDirectorRole" class="lr-filters-bar card">
      <div class="lr-filters-quick">
        <button
          v-if="(isTeacher ? weekTotalMissingCount : kpiUnfilledCount) > 0"
          :class="['lr-feedback-filter-chip', 'lr-unfilled-shortcut', { active: isTeacher ? teacherPriorityFilter === 'unfilled' : onlyUnfilled }]"
          @click="toggleUnfilledShortcut()"
        >
          未填 <span class="lr-tab-count warn">{{ isTeacher ? weekTotalMissingCount : kpiUnfilledCount }}</span>
        </button>
        <button
          v-if="unreadParentFeedbackCount > 0"
          :class="['lr-feedback-filter-chip', 'lr-unread-shortcut', { active: feedbackFilter === 'unread' }]"
          @click="feedbackFilter = feedbackFilter === 'unread' ? 'all' : 'unread'; showMoreFilters = true"
        >
          未讀回饋 <span class="lr-tab-count warn">{{ unreadParentFeedbackCount }}</span>
        </button>
        <button
          type="button"
          class="lr-more-filters-toggle"
          :class="{ active: showMoreFilters || activeSecondaryFilterCount > 0 }"
          :aria-expanded="showMoreFilters ? 'true' : 'false'"
          @click="showMoreFilters = !showMoreFilters"
        >
          篩選<span v-if="activeSecondaryFilterCount > 0" class="lr-tab-count">{{ activeSecondaryFilterCount }}</span>
          <svg class="lr-more-filters-chev" :class="{ open: showMoreFilters }" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
        </button>
      </div>

      <div v-show="showMoreFilters" class="lr-filters-panel">
        <div v-if="isTeacher" class="lr-filter-group">
          <span class="lr-feedback-filter-label">優先</span>
          <button :class="['lr-feedback-filter-chip', { active: teacherPriorityFilter === 'all' }]" @click="teacherPriorityFilter = 'all'">全部</button>
          <button :class="['lr-feedback-filter-chip', { active: teacherPriorityFilter === 'unfilled' }]" @click="teacherPriorityFilter = 'unfilled'">未填優先</button>
          <button :class="['lr-feedback-filter-chip', { active: teacherPriorityFilter === 'changes_requested' }]" @click="teacherPriorityFilter = 'changes_requested'">需修改</button>
          <button :class="['lr-feedback-filter-chip', { active: teacherPriorityFilter === 'overdue' }]" @click="teacherPriorityFilter = 'overdue'">逾期</button>
        </div>
        <div class="lr-filter-group">
          <span class="lr-feedback-filter-label">家長回饋</span>
          <button :class="['lr-feedback-filter-chip', { active: feedbackFilter === 'all' }]" @click="feedbackFilter = 'all'">全部</button>
          <button :class="['lr-feedback-filter-chip', { active: feedbackFilter === 'has' }]" @click="feedbackFilter = 'has'">
            有回饋 <span v-if="parentFeedbackCount > 0" class="lr-tab-count">{{ parentFeedbackCount }}</span>
          </button>
          <button :class="['lr-feedback-filter-chip', { active: feedbackFilter === 'unread' }]" @click="feedbackFilter = 'unread'">
            未讀回饋 <span v-if="unreadParentFeedbackCount > 0" class="lr-tab-count warn">{{ unreadParentFeedbackCount }}</span>
          </button>
        </div>
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
        <h3>課表</h3>
        <div class="ts-tabs ts-tabs--ios">
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
        <div v-if="todayEvents.length === 0" class="ts-empty enterprise-empty">今日無排課</div>
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
    <div v-if="showBulkModal" class="modal-overlay lr-modal-overlay">
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
      <div class="lr-filters-header">
        <div class="lr-filters-title">
          <svg class="lr-filters-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>
          </svg>
          <span>篩選條件</span>
          <span v-if="activeFilterCount > 0" class="lr-filters-badge" :title="`共 ${activeFilterCount} 個篩選條件已啟用`">{{ activeFilterCount }}</span>
        </div>
        <button
          v-if="hasActiveFilters"
          type="button"
          class="lr-filters-clear-link"
          @click="clearAllFilters"
        >清除全部</button>
      </div>

      <div class="lr-filters-grid">
        <div class="form-group lr-field">
          <label>搜尋學生</label>
          <input
            v-model="filters.student_name"
            type="text"
            class="lr-input"
            placeholder="輸入學生姓名..."
            @keyup.enter="fetchRecords"
          >
        </div>
        <div v-if="!isTeacher" class="form-group lr-field">
          <label>篩選老師</label>
          <SearchableSelect
            v-model="filters.teacher_id"
            :options="teacherOptions"
            placeholder="選擇老師..."
          />
        </div>
        <div class="form-group lr-field lr-field-wide">
          <label>日期範圍</label>
          <div class="lr-date-range-wrap">
            <input v-model="filters.start_date" type="date" class="lr-input lr-date-input" aria-label="起始日期" @change="onDateRangeChange">
            <span class="lr-date-range-dash" aria-hidden="true">～</span>
            <input v-model="filters.end_date" type="date" class="lr-input lr-date-input" aria-label="結束日期" @change="onDateRangeChange">
            <button
              v-if="filters.start_date || filters.end_date"
              type="button"
              class="lr-date-clear"
              title="清除日期篩選"
              aria-label="清除日期篩選"
              @click="filters.start_date = ''; filters.end_date = ''; fetchRecords()"
            >✕</button>
          </div>
          <div v-if="dateRangeError" class="lr-date-range-error" role="alert">{{ dateRangeError }}</div>
        </div>
        <div class="form-group lr-field">
          <label>科目</label>
          <select
            v-model="filters.subject"
            class="lr-input lr-subject-select"
            :disabled="availableSubjects.length === 0"
            :title="availableSubjects.length === 0 ? '目前無科目資料' : '篩選範圍：目前已載入的記錄'"
          >
            <option value="">全部科目</option>
            <option v-for="s in availableSubjects" :key="s" :value="s">{{ s }}</option>
          </select>
        </div>
      </div>

      <div class="lr-filters-actions">
        <button
          class="primary lr-filters-search"
          type="button"
          :disabled="loadingAll"
          :title="loadingAll ? '正在載入全部記錄，請稍候' : ''"
          @click="fetchRecords"
        >
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7"></circle>
            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
          </svg>
          <span>搜尋</span>
        </button>
        <button
          v-if="hasActiveFilters"
          class="ghost lr-filters-reset"
          type="button"
          :disabled="loadingAll"
          @click="clearAllFilters"
        >清除篩選</button>
      </div>
    </div>

    <!-- Default time-window banner (Jira / Salesforce "Last N days" pattern).
         Shown whenever the page auto-applies the 90-day window. Offers a text-link
         escape ("查看全部歷史") that lifts the restriction for this session. -->
    <div v-if="isUsingDefaultWindow" class="lr-window-banner" role="status">
      <svg class="lr-window-banner__icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="9"></circle>
        <polyline points="12 7 12 12 15 14"></polyline>
      </svg>
      <div class="lr-window-banner__text">
        <span>目前顯示 <strong>近 {{ defaultWindowDays }} 天</strong> 的評量記錄</span>
        <span class="lr-window-banner__sep">·</span>
        <span class="lr-window-banner__hint">較早記錄已暫時隱藏以加快載入</span>
      </div>
      <button
        type="button"
        class="lr-window-banner__cta"
        :disabled="recordsPagination.loading || loadingAll"
        @click="clearDefaultWindow"
      >查看全部歷史 →</button>
    </div>

    <!-- Hidden-by-tab info banner (GitHub Issues / Notion filter banner pattern) -->
    <div v-if="hiddenByTabCount > 0" class="lr-tab-filter-banner" role="status">
      <svg class="lr-tab-filter-banner__icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="10"></circle>
        <line x1="12" y1="16" x2="12" y2="12"></line>
        <line x1="12" y1="8" x2="12.01" y2="8"></line>
      </svg>
      <div class="lr-tab-filter-banner__text">
        <span>目前僅顯示 <strong>{{ currentTabLabel }}</strong> 狀態</span>
        <span class="lr-tab-filter-banner__sep">·</span>
        <span>另有 <strong>{{ hiddenByTabCount }}</strong> 筆其他狀態被隱藏</span>
      </div>
      <button
        type="button"
        class="lr-tab-filter-banner__cta"
        @click="showAllStatuses"
      >顯示全部狀態 →</button>
    </div>

    <!-- ===== Records Grouped By Student ===== -->
    <div class="lr-view-toolbar" aria-label="評量顯示模式">
      <div class="lr-view-toolbar__label">顯示模式</div>
      <div class="lr-view-toggle" role="group" aria-label="切換列表或卡片">
        <button
          type="button"
          :class="['lr-view-btn', { active: viewMode === 'table' }]"
          @click="viewMode = 'table'"
        >
          <span class="material-symbols-outlined" aria-hidden="true">view_list</span>
          列表
        </button>
        <button
          type="button"
          :class="['lr-view-btn', { active: viewMode === 'card' }]"
          @click="viewMode = 'card'"
        >
          <span class="material-symbols-outlined" aria-hidden="true">grid_view</span>
          卡片
        </button>
      </div>
    </div>

    <div class="card lr-table-card" data-guide="learning-table">
      <div v-if="recordsPagination.loading && records.length === 0" class="lr-record-skeleton-grid" aria-hidden="true">
        <div v-for="i in 5" :key="i" class="lr-record-skeleton-card">
          <div class="lr-skel-line lr-skel-title"></div>
          <div class="lr-skel-line"></div>
          <div class="lr-skel-line short"></div>
        </div>
      </div>

      <div v-else-if="filteredGroupedRecords.length === 0" class="lr-empty-state enterprise-empty">
        <div class="lr-empty-icon" aria-hidden="true">
          <svg viewBox="0 0 64 64" width="64" height="64" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="28" cy="28" r="16"></circle>
            <line x1="41" y1="41" x2="54" y2="54"></line>
            <line x1="22" y1="28" x2="34" y2="28"></line>
          </svg>
        </div>
        <div class="lr-empty-title">
          <template v-if="isTeacher && teacherPriorityFilter === 'unfilled' && weekTotalMissingCount > 0">
            本週還有 {{ weekTotalMissingCount }} 堂尚未建立評量草稿
          </template>
          <template v-else-if="hasActiveFilters">找不到符合條件的評量記錄</template>
          <template v-else-if="isUsingDefaultWindow">近 {{ defaultWindowDays }} 天無評量記錄</template>
          <template v-else-if="isDirectorRole && reviewTab === 'pending'">目前沒有待審評量</template>
          <template v-else>尚無評量資料</template>
        </div>
        <div
          v-if="isTeacher && teacherPriorityFilter === 'unfilled' && weekTotalMissingCount > 0"
          class="lr-empty-desc"
        >
          這些堂次因為還沒填寫過評量，所以列表上看不到記錄。
          <br>
          請從「<strong>今日待辦</strong>」或「<strong>行事曆</strong>」直接點該堂次填寫，系統會自動建立評量草稿。
        </div>
        <div v-else-if="hasActiveFilters" class="lr-empty-desc">試著調整日期範圍、科目或清除篩選條件</div>
        <div v-else-if="isUsingDefaultWindow" class="lr-empty-desc">若要查看更早的記錄，請點擊下方按鈕。</div>
        <button v-if="hasActiveFilters" class="primary lr-empty-cta" @click="clearAllFilters">清除篩選條件</button>
        <button v-else-if="isUsingDefaultWindow" class="primary lr-empty-cta" @click="clearDefaultWindow">查看全部歷史</button>
      </div>

      <div v-else-if="viewMode === 'card'" class="lr-card-view">
        <section
          v-for="group in filteredGroupedRecords"
          :key="group.key"
          class="lr-card-student-group"
        >
          <div class="lr-card-student-head">
            <button
              type="button"
              class="lr-group-student lr-group-student-btn"
              :title="`點此只載入 ${group.student_name} 的全部記錄`"
              @click.stop.prevent="filterByStudent(group.student_name)"
            >{{ group.student_name }}</button>
            <span class="lr-group-count">{{ group.records.length }} 筆</span>
          </div>
          <div class="lr-card-grid">
            <article
              v-for="record in group.records"
              :key="record.id"
              class="lr-record-card"
              :class="{
                'has-feedback': record.parent_feedback,
                'is-unread': parentFeedbackUnread(record),
                'has-feedback-read': record.parent_feedback && !parentFeedbackUnread(record)
              }"
              @click="viewRecord(record)"
            >
              <div class="lr-record-card__top">
                <div>
                  <div class="lr-record-card__student">{{ record.student_name }}</div>
                  <div class="lr-record-card__meta">
                    {{ record.student_class_label || record.Subject || '未分類' }}
                    <span v-if="record.StudentClassID" class="lr-contract-id">#{{ record.StudentClassID }}</span>
                  </div>
                </div>
                <span :class="statusTagClass(record.Status)" class="status-tag">{{ statusLabel(record.Status) }}</span>
              </div>
              <div class="lr-record-card__time">
                <span class="material-symbols-outlined" aria-hidden="true">event</span>
                {{ record.SessionDate }} {{ record.StartTime || '' }}
                <span v-if="record.session_number"> · 第{{ record.session_number }}堂</span>
              </div>
              <div class="lr-record-card__chips">
                <span
                  v-if="record.parent_feedback"
                  :class="['lr-parent-feedback-chip', parentFeedbackUnread(record) ? 'unread' : 'read']"
                  @click.stop="toggleFeedbackPreview(record)"
                  :title="parentFeedbackUnread(record) ? '有新家長回饋（點擊預覽）' : '家長回饋（點擊預覽）'"
                >
                  💬 {{ parentFeedbackUnread(record) ? '未讀回饋' : '家長回饋' }}
                </span>
                <span
                  v-if="record.teacher_comment"
                  :class="['lr-teacher-comment-chip', teacherCommentUnread(record) ? 'unread' : 'read']"
                  :title="record.teacher_comment.content?.slice(0, 60)"
                >
                  📝 {{ teacherCommentUnread(record) ? '新主任評語' : '主任評語' }}
                </span>
                <span v-if="fillLabel(record)" :class="['fill-badge', fillLabelClass(record)]">{{ fillLabel(record) }}</span>
                <span v-if="!isTeacher" class="lr-record-card__teacher">{{ record.teacher_name || '未指派' }}</span>
              </div>
              <div
                v-if="record.parent_feedback && feedbackPreviewOpen.has(record.id)"
                class="lr-feedback-inline-preview"
                @click.stop
              >
                <div class="lr-feedback-inline-preview__content">{{ record.parent_feedback.content?.slice(0, 100) }}{{ (record.parent_feedback.content?.length || 0) > 100 ? '…' : '' }}</div>
                <div class="lr-feedback-inline-preview__time">{{ formatParentFeedbackTime(record.parent_feedback.updated_at) }}</div>
              </div>
              <div class="lr-record-card__actions" @click.stop>
                <button class="ghost xs" @click="openRecordAction(record)">{{ primaryActionLabel(record) }}</button>
                <button v-if="isDirectorRole && record.id" class="ghost xs lr-btn-director-note" @click="openDirectorNoteModal(record)">主任評語</button>
                <button v-if="canApprove(record)" class="primary xs" @click="approveRecord(record)">核准</button>
                <button v-if="canRequestChanges(record)" class="ghost xs" @click="requestChangesRecord(record)">需修改</button>
                <button v-if="canReject(record)" class="danger xs" @click="rejectRecord(record)">退回</button>
              </div>
            </article>
          </div>
        </section>
      </div>

      <div v-else class="lr-groups">
        <details
          v-for="(group, groupIndex) in filteredGroupedRecords"
          :key="group.key"
          class="lr-group"
          :open="filteredGroupedRecords.length === 1"
        >
          <summary class="lr-group-summary">
            <div class="lr-group-title">
              <button
                type="button"
                class="lr-group-student lr-group-student-btn"
                :title="`點此只載入 ${group.student_name} 的全部記錄`"
                @click.stop.prevent="filterByStudent(group.student_name)"
              >{{ group.student_name }}</button>
              <span class="lr-group-count">{{ group.records.length }} 筆</span>
              <span v-if="group.pending_count > 0" class="lr-group-pending">{{ group.pending_count }} 待處理</span>
              <span v-if="group.unfilled_body_count > 0" class="lr-group-unfilled">{{ group.unfilled_body_count }} 未填</span>
              <span v-if="group.oldest_date" class="lr-group-date-range" :title="'本學生已載入評量日期範圍'">
                <span class="lr-group-date-range-label">日期</span>
                <span v-if="group.oldest_date === group.newest_date">{{ group.oldest_date }}</span>
                <span v-else>{{ group.oldest_date }} ～ {{ group.newest_date }}</span>
              </span>
            </div>
            <span class="lr-group-hint">展開 / 收合</span>
          </summary>

          <div class="lr-subject-subgroups">
            <div
              v-for="sg in group.subjectGroups"
              :key="sg.subject"
              class="lr-subject-subgroup"
              :class="{ 'lr-subject-uncategorized': sg.subject === '未分類' }"
            >
              <div class="lr-subject-header">
                <span class="lr-subject-name">{{ sg.subject }}</span>
                <span class="lr-subject-count" :title="`目前顯示 ${sg.count} 筆`">{{ sg.count }}</span>
                <div v-if="sg.statusCounts" class="lr-status-chips" aria-label="本科目狀態分佈">
                  <span
                    v-if="sg.statusCounts.approved"
                    class="lr-status-chip chip-approved"
                    :class="{ 'chip-muted': !isStatusInCurrentTab('approved') }"
                  >
                    <span class="lr-chip-dot"></span>已核准 {{ sg.statusCounts.approved }}
                  </span>
                  <span
                    v-if="sg.statusCounts.pending"
                    class="lr-status-chip chip-pending"
                    :class="{ 'chip-muted': !isStatusInCurrentTab('pending') }"
                  >
                    <span class="lr-chip-dot"></span>待審 {{ sg.statusCounts.pending }}
                  </span>
                  <span
                    v-if="sg.statusCounts.changes_requested"
                    class="lr-status-chip chip-changes"
                    :class="{ 'chip-muted': !isStatusInCurrentTab('changes_requested') }"
                  >
                    <span class="lr-chip-dot"></span>需修改 {{ sg.statusCounts.changes_requested }}
                  </span>
                  <span
                    v-if="sg.statusCounts.rejected"
                    class="lr-status-chip chip-rejected"
                    :class="{ 'chip-muted': !isStatusInCurrentTab('rejected') }"
                  >
                    <span class="lr-chip-dot"></span>退回 {{ sg.statusCounts.rejected }}
                  </span>
                </div>
                <button
                  v-if="sg.hiddenCount > 0"
                  type="button"
                  class="lr-subject-show-all"
                  :title="`切換至「全部」tab 以顯示本科目另外 ${sg.hiddenCount} 筆`"
                  @click.stop="showAllStatuses"
                >
                  另有 {{ sg.hiddenCount }} 筆未顯示 →
                </button>
              </div>
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
                    <tr v-for="record in sg.records" :key="record.id" class="lr-table-row" :class="{ 'lr-row-unfilled': fillLabelClass(record) === 'fill-missing', 'lr-row-has-feedback': record.parent_feedback && !parentFeedbackUnread(record), 'lr-row-unread': parentFeedbackUnread(record), 'lr-row-urgent': isUrgentTeacherRecord(record) }" @click="viewRecord(record)">
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
                        <div class="lr-class-label">
                          {{ record.student_class_label || record.Subject }}
                          <span v-if="record.StudentClassID" class="lr-contract-id">#{{ record.StudentClassID }}</span>
                        </div>
                        <span
                          v-if="record.parent_feedback"
                          :class="['lr-parent-feedback-chip', parentFeedbackUnread(record) ? 'unread' : 'read']"
                          @click.stop="toggleFeedbackPreview(record)"
                          :title="record.parent_feedback.content?.slice(0,60)"
                        >💬 {{ parentFeedbackUnread(record) ? '未讀回饋' : '家長回饋' }}</span>
                        <span
                          v-if="record.teacher_comment"
                          :class="['lr-teacher-comment-chip', teacherCommentUnread(record) ? 'unread' : 'read']"
                          :title="record.teacher_comment.content?.slice(0,60)"
                        >📝 {{ teacherCommentUnread(record) ? '新主任評語' : '主任評語' }}</span>
                        <div
                          v-if="record.parent_feedback && feedbackPreviewOpen.has(record.id)"
                          class="lr-feedback-inline-preview"
                          @click.stop
                        >
                          <div class="lr-feedback-inline-preview__content">{{ record.parent_feedback.content?.slice(0, 100) }}{{ (record.parent_feedback.content?.length || 0) > 100 ? '…' : '' }}</div>
                          <div class="lr-feedback-inline-preview__time">{{ formatParentFeedbackTime(record.parent_feedback.updated_at) }}</div>
                        </div>
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
                          <button v-if="isDirectorRole && record.id" class="ghost xs lr-btn-director-note" @click="openDirectorNoteModal(record)">主任評語</button>
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
            </div>
          </div>
        </details>
      </div>

      <!-- Load More / Load All (slide-up fade once fully loaded). -->
      <transition name="lr-loadmore-slide">
        <div v-if="records.length < recordsPagination.total || loadingAll" class="lr-load-more">
          <div class="lr-load-more-actions">
            <button
              type="button"
              class="lr-load-more-btn"
              :disabled="recordsPagination.loading || loadingAll"
              :title="loadingAll ? '正在載入全部記錄，請稍候' : ''"
              @click="loadMoreRecords"
            >
              <span class="lr-load-more-title">
                {{ recordsPagination.loading && !loadingAll ? '載入中…' : '載入下一頁' }}
              </span>
              <span v-if="!(recordsPagination.loading && !loadingAll)" class="lr-load-more-sub">
                +{{ Math.min(Math.max(recordsPagination.total - records.length, 0), 200) }} 筆
              </span>
            </button>
            <button
              type="button"
              class="lr-load-all-btn"
              :disabled="recordsPagination.loading && !loadingAll"
              :title="loadingAll ? '正在載入全部記錄，請稍候' : `一次載入全部 ${recordsPagination.total} 筆`"
              @click="loadAllRecords"
            >
              <span class="lr-load-all-title">
                {{ loadingAll ? `載入中… (${records.length} / ${recordsPagination.total})` : '載入全部記錄' }}
              </span>
              <span v-if="!loadingAll" class="lr-load-more-sub">
                全部 {{ recordsPagination.total }} 筆
              </span>
            </button>
          </div>
          <div class="lr-load-more-hint">
            已顯示 <strong>{{ records.length }}</strong> / {{ recordsPagination.total }} 筆<span v-if="loadedOldestDate"> · 最早 <strong>{{ loadedOldestDate }}</strong></span>
          </div>
          <div v-if="recordsPagination.loading || loadingAll" class="lr-skeleton-rows" aria-hidden="true">
            <div v-for="i in 3" :key="i" class="lr-skeleton-row"></div>
          </div>
        </div>
      </transition>
    </div>

    <div v-if="showChangeTeacherModal" class="modal-overlay lr-modal-overlay">
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
    <div v-if="showModal" class="modal-overlay lr-modal-overlay">
      <div class="modal lr-modal lr-modal--learning-form">
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

        <!-- 固定在標題下方，避免捲動時與表頭重疊或被捲出視野（手機尤甚） -->
        <div
          v-if="draftStatusText && !isReadOnly"
          class="lr-draft-bar lr-draft-bar--modal-anchor"
          :class="{ 'lr-draft-bar--error': draftSaveError }"
        >
          <span class="material-symbols-outlined lr-draft-bar-icon">{{ draftSaveError ? 'warning' : 'edit_note' }}</span>
          <span class="lr-draft-bar-text">{{ draftStatusText }}</span>
          <button v-if="!draftSaveError" type="button" class="lr-draft-bar-clear" @click="clearDraft" title="清除草稿">清除草稿</button>
        </div>

        <form @submit.prevent="submitForm" class="lr-form lr-form--stacked">
          <div class="lr-form-scroll">
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
            <div class="lr-prev-summary" aria-live="polite">
              <div class="lr-prev-summary-head">
                <span class="material-symbols-outlined">history</span>
                <span>上一堂摘要</span>
              </div>
              <div v-if="previousSummaryLoading" class="lr-prev-summary-state">載入上一堂資料中…</div>
              <div v-else-if="previousSummaryError" class="lr-prev-summary-state lr-prev-summary-state--error">
                {{ previousSummaryError }}
              </div>
              <div v-else-if="previousLessonSummary" class="lr-prev-summary-body">
                <div class="lr-prev-summary-meta">
                  <span>{{ previousLessonSummary.session_date || '—' }}</span>
                  <span>授課老師：{{ previousLessonSummary.teacher_name || '未指派' }}</span>
                  <span v-if="previousLessonSummary.is_substitute" class="lr-prev-summary-chip">代課</span>
                </div>
                <div class="lr-prev-summary-row">
                  <strong>上次作業</strong>
                  <span>{{ homeworkStatusLabel(previousLessonSummary.homework_status) }}</span>
                </div>
                <div class="lr-prev-summary-row">
                  <strong>週考成績</strong>
                  <span>{{ previousLessonSummary.quiz_score || '—' }}</span>
                </div>
                <div class="lr-prev-summary-row">
                  <strong>授課進度</strong>
                  <span>{{ previousLessonSummary.progress || '—' }}</span>
                </div>
                <div class="lr-prev-summary-row">
                  <strong>作業範圍</strong>
                  <span>{{ previousLessonSummary.next_homework || '—' }}</span>
                </div>
              </div>
              <AtEmpty
                v-else
                icon="history_edu"
                title="尚無上一堂評量資料"
                description="這可能是該課程第一堂，或前一堂評量尚未經主任審核通過。"
              />
            </div>
            <div class="form-group">
              <label>上次作業</label>
              <div class="lr-radio-group" data-group="homework">
                <label class="lr-radio" data-value="completed"><input v-model="form.HomeworkStatus" type="radio" value="completed" :disabled="isReadOnly"><span class="material-symbols-outlined lr-radio-icon">check_circle</span> 已完成</label>
                <label class="lr-radio" data-value="partial"><input v-model="form.HomeworkStatus" type="radio" value="partial" :disabled="isReadOnly"><span class="material-symbols-outlined lr-radio-icon">hourglass_top</span> 部分完成</label>
                <label class="lr-radio" data-value="incomplete"><input v-model="form.HomeworkStatus" type="radio" value="incomplete" :disabled="isReadOnly"><span class="material-symbols-outlined lr-radio-icon">cancel</span> 未完成</label>
                <label class="lr-radio" data-value="missing"><input v-model="form.HomeworkStatus" type="radio" value="missing" :disabled="isReadOnly"><span class="material-symbols-outlined lr-radio-icon">help</span> 未攜帶</label>
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
              <div v-if="!isReadOnly" class="lr-phrase-row lr-phrase-row--hscroll">
                <button v-for="p in templatePhrases.Progress" :key="p" class="lr-phrase-btn" type="button" @click="insertPhrase('Progress', p)">{{ p }}</button>
              </div>
            </div>
            <div class="form-group">
              <label>本次作業範圍</label>
              <textarea v-model="form.NextHomework" rows="2" :disabled="isReadOnly" placeholder="指定下次作業..."></textarea>
              <div v-if="!isReadOnly" class="lr-phrase-row lr-phrase-row--hscroll">
                <button v-for="p in templatePhrases.NextHomework" :key="p" class="lr-phrase-btn" type="button" @click="insertPhrase('NextHomework', p)">{{ p }}</button>
              </div>
            </div>
            <div class="form-group">
              <label>下次週考範圍</label>
              <textarea v-model="form.NextWeekTestScope" rows="2" :disabled="isReadOnly" placeholder="指定下次週考範圍..."></textarea>
              <div v-if="!isReadOnly" class="lr-phrase-row lr-phrase-row--hscroll">
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
                <label class="lr-radio" data-value="good"><input v-model="form.Performance" type="radio" value="good" :disabled="isReadOnly"><span class="material-symbols-outlined lr-radio-icon">sentiment_satisfied</span> 良好</label>
                <label class="lr-radio" data-value="average"><input v-model="form.Performance" type="radio" value="average" :disabled="isReadOnly"><span class="material-symbols-outlined lr-radio-icon">sentiment_neutral</span> 普通</label>
                <label class="lr-radio" data-value="bad"><input v-model="form.Performance" type="radio" value="bad" :disabled="isReadOnly"><span class="material-symbols-outlined lr-radio-icon">sentiment_dissatisfied</span> 不良</label>
              </div>
            </div>
            <div class="form-group">
              <label>學習進度與家長溝通</label>
              <textarea v-model="form.Comment" rows="4" :disabled="isReadOnly" placeholder="綜合評語與聯絡事項..."></textarea>
              <div v-if="!isReadOnly" class="lr-phrase-row lr-phrase-row--hscroll lr-phrase-row--comment">
                <button v-for="p in visibleCommentPhrases" :key="p" class="lr-phrase-btn" type="button" @click="insertPhrase('Comment', p)">{{ p }}</button>
                <button
                  v-if="commentExtraCount > 0"
                  class="lr-phrase-btn lr-phrase-toggle"
                  type="button"
                  :aria-expanded="showAllCommentPhrases ? 'true' : 'false'"
                  @click="showAllCommentPhrases = !showAllCommentPhrases"
                >
                  {{ showAllCommentPhrases ? '收合選項' : `顯示更多 ${commentExtraCount} 個` }}
                </button>
              </div>
            </div>
            <div v-if="_activeRecordRef?.parent_feedback" class="lr-parent-feedback-box">
              <div class="lr-parent-feedback-title">家長回饋</div>
              <div class="lr-parent-feedback-content">{{ _activeRecordRef.parent_feedback.content }}</div>
              <div class="lr-parent-feedback-time">更新：{{ formatParentFeedbackTime(_activeRecordRef.parent_feedback.updated_at) }}</div>

              <!-- 回覆對話串（家長看得到，與下方內部「主任評語」嚴格區隔）-->
              <div v-if="_activeRecordRef.parent_feedback.replies?.length" class="lr-feedback-replies">
                <div
                  v-for="rep in _activeRecordRef.parent_feedback.replies"
                  :key="'lrfr-' + rep.id"
                  :class="['lr-feedback-reply', rep.author_role === 'parent' ? 'is-parent' : 'is-staff']"
                >
                  <div class="lr-feedback-reply-who">
                    {{ rep.author_role === 'parent' ? '家長' : (rep.author_name || (rep.author_role === 'director' ? '主任' : '老師')) }}
                    <span class="lr-feedback-reply-time">{{ formatParentFeedbackTime(rep.created_at) }}</span>
                  </div>
                  <div class="lr-feedback-reply-body">{{ rep.content }}</div>
                </div>
              </div>

              <!-- 回覆家長輸入（老師與主任皆可，家長可見）-->
              <div class="lr-feedback-reply-form">
                <label class="lr-feedback-reply-label">回覆家長（這則訊息家長會看到）</label>
                <textarea
                  v-model="feedbackReplyDraft"
                  rows="2"
                  maxlength="1000"
                  placeholder="例如：謝謝家長的回饋，我們這週會加強單字默寫，下次小考再觀察。"
                ></textarea>
                <div class="lr-teacher-comment-actions">
                  <span v-if="feedbackReplyError" class="lr-teacher-comment-error">{{ feedbackReplyError }}</span>
                  <button type="button" class="primary small" :disabled="feedbackReplySaving || !feedbackReplyDraft.trim()" @click="submitFeedbackReply">
                    {{ feedbackReplySaving ? '送出中...' : '回覆家長' }}
                  </button>
                </div>
              </div>
            </div>
            <div v-if="form.id && (isDirectorRole || _activeRecordRef?.teacher_comment)" class="lr-teacher-comment-box">
              <div class="lr-teacher-comment-title">
                主任給老師評語
                <span v-if="_activeRecordRef?.teacher_comment?.author_name" class="lr-teacher-comment-author">
                  {{ _activeRecordRef.teacher_comment.author_name }}
                </span>
              </div>
              <template v-if="isDirectorRole">
                <textarea
                  v-model="teacherCommentContent"
                  rows="3"
                  maxlength="500"
                  placeholder="給老師的內部評語，不會顯示給家長..."
                ></textarea>
                <div class="lr-teacher-comment-actions">
                  <span v-if="teacherCommentError" class="lr-teacher-comment-error">{{ teacherCommentError }}</span>
                  <span v-else-if="_activeRecordRef?.teacher_comment?.updated_at" class="lr-teacher-comment-time">
                    更新：{{ formatParentFeedbackTime(_activeRecordRef.teacher_comment.updated_at) }}
                  </span>
                  <button type="button" class="primary small" :disabled="teacherCommentSaving" @click="saveTeacherComment">
                    {{ teacherCommentSaving ? '儲存中...' : '儲存主任評語' }}
                  </button>
                </div>
              </template>
              <template v-else>
                <div class="lr-teacher-comment-content">{{ _activeRecordRef.teacher_comment.content }}</div>
                <div class="lr-teacher-comment-time">更新：{{ formatParentFeedbackTime(_activeRecordRef.teacher_comment.updated_at) }}</div>
              </template>
            </div>
          </div>

          <!-- Status Context -->
          <div v-if="form.Status === 'rejected' || form.Status === 'changes_requested'" class="lr-reject-note">
            <div class="lr-reject-note-title">
              {{ form.Status === 'rejected' ? '退回原因' : '需修改說明' }}
            </div>
            <p>{{ form.ReviewNote || '（無說明）' }}</p>
          </div>

          <div v-if="form.Status === 'approved' && form.id" class="lr-approved-note">
            <span class="lr-approved-badge">✓ 已核准</span>
            <span v-if="isTeacher">此評量已由主任核准，無法再修改。</span>
          </div>

          <div v-if="timeLockMessage && !forceReadOnly" class="lr-time-lock-note">{{ timeLockMessage }}</div>
          </div>
          <!-- Actions: fixed footer inside modal (mobile-safe; avoids overlap with sticky/chrome) -->
          <div class="lr-form-actions lr-form-actions--modal-footer">
            <button type="button" class="ghost" @click="closeModal">關閉</button>
            <button v-if="!isReadOnly" type="submit" class="primary">
              {{ isEditing ? '儲存變更' : '提交評量' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- ===== Export Modal ===== -->
    <div v-if="showExportModal" class="modal-overlay lr-modal-overlay">
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
    <div v-if="showDraftPanel" class="modal-overlay lr-modal-overlay" @click.self="closeDraftPanel">
      <div class="lr-modal lr-draft-panel lr-draft-panel--sheet" style="max-width: 480px;">
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

    <!-- Director: quick internal note to teacher (no full edit flow) -->
    <div v-if="showDirectorNoteModal" class="modal-overlay lr-modal-overlay" @click.self="closeDirectorNoteModal">
      <div class="lr-modal lr-director-note-modal" style="max-width: 440px" @click.stop>
        <div class="lr-modal-header">
          <h3>主任給老師評語</h3>
          <button type="button" class="lr-modal-close" @click="closeDirectorNoteModal">&times;</button>
        </div>
        <div class="lr-director-note-body">
          <p v-if="directorNoteTarget" class="lr-director-note-meta">
            {{ directorNoteTarget.student_name }} · {{ directorNoteTarget.SessionDate }} {{ directorNoteTarget.StartTime || '' }}
            <span v-if="directorNoteTarget.teacher_name"> · {{ directorNoteTarget.teacher_name }}</span>
          </p>
          <p class="lr-director-note-hint">內部留言，老師可見，不會顯示給家長。與「需修改說明」不同，可不送審狀態也能補充。</p>
          <textarea
            v-model="directorNoteDraft"
            class="lr-director-note-textarea"
            rows="5"
            maxlength="500"
            placeholder="例：請補上週考錯題類型、或提醒下堂帶課本…"
          ></textarea>
          <div v-if="directorNoteError" class="lr-teacher-comment-error">{{ directorNoteError }}</div>
          <div class="lr-form-actions lr-director-note-actions">
            <button type="button" class="ghost" @click="closeDirectorNoteModal">取消</button>
            <button type="button" class="primary" :disabled="directorNoteSaving" @click="submitDirectorNoteModal">
              {{ directorNoteSaving ? '儲存中…' : '儲存' }}
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
import AtEmpty from '../components/design-system/AtEmpty.vue';
import { fetchClassSessions } from '../lib/classSessionsApi';
import { exportStudentCards, generateStudentCardPng, downloadBlob } from '../lib/learningRecordExport';
import { createPerfTracker } from '../lib/usePerformanceMetrics';
import perfFlags, { loadRemoteFlags } from '../lib/perfFlags';
import {
  buildTeacherClassParams,
  learningSessionStatusLabel,
  resolveLearningSessionState,
} from '../lib/sessionConsistency';
import { resolveLearningRecordsDefaultWindowStart } from '../lib/learningRecordsWindow';
import { resolveDeepLinkBranchId, shouldLiftDefaultWindowForDate, feedbackFocusState } from '../lib/learningRecordTarget';
import { compareLearningRecords } from '../lib/learningRecordSort';
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

const props = defineProps(['branchId', 'userRole', 'userId', 'targetRecordId', 'targetSession', 'feedbackFocusToken']);
const emit = defineEmits(['feedback-read']);

const feedbackPreviewOpen = ref(new Set());
const recentlyReadFeedbackIds = ref(new Set());
const toggleFeedbackPreview = (record) => {
  const id = record.id;
  if (feedbackPreviewOpen.value.has(id)) {
    feedbackPreviewOpen.value.delete(id);
  } else {
    feedbackPreviewOpen.value.add(id);
    markParentFeedbackRead(record);
  }
  feedbackPreviewOpen.value = new Set(feedbackPreviewOpen.value);
};

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
const defaultViewMode = typeof window !== 'undefined' && window.innerWidth < 760 ? 'card' : 'table';
const viewMode = ref(localStorage.getItem('lr_view_mode') || defaultViewMode);
watch(viewMode, (mode) => localStorage.setItem('lr_view_mode', mode));
/** 是否正在執行「載入全部」的自動多頁輪詢。 */
const loadingAll = ref(false);
const showModal = ref(false);
const isEditing = ref(false);
// 從課表點選堂次開啟評量表時設為該 ClassSessionID（無 csId 則為 -1），用於阻擋 form watch 的 applyTeacherFormDefaults 覆蓋（Bug fix 2026-04-18）
const _openedFromScheduleSession = ref(0);
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
const filters = reactive({ student_name: '', teacher_id: '', start_date: '', end_date: '', subject: '' });

/**
 * 使用者是否已明確點「查看全部歷史」解除預設時間窗口（近 90 天）。
 * 一旦解除即不再自動注入 start_date，直到使用者清除篩選條件或重新整理。
 */
const defaultWindowDisabled = ref(false);

/**
 * 計算當下應注入的預設時間窗口 start_date（YYYY-MM-DD），若不套用窗口則回 ''。
 * 套用條件（所有條件同時成立時才套用）：
 *   1) perfFlags.LR_DEFAULT_WINDOW_DAYS > 0（.env 可關閉）
 *   2) 使用者尚未手動設定 filters.start_date
 *   3) 使用者尚未點「查看全部歷史」解除窗口（defaultWindowDisabled=false）
 *   4) 目前 tab 不是「待審」或「需修改」（pending / changes_requested）——
 *      豁免規則參考 Jira Kanban：未完成工作永不受時間窗口隱藏。
 */
const resolvedDefaultWindowStart = computed(() => {
  const days = Number(perfFlags.LR_DEFAULT_WINDOW_DAYS || 0);
  return resolveLearningRecordsDefaultWindowStart({
    days,
    startDate: filters.start_date,
    defaultWindowDisabled: defaultWindowDisabled.value,
    isTeacher: isTeacher.value,
    teacherFilterTab: teacherFilterTab.value,
    teacherPriorityFilter: teacherPriorityFilter.value,
    reviewTab: reviewTab.value,
    feedbackFilter: feedbackFilter.value,
    now: new Date(),
  });
});

/** 當下是否正在套用預設時間窗口（用於顯示 badge）。 */
const isUsingDefaultWindow = computed(() => !!resolvedDefaultWindowStart.value);

/** Badge 顯示用的天數文案。 */
const defaultWindowDays = computed(() => Number(perfFlags.LR_DEFAULT_WINDOW_DAYS || 0));

/**
 * 解除預設時間窗口：點「查看全部歷史」或空狀態「查看更早記錄」時呼叫。
 * 保持其他 filter 不變，只讓本次 session 不再自動注入 start_date。
 */
const clearDefaultWindow = () => {
  defaultWindowDisabled.value = true;
  fetchRecords();
};

/**
 * 科目取值 — 按優先序 fallback（與 ParentPortal.vue 一致），空值歸類為「未分類」。
 */
const recordSubjectKey = (record) => {
  const raw = record?.student_class_label ?? record?.Subject ?? record?.subject ?? record?.SubjectName ?? '';
  const key = String(raw).trim();
  return key || '未分類';
};

/** 日期 range 防呆：起始 > 結束時不送 API。 */
const dateRangeError = computed(() => {
  if (filters.start_date && filters.end_date && filters.start_date > filters.end_date) {
    return '起始日期需早於或等於結束日期';
  }
  return '';
});

/** 科目下拉選項：以目前已載入的 records 去重，zh-Hant 排序（「未分類」排最後）。 */
const availableSubjects = computed(() => {
  const set = new Set();
  for (const r of records.value || []) set.add(recordSubjectKey(r));
  const collator = new Intl.Collator('zh-Hant');
  return Array.from(set).sort((a, b) => {
    if (a === '未分類' && b !== '未分類') return 1;
    if (b === '未分類' && a !== '未分類') return -1;
    return collator.compare(a, b);
  });
});

/** 目前已載入 records 的最舊 SessionDate，供「載入更多」提示用。 */
const loadedOldestDate = computed(() => {
  const dates = (records.value || []).map(r => String(r?.SessionDate || '')).filter(Boolean);
  if (!dates.length) return '';
  return dates.reduce((min, d) => (d < min ? d : min));
});

/** 是否有啟用中的篩選條件，供空狀態 CTA 與清除按鈕用。 */
const hasActiveFilters = computed(() =>
  !!(filters.student_name || filters.teacher_id || filters.start_date || filters.end_date || filters.subject)
);

/** 啟用中的篩選項目數，顯示在標題列 badge（不計 status，已由上方 tab 處理）。 */
const activeFilterCount = computed(() => {
  let n = 0;
  if (filters.student_name) n++;
  if (filters.teacher_id) n++;
  if (filters.start_date || filters.end_date) n++;
  if (filters.subject) n++;
  return n;
});

const reviewTab = ref('pending');
const teacherFilterTab = ref('all');
const teacherPriorityFilter = ref('all');
const feedbackFilter = ref('all');
const onlyUnfilled = ref(false);
const showMoreFilters = ref(false);
const activeSecondaryFilterCount = computed(() => {
  let n = 0;
  if (isTeacher.value && teacherPriorityFilter.value !== 'all') n += 1;
  if (feedbackFilter.value !== 'all') n += 1;
  return n;
});
function toggleUnfilledShortcut() {
  if (isTeacher.value) {
    const on = teacherPriorityFilter.value === 'unfilled';
    teacherFilterTab.value = 'all';
    teacherPriorityFilter.value = on ? 'all' : 'unfilled';
    if (!on) showMoreFilters.value = true;
  } else {
    const on = onlyUnfilled.value;
    if (!on) reviewTab.value = 'all';
    onlyUnfilled.value = !on;
  }
}
watch(reviewTab, (t) => {
  if (!isDirectorRole.value) return;
  if (t === 'rejected' && onlyUnfilled.value) onlyUnfilled.value = false;
});
const selectedRecordIds = ref(new Set());
const batchOperating = ref(false);
const draftAutoSaveKey = ref('');

const templatePhrases = {
  Progress: ['課本 p.XX ~ p.XX', '複習上次範圍', '練習題本 第X回'],
  NextHomework: ['課本 p.XX ~ p.XX', '題本 第X回', '背單字 Unit X'],
  NextWeekTestScope: ['課本 p.XX ~ p.XX', 'Unit X 單字', '第X章'],
  Comment: [
    '表現優良，繼續保持',
    '本次重點已有進步，可再多練習類似題型',
    '建議每日複習 15-30 分鐘，加深熟練度',
    '上課態度穩定，請持續維持',
    '課堂專注度可再提升，建議在家協助建立複習節奏',
    '若家長方便，可協助提醒孩子完成訂正與複習',
    '本次觀念理解穩定，建議持續用例題複習',
    '作業訂正請再留意計算與書寫細節',
    '考前可優先複習錯題與老師標記的重點',
    '鼓勵孩子上課多主動提問，有助於釐清觀念',
    '今日複習狀況良好，建議持續維持進度',
    '課堂問題問得很好，表示思考認真，請繼續保持',
    '本次課程偏複雜，建議週末再複習一次',
    '若作業遇到困難，可先跳過再回頭嘗試',
    '考前一週建議每天固定複習 20 分鐘',
    '本次測驗弱點已確認，下次課將針對性加強',
    '孩子學習態度積極，值得多給正向鼓勵',
    '建議每次上課前 5 分鐘快速翻閱上次筆記',
    '本次課程涵蓋考試重要範圍，需特別留意',
    '整體進度正常，預計下個月可完成本學期重點',
  ],
};

const COMMENT_PHRASE_PREVIEW_COUNT = 8;
const showAllCommentPhrases = ref(false);
const commentExtraCount = computed(() => Math.max(templatePhrases.Comment.length - COMMENT_PHRASE_PREVIEW_COUNT, 0));
const visibleCommentPhrases = computed(() => (
  showAllCommentPhrases.value
    ? templatePhrases.Comment
    : templatePhrases.Comment.slice(0, COMMENT_PHRASE_PREVIEW_COUNT)
));

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
    if (teacherPriorityFilter.value === 'unfilled') {
      list = list.filter(r =>
        (r.Status === 'pending' || r.Status === 'changes_requested') && !hasLearningRecordBody(r)
      );
    } else if (teacherPriorityFilter.value === 'changes_requested') {
      list = list.filter(r => r.Status === 'changes_requested');
    } else if (teacherPriorityFilter.value === 'overdue') {
      list = list.filter(isOverduePendingRecord);
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
    list = list.filter((r) => {
      if (hasLearningRecordBody(r)) return false;
      if (r.Status === 'pending' || r.Status === 'changes_requested') return true;
      if (r.Status === 'approved') {
        return reviewTab.value === 'approved' || reviewTab.value === 'all';
      }
      return false;
    });
  }
  if (feedbackFilter.value === 'has') {
    list = list.filter(r => !!r.parent_feedback);
  } else if (feedbackFilter.value === 'unread') {
    list = list.filter((r) => parentFeedbackUnread(r) || recentlyReadFeedbackIds.value.has(Number(r?.id || 0)));
  }
  return list;
});

/**
 * 兩層分組：學生 → 科目子分組；並計算每位學生已載入評量的最舊/最新日期。
 * 科目排序：zh-Hant 字母排序；「未分類」永遠排最後。
 */
const filteredGroupedRecords = computed(() => {
  const subjectFilter = String(filters.subject || '').trim();
  const groups = new Map();
  const collator = new Intl.Collator('zh-Hant');
  // Memoize hasLearningRecordBody per record (stable for loaded records).
  const bodyCache = new WeakMap();
  const hasBody = (r) => {
    if (!r) return false;
    if (bodyCache.has(r)) return bodyCache.get(r);
    const v = hasLearningRecordBody(r);
    bodyCache.set(r, v);
    return v;
  };
  // 排序：需老師動作的（pending / changes_requested 且未填）置頂；
  // 已核准（含空白）依日期新→舊（修正 in-app 155 / GitHub 742，見 lib/learningRecordSort.js）。
  const sortRecords = (list) => {
    list.sort((a, b) => compareLearningRecords(a, b, hasBody));
    return list;
  };

  // Precompute per-(student,subject) status counts from raw records.value (ignores reviewTab).
  const rawCounts = new Map();
  for (const record of records.value || []) {
    if (subjectFilter && recordSubjectKey(record) !== subjectFilter) continue;
    const sid = Number(record?.student_id || 0) || null;
    const sname = String(record?.student_name || '').trim() || '未命名學生';
    const skey = sid ? `student-${sid}` : `name-${sname}`;
    const subj = recordSubjectKey(record);
    const ck = `${skey}::${subj}`;
    let c = rawCounts.get(ck);
    if (!c) {
      c = { approved: 0, pending: 0, changes_requested: 0, rejected: 0, unfilled: 0, total: 0 };
      rawCounts.set(ck, c);
    }
    c.total += 1;
    const s = record?.Status;
    if (s === 'approved') c.approved += 1;
    else if (s === 'rejected') c.rejected += 1;
    else if (s === 'changes_requested') c.changes_requested += 1;
    else if (s === 'pending') c.pending += 1;
    if (!hasBody(record) && (s === 'pending' || s === 'changes_requested' || s === 'approved')) c.unfilled += 1;
  }

  for (const record of filteredRecords.value) {
    if (subjectFilter && recordSubjectKey(record) !== subjectFilter) continue;
    const studentId = Number(record?.student_id || 0) || null;
    const studentName = String(record?.student_name || '').trim() || '未命名學生';
    const key = studentId ? `student-${studentId}` : `name-${studentName}`;
    if (!groups.has(key)) {
      groups.set(key, {
        key,
        student_id: studentId,
        student_name: studentName,
        pending_count: 0,
        unfilled_body_count: 0,
        records: [],
        subjectMap: new Map(),
        oldest_date: '',
        newest_date: '',
      });
    }
    const group = groups.get(key);
    group.records.push(record);
    if (record?.Status === 'pending' || record?.Status === 'changes_requested') {
      group.pending_count += 1;
      if (!hasBody(record)) group.unfilled_body_count += 1;
    } else if (record?.Status === 'approved' && !hasBody(record)) {
      group.unfilled_body_count += 1;
    }
    const subjKey = recordSubjectKey(record);
    if (!group.subjectMap.has(subjKey)) group.subjectMap.set(subjKey, []);
    group.subjectMap.get(subjKey).push(record);
    const d = String(record?.SessionDate || '');
    if (d) {
      if (!group.oldest_date || d < group.oldest_date) group.oldest_date = d;
      if (!group.newest_date || d > group.newest_date) group.newest_date = d;
    }
  }

  return Array.from(groups.values())
    .map(group => {
      const subjectGroups = Array.from(group.subjectMap.entries())
        .map(([subject, list]) => {
          const counts = rawCounts.get(`${group.key}::${subject}`) || null;
          let shown = 0;
          if (counts) {
            if (isStatusInCurrentTab('approved')) shown += counts.approved;
            if (isStatusInCurrentTab('pending')) shown += counts.pending;
            if (isStatusInCurrentTab('changes_requested')) shown += counts.changes_requested;
            if (isStatusInCurrentTab('rejected')) shown += counts.rejected;
          }
          const hiddenCount = counts ? Math.max(0, counts.total - shown) : 0;
          return {
            subject,
            count: list.length,
            records: sortRecords(list.slice()),
            statusCounts: counts,
            hiddenCount,
          };
        })
        .sort((a, b) => {
          if (a.subject === '未分類' && b.subject !== '未分類') return 1;
          if (b.subject === '未分類' && a.subject !== '未分類') return -1;
          return collator.compare(a.subject, b.subject);
        });
      group.subjectGroups = subjectGroups;
      group.records = sortRecords(group.records.slice());
      delete group.subjectMap;
      return group;
    })
    .sort((a, b) => collator.compare(a.student_name, b.student_name));
});

const pendingCount = computed(() => (records.value || []).filter(r => r.Status === 'pending' || r.Status === 'changes_requested').length);
const kpiPendingOnlyCount = computed(() => (records.value || []).filter(r => r.Status === 'pending').length);
const changesRequestedCount = computed(() => (records.value || []).filter(r => r.Status === 'changes_requested').length);
const approvedCount = computed(() => (records.value || []).filter(r => r.Status === 'approved').length);
const rejectedCount = computed(() => (records.value || []).filter(r => r.Status === 'rejected').length);

// 主任審核分頁 badge 的權威來源：伺服器端全量 per-status 計數（#139/#595）。
// 列表分頁只載一頁，client 計數會與總覽（server 全量）對不起來；badge 改用 server 計數，
// 載入前以 client 計數 fallback，避免閃爍。
const serverStatusCounts = ref(null);
const serverPendingBadge = computed(() => {
  if (!serverStatusCounts.value) return pendingCount.value;
  return Number(serverStatusCounts.value.pending || 0) + Number(serverStatusCounts.value.changes_requested || 0);
});
const serverChangesBadge = computed(() => {
  if (!serverStatusCounts.value) return changesRequestedCount.value;
  return Number(serverStatusCounts.value.changes_requested || 0);
});
const serverApprovedBadge = computed(() => {
  if (!serverStatusCounts.value) return approvedCount.value;
  return Number(serverStatusCounts.value.approved || 0);
});
const kpiUnfilledCount = computed(() =>
  (records.value || []).filter((r) => {
    if (hasLearningRecordBody(r)) return false;
    return r.Status === 'pending' || r.Status === 'changes_requested' || r.Status === 'approved';
  }).length
);
const parentFeedbackUnread = (record) => {
  const fb = record?.parent_feedback;
  return isTeacher.value ? !!fb?.unread_for_teacher : !!fb?.unread_for_director;
};
const teacherCommentUnread = (record) => {
  const comment = record?.teacher_comment;
  return isTeacher.value ? !!comment?.unread_for_teacher : false;
};
const formatParentFeedbackTime = (value) => {
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '';
  return `${d.getMonth() + 1}/${d.getDate()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};
const markParentFeedbackRead = async (record) => {
  const feedback = record?.parent_feedback;
  if (!feedback?.id || !parentFeedbackUnread(record)) return;
  try {
    const token = await getToken();
    if (!token) throw new Error('missing auth token');
    const res = await fetch(`/api/v1/learning-record-feedbacks/${feedback.id}/read`, {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${token}` },
    });
    if (!res.ok) throw new Error(`mark read failed: ${res.status}`);
    if (feedbackFilter.value === 'unread') {
      const id = Number(record?.id || 0);
      if (id > 0) {
        recentlyReadFeedbackIds.value.add(id);
        recentlyReadFeedbackIds.value = new Set(recentlyReadFeedbackIds.value);
      }
    }
    if (isTeacher.value) feedback.unread_for_teacher = false;
    else feedback.unread_for_director = false;
    emit('feedback-read');
  } catch (e) {
    console.warn('[LR] Failed to mark parent feedback as read');
  }
};
const updateRecordTeacherComment = (recordId, comment) => {
  const rid = Number(recordId || 0);
  if (!rid) return;
  const apply = (record) => (Number(record?.id || 0) === rid ? { ...record, teacher_comment: comment } : record);
  records.value = (records.value || []).map(apply);
  if (_activeRecordRef.value && Number(_activeRecordRef.value.id || 0) === rid) {
    _activeRecordRef.value = { ..._activeRecordRef.value, teacher_comment: comment };
  }
};
const markTeacherCommentRead = async (record) => {
  const comment = record?.teacher_comment;
  if (!comment?.id || !teacherCommentUnread(record)) return;
  try {
    const token = await getToken();
    if (!token) throw new Error('missing auth token');
    const res = await fetch(`/api/v1/learning-record-teacher-comments/${comment.id}/read`, {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${token}` },
    });
    if (!res.ok) throw new Error(`mark read failed: ${res.status}`);
    updateRecordTeacherComment(record.id, { ...comment, unread_for_teacher: false });
  } catch (e) {
    console.warn('[LR] Failed to mark teacher comment as read');
  }
};
const saveTeacherComment = async () => {
  const recordId = Number(_activeRecordRef.value?.id || form.id || 0);
  if (!recordId || teacherCommentSaving.value) return;
  const content = String(teacherCommentContent.value || '').trim();
  if (!content || content.length > 500) {
    teacherCommentError.value = '主任評語需為 1-500 字';
    return;
  }
  teacherCommentSaving.value = true;
  teacherCommentError.value = '';
  try {
    const token = await getToken();
    if (!token) throw new Error('請重新登入');
    const res = await fetch(`/api/v1/learning-records/${recordId}/teacher-comment`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
      },
      body: JSON.stringify({ content }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json?.message || '儲存主任評語失敗');
    updateRecordTeacherComment(recordId, json.comment || null);
    teacherCommentContent.value = json.comment?.content || content;
  } catch (e) {
    teacherCommentError.value = e?.message || '儲存主任評語失敗';
  } finally {
    teacherCommentSaving.value = false;
  }
};

// 老師/主任回覆家長（家長可見）；送出後重新取得回覆串並同步清單。
const submitFeedbackReply = async () => {
  const fb = _activeRecordRef.value?.parent_feedback;
  if (!fb?.id || feedbackReplySaving.value) return;
  const content = String(feedbackReplyDraft.value || '').trim();
  if (!content || content.length > 1000) {
    feedbackReplyError.value = '回覆需為 1-1000 字';
    return;
  }
  feedbackReplySaving.value = true;
  feedbackReplyError.value = '';
  try {
    const token = await getToken();
    if (!token) throw new Error('請重新登入');
    const res = await fetch(`/api/v1/learning-record-feedbacks/${fb.id}/reply`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ content }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json?.message || '回覆失敗');
    const repRes = await fetch(`/api/v1/learning-record-feedbacks/${fb.id}/replies`, {
      headers: { 'Authorization': `Bearer ${token}` },
    });
    const repJson = await repRes.json().catch(() => ({}));
    const replies = repJson?.data || [];
    const newFb = { ...fb, replies };
    const rid = Number(_activeRecordRef.value?.id || 0);
    _activeRecordRef.value = { ..._activeRecordRef.value, parent_feedback: newFb };
    records.value = (records.value || []).map(r => Number(r?.id || 0) === rid ? { ...r, parent_feedback: newFb } : r);
    feedbackReplyDraft.value = '';
  } catch (e) {
    feedbackReplyError.value = e?.message || '回覆失敗';
  } finally {
    feedbackReplySaving.value = false;
  }
};

const parentFeedbackCount = computed(() => (records.value || []).filter(r => !!r.parent_feedback).length);
const unreadParentFeedbackCount = computed(() => (records.value || []).filter(parentFeedbackUnread).length);

/**
 * 目前頂部 tab（reviewTab / teacherFilterTab）隱藏了幾筆「否則會顯示」的記錄。
 * 當此值 > 0 時，在畫面上方顯示 info banner，提示使用者資料未遺失。
 * 參考：GitHub Issues / Notion filter banner、Nielsen "Visibility of system status"。
 */
const hiddenByTabCount = computed(() => {
  const all = records.value || [];
  if (isTeacher.value) {
    if (teacherFilterTab.value === 'changes_requested') {
      return all.length - all.filter(r => r.Status === 'changes_requested').length;
    }
    if (teacherFilterTab.value === 'approved') {
      return all.length - all.filter(r => r.Status === 'approved').length;
    }
    if (teacherFilterTab.value === 'pending') {
      return all.length - all.filter(r => r.Status === 'pending').length;
    }
    return 0;
  }
  if (isDirectorRole.value) {
    if (reviewTab.value === 'pending') {
      return all.length - all.filter(r => r.Status === 'pending' || r.Status === 'changes_requested').length;
    }
    if (reviewTab.value === 'changes_requested') {
      return all.length - all.filter(r => r.Status === 'changes_requested').length;
    }
    if (reviewTab.value === 'approved') {
      return all.length - all.filter(r => r.Status === 'approved').length;
    }
    if (reviewTab.value === 'rejected') {
      return all.length - all.filter(r => r.Status === 'rejected').length;
    }
    return 0;
  }
  return 0;
});

/** 目前 tab 的人類可讀名稱，用於 banner 文案。 */
const currentTabLabel = computed(() => {
  if (isTeacher.value) {
    return ({
      pending: '待審核',
      changes_requested: '需修改',
      approved: '已核准',
    })[teacherFilterTab.value] || '';
  }
  if (isDirectorRole.value) {
    return ({
      pending: '待審佇列',
      changes_requested: '需修改追蹤',
      approved: '已核准',
      rejected: '已退回',
    })[reviewTab.value] || '';
  }
  return '';
});

/**
 * 判斷某個 status 是否落在目前 tab 的顯示範圍內。
 * 回傳 true 代表該 status 的記錄不會被 tab 隱藏。
 */
const isStatusInCurrentTab = (status) => {
  if (isTeacher.value) {
    const t = teacherFilterTab.value;
    if (t === 'all') return true;
    return t === status;
  }
  if (isDirectorRole.value) {
    const t = reviewTab.value;
    if (t === 'all') return true;
    if (t === 'pending') return status === 'pending' || status === 'changes_requested';
    return t === status;
  }
  return true;
};

/**
 * 點選學生姓名後，把該姓名寫入 `filters.student_name` 並重新抓取。
 * 後端依 student_name 過濾，能回傳該生全部記錄（避免受跨學生分頁限制）。
 * 參考 Airtable / Linear 的「點值過濾到該值」群組互動模式。
 */
const filterByStudent = (name) => {
  const trimmed = String(name || '').trim();
  if (!trimmed) return;
  if (filters.student_name === trimmed && defaultWindowDisabled.value) return;
  filters.student_name = trimmed;
  // 點學生姓名 = 我想看「這個學生的全部歷史」，同步解除預設時間窗口（FR-005）
  defaultWindowDisabled.value = true;
  fetchRecords();
};

/** 一鍵切回「全部」tab，供 banner CTA 使用。 */
const showAllStatuses = () => {
  if (isTeacher.value) {
    teacherFilterTab.value = 'all';
  } else if (isDirectorRole.value) {
    reviewTab.value = 'all';
  }
  selectedRecordIds.value = new Set();
};

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
const previousLessonSummary = ref(null);
const previousSummaryLoading = ref(false);
const previousSummaryError = ref('');
const previousSummaryContext = ref({ studentClassId: 0, sessionDate: '', excludeId: 0 });
const teacherCommentContent = ref('');
const teacherCommentSaving = ref(false);
const teacherCommentError = ref('');
const feedbackReplyDraft = ref('');
const feedbackReplySaving = ref(false);
const feedbackReplyError = ref('');

/** 主任從列表／卡片直接開「給老師評語」，不必先進完整編輯 */
const showDirectorNoteModal = ref(false);
const directorNoteTarget = ref(null);
const directorNoteDraft = ref('');
const directorNoteSaving = ref(false);
const directorNoteError = ref('');

const openDirectorNoteModal = (record) => {
  if (!isDirectorRole.value || !record?.id) return;
  directorNoteTarget.value = record;
  directorNoteDraft.value = String(record?.teacher_comment?.content || '').trim() || '';
  directorNoteError.value = '';
  showDirectorNoteModal.value = true;
};

const closeDirectorNoteModal = () => {
  showDirectorNoteModal.value = false;
  directorNoteTarget.value = null;
  directorNoteDraft.value = '';
  directorNoteError.value = '';
  directorNoteSaving.value = false;
};

const submitDirectorNoteModal = async () => {
  const recordId = Number(directorNoteTarget.value?.id || 0);
  if (!recordId || directorNoteSaving.value) return;
  const content = String(directorNoteDraft.value || '').trim();
  if (!content || content.length > 500) {
    directorNoteError.value = '主任評語需為 1-500 字';
    return;
  }
  directorNoteSaving.value = true;
  directorNoteError.value = '';
  try {
    const token = await getToken();
    if (!token) throw new Error('請重新登入');
    const res = await fetch(`/api/v1/learning-records/${recordId}/teacher-comment`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
      },
      body: JSON.stringify({ content }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json?.message || '儲存主任評語失敗');
    updateRecordTeacherComment(recordId, json.comment || null);
    if (Number(form.id) === recordId) {
      teacherCommentContent.value = json.comment?.content || content;
    }
    closeDirectorNoteModal();
  } catch (e) {
    directorNoteError.value = e?.message || '儲存主任評語失敗';
  } finally {
    directorNoteSaving.value = false;
  }
};
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
  if (!date) return true;
  if (!time) {
    const dayStart = new Date(`${date}T00:00:00`);
    if (Number.isNaN(dayStart.getTime())) return true;
    return Date.now() >= dayStart.getTime();
  }
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
  const effTidForName = incoming.effective_teacher_id ?? incoming.TeacherID ?? incoming.teacher_id;
  const normalized = {
    ...incoming,
    id: Number(incoming.id),
    student_id: Number(incoming.student_id || incoming.StudentID || 0) || null,
    student_name: incoming.student_name || studentList.value.find((s) => String(s.id) === String(incoming.student_id || incoming.StudentID || ''))?.name || '',
    teacher_name: incoming.teacher_name || teacherList.value.find((t) => String(t.id) === String(effTidForName || ''))?.Name || '',
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

const homeworkStatusLabel = (status) => {
  const map = {
    completed: '已完成',
    partial: '部分完成',
    incomplete: '未完成',
    missing: '未攜帶',
  };
  return map[String(status || '').trim()] || '—';
};

const resetPreviousSummaryState = () => {
  previousLessonSummary.value = null;
  previousSummaryError.value = '';
  previousSummaryLoading.value = false;
  previousSummaryContext.value = { studentClassId: 0, sessionDate: '', excludeId: 0 };
};

const loadLatestApprovedSummary = async ({ studentClassId = 0, sessionDate = '', excludeId = 0 } = {}) => {
  const sid = Number(studentClassId || 0);
  if (sid <= 0) {
    previousLessonSummary.value = null;
    previousSummaryError.value = '';
    previousSummaryLoading.value = false;
    return;
  }

  previousSummaryContext.value = {
    studentClassId: sid,
    sessionDate: String(sessionDate || '').slice(0, 10),
    excludeId: Number(excludeId || 0),
  };
  previousSummaryLoading.value = true;
  previousSummaryError.value = '';

  try {
    const token = await getToken();
    if (!token) throw new Error('請重新登入');
    const params = new URLSearchParams({ student_class_id: String(sid) });
    if (previousSummaryContext.value.sessionDate) params.set('session_date', previousSummaryContext.value.sessionDate);
    if (previousSummaryContext.value.excludeId > 0) params.set('exclude_id', String(previousSummaryContext.value.excludeId));
    const res = await fetch(`/api/v1/learning-records/latest-approved-summary?${params.toString()}`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json?.message || '上一堂資料載入失敗');
    previousLessonSummary.value = json?.summary || null;
  } catch (e) {
    console.error('[LR] load latest-approved-summary failed:', e);
    previousLessonSummary.value = null;
    previousSummaryError.value = '上一堂資料暫時無法讀取，請稍後再試';
  } finally {
    previousSummaryLoading.value = false;
  }
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
    // Include inactive/closed classes as well. Teachers may still need to fill
    // recent learning records for sessions that were attended before closure.
    const params = buildTeacherClassParams({ branchId: props.branchId, perPage: 200 });
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

const scheduleStatusLabel = learningSessionStatusLabel;

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
    const id = Number(s?.id || 0);
    const date = String(s?.session_date || s?.SessionDate || '').slice(0, 10);
    const time = normalizeTime(s?.start_time || s?.StartTime) || '';
    // Keep different ClassSession IDs even when they share the same slot:
    // a legitimate reschedule-to-past can produce two lessons on the same date/time.
    // Grouping only by date|time would hide one and block teacher fill flow.
    const key = id > 0 ? `id:${id}` : `slot:${date}|${time}`;
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

const resolveStudentClassIdForCurrentForm = () => {
  const activeClassId = Number(_activeRecordRef.value?.StudentClassID || _activeRecordRef.value?.student_class_id || 0);
  if (activeClassId > 0) return activeClassId;
  const studentId = String(form.StudentID || '').trim();
  if (!studentId) return 0;
  let candidates = courseList.value.filter((course) => {
    if (String(course.student_id || course.StudentID || '') !== studentId) return false;
    return subjectsMatch(course.subject || course.Subject, form.Subject);
  });
  if (form.TeacherID) {
    const filtered = candidates.filter((course) => String(course.teacher_id || course.TeacherID || '') === String(form.TeacherID));
    if (filtered.length > 0) candidates = filtered;
  }
  return Number(candidates[0]?.id || candidates[0]?.ID || 0);
};

watch(
  () => [showModal.value, isEditing.value, form.StudentID, form.Subject, form.TeacherID, form.SessionDate],
  ([visible, editing]) => {
    if (!visible || editing) return;
    const studentClassId = resolveStudentClassIdForCurrentForm();
    if (studentClassId <= 0) return;
    const sessionDate = String(form.SessionDate || '').slice(0, 10);
    const current = previousSummaryContext.value;
    if (
      Number(current.studentClassId || 0) === studentClassId &&
      String(current.sessionDate || '') === sessionDate &&
      Number(current.excludeId || 0) === 0
    ) {
      return;
    }
    void loadLatestApprovedSummary({ studentClassId, sessionDate, excludeId: 0 });
  }
);

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
      const byCs = csId > 0 ? recordLookup.value.get(`cs:${csId}`) : null;
      const byTime = startTime ? recordLookup.value.get(`${classId}|${dateStr}|${startTime}`) : null;
      const byDate = recordLookup.value.get(`${classId}|${dateStr}`);
      const record = byCs || byTime || byDate;
      const rowStatus = String(rawSession?.learning_record_status || '');
      const sessionStatus = String(rawSession?.status || rawSession?.Status || '').toLowerCase();
      // 請假／取消堂次：一律不需填評量；與 SmartCalendar.evalBadge 的 LEAVE_STATUSES 行為對齊。
      // 後端 LR 已被 CourseLeaveCascadeService 作廢（VoidedAt），learning_record_status 回 'missing'，
      // 若未於此處攔截，評量頁會誤顯示「未填」並開放填寫 → 2026-04-17 修正。
      const isSubstituted = Number(rawSession?.learning_record_teacher_id || 0) > 0
        && Number(rawSession?.learning_record_teacher_id || 0) !== myId
        && myId > 0;
      const sessionStarted = isSessionStarted(dateStr, startTime);
      const sessionState = resolveLearningSessionState({
        sessionStatus,
        learningRecordStatus: rowStatus,
        recordStatus: record?.Status,
        isSubstituted,
        sessionStarted,
      });
      const isLeaveSession = sessionState.isLeave;
      const isCancelledSession = sessionState.isCancelled;
      const isAbsentSession = sessionState.isAbsent;
      if (isTeacher.value && isCancelledSession) continue;
      const formStatus = sessionState.formStatus;
      const apiLrId = rawSession?.learning_record_id != null ? Number(rawSession.learning_record_id) : null;
      const safeLookupRecord = byCs || byTime;
      let recordId = (apiLrId != null && apiLrId > 0) ? apiLrId : null;
      if (!recordId && safeLookupRecord?.id) {
        recordId = Number(safeLookupRecord.id);
      }

      // 請假／取消／缺席：永遠鎖定不可填；其他沿用既有規則（代課 or 尚未開始）。
      const fillLocked = sessionState.fillLocked;
      const student = studentList.value.find(s => String(s.id) === String(sc.student_id || sc.StudentID));
      const studentName = student?.name || sc.student_name || `學生#${sc.student_id || sc.StudentID}`;
      const eventKey = csId > 0 ? `cs-${csId}` : `${classId}-${dateStr}-${startTime || ''}`;
      const fillLockReason = sessionState.fillLockReason;

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
        // 請假／取消／缺席：不綁 recordId，避免誤觸 canEdit 分支開啟評量 modal。
        recordId: sessionState.recordIdAllowed ? (recordId || null) : null,
        formStatus: isSubstituted ? 'substituted' : formStatus,
        formStatusLabel: isSubstituted ? '代課' : sessionState.label,
        fillLocked,
        fillLockReason,
        isSubstituted,
        isLeave: isLeaveSession,
        isCancelled: isCancelledSession,
        isAbsent: isAbsentSession,
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

const openFromSchedule = async (ev) => {
  if (ev.recordId) {
    let existing = records.value.find((r) => Number(r.id) === Number(ev.recordId));
    if (!existing) {
      // 記錄存在於伺服器但不在本地清單（90 天視窗外）→ 重新拉取後重試
      await fetchRecords();
      existing = records.value.find((r) => Number(r.id) === Number(ev.recordId));
    }
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
  void loadLatestApprovedSummary({
    studentClassId: Number(ev.classId || 0),
    sessionDate: ev.date || form.SessionDate || '',
    excludeId: 0,
  });
  _openedFromScheduleSession.value = ev.classSessionId || -1;
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
/**
 * 建構 GET /api/v1/learning-records 的查詢參數。
 *
 * 特殊 options：
 *   - beforeId (number)：若指定，以 keyset 模式發送（`before_id=N`，不帶 `page`）；
 *     用於 loadAllRecords 避免 OFFSET 分頁的 sliding window 問題（Slack pagination 規範）。
 *
 * 時間窗口（預設 90 天）：當使用者未設定 start_date、未點「查看全部歷史」、
 * 且目前 tab 不是待審/需修改時，自動注入 `start_date = today - WINDOW_DAYS`。
 */
const _buildRecordsParams = (page = 1, { beforeId = null } = {}) => {
  const params = new URLSearchParams();
  if (props.branchId) params.set('branch_id', props.branchId);
  if (filters.student_name) params.set('student_name', filters.student_name);
  if (filters.teacher_id) params.set('teacher_id', filters.teacher_id);
  // 起始 > 結束時不送 API，由 dateRangeError 顯示 inline 錯誤
  if (!dateRangeError.value) {
    if (filters.start_date) {
      params.set('start_date', filters.start_date);
    } else {
      const auto = resolvedDefaultWindowStart.value;
      if (auto) params.set('start_date', auto);
    }
    if (filters.end_date) params.set('end_date', filters.end_date);
  }
  // 家長回饋篩選改走伺服器端，確保跨頁/跨日期的回饋紀錄都撈得到（#138/#139）。
  if (feedbackFilter.value === 'has' || feedbackFilter.value === 'unread') {
    params.set('feedback', feedbackFilter.value);
  }
  // 主任審核分頁改走伺服器端 status 篩選，讓列表＝該狀態全量（分頁載入），與 badge/總覽一致（#139/#595）。
  if (isDirectorRole.value && !isTeacher.value) {
    const tabStatus = {
      pending: 'pending,changes_requested',
      changes_requested: 'changes_requested',
      approved: 'approved',
      rejected: 'rejected',
    }[reviewTab.value];
    if (tabStatus) params.set('status', tabStatus);
  }
  params.set('sort', 'session_date');
  params.set('per_page', String(perfFlags.LR_DEFAULT_PER_PAGE));
  if (beforeId != null && beforeId > 0) {
    // Keyset branch — backend 嚴格忽略 page 參數，前端亦不送 page 以避免誤解
    params.set('before_id', String(beforeId));
  } else {
    params.set('page', String(page));
  }
  return params;
};

/** 日期 range 變更 handler：僅在無防呆錯誤時送出 API。 */
const onDateRangeChange = () => {
  if (dateRangeError.value) return;
  fetchRecords();
};

/** 清除所有篩選條件，常用於空狀態 CTA。預設時間窗口一併恢復。 */
const clearAllFilters = () => {
  filters.student_name = '';
  filters.teacher_id = '';
  filters.start_date = '';
  filters.end_date = '';
  filters.subject = '';
  teacherPriorityFilter.value = 'all';
  defaultWindowDisabled.value = false;
  fetchRecords();
};

// 抓主任審核分頁 badge 用的伺服器端全量 per-status 計數（#139/#595）。
// 與列表查詢分離，不帶 status/feedback，故得到該分校全量、權威的各狀態總數。
const fetchStatusCounts = async () => {
  if (!isDirectorRole.value || isTeacher.value) return;
  try {
    const token = await getToken();
    if (!token) return;
    const params = new URLSearchParams({ with_status_counts: '1', per_page: '1' });
    if (props.branchId) params.set('branch_id', props.branchId);
    const res = await fetch(`/api/v1/learning-records?${params.toString()}`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (!res.ok) return;
    const data = await res.json();
    serverStatusCounts.value = data.status_counts || {};
  } catch (e) {
    console.error('[LR] fetchStatusCounts failed', e);
  }
};

const fetchRecords = async () => {
  try {
    const token = await getToken();
    if (!token) return;
    recordsPagination.value = { ...recordsPagination.value, loading: true };

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
    recordsPagination.value = { ...recordsPagination.value, loading: false };
  }
};

/**
 * 抓取指定頁或指定 before_id 的記錄並合併到 records.value，回傳載入結果統計。
 * 抽出為共用函式，供 loadMoreRecords（OFFSET）與 loadAllRecords（keyset）使用。
 *
 * @param {number} targetPage - OFFSET 模式下的頁數（忽略於 keyset 模式）
 * @param {{ beforeId?: number|null }} opts - beforeId 非空時改用 keyset 模式
 */
const _fetchRecordsPage = async (targetPage, { beforeId = null } = {}) => {
  const token = await getToken();
  if (!token) return { ok: false, reason: 'no-token' };

  const params = _buildRecordsParams(targetPage, { beforeId });
  const res = await fetch(`/api/v1/learning-records?${params.toString()}`, {
    headers: { 'Authorization': `Bearer ${token}` }
  });
  if (!res.ok) {
    const body = await res.text().catch(() => '');
    console.error('[LR] fetch page failed:', res.status, body);
    return { ok: false, reason: 'http', status: res.status };
  }

  const data = await res.json();
  const newRows = data.data || [];
  const existingIds = new Set(records.value.map(r => r.id));
  const deduped = newRows.filter(r => !existingIds.has(r.id));
  records.value = [...records.value, ...deduped];
  const pg = recordsPagination.value;
  // Keyset 回應無 current_page/last_page/total，僅推算已載入筆數與 next_before_id
  const isKeyset = data.pagination === 'keyset' || beforeId != null;
  if (!isKeyset) {
    pg.currentPage = data.current_page || targetPage;
    pg.lastPage = data.last_page || pg.lastPage;
    pg.total = data.total || pg.total;
  }
  const minIdInPage = newRows.length > 0
    ? newRows.reduce((min, r) => (Number(r.id) < min ? Number(r.id) : min), Number.POSITIVE_INFINITY)
    : null;
  return {
    ok: true,
    added: deduped.length,
    returned: newRows.length,
    minId: Number.isFinite(minIdInPage) ? minIdInPage : null,
    nextBeforeId: data.next_before_id ?? (Number.isFinite(minIdInPage) ? minIdInPage : null),
    isKeyset,
    data,
  };
};

const loadMoreRecords = async () => {
  const pg = recordsPagination.value;
  if (pg.loading || pg.currentPage >= pg.lastPage) return;
  pg.loading = true;
  try {
    const result = await _fetchRecordsPage(pg.currentPage + 1);
    if (!result.ok) {
      if (result.reason === 'http') alert(`載入更早記錄失敗（HTTP ${result.status}），請稍後再試`);
      return;
    }
    if (result.added === 0 && result.returned === 0) {
      // 真正沒有更多資料；更新 lastPage 以避免使用者再次點擊
      pg.lastPage = pg.currentPage;
    } else if (result.added === 0 && result.returned > 0) {
      // 後端回傳了記錄但 ID 全部重複——通常是瀏覽器/CDN 快取舊頁。
      // 更新 currentPage 推進而不顯示誤導的「沒有更多」alert。
      console.warn('[LR] 載入更多回傳重複資料，可能為快取問題；推進 currentPage');
    } else {
      console.log(`[LR] 載入 ${result.added} 筆更早記錄（目前共 ${records.value.length} / ${pg.total}）`);
    }
  } catch (e) {
    console.error('[LR] load more error:', e);
    alert('載入更早記錄時發生錯誤，請稍後再試');
  } finally {
    pg.loading = false;
  }
};

/**
 * 一鍵載入全部剩餘記錄——使用 keyset（before_id）pagination 避免 OFFSET sliding window 問題。
 *
 * 演算法（對齊 Slack Engineering "Evolving API Pagination at Slack" 的 next_cursor 規範）：
 *   1) 以目前已載入記錄中最小的 id 作為起始 anchor。
 *   2) 每次呼叫 API 時帶 `before_id=anchor`，後端回傳 id < anchor 的下一批。
 *   3) 以「data 陣列為空」為唯一的終止條件，不以 current_page/last_page 判斷。
 *   4) 每輪更新 anchor = 該批最小 id；若 anchor 未前進（重覆或異常）立即中斷避免死循環。
 *   5) MAX_ROUNDS 安全上限 100 輪（即 100 × per_page = 20,000 筆）——超過時提示使用者。
 */
const loadAllRecords = async () => {
  const pg = recordsPagination.value;
  if (pg.loading || loadingAll.value) return;
  if (records.value.length === 0) return;
  loadingAll.value = true;
  pg.loading = true;
  const MAX_ROUNDS = 100;
  let rounds = 0;
  try {
    // 起始 anchor：目前已載入記錄裡最小的 id（API 將回傳 id < anchor 的更早記錄）
    let anchor = records.value.reduce((min, r) => {
      const id = Number(r.id) || 0;
      return id > 0 && id < min ? id : min;
    }, Number.POSITIVE_INFINITY);
    if (!Number.isFinite(anchor) || anchor <= 0) {
      console.warn('[LR] load-all: 找不到有效的 anchor id，已中斷');
      return;
    }

    while (rounds < MAX_ROUNDS) {
      rounds += 1;
      const result = await _fetchRecordsPage(0, { beforeId: anchor });
      if (!result.ok) {
        if (result.reason === 'http') alert(`載入全部記錄失敗（HTTP ${result.status}，已載入 ${records.value.length} 筆）`);
        break;
      }
      // Slack next_cursor 規範：data 為空 → 已到資料集終點
      if (result.returned === 0) break;
      const nextAnchor = result.nextBeforeId || result.minId;
      if (!nextAnchor || nextAnchor >= anchor) {
        console.warn('[LR] load-all: anchor 未前進（可能是 API 版本不支援 before_id），中斷以避免死循環');
        break;
      }
      anchor = nextAnchor;
    }
    if (rounds >= MAX_ROUNDS) {
      alert(`安全上限：已載入 ${records.value.length} 筆，如需更多請手動再按一次「載入全部」`);
    }
  } catch (e) {
    console.error('[LR] load all error:', e);
    alert('載入全部記錄時發生錯誤');
  } finally {
    loadingAll.value = false;
    pg.loading = false;
  }
};

// ── Modal ──
const _fillForm = (record) => {
  isEditing.value = true;
  formTimesFromBinding.value = false;
  _activeRecordRef.value = record;
  teacherCommentContent.value = record?.teacher_comment?.content || '';
  teacherCommentError.value = '';
  feedbackReplyDraft.value = '';
  feedbackReplyError.value = '';
  Object.assign(form, {
    id: record.id,
    StudentID: Number(record.student_id) || '',
    TeacherID: Number((record.effective_teacher_id ?? record.TeacherID) || 0) || '',
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
  const studentClassId = Number(record.StudentClassID || record.student_class_id || 0);
  void loadLatestApprovedSummary({
    studentClassId,
    sessionDate: form.SessionDate || record.SessionDate || '',
    excludeId: Number(record.id || 0),
  });
};

const _clearForm = () => {
  isEditing.value = false;
  formTimesFromBinding.value = false;
  _activeRecordRef.value = null;
  teacherCommentContent.value = '';
  teacherCommentError.value = '';
  teacherCommentSaving.value = false;
  feedbackReplyDraft.value = '';
  feedbackReplyError.value = '';
  feedbackReplySaving.value = false;
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
  resetPreviousSummaryState();
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
  showAllCommentPhrases.value = false;
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
  showAllCommentPhrases.value = false;
  forceReadOnly.value = true;
  _fillForm(record);
  showModal.value = true;
  markParentFeedbackRead(record);
  markTeacherCommentRead(record);
  _attachTextareaResize();
};

const editRecord = (record) => {
  showAllCommentPhrases.value = false;
  forceReadOnly.value = false;
  _fillForm(record);
  showModal.value = true;
  markTeacherCommentRead(record);
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
  showAllCommentPhrases.value = false;
  showModal.value = false;
  resetPreviousSummaryState();
  _openedFromScheduleSession.value = 0;
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

/** 409 後精準拉取該堂次評量（含作廢列），避開清單分頁漏載 */
const fetchLearningRecordsForConflictLookup = async (classSessionId, preferredId = null) => {
  try {
    const cs = Number(classSessionId || 0);
    if (cs <= 0) return null;
    const token = await getToken();
    if (!token) return null;
    const params = new URLSearchParams({
      for_conflict_lookup: '1',
      class_session_id: String(cs),
      per_page: '5',
    });
    const res = await fetch(`/api/v1/learning-records?${params.toString()}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    if (!res.ok) return null;
    const data = await res.json().catch(() => ({}));
    const rows = data.data || [];
    for (const r of rows) {
      upsertRecordInList(r);
    }
    const want = preferredId != null && Number(preferredId) > 0 ? Number(preferredId) : null;
    if (want) {
      const byId = records.value.find((rec) => Number(rec.id) === want);
      if (byId) return byId;
    }
    return records.value.find((rec) => Number(rec.ClassSessionID) === cs) || rows[0] || null;
  } catch (e) {
    console.error('[LR] conflict lookup failed', e);
    return null;
  }
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
    // (#105) 若剛存的評量日期早於預設「近 N 天」視窗，自動解除視窗，
    // 否則 fetchRecords 會把它濾掉 → 老師誤以為「新增評量後列表卻看不到」。
    const savedDate = String(localRecord?.SessionDate || form.SessionDate || '').slice(0, 10);
    if (shouldLiftDefaultWindowForDate({ savedDate, windowStart: resolvedDefaultWindowStart.value })) {
      defaultWindowDisabled.value = true;
    }
    await fetchRecords();
    if (localRecord?.id) {
      upsertRecordInList(localRecord);
    }
    if (isTeacher.value) {
      await fetchTeacherClasses();
    }
    closeModal();
  } else if (res.status === 409) {
    const errBody = await res.json().catch(() => ({}));
    clearDraft();
    if (errBody.voided) {
      closeModal();
      alert(errBody.message || '此堂評量已作廢，請聯絡分校主任協助處理。');
      await fetchRecords();
      if (isTeacher.value) {
        await fetchTeacherClasses();
      }
      return;
    }
    const csId = Number(form.ClassSessionID || 0);
    const existingId = errBody?.existing_id ?? errBody?.existing_record_id;
    let conflicting = existingId
      ? records.value.find((r) => Number(r.id) === Number(existingId))
      : null;
    if (!conflicting && csId > 0) {
      conflicting = records.value.find((r) => Number(r.ClassSessionID) === csId);
    }
    if (!conflicting && csId > 0) {
      conflicting = await fetchLearningRecordsForConflictLookup(csId, existingId);
    }
    if (!conflicting) {
      await fetchRecords();
      conflicting = existingId
        ? records.value.find((r) => Number(r.id) === Number(existingId))
        : null;
      if (!conflicting && csId > 0) {
        conflicting = records.value.find((r) => Number(r.ClassSessionID) === csId);
      }
    }
    closeModal();
    if (conflicting) {
      openRecordAction(conflicting);
    } else {
      alert(errBody?.message || '此堂評量已存在，請重新整理後查看。');
    }
    if (isTeacher.value) {
      await fetchTeacherClasses();
    }
    await fetchRecords();
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
    fetchStatusCounts();
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
    fetchStatusCounts();
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
    fetchStatusCounts();
  } else {
    const err = await res.json().catch(() => ({}));
    alert('退回待審失敗: ' + (err.message || '未知錯誤'));
  }
};

const openChangeTeacherModal = (record) => {
  teacherChangeForm.record_id = record.id;
  teacherChangeForm.teacher_id = Number((record.effective_teacher_id ?? record.TeacherID) || 0) || '';
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

const isOverduePendingRecord = (record) => {
  if (!record) return false;
  const status = String(record.Status || '').toLowerCase();
  if (status !== 'pending' && status !== 'changes_requested') return false;
  const sessionDate = String(record.SessionDate || '').slice(0, 10);
  if (!sessionDate) return false;
  const today = formatLocalDate(new Date());
  return sessionDate < today;
};

const isUrgentTeacherRecord = (record) => (
  isTeacher.value &&
  (isOverduePendingRecord(record)
    || ((String(record.Status || '').toLowerCase() === 'changes_requested') && !hasLearningRecordBody(record)))
);

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
    changes_requested: 'changes-requested'
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
      fetchStatusCounts();
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
      fetchStatusCounts();
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
      fetchStatusCounts();
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
    fetchStatusCounts();
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

/**
 * 從 TeacherHomePage「補填提醒」點進來時，帶有 classSessionId + sessionDate。
 * 自動切到包含該日期的週，並打開對應的填寫 modal。
 * 若 sessionDatesByClassId 尚未載入，由後續 watch 補觸發。
 */
const _pendingTargetSession = ref(null);
const fetchTargetSessionEvent = async ({ classSessionId, sessionDate, branchId } = {}) => {
  const targetId = Number(classSessionId || 0);
  const dateStr = String(sessionDate || '').slice(0, 10);
  if (!targetId || !dateStr) return null;
  try {
    const token = await getToken();
    if (!token) return null;
    // (#54 / #82) 補填提醒可跨分校，且從工作台切換分校未必已生效；用深連結帶入的
    // 目標分校查（退回目前分校），避免「提醒看得到、點進去查無該堂」。
    const lookupBranchId = resolveDeepLinkBranchId({ targetBranchId: branchId, currentBranchId: props.branchId });
    if (!lookupBranchId) return null;
    const { items } = await fetchClassSessions({
      token,
      branchId: lookupBranchId,
      start: dateStr,
      end: dateStr,
      perPage: perfFlags.SESSION_MAX_PER_PAGE,
    });
    const row = (items || []).find((s) => Number(s?.id || 0) === targetId);
    if (!row) return null;
    const status = String(row?.status || '').toLowerCase();
    const isLeaveSession = LEAVE_STATUSES.has(status);
    const isCancelledSession = status === 'cancelled';
    if (isTeacher.value && isCancelledSession) return { skip: true };
    const fillLocked = !isSessionStarted(dateStr, row?.start_time);
    let fillLockReason = '';
    if (isLeaveSession) fillLockReason = '此堂為請假，無需填寫評量';
    else if (isCancelledSession) fillLockReason = '此堂已取消，無需填寫評量';
    else if (fillLocked) fillLockReason = '上課開始後開放填寫';
    return {
      key: `target-${targetId}`,
      classSessionId: targetId,
      classId: Number(row?.student_class_id || 0),
      studentId: Number(row?.student_id || 0),
      studentName: row?.student_name || '—',
      subject: row?.subject || row?.subject_name || '?',
      subjectName: row?.subject_name || row?.subject || '?',
      date: dateStr,
      startTime: normalizeTime(row?.start_time) || String(row?.start_time || '').slice(0, 5),
      endTime: normalizeTime(row?.end_time) || String(row?.end_time || '').slice(0, 5),
      timeRange: `${String(row?.start_time || '').slice(0, 5)}~${String(row?.end_time || '').slice(0, 5)}`,
      recordId: (isLeaveSession || isCancelledSession) ? null : (row?.learning_record_id || null),
      formStatus: isLeaveSession ? 'leave' : (isCancelledSession ? 'cancelled' : (row?.learning_record_status || 'missing')),
      formStatusLabel: scheduleStatusLabel(isLeaveSession ? 'leave' : (isCancelledSession ? 'cancelled' : (row?.learning_record_status || 'missing'))),
      fillLocked,
      fillLockReason,
      isSubstituted: false,
      isLeave: isLeaveSession,
      isCancelled: isCancelledSession,
    };
  } catch (e) {
    console.warn('fetchTargetSessionEvent failed', e);
    return null;
  }
};

const openTargetSession = async ({ classSessionId, sessionDate, branchId } = {}) => {
  if (!classSessionId || !sessionDate) return;
  const dateStr = String(sessionDate).slice(0, 10);

  // 計算目標日期在哪一週（相對於本週 Monday）
  const sessionDateObj = new Date(dateStr + 'T00:00:00');
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const dow = today.getDay();
  const mondayOffset = dow === 0 ? -6 : 1 - dow;
  const thisMonday = new Date(today);
  thisMonday.setDate(today.getDate() + mondayOffset);
  thisMonday.setHours(0, 0, 0, 0);
  const diffDays = Math.round((sessionDateObj.getTime() - thisMonday.getTime()) / 86400000);
  weekOffset.value = diffDays >= 0 ? Math.floor(diffDays / 7) : Math.ceil(diffDays / 7);
  scheduleView.value = 'week';

  // 找到對應的 event 並直接開 modal
  const dayEvents = buildEvents([dateStr]);
  const targetEv = dayEvents.find(ev => ev.classSessionId === Number(classSessionId));
  if (targetEv) {
    openFromScheduleMaybe(targetEv);
    _pendingTargetSession.value = null;
  } else {
    const fetchedEvent = await fetchTargetSessionEvent({ classSessionId, sessionDate: dateStr, branchId });
    if (fetchedEvent?.skip) {
      _pendingTargetSession.value = null;
      return;
    }
    if (fetchedEvent) {
      openFromScheduleMaybe(fetchedEvent);
      _pendingTargetSession.value = null;
      return;
    }
    // sessionDatesByClassId 可能還沒載入，先暫存等資料到齊後觸發（保留目標分校）
    _pendingTargetSession.value = { classSessionId, sessionDate, branchId };
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
      if (_openedFromScheduleSession.value !== 0) {
        _openedFromScheduleSession.value = 0;
        return;
      }
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

watch(() => props.targetSession, (newSession) => {
  if (newSession?.classSessionId) {
    nextTick(() => openTargetSession(newSession));
  }
});

/**
 * 從「家長回饋待看」CTA 導入時，把資料集放到最大並切到未讀回饋，確保有回饋的紀錄一定被載入並列出。
 * 因 badge 計數（server 通知）與列表載入 pipeline 不同，預設待審分頁 + 90 天視窗會把回饋紀錄濾掉。(#138)
 */
// 切換家長回饋篩選時改走伺服器端重新抓取（feedback 已是 server 篩選參數，#138/#139）。
// applyFeedbackFocus 內部自行抓取，故以此旗標避免重複請求。
let _suppressFeedbackRefetch = false;
watch(feedbackFilter, () => {
  if (feedbackFilter.value !== 'unread' && recentlyReadFeedbackIds.value.size > 0) {
    recentlyReadFeedbackIds.value = new Set();
  }
  if (_suppressFeedbackRefetch) return;
  fetchRecords();
});

async function applyFeedbackFocus() {
  const s = feedbackFocusState({ isTeacher: isTeacher.value });
  _suppressFeedbackRefetch = true;
  try {
    onlyUnfilled.value = s.onlyUnfilled;
    defaultWindowDisabled.value = s.liftWindow;
    showMoreFilters.value = true;
    if (isTeacher.value) {
      teacherFilterTab.value = s.teacherFilterTab;
      teacherPriorityFilter.value = 'all';
    } else {
      reviewTab.value = s.reviewTab;
    }
    feedbackFilter.value = s.feedbackFilter; // 'unread'
    await fetchRecords();
    // 伺服器已按 unread 篩選；若沒有未讀（可能都讀過了），退回「有回饋」讓使用者仍看得到全部回饋。
    if (feedbackFilter.value === 'unread' && (records.value || []).length === 0) {
      feedbackFilter.value = 'has';
      await fetchRecords();
    }
  } finally {
    _suppressFeedbackRefetch = false;
  }
  nextTick(() => {
    const el = document.querySelector('.lr-filters-bar');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
}

watch(() => props.feedbackFocusToken, (t) => {
  if (t) nextTick(() => applyFeedbackFocus());
});

// 當 sessionDatesByClassId 載入完成後，消化未完成的 pending target
watch(
  () => Object.keys(sessionDatesByClassId.value || {}).length,
  () => {
    const pending = _pendingTargetSession.value;
    if (pending?.classSessionId) {
      openTargetSession(pending);
    }
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
  if (window.innerWidth <= 640) scheduleView.value = 'today';
  migrateLegacyDrafts();
  if (props.userId) pruneOldDrafts(props.userId);

  // Pull backend perf_flags (lr_default_window_days 等) before first fetch so that
  // _buildRecordsParams injects the authoritative window in the initial request.
  await perf.trackAsync('loadRemoteFlags', () => loadRemoteFlags().catch(() => {}));

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
  if (isDirectorRole.value && !isTeacher.value) {
    secondaryLoads.push(perf.trackAsync('fetchStatusCounts', () => fetchStatusCounts()));
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
  // 首次掛載即帶有回饋 focus（從其他頁點 CTA 切過來）→ 套用回饋篩選。(#138)
  if (props.feedbackFocusToken) nextTick(() => applyFeedbackFocus());
});

watch(() => props.branchId, () => {
  fetchRecords();
  fetchTeachers();
  fetchSubjects();
  fetchCourses();
  fetchStudents();
  fetchStatusCounts();
  if (isTeacher.value) fetchTeacherClasses();
});

/**
 * 當 reviewTab / teacherFilterTab 切換導致預設時間窗口生效狀態改變（pending↔其他），
 * 自動重抓記錄以確保資料集與 tab 語意一致（Jira Kanban：未完成工作永不受窗口限制）。
 * 以 resolvedDefaultWindowStart 字串值為追蹤源，只在值真正變動時觸發。
 */
watch([reviewTab, resolvedDefaultWindowStart], ([rt, win], [prt, pwin]) => {
  if (rt === prt && win === pwin) return;
  // 主任切換審核分頁（status 改走 server 篩選）或窗口狀態變化時，重抓列表。
  // 監聽陣列在同一 tick 多源變動只觸發一次，避免重複請求。初始 fetch 由 onMounted 處理。
  fetchRecords();
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
  border-radius: 999px;
  border: 1px solid var(--ds-hairline);
  background: var(--ds-canvas);
  font-size: 13px;
  cursor: pointer;
  color: var(--ds-ink-secondary);
  transition: all 0.15s;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.lr-tab:hover {
  background: var(--ds-canvas-soft);
  border-color: var(--ds-hairline-input);
}

.lr-tab.active {
  background: var(--ds-primary);
  color: var(--ds-on-primary);
  border-color: var(--ds-primary);
}

.lr-tab-count {
  font-size: 11px;
  padding: 1px 6px;
  border-radius: 10px;
  background: rgba(0,0,0,0.08);
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.lr-tab.active .lr-tab-count {
  background: rgba(255,255,255,0.25);
  color: #fff;
}

.lr-tab-count.warn {
  background: var(--ds-warning-wash);
  color: var(--ds-warning);
}

.lr-tab.active .lr-tab-count.warn {
  background: rgba(255,255,255,0.3);
  color: #fff;
}

.lr-tab-count.ok {
  background: var(--ds-success-wash);
  color: var(--ds-success);
}

.lr-tab.active .lr-tab-count.ok {
  background: rgba(255,255,255,0.3);
  color: #fff;
}

.lr-tab-hint {
  margin-top: 8px;
  font-size: 12px;
  color: var(--ds-ink-mute);
}

.lr-filters-bar {
  margin-bottom: 12px;
  padding: 10px 16px;
}

.lr-filters-quick {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.lr-more-filters-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 32px;
  padding: 5px 12px;
  border-radius: 999px;
  border: 1px solid var(--ds-hairline);
  background: var(--ds-canvas);
  color: var(--ds-ink-secondary);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s ease;
}

.lr-more-filters-toggle:hover {
  background: var(--ds-canvas-soft);
  border-color: var(--ds-hairline-input);
}

.lr-more-filters-toggle.active {
  border-color: var(--ds-primary);
  color: var(--ds-primary-deep);
}

.lr-more-filters-chev {
  transition: transform 0.15s ease;
}

.lr-more-filters-chev.open {
  transform: rotate(180deg);
}

.lr-filters-panel {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid var(--ds-hairline);
}

.lr-filter-group {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.lr-feedback-filter-label {
  font-size: 13px;
  font-weight: 600;
  color: var(--ds-ink-mute);
}

.lr-feedback-filter-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 32px;
  padding: 5px 12px;
  border-radius: 999px;
  border: 1px solid var(--ds-hairline);
  background: var(--ds-canvas);
  color: var(--ds-ink-secondary);
  font-size: 12px;
  cursor: pointer;
  transition: all 0.15s ease;
}

.lr-feedback-filter-chip:hover {
  background: var(--ds-canvas-soft);
  border-color: var(--ds-hairline-input);
}

.lr-feedback-filter-chip.active {
  background: var(--ds-primary);
  border-color: var(--ds-primary);
  color: var(--ds-on-primary);
}

.lr-feedback-filter-chip.active .lr-tab-count {
  background: rgba(255,255,255,0.3);
  color: #fff;
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
  background: var(--ds-primary-wash);
  border: 1px solid var(--ds-hairline);
  border-radius: 8px;
  flex-wrap: wrap;
}

.lr-batch-count {
  font-size: 13px;
  font-weight: 600;
  color: var(--ds-primary-deep);
  margin-right: 4px;
  font-variant-numeric: tabular-nums;
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
  border: 1px solid var(--ds-hairline);
  background: var(--ds-canvas-soft);
  color: var(--ds-ink-secondary);
  cursor: pointer;
  transition: all 0.15s;
}

.lr-phrase-btn:hover {
  background: var(--ds-primary-wash);
  border-color: var(--ds-primary-soft);
  color: var(--ds-primary-deep);
}

.lr-phrase-toggle {
  border-style: dashed;
  color: var(--ds-primary);
  background: var(--ds-primary-wash);
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

.ts-status-chip.status-absent {
  background: #fee2e2;
  color: #991b1b;
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
  padding: 18px 22px 16px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

/* Filter card header */
.lr-filters-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding-bottom: 10px;
  border-bottom: 1px dashed #e2e8f0;
}
.lr-filters-title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 600;
  color: #334155;
}
.lr-filters-icon {
  color: #64748b;
  flex-shrink: 0;
}
.lr-filters-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  border-radius: 10px;
  background: #dbeafe;
  color: #1d4ed8;
  font-size: 11px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  line-height: 1;
}
.lr-filters-clear-link {
  background: none;
  border: none;
  padding: 4px 8px;
  font: inherit;
  font-size: 12px;
  color: #64748b;
  cursor: pointer;
  border-radius: 6px;
  transition: background 0.15s, color 0.15s;
}
.lr-filters-clear-link:hover {
  background: #f1f5f9;
  color: #334155;
  text-decoration: underline;
}

/* Filter fields grid */
.lr-filters-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 14px 16px;
  align-items: start;
}
.lr-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 0;
  margin: 0;
}
.lr-field > label {
  font-size: 12px;
  font-weight: 600;
  color: #475569;
  line-height: 1.2;
  margin: 0;
}
/* Date range occupies 2 grid columns to avoid cramped inputs */
.lr-field-wide {
  grid-column: span 2;
  min-width: 260px;
}

/* Unified input appearance across the filter bar */
.lr-input {
  width: 100%;
  min-width: 0;
  height: 38px;
  padding: 0 12px;
  font-size: 14px;
  line-height: 1.2;
  color: #1e293b;
  background: #ffffff;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  box-sizing: border-box;
  transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
}
.lr-input::placeholder {
  color: #94a3b8;
}
.lr-input:hover:not(:disabled) {
  border-color: #94a3b8;
}
.lr-input:focus,
.lr-input:focus-visible {
  outline: none;
  border-color: var(--ds-primary);
  box-shadow: 0 0 0 3px var(--ds-focus-ring);
}
.lr-input:disabled {
  background: #f1f5f9;
  color: #94a3b8;
  cursor: not-allowed;
  border-color: #e2e8f0;
}
/* native select chevron */
select.lr-input {
  appearance: none;
  -webkit-appearance: none;
  -moz-appearance: none;
  padding-right: 34px;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 12px center;
  background-size: 12px 12px;
  cursor: pointer;
}

/* Date range group */
.lr-date-range-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: nowrap;
}
.lr-date-range-wrap .lr-date-input {
  flex: 1 1 0;
  min-width: 0;
  font-variant-numeric: tabular-nums;
}
.lr-date-range-dash {
  flex-shrink: 0;
  color: #94a3b8;
  font-size: 13px;
  user-select: none;
}
.lr-date-clear {
  flex-shrink: 0;
  width: 32px;
  height: 32px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #f8fafc;
  color: #64748b;
  font-size: 12px;
  cursor: pointer;
  transition: background 0.15s, color 0.15s, border-color 0.15s;
}
.lr-date-clear:hover {
  background: #fee2e2;
  border-color: #fecaca;
  color: #b91c1c;
}
.lr-date-range-error {
  font-size: 12px;
  color: #b91c1c;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 6px;
  padding: 4px 8px;
  line-height: 1.4;
}

/* Subject select (kept for legacy) */
.lr-subject-select:disabled {
  background: #f1f5f9;
  color: #94a3b8;
  cursor: not-allowed;
}

/* Action bar at the bottom of the filter card */
.lr-filters-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  padding-top: 4px;
}
.lr-filters-search {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 38px;
  padding: 0 20px;
  font-size: 14px;
  font-weight: 600;
  border-radius: 8px;
}
.lr-filters-search svg {
  flex-shrink: 0;
}
.lr-filters-reset {
  height: 38px;
  padding: 0 16px;
  font-size: 14px;
  border-radius: 8px;
}

/* SearchableSelect inside filter grid — align visual height with siblings */
.lr-field :deep(.searchable-select),
.lr-field :deep(.searchable-select-input) {
  min-height: 38px;
}

/* ── Table ── */
.lr-view-toolbar {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 10px;
  margin: 10px 0;
}

.lr-view-toolbar__label {
  font-size: 12px;
  color: var(--muted);
  font-weight: 700;
}

.lr-view-toggle {
  display: inline-flex;
  padding: 3px;
  border: 1px solid var(--border);
  border-radius: 999px;
  background: var(--card-bg);
}

.lr-view-btn {
  border: 0;
  background: transparent;
  color: var(--muted);
  border-radius: 999px;
  padding: 7px 12px;
  min-height: 36px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  cursor: pointer;
  font-weight: 700;
}

.lr-view-btn .material-symbols-outlined {
  font-size: 17px;
}

.lr-view-btn.active {
  background: var(--primary);
  color: #fff;
  box-shadow: 0 2px 8px rgba(59, 130, 246, .22);
}

.lr-table-card {
  padding: 0;
  overflow: hidden;
}

.lr-record-skeleton-grid,
.lr-card-view {
  padding: 14px;
}

.lr-record-skeleton-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 12px;
}

.lr-record-skeleton-card {
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 16px;
  background: var(--card-bg);
}

.lr-skel-line {
  height: 12px;
  margin-top: 12px;
  border-radius: 999px;
  background: linear-gradient(90deg, #eef2f7 25%, #f8fafc 37%, #eef2f7 63%);
  background-size: 400% 100%;
  animation: lr-skeleton-shimmer 1.2s ease-in-out infinite;
}

.lr-skel-title { height: 18px; margin-top: 0; width: 70%; }
.lr-skel-line.short { width: 45%; }

@keyframes lr-skeleton-shimmer {
  0% { background-position: 100% 0; }
  100% { background-position: 0 0; }
}

.lr-card-view {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.lr-card-student-head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 10px;
}

.lr-card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 12px;
}

.lr-record-card {
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 16px;
  background: var(--card-bg);
  box-shadow: 0 1px 2px rgba(15, 23, 42, .06);
  cursor: pointer;
  transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease;
}

.lr-record-card:hover {
  transform: translateY(-2px);
  border-color: rgba(59, 130, 246, .35);
  box-shadow: 0 12px 26px rgba(15, 23, 42, .1);
}

.lr-record-card.has-feedback {
  border-left: 3px solid #9CA3AF;
}
.lr-record-card.is-unread {
  border-left: 3px solid #F97316;
  border-color: #F97316;
  box-shadow: 0 8px 20px rgba(249, 115, 22, .12);
}

.lr-record-card__top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.lr-record-card__student {
  font-size: 17px;
  font-weight: 800;
  color: var(--text);
}

.lr-record-card__meta,
.lr-record-card__time,
.lr-record-card__teacher {
  color: var(--muted);
  font-size: 13px;
}

.lr-contract-id {
  display: inline-block;
  margin-left: 5px;
  font-size: 11px;
  color: #9ca3af;
  font-family: ui-monospace, 'SF Mono', monospace;
  letter-spacing: 0;
}

.lr-record-card__time {
  display: flex;
  align-items: center;
  gap: 5px;
  margin-top: 10px;
}

.lr-record-card__time .material-symbols-outlined {
  font-size: 16px;
}

.lr-record-card__chips {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-top: 12px;
}

.lr-record-card__actions {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 14px;
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

.lr-group-student-btn {
  background: none;
  border: none;
  padding: 2px 6px;
  margin-left: -6px;
  font: inherit;
  font-weight: 700;
  color: inherit;
  cursor: pointer;
  border-radius: 6px;
  transition: background 0.15s, color 0.15s;
}
.lr-group-student-btn:hover {
  background: #dbeafe;
  color: #1d4ed8;
  text-decoration: underline;
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

.lr-group-date-range {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  border-radius: 999px;
  padding: 2px 10px;
  font-size: 12px;
  background: #eef2ff;
  color: #3730a3;
  font-variant-numeric: tabular-nums;
}
.lr-group-date-range-label {
  font-size: 11px;
  opacity: 0.7;
}

.lr-group[open] .lr-group-summary {
  background: #eff6ff;
}

/* ── Subject Subgroup (FR-004) ── */
.lr-subject-subgroups {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 10px 12px 14px;
  background: var(--card-bg);
}
.lr-subject-subgroup {
  border: 1px solid var(--border);
  border-radius: 8px;
  overflow: hidden;
  background: var(--card-bg);
}
.lr-subject-header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  background: #f1f5f9;
  border-bottom: 1px solid var(--border);
  flex-wrap: wrap;
}
.lr-subject-name {
  font-size: 13px;
  font-weight: 600;
  color: #334155;
  flex: 0 1 auto;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.lr-subject-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 24px;
  padding: 1px 8px;
  font-size: 11px;
  font-weight: 700;
  border-radius: 999px;
  background: #e2e8f0;
  color: #475569;
  font-variant-numeric: tabular-nums;
}
.lr-subject-uncategorized .lr-subject-header {
  background: #fff7ed;
}
.lr-subject-uncategorized .lr-subject-name {
  color: #9a3412;
  font-style: italic;
}

/* ── Status distribution chips inside subject sub-header ── */
.lr-status-chips {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  flex: 1 1 auto;
  flex-wrap: wrap;
  min-width: 0;
}
.lr-status-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
  line-height: 1.6;
  font-variant-numeric: tabular-nums;
  border: 1px solid transparent;
  transition: opacity 0.15s, background 0.15s;
  white-space: nowrap;
}
.lr-status-chip .lr-chip-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  flex-shrink: 0;
}
.lr-status-chip.chip-approved {
  background: #dcfce7;
  color: #166534;
  border-color: #bbf7d0;
}
.lr-status-chip.chip-approved .lr-chip-dot {
  background: #16a34a;
}
.lr-status-chip.chip-pending {
  background: #fef3c7;
  color: #92400e;
  border-color: #fde68a;
}
.lr-status-chip.chip-pending .lr-chip-dot {
  background: #f59e0b;
}
.lr-status-chip.chip-changes {
  background: #ffedd5;
  color: #9a3412;
  border-color: #fed7aa;
}
.lr-status-chip.chip-changes .lr-chip-dot {
  background: #ea580c;
}
.lr-status-chip.chip-rejected {
  background: #fee2e2;
  color: #991b1b;
  border-color: #fecaca;
}
.lr-status-chip.chip-rejected .lr-chip-dot {
  background: #dc2626;
}
/* 狀態不在目前 tab 範圍內 → 淡化，但仍可見（Gmail 模式） */
.lr-status-chip.chip-muted {
  opacity: 0.55;
  background: #f1f5f9;
  color: #64748b;
  border-color: #e2e8f0;
}
.lr-status-chip.chip-muted .lr-chip-dot {
  background: #94a3b8;
}

.lr-subject-show-all {
  margin-left: auto;
  padding: 4px 10px;
  background: #ffffff;
  border: 1px solid #bfdbfe;
  border-radius: 999px;
  color: #1d4ed8;
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s, border-color 0.15s;
  white-space: nowrap;
}
.lr-subject-show-all:hover {
  background: #eff6ff;
  border-color: #60a5fa;
}

/* ── Top-level "hidden by tab" info banner (GitHub Issues / Notion pattern) ── */
.lr-tab-filter-banner {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
  padding: 10px 14px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-left: 4px solid #f59e0b;
  border-radius: 8px;
  font-size: 13px;
  color: #78350f;
  line-height: 1.4;
}
.lr-tab-filter-banner__icon {
  flex-shrink: 0;
  color: #d97706;
}
.lr-tab-filter-banner__text {
  flex: 1 1 auto;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.lr-tab-filter-banner__text strong {
  color: #78350f;
  font-variant-numeric: tabular-nums;
}
.lr-tab-filter-banner__sep {
  color: #d97706;
  opacity: 0.6;
}
.lr-tab-filter-banner__cta {
  flex-shrink: 0;
  padding: 6px 14px;
  background: #f59e0b;
  border: none;
  border-radius: 6px;
  color: #ffffff;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s;
}
.lr-tab-filter-banner__cta:hover {
  background: #d97706;
}

@media (max-width: 640px) {
  .lr-tab-filter-banner {
    flex-direction: column;
    align-items: flex-start;
  }
  .lr-tab-filter-banner__cta {
    width: 100%;
    text-align: center;
  }
  .lr-subject-show-all {
    margin-left: 0;
  }
  /* 快速語句改由 .lr-phrase-row--hscroll 全寬橫向捲動 */
}

/* ── Default time-window banner (FR-002) ──
   Shares the amber visual language with .lr-tab-filter-banner so both banners feel
   like the same family: a benign, dismissible "you are looking at a filtered slice"
   hint. CTA is a text link (underline on hover) rather than a filled button to avoid
   competing with the primary 搜尋 action above. */
.lr-window-banner {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
  padding: 10px 14px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-left: 4px solid #f59e0b;
  border-radius: 8px;
  font-size: 13px;
  color: #78350f;
  line-height: 1.4;
}
.lr-window-banner__icon {
  flex-shrink: 0;
  color: #d97706;
}
.lr-window-banner__text {
  flex: 1 1 auto;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.lr-window-banner__text strong {
  color: #78350f;
  font-variant-numeric: tabular-nums;
}
.lr-window-banner__sep {
  color: #d97706;
  opacity: 0.6;
}
.lr-window-banner__hint {
  color: #92400e;
  opacity: 0.85;
}
.lr-window-banner__cta {
  flex-shrink: 0;
  padding: 6px 10px;
  background: transparent;
  border: none;
  color: #b45309;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  text-decoration: underline;
  text-underline-offset: 2px;
  transition: color 0.15s;
  min-height: 44px;
}
.lr-window-banner__cta:hover:not(:disabled) {
  color: #78350f;
}
.lr-window-banner__cta:disabled {
  color: #b45309;
  opacity: 0.5;
  cursor: not-allowed;
  text-decoration: none;
}

@media (max-width: 640px) {
  .lr-window-banner {
    flex-direction: column;
    align-items: flex-start;
  }
  .lr-window-banner__cta {
    width: 100%;
    text-align: center;
  }
}

/* Load-more slide-up fade — plays when records.length === total (everything loaded).
   Keeps record list layout stable (no jump) and signals "you are done" in a subtle
   way rather than an abrupt disappearance. Reduced-motion users skip the motion. */
.lr-loadmore-slide-enter-active,
.lr-loadmore-slide-leave-active {
  transition: opacity 0.28s ease, transform 0.28s ease, max-height 0.32s ease;
  overflow: hidden;
}
.lr-loadmore-slide-enter-from,
.lr-loadmore-slide-leave-to {
  opacity: 0;
  transform: translateY(-4px);
  max-height: 0;
}
.lr-loadmore-slide-enter-to,
.lr-loadmore-slide-leave-from {
  opacity: 1;
  transform: translateY(0);
  max-height: 320px;
}
@media (prefers-reduced-motion: reduce) {
  .lr-loadmore-slide-enter-active,
  .lr-loadmore-slide-leave-active {
    transition: opacity 0.15s ease;
  }
  .lr-loadmore-slide-enter-from,
  .lr-loadmore-slide-leave-to {
    transform: none;
    max-height: none;
  }
}

/* ── Empty State (FR-007) ── */
.lr-empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 24px;
  gap: 12px;
  text-align: center;
}
.lr-empty-icon {
  color: #94a3b8;
}
.lr-empty-title {
  font-size: 16px;
  font-weight: 600;
  color: #334155;
}
.lr-empty-desc {
  font-size: 13px;
  color: var(--text-light);
  max-width: 360px;
  line-height: 1.5;
}
.lr-empty-cta {
  margin-top: 4px;
  min-width: 160px;
}

/* ── Load More Hint & Skeleton (FR-002) ── */
.lr-load-more {
  flex-direction: column;
  gap: 10px;
}
.lr-load-more-actions {
  display: flex;
  align-items: stretch;
  gap: 10px;
  flex-wrap: wrap;
  justify-content: center;
}
.lr-load-more-btn,
.lr-load-all-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  min-width: 220px;
  padding: 12px 20px;
  border-radius: 10px;
  cursor: pointer;
  transition: background 0.15s, border-color 0.15s, transform 0.08s;
  font: inherit;
}
.lr-load-more-btn {
  background: var(--card-bg);
  color: #1e40af;
  border: 1px solid #bfdbfe;
}
.lr-load-more-btn:hover:not(:disabled) {
  background: #eff6ff;
  border-color: #60a5fa;
}
.lr-load-all-btn {
  background: #1d4ed8;
  color: #ffffff;
  border: 1px solid #1d4ed8;
}
.lr-load-all-btn:hover:not(:disabled) {
  background: #1e40af;
  border-color: #1e40af;
}
.lr-load-all-btn .lr-load-more-sub {
  color: rgba(255, 255, 255, 0.85);
}
.lr-load-more-btn:active:not(:disabled),
.lr-load-all-btn:active:not(:disabled) {
  transform: translateY(1px);
}
.lr-load-more-btn:disabled,
.lr-load-all-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.lr-load-more-title,
.lr-load-all-title {
  font-size: 14px;
  font-weight: 600;
}
.lr-load-more-hint {
  text-align: center;
  font-size: 12px;
  color: var(--text-light);
  line-height: 1.5;
}
.lr-load-more-hint strong {
  color: #334155;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}
.lr-load-more-sub {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--text-light);
  font-weight: 400;
  line-height: 1.5;
  flex-wrap: wrap;
  justify-content: center;
}
.lr-load-more-sub strong {
  color: #334155;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}
.lr-load-more-dot {
  color: #cbd5e1;
}
.lr-skeleton-rows {
  display: flex;
  flex-direction: column;
  gap: 8px;
  width: 100%;
  max-width: 640px;
  padding: 8px 0;
}
.lr-skeleton-row {
  height: 44px;
  border-radius: 8px;
  background: linear-gradient(90deg, #f1f5f9 0%, #e2e8f0 50%, #f1f5f9 100%);
  background-size: 200% 100%;
  animation: lrSkeletonShimmer 1.2s ease-in-out infinite;
}
@keyframes lrSkeletonShimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
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
.lr-table-row.lr-row-urgent {
  background: rgba(251, 146, 60, 0.08);
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
.lr-parent-feedback-chip { display:inline-flex; align-items:center; gap:3px; margin-top:4px; padding:3px 10px; border-radius:10px; font-size:12px; cursor:pointer; user-select:none; transition: opacity .15s; }
.lr-parent-feedback-chip:hover { opacity:.8; }
.lr-parent-feedback-chip.unread { background:#FFF3E0; color:#C2410C; font-weight:600; border:1px solid #FED7AA; }
.lr-parent-feedback-chip.read { background:#F1F5F9; color:#64748B; border:1px solid #E2E8F0; }
.lr-teacher-comment-chip { display:inline-flex; align-items:center; gap:3px; margin-top:4px; padding:3px 10px; border-radius:10px; font-size:12px; user-select:none; }
.lr-teacher-comment-chip.unread { background:#EEF2FF; color:#3730A3; font-weight:700; border:1px solid #C7D2FE; }
.lr-teacher-comment-chip.read { background:#F5F3FF; color:#6D28D9; border:1px solid #DDD6FE; }

.lr-feedback-inline-preview { margin-top:8px; padding:8px 10px; background:#FFFBF5; border:1px solid #FED7AA; border-radius:8px; font-size:12px; }
.lr-feedback-inline-preview__content { color:#1E293B; line-height:1.5; white-space:pre-wrap; }
.lr-feedback-inline-preview__time { margin-top:4px; color:#94A3B8; font-size:11px; }

/* 列表視圖 row 高亮 */
tr.lr-row-has-feedback { border-left: 3px solid #9CA3AF; }
tr.lr-row-unread { border-left: 3px solid #F97316; background: rgba(249,115,22,.03); }
.lr-parent-feedback-box { margin-top:12px; padding:12px; border:1px solid #e0e7ff; border-radius:12px; background:rgba(92,107,192,.06); }
.lr-parent-feedback-title { font-weight:700; color:#3949ab; margin-bottom:6px; }
.lr-parent-feedback-content { white-space:pre-wrap; line-height:1.6; }
.lr-parent-feedback-time { margin-top:6px; font-size:12px; color:#78909c; }
.lr-feedback-replies { margin-top:10px; padding-top:10px; border-top:1px dashed #c5cae9; display:flex; flex-direction:column; gap:8px; }
.lr-feedback-reply { padding:8px 10px; border-radius:10px; font-size:13px; max-width:92%; }
.lr-feedback-reply.is-staff { background:#fff; border:1px solid #e0e7ff; align-self:flex-start; }
.lr-feedback-reply.is-parent { background:#e8f0fe; align-self:flex-end; }
.lr-feedback-reply-who { font-size:12px; font-weight:700; color:#3949ab; display:flex; gap:8px; align-items:baseline; }
.lr-feedback-reply-time { font-weight:400; color:#90a4ae; }
.lr-feedback-reply-body { margin-top:3px; color:#37474f; white-space:pre-wrap; word-break:break-word; }
.lr-feedback-reply-form { margin-top:10px; }
.lr-feedback-reply-label { display:block; font-size:12px; font-weight:600; color:#3949ab; margin-bottom:4px; }
.lr-feedback-reply-form textarea { width:100%; resize:vertical; border:1px solid #c5cae9; border-radius:10px; padding:8px; font:inherit; box-sizing:border-box; }
.lr-teacher-comment-box { margin-top:12px; padding:12px; border:1px solid #C7D2FE; border-radius:12px; background:#F8FAFF; }
.lr-teacher-comment-title { display:flex; align-items:center; gap:8px; font-weight:700; color:#3730A3; margin-bottom:8px; }
.lr-teacher-comment-author { font-size:12px; font-weight:500; color:#64748B; }
.lr-teacher-comment-box textarea { width:100%; resize:vertical; }
.lr-teacher-comment-actions { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-top:8px; flex-wrap:wrap; }
.lr-teacher-comment-error { color:#B91C1C; font-size:12px; }
.lr-teacher-comment-time { color:#64748B; font-size:12px; }
.lr-teacher-comment-content { white-space:pre-wrap; line-height:1.6; color:#1E293B; }

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

/* 選項圖示預設隱藏，手機版才顯示 */
.lr-radio-icon {
  display: none;
}

/* Status tag variants */
.status-tag.rejected {
  background: var(--danger-bg);
  color: var(--danger);
}
.status-tag.changes-requested {
  background: #fff4ed;
  color: #ea580c;
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
.lr-modal-overlay {
  z-index: 13000;
}

.lr-modal {
  width: 720px;
  max-width: 95vw;
  max-height: 90vh;
  max-height: 90dvh;
  overflow-y: auto;
  padding: 0;
}

/* 評量主表單：標頭 + 可捲動內容 + 底部按鈕（手機不會被內容或底欄吃掉） */
.lr-modal--learning-form {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  max-height: min(90vh, 90dvh);
}

.lr-form--stacked {
  display: flex;
  flex-direction: column;
  flex: 1 1 auto;
  min-height: 0;
  padding: 0;
}

.lr-form-scroll {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  padding: 24px 28px;
}

.lr-form-actions--modal-footer {
  flex-shrink: 0;
  margin-top: 0;
  padding: 14px 28px calc(14px + env(safe-area-inset-bottom, 0px));
  background: var(--card-bg);
  border-top: 1px solid var(--border);
  box-shadow: 0 -4px 12px rgba(15, 23, 42, 0.06);
}

/* 橫向快速語句：避免窄螢幕被裁切；全寬可滑 */
.lr-phrase-row--hscroll {
  flex-wrap: nowrap;
  overflow-x: auto;
  overflow-y: visible;
  -webkit-overflow-scrolling: touch;
  padding: 4px 2px 10px;
  margin: 2px -4px 0;
  gap: 8px;
  min-height: 40px;
  overscroll-behavior-x: contain;
}

.lr-phrase-row--hscroll .lr-phrase-btn {
  flex-shrink: 0;
  max-width: min(280px, 85vw);
  white-space: normal;
  text-align: left;
  line-height: 1.25;
  padding: 8px 12px;
  min-height: 40px;
}

.lr-director-note-body {
  padding: 0 20px 20px;
}
.lr-director-note-meta {
  font-size: 13px;
  font-weight: 600;
  color: var(--text);
  margin: 0 0 8px;
}
.lr-director-note-hint {
  font-size: 12px;
  color: var(--text-light);
  line-height: 1.45;
  margin: 0 0 12px;
}
.lr-director-note-textarea {
  width: 100%;
  box-sizing: border-box;
  font-size: 15px;
  padding: 12px;
  border-radius: 8px;
  border: 1px solid var(--border);
  min-height: 120px;
  resize: vertical;
}
.lr-director-note-actions {
  margin-top: 14px;
  padding-top: 0;
  border-top: none;
  justify-content: flex-end;
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

.lr-prev-summary {
  margin-bottom: 12px;
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: #f8fafc;
}

.lr-prev-summary-head {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 700;
  color: #334155;
  margin-bottom: 8px;
}

.lr-prev-summary-state {
  font-size: 12px;
  color: #64748b;
}

.lr-prev-summary-state--error {
  color: #b91c1c;
}

.lr-prev-summary-body {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.lr-prev-summary-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  font-size: 12px;
  color: #475569;
}

.lr-prev-summary-chip {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  color: #7c2d12;
  background: #ffedd5;
}

.lr-prev-summary-row {
  display: grid;
  grid-template-columns: 88px 1fr;
  gap: 8px;
  align-items: start;
  font-size: 12px;
  color: #1f2937;
}

.lr-prev-summary-row strong {
  color: #475569;
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
  .lr-filters {
    padding: 14px 16px;
  }
  .lr-filters-grid {
    grid-template-columns: 1fr;
  }
  .lr-field-wide {
    grid-column: auto;
    min-width: 0;
  }
  .lr-input {
    height: 44px;
    font-size: 16px;
  }
  select.lr-input {
    background-position: right 14px center;
  }
  .lr-date-range-wrap {
    flex-wrap: nowrap;
  }
  .lr-date-range-wrap .lr-date-input {
    flex: 1 1 0;
    min-width: 0;
  }
  .lr-date-clear {
    width: 40px;
    height: 40px;
  }
  .lr-filters-actions {
    flex-direction: column;
    align-items: stretch;
    gap: 8px;
  }
  .lr-filters-search,
  .lr-filters-reset {
    width: 100%;
    height: 44px;
    justify-content: center;
  }
  .lr-empty-state {
    padding: 32px 16px;
  }
  .lr-subject-subgroups {
    padding: 8px 8px 12px;
    gap: 10px;
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
    padding-bottom: env(safe-area-inset-bottom, 0px);
    box-sizing: border-box;
  }

  .lr-modal {
    width: 100%;
    max-width: 100vw;
    max-height: 92vh;
    max-height: 92dvh;
    border-radius: 20px 20px 0 0;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
  }

  .lr-modal--learning-form {
    max-height: calc(100dvh - env(safe-area-inset-bottom, 0px) - 12px);
    max-height: calc(100vh - env(safe-area-inset-bottom, 0px) - 12px);
    overflow: hidden;
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

  .lr-form-scroll {
    padding: 16px 20px 28px;
    scroll-padding-bottom: 32px;
  }

  .lr-draft-bar--modal-anchor {
    padding-left: 20px;
    padding-right: 20px;
  }

  .lr-form-actions--modal-footer {
    padding: 12px 20px calc(16px + env(safe-area-inset-bottom, 0px));
    flex-direction: column-reverse;
    gap: 10px;
  }

  .lr-form-actions--modal-footer button {
    width: 100%;
    padding: 14px;
    font-size: 15px;
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

  /* 選項圖示手機版顯示 */
  .lr-radio-icon {
    display: block;
    font-size: 20px;
    line-height: 1;
    font-variation-settings: 'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 20;
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

  /* 評量 modal 改為內建底部欄，不再用 sticky（避免與主內容重疊） */

  /* 快捷語句 chip：手機改為 wrap 換行，不再橫向 scroll
     業界做法：Material 3 Suggestion Chip / Apple HIG / WhatsApp 快捷回覆
     皆為 flex-wrap，避免使用者看不到「還有幾顆 chip」與被迫橫滑。
     桌面（min-width:641px）維持原本的 hscroll，避免長條 chip 擠壓表單。 */
  .lr-phrase-row--hscroll {
    flex-wrap: wrap;
    overflow-x: visible;
    overflow-y: visible;
    -webkit-overflow-scrolling: auto;
    margin: 6px 0 0;
    padding: 0;
    gap: 6px;
  }

  .lr-phrase-row--hscroll .lr-phrase-btn {
    flex: 0 0 auto;
    max-width: 100%;
    min-height: 44px; /* Apple HIG / Material 3 觸控目標 */
    font-size: 13px;
    padding: 8px 12px;
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

/* 評量 Modal：緊貼標題列下方，不參與表單捲動 */
.lr-draft-bar--modal-anchor {
  flex-shrink: 0;
  margin: 0;
  border-radius: 0;
  border-left: none;
  border-right: none;
  border-top: none;
  padding: 8px 28px;
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
  .lr-draft-panel--sheet {
    max-width: 100% !important;
    margin: 0;
    border-radius: 12px 12px 0 0;
    max-height: min(92dvh, calc(100dvh - env(safe-area-inset-top, 0px) - 8px)) !important;
    height: auto;
    align-self: flex-end;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    padding-bottom: env(safe-area-inset-bottom, 0);
  }
  .lr-draft-panel--sheet .lr-draft-panel-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    padding-bottom: calc(16px + env(safe-area-inset-bottom, 0px));
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

/* ─────────────────────────────────────────
   iOS-style Teacher View overrides
   Scoped to .lr-page--teacher to avoid
   touching the director view.
───────────────────────────────────────── */
.lr-page--teacher {
  --ios-radius: 14px;
  --ios-shadow: 0 2px 12px rgba(0, 0, 0, 0.07);
  --ios-accent: var(--primary, #007aff);
  --ios-surface: #ffffff;
  --ios-surface-secondary: #f2f2f7;
  --ios-separator: rgba(0, 0, 0, 0.08);
  --ios-label: #1c1c1e;
  --ios-label-secondary: #6e6e73;
  --ios-label-tertiary: #aeaeb2;
  --ios-font: -apple-system, 'SF Pro Text', 'Helvetica Neue', system-ui, sans-serif;
}

/* Page header */
.lr-page--teacher .lr-header {
  padding: 4px 0 16px;
  border-bottom: none;
}
.lr-page--teacher .lr-header h2 {
  font-family: var(--ios-font);
  font-size: 28px;
  font-weight: 700;
  color: var(--ios-label);
  letter-spacing: -0.5px;
  margin-bottom: 2px;
}
.lr-page--teacher .lr-header .page-desc {
  font-family: var(--ios-font);
  font-size: 15px;
  color: var(--ios-label-secondary);
}

/* Teacher schedule card */
.lr-page--teacher .teacher-schedule {
  background: var(--ios-surface);
  border-radius: var(--ios-radius);
  box-shadow: var(--ios-shadow);
  border: none;
  padding: 0;
  overflow: hidden;
}
.lr-page--teacher .ts-header {
  padding: 16px 18px 12px;
  border-bottom: 1px solid var(--ios-separator);
  gap: 10px;
}
.lr-page--teacher .ts-header h3 {
  font-family: var(--ios-font);
  font-size: 17px;
  font-weight: 600;
  color: var(--ios-label);
}

/* iOS segmented control */
.lr-page--teacher .ts-tabs--ios {
  display: flex;
  background: var(--ios-surface-secondary);
  border-radius: 9px;
  padding: 2px;
  border: none;
  gap: 0;
}
.lr-page--teacher .ts-tabs--ios button {
  padding: 6px 14px;
  font-family: var(--ios-font);
  font-size: 13px;
  font-weight: 500;
  min-height: 32px;
  border-radius: 7px;
  color: var(--ios-label-secondary);
  background: transparent;
  transition: background 0.18s, color 0.18s, box-shadow 0.18s;
}
.lr-page--teacher .ts-tabs--ios button.active {
  background: var(--ios-surface);
  color: var(--ios-label);
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.12), 0 0 0 0.5px rgba(0, 0, 0, 0.04);
}
.lr-page--teacher .ts-nav {
  margin-left: auto;
}
.lr-page--teacher .icon-btn {
  border: none;
  background: var(--ios-surface-secondary);
  border-radius: 50%;
  width: 30px;
  height: 30px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  color: var(--ios-accent);
  padding: 0;
}
.lr-page--teacher .ts-week-label {
  font-family: var(--ios-font);
  font-size: 14px;
  font-weight: 500;
  color: var(--ios-label);
}

/* Today events — iOS list rows */
.lr-page--teacher .ts-today {
  padding: 6px 0;
  gap: 0;
}
.lr-page--teacher .ts-event {
  padding: 13px 18px;
  border-radius: 0;
  background: var(--ios-surface);
  border: none;
  border-bottom: 1px solid var(--ios-separator);
  gap: 14px;
  min-height: 64px;
}
.lr-page--teacher .ts-today .ts-event:last-child {
  border-bottom: none;
}
.lr-page--teacher .ts-time {
  font-family: var(--ios-font);
  font-size: 13px;
  font-weight: 500;
  color: var(--ios-label-secondary);
  min-width: 56px;
}
.lr-page--teacher .ts-student {
  font-family: var(--ios-font);
  font-size: 16px;
  font-weight: 500;
  color: var(--ios-label);
}
.lr-page--teacher .ts-subject {
  font-family: var(--ios-font);
  font-size: 13px;
  color: var(--ios-label-secondary);
}
.lr-page--teacher .ts-fill-btn {
  font-family: var(--ios-font);
  font-size: 14px;
  font-weight: 500;
  color: var(--ios-accent);
  background: none;
  border: none;
  padding: 0 4px;
  cursor: pointer;
  white-space: nowrap;
  margin-left: auto;
}
.lr-page--teacher .ts-fill-btn:disabled {
  color: var(--ios-label-tertiary);
}
.lr-page--teacher .ts-fill-hint {
  font-family: var(--ios-font);
  font-size: 13px;
  color: var(--ios-accent);
  margin-left: auto;
}
.lr-page--teacher .ts-empty {
  padding: 24px 18px;
  font-family: var(--ios-font);
  font-size: 15px;
  color: var(--ios-label-tertiary);
  text-align: center;
}

/* Week view grid */
.lr-page--teacher .ts-week {
  gap: 0;
}
.lr-page--teacher .ts-day {
  padding: 14px 18px;
  border-right: 1px solid var(--ios-separator);
}
.lr-page--teacher .ts-day:last-child { border-right: none; }
.lr-page--teacher .ts-day-header {
  margin-bottom: 10px;
}
.lr-page--teacher .ts-day-name {
  font-family: var(--ios-font);
  font-size: 13px;
  font-weight: 600;
  color: var(--ios-label-secondary);
  text-transform: uppercase;
  letter-spacing: 0.4px;
}
.lr-page--teacher .ts-day-date {
  font-family: var(--ios-font);
  font-size: 11px;
  color: var(--ios-label-tertiary);
}
.lr-page--teacher .ts-day.today .ts-day-name {
  color: var(--ios-accent);
}
.lr-page--teacher .ts-event-sm {
  padding: 9px 10px;
  border-radius: 10px;
  background: var(--ios-surface-secondary);
  border: none;
  margin-bottom: 6px;
  gap: 6px;
  min-height: 0;
}
.lr-page--teacher .ts-event-sm:last-child { margin-bottom: 0; }
.lr-page--teacher .ts-event-sm .ts-time {
  font-size: 11px;
  min-width: 0;
}
.lr-page--teacher .ts-event-sm .ts-student {
  font-size: 14px;
}
.lr-page--teacher .ts-missing-pill {
  font-size: 10px;
  background: #ff3b30;
  color: #fff;
  padding: 1px 6px;
  border-radius: 10px;
  margin-left: 5px;
  font-weight: 600;
}
.lr-page--teacher .ts-week-allclear {
  padding: 20px 18px;
  font-family: var(--ios-font);
  font-size: 15px;
  color: #34c759;
  font-weight: 500;
  text-align: center;
}
.lr-page--teacher .ts-day-empty {
  font-family: var(--ios-font);
  font-size: 13px;
  color: var(--ios-label-tertiary);
}

/* Status chips → iOS pills */
.lr-page--teacher .ts-status-chip {
  font-family: var(--ios-font);
  font-size: 11px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 10px;
  letter-spacing: 0.2px;
}
.lr-page--teacher .ts-status-chip.status-pending { background: #fff3cd; color: #9a6700; }
.lr-page--teacher .ts-status-chip.status-approved { background: #d1fae5; color: #065f46; }
.lr-page--teacher .ts-status-chip.status-rejected,
.lr-page--teacher .ts-status-chip.status-changes_requested { background: #fee2e2; color: #991b1b; }
.lr-page--teacher .ts-status-chip.status-unfilled { background: #e0e7ff; color: #3730a3; }
.lr-page--teacher .ts-status-chip.status-locked { background: var(--ios-surface-secondary); color: var(--ios-label-tertiary); }

/* Teacher filter tabs → iOS segmented control */
.lr-page--teacher .lr-review-tabs {
  background: var(--ios-surface);
  border-radius: var(--ios-radius);
  box-shadow: var(--ios-shadow);
  border: none;
  padding: 12px 16px;
}
.lr-page--teacher .lr-tabs-row {
  background: var(--ios-surface-secondary);
  border-radius: 9px;
  padding: 2px;
  display: inline-flex;
  gap: 0;
  width: 100%;
}
.lr-page--teacher .lr-tab {
  flex: 1;
  font-family: var(--ios-font);
  font-size: 13px;
  font-weight: 500;
  padding: 7px 8px;
  border-radius: 7px;
  min-height: 34px;
  color: var(--ios-label-secondary);
  background: transparent;
  border: none;
  transition: background 0.18s, color 0.18s, box-shadow 0.18s;
}
.lr-page--teacher .lr-tab.active {
  background: var(--ios-surface);
  color: var(--ios-label);
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.12);
}
.lr-page--teacher .lr-tab-count {
  font-size: 11px;
  background: none;
  color: inherit;
  padding: 0;
  border-radius: 0;
  font-weight: 600;
}
.lr-page--teacher .lr-tab-hint {
  font-family: var(--ios-font);
  font-size: 12px;
  color: var(--ios-label-tertiary);
  margin-top: 10px;
}
.lr-page--teacher .lr-feedback-filter-chip {
  font-family: var(--ios-font);
  font-size: 13px;
  padding: 5px 14px;
  border-radius: 20px;
  border: 1px solid var(--ios-separator);
  background: var(--ios-surface-secondary);
  color: var(--ios-label-secondary);
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
}
.lr-page--teacher .lr-feedback-filter-chip.active {
  background: var(--ios-accent);
  color: #fff;
  border-color: transparent;
}

/* Record cards → iOS grouped table rows */
.lr-page--teacher .lr-card-view .lr-groups {
  gap: 20px;
  padding: 0;
}
.lr-page--teacher .lr-group {
  background: var(--ios-surface);
  border-radius: var(--ios-radius);
  box-shadow: var(--ios-shadow);
  border: none;
  overflow: hidden;
}
.lr-page--teacher .lr-group-summary {
  padding: 12px 16px;
  background: var(--ios-surface);
  border-bottom: 1px solid var(--ios-separator);
  font-family: var(--ios-font);
  font-size: 13px;
  font-weight: 600;
  color: var(--ios-label-secondary);
  letter-spacing: 0.3px;
  text-transform: uppercase;
  cursor: pointer;
}
.lr-page--teacher .lr-group-detail {
  border-top: none;
  padding: 0;
}
.lr-page--teacher .lr-card-grid {
  display: flex;
  flex-direction: column;
  gap: 0;
  padding: 0;
}
.lr-page--teacher .lr-record-card {
  border-radius: 0;
  border: none;
  border-bottom: 1px solid var(--ios-separator);
  box-shadow: none;
  padding: 14px 16px;
  background: var(--ios-surface);
  transition: background 0.15s;
}
.lr-page--teacher .lr-record-card:last-child {
  border-bottom: none;
}
.lr-page--teacher .lr-record-card:hover {
  transform: none;
  box-shadow: none;
  background: rgba(0, 0, 0, 0.02);
  border-color: var(--ios-separator);
}
.lr-page--teacher .lr-record-card:active {
  background: rgba(0, 0, 0, 0.05);
}
.lr-page--teacher .lr-record-card.is-unread {
  border-left: 3px solid #ff9500;
  border-color: var(--ios-separator);
  box-shadow: none;
}
.lr-page--teacher .lr-record-card__student {
  font-family: var(--ios-font);
  font-size: 16px;
  font-weight: 600;
  color: var(--ios-label);
}
.lr-page--teacher .lr-record-card__meta {
  font-family: var(--ios-font);
  font-size: 13px;
  color: var(--ios-label-secondary);
}
.lr-page--teacher .lr-record-card__time {
  font-family: var(--ios-font);
  font-size: 13px;
  color: var(--ios-label-secondary);
  margin-top: 6px;
}
.lr-page--teacher .lr-record-card__actions {
  margin-top: 10px;
  gap: 8px;
}
.lr-page--teacher .lr-record-card__actions .ghost.xs {
  font-family: var(--ios-font);
  font-size: 14px;
  font-weight: 500;
  color: var(--ios-accent);
  background: none;
  border: none;
  padding: 4px 2px;
}
.lr-page--teacher .lr-record-card__actions .primary.xs {
  font-family: var(--ios-font);
  font-size: 14px;
  font-weight: 600;
  background: var(--ios-accent);
  border-radius: 8px;
  padding: 6px 14px;
  border: none;
}
/* Status tags */
.lr-page--teacher .status-tag {
  font-family: var(--ios-font);
  font-size: 12px;
  font-weight: 600;
  padding: 3px 9px;
  border-radius: 10px;
  letter-spacing: 0.2px;
}
</style>
