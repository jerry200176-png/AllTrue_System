<template>
  <div>
    <div class="card">
      <div class="header-actions" data-guide="students-header">
        <div class="header-title-area">
          <h2>
            <span class="material-symbols-outlined header-icon">school</span>
            學生管理
          </h2>
          <div class="header-stats">
            <span class="stat-badge">
              <span class="material-symbols-outlined" style="font-size:15px;">groups</span>
              本分校 <strong>{{ branchStudentTotal }}</strong> 人
            </span>
            <span class="stat-badge stat-badge-light">目前列表 {{ displayStudents.length }} 人</span>
          </div>
        </div>
        <div class="header-buttons">
          <label class="button-outline">
            <span class="material-symbols-outlined btn-icon">upload_file</span>
            匯入名單
            <input type="file" @change="importStudents" accept=".csv,.xlsx" style="display: none;" />
          </label>
          <button class="primary" @click="openAddStudent">
            <span class="material-symbols-outlined btn-icon">add</span>
            新增學生
          </button>
        </div>
      </div>

      <!-- Bulk Action Toolbar (appears when students selected) -->
      <div v-if="hasSelectedStudents" class="bulk-toolbar">
        <span class="bulk-count">
          <span class="material-symbols-outlined" style="font-size:18px;">check_circle</span>
          已選 {{ selectedStudentCount }} 位
        </span>
        <div class="bulk-btns">
          <button class="small ghost" @click="clearSelectedStudents">清除勾選</button>
          <button class="small danger" @click="deleteSelectedStudents">
            <span class="material-symbols-outlined btn-icon">delete</span>
            批量刪除
          </button>
        </div>
      </div>

      <div class="grid filter-bar" data-guide="students-filters">
        <div class="filter-search">
          <label>搜尋姓名</label>
          <div class="search-input-wrap">
            <span class="material-symbols-outlined search-icon">search</span>
            <input v-model="filters.name" placeholder="輸入姓名..." @input="debouncedLoad" />
          </div>
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
        <div class="filter-toggles">
          <button class="small ghost" @click="showGradePromotion = true" style="white-space: nowrap;">
            <span class="material-symbols-outlined btn-icon">school</span>
            年級升級
          </button>
          <button class="small" :class="showHistoricalCourses ? 'primary' : 'ghost'" @click="toggleHistoricalCourses">
            {{ showHistoricalCourses ? '隱藏已結業/歷史課程' : '顯示已結業/歷史課程' }}
          </button>
        </div>
      </div>

      <!-- Student Table -->
      <div v-if="displayStudents.length" class="table-scroll-wrap">
      <table data-guide="students-table">
        <thead>
          <tr>
            <th class="student-select-head">
              <input
                class="student-select-checkbox"
                type="checkbox"
                :checked="allVisibleSelected"
                :indeterminate.prop="hasSelectedStudents && !allVisibleSelected"
                @change="toggleSelectAllStudents($event.target.checked)"
              />
            </th>
            <th style="width: 30px;"></th>
            <th>姓名</th>
            <th>年級</th>
            <th>學校</th>
            <th>家長</th>
            <th>RFID</th>
            <th>補習科目 / 堂數</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody v-for="student in displayStudents" :key="student.id">
          <!-- Student Row -->
          <tr
            class="student-row"
            :class="[{ expanded: expandedId === student.id }, 'status-' + (student.status || 'active')]"
            @click="toggleExpand(student)"
          >
            <td class="student-select-cell" @click.stop>
              <input
                class="student-select-checkbox"
                type="checkbox"
                :disabled="!getLaravelStudentId(student)"
                :checked="isStudentSelected(student)"
                @change="toggleStudentSelection(student, $event.target.checked)"
              />
            </td>
            <td class="expand-icon">
              <span class="material-symbols-outlined expand-chevron" :class="{ rotated: expandedId === student.id }">expand_more</span>
            </td>
            <td>
              <div class="student-name-cell">
                <div class="student-avatar-mini" :class="student.status || 'active'">{{ (student.name || '?')[0] }}</div>
                <div>
                  <strong>{{ student.name }}</strong>
                  <span v-if="student.notes" class="note-icon" :title="student.notes">
                    <span class="material-symbols-outlined" style="font-size:14px;">sticky_note_2</span>
                  </span>
                  <span v-if="student.status && student.status !== 'active'" :class="['student-status-badge', student.status]">
                    {{ studentStatusLabel(student.status) }}
                  </span>
                </div>
              </div>
            </td>
            <td>{{ getGradeLabel(student.grade) }}</td>
            <td>{{ student.school || '—' }}</td>
            <td>{{ student.parent_name || '—' }}</td>
            <td @click.stop>
              <div class="student-binding-badges">
                <span v-if="student.rfid" class="rfid-tag">
                  <span class="material-symbols-outlined" style="font-size:14px;vertical-align:middle;">contactless</span>
                  {{ student.rfid }}
                </span>
                <span v-else class="rfid-unbound">
                  <span class="material-symbols-outlined" style="font-size:14px;vertical-align:middle;color:#bdbdbd;">contactless</span>
                  未綁定
                </span>
                <span v-if="student.line_bound" class="line-bound-badge">LINE</span>
              </div>
            </td>
            <td>
              <div class="subject-tags" v-if="getStudentCourses(student.id).length > 0">
                <span
                  v-for="course in getStudentCourses(student.id)"
                  :key="course.id"
                  :class="['subject-pill', { low: isSessionPaymentLowRemaining(course) }]"
                >
                  {{ getSubjectLabel(course.subject).split('(')[0].trim() }}
                  <template v-if="String(course.payment_type || '').toLowerCase() === 'monthly'">
                    <template v-if="parseCourseNumber(course.monthly_sessions) != null && parseCourseNumber(course.monthly_sessions) > 0">
                      每月<strong>{{ parseCourseNumber(course.monthly_sessions) }}</strong>堂
                    </template>
                    <template v-else>月結</template>
                  </template>
                  <template v-else>
                    <strong>{{ course.PackageID ? (course.package_remaining_sessions ?? 0) : (course.remaining_sessions ?? 0) }}</strong>堂
                  </template>
                </span>
              </div>
              <span class="hint" v-else>尚未設定</span>
            </td>
            <td @click.stop class="action-cell">
              <div class="action-cell-buttons">
                <button class="small ghost icon-btn" @click="editStudent(student)" title="編輯">
                  <span class="material-symbols-outlined">edit</span>
                </button>
                <button class="small danger icon-btn" @click="deleteStudent(student)" title="刪除">
                  <span class="material-symbols-outlined">delete</span>
                </button>
              </div>
            </td>
          </tr>

          <!-- Expanded Course Detail -->
          <tr v-if="expandedId === student.id" class="course-detail-row">
            <td colspan="10">
              <div class="course-panel">
                <div class="course-panel-header">
                  <h4>
                    <span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;">menu_book</span>
                    {{ student.name }} 的課程安排
                  </h4>
                  <button class="primary small" @click="openAddCourse(student)">
                    <span class="material-symbols-outlined btn-icon">add</span>
                    新增課程
                  </button>
                </div>
                <div class="student-note-line">
                  <span class="student-note-label">學生備註：</span>
                  <span>{{ student.notes || '無' }}</span>
                </div>

                <div v-if="getStudentCourses(student.id).length === 0" class="empty-text">
                  {{ showHistoricalCourses ? '尚未建立課程，請點擊「+ 新增課程」開始設定' : '目前沒有進行中的課程（可切換「顯示已結業/歷史課程」查看歷史資料）' }}
                </div>

                <template v-else>
                <!-- Active courses table -->
                <table v-if="getActiveStudentCourses(student.id).length > 0" class="course-inner-table">
                  <thead>
                    <tr>
                      <th>科目</th>
                      <th>老師</th>
                      <th>類型</th>
                      <th>剩餘 / 已購</th>
                      <th>一堂課費用</th>
                      <th>時長</th>
                      <th>排課時段</th>
                      <th>地點</th>
                      <th>操作</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="course in getActiveStudentCourses(student.id)" :key="course.id">
                      <td>
                        <span class="tag">{{ getSubjectLabel(course.subject) }}</span>
                        <span v-if="course.PackageID" class="tag tag-package" :title="course.PackageName || '多科方案'">方案</span>
                        <span v-if="course.status === 'inactive'" class="tag tag-paused-sm">已暫停</span>
                        <span v-else-if="course.payment_type === 'session' && !effectiveClosedReason(course) && (course.PackageID ? (course.package_remaining_sessions ?? 0) <= 2 : (course.remaining_sessions ?? 0) <= 2)" class="tag tag-expiring">即將用完</span>
                      </td>
                      <td>{{ course.teacher_name || '待指派' }}</td>
                      <td>
                        <span class="status-tag" :class="course.class_type">
                          {{ classTypeLabel(course.class_type) }}
                        </span>
                        <div v-if="courseMemo(course)" class="course-memo-line">備註：{{ courseMemo(course) }}</div>
                      </td>
                      <td>
                        <div v-if="course.payment_type === 'session'" class="sessions-cell">
                          <div class="mini-progress">
                            <div class="mini-progress-fill" :style="{
                              width: course.PackageID
                                ? Math.min(100, Math.round(((course.package_used_sessions ?? 0) / Math.max(course.package_total_sessions ?? 1, 1)) * 100)) + '%'
                                : Math.min(100, Math.round(((course.used_sessions || 0) / Math.max(course.sessions_purchased || 1, 1)) * 100)) + '%',
                              background: course.PackageID
                                ? ((course.package_remaining_sessions ?? 0) <= 2 ? '#c62828' : (course.package_remaining_sessions ?? 0) <= 4 ? '#f57c00' : '#2e7d32')
                                : ((course.remaining_sessions ?? 0) <= 2 ? '#c62828' : (course.remaining_sessions ?? 0) <= 4 ? '#f57c00' : '#2e7d32')
                            }"></div>
                          </div>
                          <span :class="{ 'text-red': course.PackageID ? (course.package_remaining_sessions ?? 0) <= 2 : course.remaining_sessions <= 2 }">
                            <template v-if="course.PackageID">
                              <strong>{{ course.package_remaining_sessions ?? 0 }}</strong> / {{ course.package_total_sessions ?? 0 }} 堂
                              <span class="tag tag-package-hint">（方案共用）</span>
                            </template>
                            <template v-else>
                              <strong>{{ course.remaining_sessions ?? 0 }}</strong> / {{ course.sessions_purchased || 0 }} 堂
                            </template>
                          </span>
                        </div>
                        <span v-else class="hint">
                          月結<span v-if="course.settlement_day">（每月{{ course.settlement_day }}號）</span>
                          <template v-if="parseCourseNumber(course.monthly_sessions) != null && parseCourseNumber(course.monthly_sessions) > 0">
                            <span v-if="course.settlement_day"> · </span>
                            <span v-else> </span>
                            每月<strong>{{ parseCourseNumber(course.monthly_sessions) }}</strong>堂
                          </template>
                        </span>
                      </td>
                      <td style="font-weight: 600;">${{ sessionFeeDisplay(course) }}</td>
                      <td>{{ course.duration_hours }} 小時</td>
                      <td class="cell-schedule-slots">
                        <div v-if="scheduleDisplayLines(course).length > 0" class="schedule-slot-lines">
                          <div
                            v-for="(line, sidx) in scheduleDisplayLines(course)"
                            :key="`${course.id}-sch-${sidx}`"
                            class="schedule-slot-line"
                          >
                            {{ line }}
                          </div>
                        </div>
                        <span v-else-if="course.days_of_week && course.days_of_week.length">
                          {{ scheduleDisplay(course) }}
                        </span>
                        <span v-else-if="course.day_of_week">
                          {{ dayLabel(course.day_of_week) }} {{ scheduleTimeRange(course) }}
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
                          :class="['small', paymentStatusButtonClass(course)]"
                          title="點擊切換繳費狀態"
                          :disabled="isPaymentStatusPending(course.id)"
                          @click="togglePaymentStatus(course, student.name)"
                          style="font-size: 12px;"
                        >
                          {{ paymentStatusButtonLabel(course) }}
                        </button>
                        <span v-if="course.last_paid_at" class="paid-date-hint">{{ course.last_paid_at }}</span>
                        <button
                          :class="['small', course.payment_type === 'session' && (course.PackageID ? (course.package_remaining_sessions ?? 0) <= 2 : (course.remaining_sessions ?? 0) <= 2) ? 'btn-renew-warn' : 'ghost']"
                          @click="openAddSessionsForCourse(course)"
                        >{{ course.payment_type === 'session' && (course.PackageID ? (course.package_remaining_sessions ?? 0) <= 2 : (course.remaining_sessions ?? 0) <= 2) ? '續報加購' : '加購' }}</button>
                        <button class="small ghost" @click="editCourse(course)">編輯</button>
                        <button v-if="canCloseCourse(course)" class="small close-btn" @click="closeCourseNoRenew(course, student.name)">結案</button>
                        <button class="small danger" @click="deleteCourse(course)">刪除</button>
                      </td>
                    </tr>
                  </tbody>
                </table>
                <div v-else-if="getHistoryStudentCourses(student.id).length > 0" class="sl-empty-active">
                  <span class="material-symbols-outlined sl-empty-active__icon" aria-hidden="true">school</span>
                  <span>目前沒有進行中的課程</span>
                </div>

                <!-- History courses collapsible section -->
                <div v-if="getHistoryStudentCourses(student.id).length > 0" class="sl-history-section">
                  <button class="sl-history-toggle" @click.stop="toggleHistoryCourses(student.id)">
                    <span class="material-symbols-outlined sl-history-toggle__icon" aria-hidden="true">inventory_2</span>
                    <span>歷史課程</span>
                    <span class="sl-history-toggle__count">{{ getHistoryStudentCourses(student.id).length }} 筆</span>
                    <span class="sl-history-toggle__chevron">{{ expandedHistoryCourses.has(student.id) ? '▲' : '▼' }}</span>
                  </button>
                  <div v-if="expandedHistoryCourses.has(student.id)" class="sl-history-body">
                    <div v-for="hc in getHistoryStudentCourses(student.id)" :key="hc.id" class="sl-history-card">
                      <div class="sl-history-card__header">
                        <span class="tag sl-history-card__subject">{{ getSubjectLabel(hc.subject) }}</span>
                        <span class="status-tag" :class="hc.class_type">{{ classTypeLabel(hc.class_type) }}</span>
                        <span v-if="hc.PackageID" class="tag tag-package" :title="hc.PackageName || '多科方案'">方案</span>
                        <span v-if="effectiveClosedReason(hc) === 'settled'" class="tag sl-tag-history sl-tag-history--settled">已結算</span>
                        <span v-else class="tag sl-tag-history sl-tag-history--completed">已完課</span>
                      </div>
                      <div class="sl-history-card__details">
                        <span><span class="sl-history-card__label">老師</span>{{ hc.teacher_name || '—' }}</span>
                        <span><span class="sl-history-card__label">費用</span>${{ sessionFeeDisplay(hc) }}/堂</span>
                        <span v-if="hc.payment_type === 'session'"><span class="sl-history-card__label">堂數</span>{{ hc.used_sessions || 0 }} / {{ hc.sessions_purchased || 0 }}</span>
                        <span v-if="hc.last_paid_at"><span class="sl-history-card__label">繳費</span>{{ hc.last_paid_at }}</span>
                      </div>
                      <div class="sl-history-card__actions">
                        <button class="small ghost" @click="editCourse(hc)">編輯</button>
                        <button class="small danger" @click="deleteCourse(hc)">刪除</button>
                      </div>
                    </div>
                  </div>
                </div>
                </template>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      </div>
      <div v-else class="empty-text">
        {{ showHistoricalCourses ? '目前無學生資料，請點擊「+ 新增學生」或匯入 CSV' : '目前沒有進行中的學生/課程，可切換「顯示已結業/歷史課程」查看歷史資料' }}
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

        <div v-if="editingStudentId" class="form-section-title">LINE 綁定家長</div>
        <div v-if="editingStudentId" class="line-bindings-section">
          <div v-if="lineBindingsLoading" class="line-bindings-empty">載入中…</div>
          <div v-else-if="lineBindings.length === 0" class="line-bindings-empty">尚未有家長透過 LINE 綁定此學生</div>
          <div v-else class="line-bindings-list">
            <div v-for="b in lineBindings" :key="b.id" class="line-binding-row">
              <span class="line-binding-id">{{ b.line_user_id_masked }}</span>
              <span class="line-binding-time">{{ b.bound_at }}</span>
              <button type="button" class="line-binding-remove" @click="removeLineBinding(b.id)">解除</button>
            </div>
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

    <!-- Edit Course Modal -->
    <div v-if="showCourseModal && editingCourseId" class="modal-overlay" @click.self="closeCourseModal">
      <div class="modal" style="width: 520px;">
        <h3>編輯課程</h3>
        <CourseEditForm
          ref="editFormRef"
          v-model="courseForm"
          :teachers="teachers"
          :rooms="rooms"
          :subjects="subjectOptions"
          :day-options="dayOptions"
          :time-options="TIME_OPTIONS_30"
          :settlement-day-options="settlementDayOptions"
          :student-grade="selectedStudent?.grade || selectedStudent?.ClassID || null"
          :package-info="editPackageInfo"
          :context-title="editContextTitle"
        />

        <div v-if="courseForm.payment_type === 'session'" class="quick-add-session-link" style="margin: 12px 0 4px; text-align: right;">
          <button type="button" class="ghost small" @click="openQuickAddSession">＋ 單次加課（不增加總堂數）</button>
        </div>

        <div class="actions">
          <button class="ghost" @click="closeCourseModal">取消</button>
          <button class="primary" :disabled="editFormRef?.hasErrors" @click="submitCourse">儲存</button>
        </div>
      </div>
    </div>

    <ToastWithUndo ref="toastRef" />

    <QuickAddSessionModal
      :show="showQuickAddSession"
      :form="quickAddSessionForm"
      :time-options="TIME_OPTIONS_30"
      :conflict="quickAddConflict"
      :checking="quickAddChecking"
      @close="showQuickAddSession = false"
      @submit="submitQuickAddSession"
      @check="runQuickAddCheck"
    />

    <UniversalClassScheduler
      v-if="showCourseModal && !editingCourseId"
      title="新增排課（統一排課介面）"
      submit-label="建立課程並寫入堂次"
      :branch-id="props.branchId"
      :students="schedulerStudents"
      :teachers="teachers"
      :rooms="rooms"
      :initial-student-id="selectedStudentSchedulerId"
      mode="create"
      @cancel="closeCourseModal"
      @success="handleUniversalSchedulerSuccess"
      @duplicate-course="handleSchedulerDuplicate"
    />

    <RenewMonthlyModal
      :show="showRenewMonthlyModal"
      :form="renewMonthlyForm"
      @close="showRenewMonthlyModal = false"
      @submit="submitRenewMonthly"
    />

    <!-- Add Sessions Modal -->
    <div v-if="showSessionsModal" class="modal-overlay" @click.self="showSessionsModal = false">
      <div class="modal">
        <h3>💰 加購堂數 — {{ getSubjectLabel(selectedCourse?.subject) }}</h3>
        <div class="form-group">
          <label>學生</label>
          <p style="font-weight: 600;">{{ selectedStudent?.name }}</p>
        </div>
        <div class="form-group">
          <label>{{ selectedCourse?.PackageID ? '目前剩餘（方案池）' : '目前剩餘（此課程）' }}</label>
          <p :style="{ fontSize: '20px', fontWeight: 700, color: (selectedCourse?.PackageID ? (selectedCourse?.package_remaining_sessions ?? 0) : (selectedCourse?.remaining_sessions ?? 0)) <= 2 ? '#e65100' : 'var(--primary)' }">
            {{ selectedCourse?.PackageID ? (selectedCourse?.package_remaining_sessions ?? 0) : (selectedCourse?.remaining_sessions ?? 0) }} 堂
            <span v-if="(selectedCourse?.PackageID ? (selectedCourse?.package_remaining_sessions ?? 0) : (selectedCourse?.remaining_sessions ?? 0)) <= 2" style="font-size: 13px; color: #e65100;">（即將用完，建議盡快加購）</span>
          </p>
        </div>
        <p class="hint" style="color: #666; margin-bottom: 8px;">此加購會延續原課程，不會建立新的課程。</p>
        <div class="form-group">
          <label>加購堂數</label>
          <input v-model.number="addSessionCount" type="number" placeholder="8" />
        </div>
        <div class="form-group">
          <label>新批次開始日期</label>
          <input v-model="addSessionStartDate" type="date" />
        </div>
        <p class="hint" v-if="addSessionCount > 0">
          將新增一筆 <strong>{{ addSessionCount }}</strong> 堂的未繳課程批次（不再併入原課程）
        </p>
        <div class="actions">
          <button class="ghost" @click="showSessionsModal = false">取消</button>
          <button class="primary" @click="submitAddSessions">
            確認加購
          </button>
        </div>
      </div>
    </div>
    <!-- Duplicate Course Intercept Modal -->
    <div v-if="showDuplicateInterceptModal" class="modal-overlay" @click.self="showDuplicateInterceptModal = false">
      <div class="modal" style="width: 480px;">
        <h3 style="color: #e65100;">⚠️ 此學生已有進行中的課程</h3>
        <p style="margin-bottom: 12px;">
          <strong>{{ interceptPendingStudent?.name }}</strong> 目前有以下進行中的課程，通常續報應使用「加購堂數」延續原課程，而非新增：
        </p>
        <div style="max-height: 200px; overflow-y: auto; margin-bottom: 16px;">
          <table class="course-inner-table" style="font-size: 13px;">
            <thead>
              <tr>
                <th>科目</th>
                <th>類型</th>
                <th>剩餘堂數</th>
                <th>操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in duplicateConflicts" :key="c.id">
                <td>{{ c.subject_name }}</td>
                <td>{{ { one_on_one: '一對一', one_on_two: '一對二', one_on_three: '一對三', tutoring: '輔導' }[c.class_type] || c.class_type }}</td>
                <td :style="{ color: (c.remaining_sessions ?? 0) <= 2 ? '#c62828' : 'inherit', fontWeight: 600 }">{{ c.remaining_sessions ?? 0 }} 堂</td>
                <td><button class="small btn-renew-warn" @click="interceptGoToPurchase(c)">去加購</button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="actions" style="gap: 8px;">
          <button class="ghost" @click="showDuplicateInterceptModal = false" :disabled="forceSubmitting">取消</button>
          <button class="ghost" @click="forceCreateCourse()" :disabled="forceSubmitting">{{ forceSubmitting ? '建立中...' : '我知道，仍要新增課程' }}</button>
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
  <div v-if="toastVisible" class="toast-notification">{{ toastMsg }}</div>
</template>

<script setup>
import { ref, onMounted, watch, computed } from 'vue';
import { supabase } from '../supabase';
import { GRADES, SUBJECTS, getSubjectLabel as getSubjectText } from '../lib/constants';
import { fetchSubjectOptions } from '../lib/subjectsApi';
import { getPerSessionFee } from '../lib/coursePricing';
import { fetchAllPages } from '../lib/pagedFetchAll';
import { createUniversalClassSchedule } from '../lib/universalSchedulerApi';
import CourseEditForm from '../components/CourseEditForm.vue';
import UniversalClassScheduler from '../components/UniversalClassScheduler.vue';
import QuickAddSessionModal from '../components/course-management/QuickAddSessionModal.vue';
import RenewMonthlyModal from '../components/course-management/RenewMonthlyModal.vue';
import ToastWithUndo from '../components/substitute/ToastWithUndo.vue';

const props = defineProps({ branchId: [String, Number] });

// --- State ---
const subjectOptions = ref([...SUBJECTS]);
const students = ref([]);
const branchStudentTotal = ref(0);
const studentCourses = ref({}); // { studentId: [courses] }
const teachers = ref([]);
const expandedId = ref(null);
const filters = ref({ name: '', grade: '', status: 'active' });
const selectedStudentIds = ref([]);
const showHistoricalCourses = ref(false);

// Student modal
const showStudentModal = ref(false);
const editingStudentId = ref(null);
const studentForm = ref({ name: '', grade: 'J1', phone: '', school: '', parent_name: '', parent_phone: '', status: 'active', notes: '' });

// LINE bindings (in edit modal)
const lineBindings = ref([]);
const lineBindingsLoading = ref(false);

// Course modal
const showCourseModal = ref(false);
const editingCourseId = ref(null);
const editingCourseFromLaravel = ref(false);
const editingCourseRaw = ref(null);
const editFormRef = ref(null);
const toastRef = ref(null);
const selectedStudent = ref(null);
const isLaravelCourse = (course) => (
  course?.data_source === 'laravel'
  || course?.branch_name != null
  || course?.room_name != null
  || course?.settlement_day != null
);
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
  day_time_slots: [],
  start_time: '16:00',
  end_time: '18:00',
  first_class_date: '',
  room_id: null,
  memo: ''
});
const rooms = ref([]);
const schedulerStudents = computed(() => (
  (students.value || []).map((s) => ({
    id: Number(s?._laravelId ?? s?.id ?? 0) || Number(s?.id ?? 0),
    name: s?.name || `#${s?.id ?? ''}`,
  })).filter((s) => Number.isFinite(s.id) && s.id > 0)
));
const selectedStudentSchedulerId = computed(() => {
  const raw = selectedStudent.value?._laravelId ?? selectedStudent.value?.id ?? '';
  const id = Number(raw);
  return Number.isFinite(id) && id > 0 ? id : '';
});

const dayOptions = [
  { value: 1, label: '一' }, { value: 2, label: '二' }, { value: 3, label: '三' },
  { value: 4, label: '四' }, { value: 5, label: '五' }, { value: 6, label: '六' }, { value: 7, label: '日' }
];
const settlementDayOptions = Array.from({ length: 31 }, (_, i) => i + 1);
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
const addSessionStartDate = ref(new Date().toISOString().slice(0, 10));
const showRenewMonthlyModal = ref(false);
const renewMonthlyTargetCourse = ref(null);
const renewMonthlyForm = ref({});
const selectedCourse = ref(null);

// Duplicate course intercept modal
const showDuplicateInterceptModal = ref(false);
const duplicateConflicts = ref([]);
const interceptPendingStudent = ref(null);
const interceptOriginalPayload = ref(null);
const forceSubmitting = ref(false);
const pendingPaymentStatusIds = ref(new Set());

// Quick add session (single extra lesson within existing session count)
const showQuickAddSession = ref(false);
const quickAddConflict = ref(null);
const quickAddChecking = ref(false);
const quickAddSessionForm = ref({ session_date: '', start_time: '16:00', duration_minutes: 120, note: '', auto_approve: true, student_name: '', subject: '' });

const isPaymentStatusPending = (courseId) => pendingPaymentStatusIds.value.has(String(courseId));
const setPaymentStatusPending = (courseId, pending) => {
  const next = new Set(pendingPaymentStatusIds.value);
  const key = String(courseId);
  if (pending) next.add(key);
  else next.delete(key);
  pendingPaymentStatusIds.value = next;
};
const paymentStatusButtonClass = (course) => {
  return course?.payment_status === 'paid' ? 'ghost' : 'primary';
};
const paymentStatusButtonLabel = (course) => {
  return course?.payment_status === 'paid' ? '已繳費' : '未繳費';
};

// --- Helpers ---
const getGradeLabel = (val) => GRADES.find(g => g.value === val)?.label || val;
const getSubjectLabel = (val) => getSubjectText(val);
const sessionFeeDisplay = (c) => getPerSessionFee(c);
const classTypeLabel = (type) => {
  const map = { one_on_one: '一對一', one_on_two: '一對二', one_on_three: '一對三', tutoring: '輔導', trial: '試聽' };
  return map[type] || type;
};
const courseMemo = (course) => {
  const text = String(course?.memo ?? course?.Memo ?? '').trim();
  return text || '';
};
const dayLabel = (d) => {
  const days = ['', '週一', '週二', '週三', '週四', '週五', '週六', '週日'];
  return days[d] || '';
};
const scheduleTimeRange = (course) => {
  const start = course?.start_time;
  if (!start) return '';
  const end = computeEndTime(start, Number(course?.duration_hours) || 2) || course?.end_time;
  return `${start}~${end}`;
};

/** 有 day_time_slots 時每段一行（與課程管理「時段」一致） */
const scheduleDisplayLines = (course) => {
  const slots = Array.isArray(course?.day_time_slots) ? course.day_time_slots : [];
  const globalDur = Number(course?.duration_hours) || 2;
  if (!slots.length) return [];
  const normalized = slots
    .map((slot) => ({
      day: Number(slot?.day || 0),
      start: String(slot?.start_time || '').slice(0, 5),
      dur: Number(slot?.duration_hours || 0) || globalDur,
    }))
    .filter((slot) => slot.day >= 1 && slot.day <= 7 && slot.start)
    .sort((a, b) => a.day - b.day || a.start.localeCompare(b.start));
  if (!normalized.length) return [];
  const allSameDur = new Set(normalized.map((s) => s.dur)).size <= 1;
  return normalized.map((slot) => {
    const end = computeEndTime(slot.start, slot.dur) || course?.end_time || '';
    const durSuffix = !allSameDur ? ` ${slot.dur}h` : '';
    return `${dayLabel(slot.day)} ${slot.start}~${end}${durSuffix}`;
  });
};

const scheduleDisplay = (course) => {
  const lines = scheduleDisplayLines(course);
  if (lines.length > 0) {
    return lines.join('、');
  }
  const daysText = (course?.days_of_week || []).map((d) => dayLabel(d)).join(' ');
  const range = scheduleTimeRange(course);
  return `${daysText} ${range}`.trim();
};

const parseCourseNumber = (value) => {
  const n = Number(value);
  return Number.isFinite(n) ? n : null;
};
const getCourseRemainingSessions = (course) => (
  parseCourseNumber(course?.remaining_sessions ?? course?.RemainingSessions)
);
/** 列表小徽章：僅堂數制用「剩餘 ≤2」標紅；月結制不以 RemainingSessions（常為 0）判斷。
 *  方案課程（PackageID）改以 package_remaining_sessions（方案池剩餘）判斷，
 *  與主要「剩餘堂數」顯示一致。 */
const isSessionPaymentLowRemaining = (course) => {
  if (String(course?.payment_type || '').toLowerCase() === 'monthly') return false;
  if (course?.PackageID) {
    const pr = Number(course?.package_remaining_sessions ?? NaN);
    if (!Number.isFinite(pr)) return false;
    return pr <= 2;
  }
  const r = getCourseRemainingSessions(course);
  if (r == null) return false;
  return r <= 2;
};
const isCourseSettled = (course) => {
  const paymentStatus = String(course?.payment_status || '').toLowerCase();
  if (paymentStatus === 'paid') return true;
  const paid = parseCourseNumber(course?.Paid ?? course?.paid);
  const charge = parseCourseNumber(course?.Charge ?? course?.charge ?? course?.Pay ?? course?.pay);
  if (paid != null && charge != null) return paid >= charge && charge > 0;
  if (paid != null) return paid > 0;
  return false;
};
function effectiveClosedReason(course) {
  if (course?.closed_reason) return course.closed_reason;
  if (String(course?.status || '').toLowerCase() === 'inactive'
    && course?.payment_type === 'session'
    && isCourseSettled(course)
    && getCourseRemainingSessions(course) != null
    && getCourseRemainingSessions(course) <= 0) {
    return 'completed';
  }
  // 月結制課程停用即視為完課（DB 無 closed_reason 的歷史髒資料也走此分支）
  if (String(course?.status || '').toLowerCase() === 'inactive'
    && course?.payment_type !== 'session') {
    return 'completed';
  }
  return null;
}
const isHistoricalCourse = (course) => {
  // FR-001：共用方案課程（PackageID）以方案共用池記錄剩餘，個別 StudentClass 的 remaining 欄可能
  // 被 over-deduction 誤設為 0；若此時又已繳費，舊邏輯會把 active 方案課程誤判為「歷史課程」並隱藏，
  // 造成學生管理欄位顯示「尚未設定」。僅在明確停課（status=inactive，即 Stop=1）時才視為歷史。
  if (course?.PackageID && String(course?.status || '').toLowerCase() !== 'inactive') {
    return false;
  }
  const remaining = getCourseRemainingSessions(course);
  if (remaining == null) return false;
  return remaining <= 0 && isCourseSettled(course);
};
const isHistoryCourseByReason = (course) => {
  const reason = effectiveClosedReason(course);
  return reason === 'settled' || reason === 'completed';
};
const getActiveStudentCourses = (id) => {
  return getStudentCourses(id).filter(c => !isHistoryCourseByReason(c));
};
const getHistoryStudentCourses = (id) => {
  return getStudentCourses(id).filter(c => isHistoryCourseByReason(c));
};
const expandedHistoryCourses = ref(new Set());
const toggleHistoryCourses = (studentId) => {
  const s = new Set(expandedHistoryCourses.value);
  if (s.has(studentId)) s.delete(studentId);
  else s.add(studentId);
  expandedHistoryCourses.value = s;
};

const canCloseCourse = (course) => {
  const remaining = getCourseRemainingSessions(course);
  return course?.payment_type === 'session'
    && isCourseSettled(course)
    && remaining != null && remaining <= 0
    && String(course?.status || '').toLowerCase() !== 'inactive';
};

async function closeCourseNoRenew(course, studentName) {
  const subject = getSubjectLabel(course?.subject);
  if (!confirm(`確定要結案「${studentName || '學生'}」的 ${subject} 課程嗎？\n\n結案後此課程將不再出現在繳費／續課提醒中。\n（等同暫停課程，之後仍可手動恢復。）`)) return;
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { alert('請重新登入'); return; }
    const res = await fetch(`/api/v1/student-classes/${course.id}/pause`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ action: 'pause', reason: 'completed' }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) { alert('結案失敗：' + (json.message || res.statusText)); return; }
    alert('已結案，此課程不再出現在繳費／續課提醒中。');
    await loadAllStudentCourses();
  } catch (e) {
    alert('操作失敗：' + (e?.message || '請稍後再試'));
  }
}

const getStudentAllCourses = (id) => studentCourses.value[id] || [];
const getStudentCourses = (id) => {
  const all = getStudentAllCourses(id);
  if (showHistoricalCourses.value) return all;
  return all.filter((course) => !isHistoricalCourse(course));
};
const getStudentCourseCount = (id) => getStudentCourses(id).length;
const studentStatusLabel = (s) => ({ active: '在學中', graduated: '已畢業', paused: '暫停中', transferred: '已轉校' }[s] || s);
const getLaravelStudentId = (student) => {
  const id = Number(student?._laravelId ?? student?.id ?? 0);
  return Number.isFinite(id) && id > 0 ? id : null;
};
const displayStudents = computed(() => students.value);
const visibleStudentLaravelIds = computed(() => displayStudents.value.map(getLaravelStudentId).filter(id => id != null));
const selectedStudentCount = computed(() => selectedStudentIds.value.length);
const hasSelectedStudents = computed(() => selectedStudentCount.value > 0);
const allVisibleSelected = computed(() =>
  visibleStudentLaravelIds.value.length > 0
  && visibleStudentLaravelIds.value.every(id => selectedStudentIds.value.includes(id))
);
const isStudentSelected = (student) => {
  const id = getLaravelStudentId(student);
  return id != null && selectedStudentIds.value.includes(id);
};
const toggleStudentSelection = (student, checked) => {
  const id = getLaravelStudentId(student);
  if (!id) return;
  if (checked) {
    if (!selectedStudentIds.value.includes(id)) {
      selectedStudentIds.value = [...selectedStudentIds.value, id];
    }
    return;
  }
  selectedStudentIds.value = selectedStudentIds.value.filter(v => v !== id);
};
const toggleSelectAllStudents = (checked) => {
  selectedStudentIds.value = checked ? [...visibleStudentLaravelIds.value] : [];
};
const clearSelectedStudents = () => {
  selectedStudentIds.value = [];
};
const toggleHistoricalCourses = () => {
  showHistoricalCourses.value = !showHistoricalCourses.value;
};
const syncSelectedStudentIdsWithCurrentList = () => {
  const visible = new Set(visibleStudentLaravelIds.value);
  selectedStudentIds.value = selectedStudentIds.value.filter(id => visible.has(id));
};

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
const loadBranchStudentTotal = async () => {
  const branchId = Number(props.branchId);
  if (!branchId) {
    branchStudentTotal.value = 0;
    return;
  }

  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (token) {
      const res = await fetch(`/api/v1/students?branch_id=${branchId}&per_page=1`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      if (res.ok) {
        const json = await res.json();
        const total = Number(json?.total);
        if (!Number.isNaN(total) && Number(props.branchId) === branchId) {
          branchStudentTotal.value = total;
          return;
        }
      }
    }
  } catch (_) {}

  try {
    const { data } = await supabase.from('students').select('*').eq('branch_id', branchId);
    if (Number(props.branchId) === branchId) {
      branchStudentTotal.value = Array.isArray(data) ? data.length : 0;
    }
  } catch (_) {
    if (Number(props.branchId) === branchId) {
      branchStudentTotal.value = 0;
    }
  }
};

const loadStudents = async () => {
  if (!props.branchId) {
    branchStudentTotal.value = 0;
    selectedStudentIds.value = [];
    return;
  }
  loadBranchStudentTotal();
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (token) {
      const params = new URLSearchParams({
        branch_id: String(props.branchId),
        per_page: '500'
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
        syncSelectedStudentIdsWithCurrentList();
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
      const res = await fetch(`/api/v1/students?branch_id=${props.branchId}&per_page=500`, {
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
  syncSelectedStudentIdsWithCurrentList();
};

const loadTeachers = async () => {
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { teachers.value = []; return; }
    const branch = Number(props.branchId || 0);
    const params = new URLSearchParams({ per_page: 'all' });
    if (branch > 0) params.set('branch_id', String(branch));
    const res = await fetch(`/api/v1/teachers?${params.toString()}`, {
      headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${token}` }
    });
    const data = await res.json().catch(() => ({}));
    const list = Array.isArray(data) ? data : (data?.data ?? []);
    const normalized = list
      .map((t) => {
        const id = Number(t?.id ?? 0);
        if (!Number.isFinite(id) || id <= 0) return null;
        const branchIds = Array.isArray(t?.branch_ids)
          ? t.branch_ids.map((idValue) => Number(idValue)).filter((idValue) => Number.isFinite(idValue) && idValue > 0)
          : [];
        const displayName = String(
          t?.name
          || t?.Name
          || t?.T_Name
          || t?.username
          || t?.LoginName
          || ''
        ).trim();
        return {
          id,
          name: displayName,
          Name: displayName,
          T_Name: t?.T_Name || '',
          username: t?.username || '',
          LoginName: t?.LoginName || '',
          branch_ids: branchIds,
          branch_id: Number(t?.branch_id || 0) || null,
        };
      })
      .filter(Boolean);
    teachers.value = branch > 0
      ? normalized.filter((t) => (t.branch_ids || []).includes(branch) || Number(t.branch_id || 0) === branch)
      : normalized;
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
          payment_status: c.payment_status || 'unpaid',
          rate_per_30min: c.rate_per_30min,
          duration_hours: c.duration_hours,
          payment_type: c.payment_type,
          sessions_purchased: c.sessions_purchased,
          remaining_sessions: c.remaining_sessions,
          start_time: c.start_time,
          end_time: c.end_time,
          days_of_week: c.days_of_week,
          day_time_slots: c.day_time_slots,
          day_of_week: c.day_of_week,
          first_class_date: c.first_class_date,
          branch_id: c.branch_id,
          branch_name: c.branch_name,
          room_name: c.room_name,
          room_id: c.room_id,
          settlement_day: c.settlement_day,
          monthly_sessions: c.monthly_sessions,
          memo: c.memo ?? c.Memo,
          data_source: 'laravel'
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
    payment_status: c.payment_status || 'unpaid',
    teacher_name: c.teacher?.username || '',
    data_source: 'supabase'
  }));
  studentCourses.value = { ...studentCourses.value, [studentId]: courses };
};

const loadAllStudentCourses = async () => {
  if (!props.branchId) {
    studentCourses.value = {};
    return;
  }
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (token) {
      const apiUrl = `/api/v1/student-classes?branch_id=${props.branchId}`;
      const { data: allData } = await fetchAllPages(apiUrl, token, { perPage: 200 });
      if (allData.length > 0) {
        const arr = allData;
        const map = {};
        arr.forEach((c) => {
          const sid = Number(c?.student_id ?? c?.StudentID ?? 0);
          if (!Number.isFinite(sid) || sid <= 0) return;
          if (!map[sid]) map[sid] = [];
          map[sid].push({
            id: c.id,
            student_id: sid,
            subject: c.subject,
            teacher_id: c.teacher_id,
            teacher_name: c.teacher_name || '',
            class_type: c.class_type,
            payment_status: c.payment_status || (Number(c?.Paid || 0) > 0 ? 'paid' : 'unpaid'),
            rate_per_30min: c.rate_per_30min,
            duration_hours: c.duration_hours,
            payment_type: c.payment_type,
            sessions_purchased: c.sessions_purchased,
            remaining_sessions: c.remaining_sessions ?? c.RemainingSessions ?? null,
            start_time: c.start_time,
            end_time: c.end_time,
            days_of_week: c.days_of_week,
            day_time_slots: c.day_time_slots,
            day_of_week: c.day_of_week,
            first_class_date: c.first_class_date,
            branch_id: c.branch_id,
            branch_name: c.branch_name,
            room_name: c.room_name,
            room_id: c.room_id,
            settlement_day: c.settlement_day,
            monthly_sessions: c.monthly_sessions,
            memo: c.memo ?? c.Memo,
            Paid: c.Paid,
            Charge: c.Charge,
            Pay: c.Pay,
            data_source: 'laravel'
          });
        });
        studentCourses.value = map;
        return;
      }
    }
  } catch (_) {}

  const { data } = await supabase
    .from('student-classes')
    .select('*, teacher:profiles(username)')
    .eq('branch_id', props.branchId);

  const map = {};
  (data || []).forEach(c => {
    const sid = c.student_id;
    if (!map[sid]) map[sid] = [];
    map[sid].push({ ...c, teacher_name: c.teacher?.username || '', data_source: 'supabase' });
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
  const laravelId = student._laravelId ?? student.id;
  if (laravelId) fetchLineBindings(laravelId);
};

const closeStudentModal = () => {
  showStudentModal.value = false;
  editingStudentId.value = null;
  lineBindings.value = [];
};

const fetchLineBindings = async (studentId) => {
  if (!studentId) return;
  lineBindingsLoading.value = true;
  lineBindings.value = [];
  try {
    const sess = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
    const token = sess?.access_token;
    if (!token) return;
    const res = await fetch(`/api/v1/students/${studentId}/line-bindings`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (res.ok) {
      const json = await res.json();
      lineBindings.value = json.bindings || [];
    }
  } catch (_) {}
  lineBindingsLoading.value = false;
};

const removeLineBinding = async (bindingId) => {
  if (!confirm('確定要解除此 LINE 綁定？解除後家長需重新綁定。')) return;
  const studentId = editingStudentId.value;
  const st = students.value.find(s => s.id === studentId);
  const laravelId = st?._laravelId ?? st?.id;
  if (!laravelId) return;
  try {
    const sess = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
    const token = sess?.access_token;
    if (!token) return;
    const res = await fetch(`/api/v1/students/${laravelId}/line-bindings/${bindingId}`, {
      method: 'DELETE',
      headers: { 'Authorization': `Bearer ${token}` }
    });
    if (res.ok) {
      lineBindings.value = lineBindings.value.filter(b => b.id !== bindingId);
      const idx = students.value.findIndex(
        s => s.id === studentId || s._laravelId === laravelId
      );
      if (idx !== -1) {
        students.value[idx] = {
          ...students.value[idx],
          line_bound: lineBindings.value.length > 0,
        };
      }
      showToast('已解除綁定');
    } else {
      alert('解除失敗，請重試');
    }
  } catch (_) {
    alert('解除失敗，請重試');
  }
};

const toastMsg = ref('');
const toastVisible = ref(false);
let toastTimer = null;
const showToast = (msg) => {
  toastMsg.value = msg;
  toastVisible.value = true;
  if (toastTimer) clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { toastVisible.value = false; }, 2500);
};

const deleteSelectedStudents = async () => {
  const ids = selectedStudentIds.value.map(v => Number(v)).filter(v => Number.isFinite(v) && v > 0);
  if (ids.length === 0) {
    alert('請先勾選要刪除的學生');
    return;
  }

  if (!confirm(`確定要批量刪除 ${ids.length} 位學生嗎？\n\n系統會一併刪除相關課程、排課、評量與帳務資料。`)) return;

  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) {
      alert('請重新登入後再試');
      return;
    }

    const res = await fetch('/api/v1/students/bulk-delete', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json'
      },
      body: JSON.stringify({ student_ids: ids })
    });

    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      const invalidIds = Array.isArray(json?.invalid_ids) ? json.invalid_ids : [];
      const detail = invalidIds.length > 0 ? `\n無法刪除 ID：${invalidIds.join(', ')}` : '';
      alert((json?.message || '批量刪除失敗') + detail);
      return;
    }

    expandedId.value = null;
    clearSelectedStudents();
    await loadStudents();
    await loadAllStudentCourses();
    alert(json?.message || `已批量刪除 ${ids.length} 位學生`);
  } catch (e) {
    alert('批量刪除失敗：' + (e?.message || '請稍後再試'));
  }
};

const deleteStudent = async (student) => {
  const name = student?.name || '此學生';
  if (!confirm(`確定要刪除「${name}」嗎？\n\n系統會一併刪除該學生相關課程、排課、評量與帳務資料。`)) return;

  const laravelId = student?._laravelId ?? student?.id;
  if (!laravelId) {
    alert('刪除失敗：找不到學生 ID');
    return;
  }

  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) {
      alert('請重新登入後再試');
      return;
    }

    const res = await fetch(`/api/v1/students/${laravelId}`, {
      method: 'DELETE',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json'
      }
    });

    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      const msg = json?.message || '刪除失敗';
      alert(msg);
      return;
    }

    if (expandedId.value === student.id) {
      expandedId.value = null;
    }
    selectedStudentIds.value = selectedStudentIds.value.filter(id => id !== Number(laravelId));
    await loadStudents();
    await loadAllStudentCourses();
    alert(`已刪除 ${name}`);
  } catch (e) {
    alert('刪除失敗：' + (e?.message || '請稍後再試'));
  }
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
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      alert(`取得暫存 RFID 失敗（HTTP ${res.status}）${json?.message ? '：' + json.message : ''}`);
      return;
    }
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
  const trimmedName = (studentForm.value.name || '').trim();
  if (!trimmedName) { alert('姓名不得為空'); return; }
  studentForm.value.name = trimmedName;
  if (!editingStudentId.value && !props.branchId) {
    alert('請先在上方「切換分校」選擇要新增學生的分校');
    return;
  }
  const payload = {
    name: trimmedName,
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
            body: JSON.stringify(payload)
          });
          if (res.ok) {
            if (payload.rfid) {
              await fetch(`/api/v1/students/${laravelId}/bind-card`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
                body: JSON.stringify({ rfid: payload.rfid })
              });
            }
            // Dual-source sync: Laravel (primary) succeeded — mirror to Supabase so
            // loadStudents() fallback path reads the same cleared/updated values
            // (especially for notes clear operations). Fire-and-forget.
            supabase
              .from('students')
              .update(payload)
              .eq('id', editingStudentId.value)
              .then(({ error }) => {
                if (error) console.warn('Supabase mirror update failed (non-blocking):', error?.message);
              });
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
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify(body)
      });
      if (res.ok) {
        closeStudentModal();
        loadStudents();
        loadAllStudentCourses();
        return;
      }
      const err = await res.json().catch(() => ({}));
      const details = err?.errors ? Object.values(err.errors || {}).flat().join(' ') : '';
      const generic = String(err?.message || '').trim();
      const msg = (details && (!generic || generic === 'The given data was invalid.' || generic === 'The given data was invalid'))
        ? details
        : (generic || details || '新增學生失敗，請稍後再試');
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

const openAddCourse = async (student) => {
  const sid = Number(student?._laravelId ?? student?.id ?? 0);
  if (sid > 0) {
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (token) {
        const res = await fetch(`/api/v1/students/${sid}/active-courses`, {
          headers: { 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
        });
        if (res.ok) {
          const json = await res.json();
          const active = json?.courses || [];
          if (active.length > 0) {
            duplicateConflicts.value = active;
            interceptPendingStudent.value = student;
            showDuplicateInterceptModal.value = true;
            return;
          }
        }
      }
    } catch { /* proceed normally if check fails */ }
  }
  proceedOpenAddCourse(student);
};

const proceedOpenAddCourse = (student) => {
  showDuplicateInterceptModal.value = false;
  selectedStudent.value = student;
  editingCourseId.value = null;
  editingCourseFromLaravel.value = false;
  const today = new Date().toISOString().slice(0, 10);
  courseForm.value = {
    subject: 'Math', teacher_id: '', class_type: 'one_on_one',
    rate_per_30min: 500, duration_hours: 2, payment_type: 'session',
    sessions_purchased: 8, settlement_day: null, monthly_sessions: null,
    day_of_week: 0, days_of_week: [], day_time_slots: [], start_time: '16:00', end_time: '18:00',
    first_class_date: today, room_id: null, memo: ''
  };
  loadRoomsForBranch();
  showCourseModal.value = true;
};

const forceCreateCourse = async () => {
  const payload = interceptOriginalPayload.value;
  if (!payload) {
    proceedOpenAddCourse(interceptPendingStudent.value);
    return;
  }
  forceSubmitting.value = true;
  try {
    const result = await createUniversalClassSchedule({ ...payload, force: true });
    showDuplicateInterceptModal.value = false;
    interceptOriginalPayload.value = null;
    const created = Number(result?.created_confirmed_sessions ?? 0) + Number(result?.created_future_sessions ?? 0);
    alert(`已強制建立 ${created} 堂課`);
    const sid = selectedStudent.value?.id;
    if (sid != null) await loadStudentCourses(sid);
    await loadAllStudentCourses();
  } catch (err) {
    alert(err?.message || '強制建立失敗，請稍後再試');
  } finally {
    forceSubmitting.value = false;
  }
};

const interceptGoToPurchase = (conflict) => {
  showDuplicateInterceptModal.value = false;
  const student = interceptPendingStudent.value;
  if (!student) return;
  const sid = Number(student?._laravelId ?? student?.id ?? 0);
  const courses = getStudentAllCourses(sid);
  const target = courses.find(c => c.id === conflict.existing_course_id);
  if (target) {
    openAddSessionsForCourse(target);
  }
};

const editCourse = (course) => {
  selectedStudent.value = students.value.find(s => s.id === course.student_id);
  editingCourseId.value = course.id;
  editingCourseRaw.value = course;
  editingCourseFromLaravel.value = isLaravelCourse(course);
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
    rate_unit: course.rate_unit || 'session',
    ...(() => {
      const dowBase = Array.isArray(course.days_of_week)
        ? course.days_of_week.map(Number).filter((d) => d >= 1 && d <= 7)
        : (course.day_of_week ? [Number(course.day_of_week)] : []);
      const mappedSlots = Array.isArray(course.day_time_slots) && course.day_time_slots.length
        ? course.day_time_slots.map((slot) => ({
          day: Number(slot?.day || 0),
          start_time: normalizeTo30Min(slot?.start_time || course.start_time || '16:00'),
          duration_hours: Number(slot?.duration_hours || 0) || course.duration_hours || 2,
        })).filter((slot) => slot.day >= 1 && slot.day <= 7)
        : (dowBase.length
          ? dowBase.map((day) => ({
            day: Number(day),
            start_time: normalizeTo30Min(course.start_time || '16:00'),
            duration_hours: course.duration_hours || 2,
          }))
          : []);
      const slotDays = mappedSlots.map((s) => s.day).filter((d) => d >= 1 && d <= 7);
      // 有時段時以 day_time_slots 為準（與列表 scheduleDisplayLines 一致），勿與 API days_of_week 聯集以免多出幽靈星期
      const days_of_week = mappedSlots.length > 0
        ? [...new Set(slotDays)].sort((a, b) => a - b)
        : [...new Set(dowBase)].sort((a, b) => a - b);
      return { days_of_week, day_time_slots: mappedSlots };
    })(),
    start_time: normalizeTo30Min(course.start_time || '16:00'),
    end_time: course.end_time || '18:00',
    first_class_date: course.first_class_date || '',
    room_id: course.room_id ?? null,
    memo: course.memo || '',
    paid_at: course.paid_at || course.last_paid_at || ''
  };
  loadRoomsForBranch();
  showCourseModal.value = true;
};

const editPackageInfo = computed(() => {
  const c = editingCourseRaw.value;
  if (!c?.PackageID) return null;
  return {
    id: c.PackageID,
    name: c.package_name || '共用方案',
    total_sessions: c.package_total_sessions ?? 0,
    remaining_sessions: c.package_remaining_sessions ?? 0,
  };
});
const editContextTitle = computed(() => {
  const c = editingCourseRaw.value;
  if (!c) return '';
  const subjectLabel = c.subject_name || c.subject || '';
  const studentName = selectedStudent.value?.name || c.student_name || '';
  return studentName ? `正在編輯：${subjectLabel} ／ ${studentName}` : `正在編輯：${subjectLabel}`;
});

const closeCourseModal = () => {
  showCourseModal.value = false;
  editingCourseId.value = null;
  editingCourseRaw.value = null;
  editingCourseFromLaravel.value = false;
};

const handleUniversalSchedulerSuccess = async () => {
  const sid = selectedStudent.value?.id;
  closeCourseModal();
  if (sid != null) {
    await loadStudentCourses(sid);
  }
  await loadAllStudentCourses();
};

const handleSchedulerDuplicate = (evt) => {
  closeCourseModal();
  duplicateConflicts.value = (evt?.conflicts || []).map(c => ({
    existing_course_id: c.existing_course_id,
    subject_name: c.subject || '',
    remaining_sessions: c.remaining_sessions ?? 0,
    class_type: c.class_type || '',
  }));
  interceptPendingStudent.value = selectedStudent.value;
  interceptOriginalPayload.value = evt?.originalPayload || null;
  showDuplicateInterceptModal.value = true;
};

const parseApiErrorMessage = (err, fallback = '操作失敗') => {
  const firstConflict = Array.isArray(err?.conflicts) ? err.conflicts[0] : null;
  if (firstConflict?.type === 'teacher_capacity') {
    const current = Number(firstConflict.current_students ?? 0);
    const allowed = Number(firstConflict.allowed_students ?? 0);
    const start = String(firstConflict.start_time || '');
    const end = String(firstConflict.end_time || '');
    const timeLabel = start && end ? `（${start}~${end}）` : '';
    return `老師在此時段${timeLabel}已達可排學生上限（目前 ${current} 位／上限 ${allowed} 位），請改時段、老師或課型。`;
  }
  if (firstConflict?.type === 'room_capacity') {
    const roomName = firstConflict.room_name || `#${firstConflict.room_id || ''}`;
    const current = Number(firstConflict.current_students ?? 0);
    const allowed = Number(firstConflict.allowed_students ?? 0);
    const start = String(firstConflict.start_time || '');
    const end = String(firstConflict.end_time || '');
    const timeLabel = start && end ? `（${start}~${end}）` : '';
    return `教室「${roomName}」在此時段${timeLabel}已滿（可容納學生 ${allowed} 位、目前 ${current} 位），請換教室或時段。`;
  }

  const details = err?.errors ? Object.values(err.errors || {}).flat().join(' ') : '';
  const generic = String(err?.message || '').trim();
  if (details && (!generic || generic === 'The given data was invalid.' || generic === 'The given data was invalid')) {
    return details;
  }
  return generic || details || fallback;
};

const submitCourse = async () => {
  const form = courseForm.value;
  const student = selectedStudent.value;
  const laravelStudentId = student._laravelId ?? student.id;

  if (!editingCourseId.value) {
    if (!form.first_class_date) {
      alert('請選擇開課日');
      return;
    }
    if (form.payment_type === 'monthly' && (form.settlement_day == null || form.settlement_day < 1 || form.settlement_day > 31)) {
      alert('月結制度請選擇結算日（每月 1–31 號）');
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
        rate_unit: form.rate_unit || 'session',
        duration_hours: form.duration_hours,
        payment_type: form.payment_type,
        sessions_purchased: form.payment_type === 'session' ? (form.sessions_purchased || 8) : 0,
        first_class_date: form.first_class_date,
        days_of_week: form.days_of_week,
        start_time: form.start_time,
        day_time_slots: (form.day_time_slots || [])
          .map((slot) => ({
            day: Number(slot?.day || 0),
            start_time: normalizeTo30Min(slot?.start_time || form.start_time || '16:00'),
            duration_minutes: Number(slot?.duration_hours || 0) > 0 ? Math.round(Number(slot.duration_hours) * 60) : undefined,
          }))
          .filter((slot) => slot.day >= 1 && slot.day <= 7),
        room_id: form.room_id || null,
        settlement_day: form.payment_type === 'monthly' ? form.settlement_day : null,
        monthly_sessions: form.payment_type === 'monthly' ? (form.monthly_sessions || null) : null,
        Memo: form.memo || null
      };
      const res = await fetch('/api/v1/student-classes', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify(body)
      });
      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        alert(parseApiErrorMessage(err, '新增課程失敗'));
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
      if (!token) {
        alert('請重新登入後再試');
        return;
      }
      const isPackageCourse = !!editingCourseRaw.value?.PackageID;
      const body = {
        subject: form.subject,
        teacher_id: form.teacher_id || null,
        class_type: form.class_type,
        rate_per_30min: form.rate_per_30min,
        rate_unit: form.rate_unit || 'session',
        duration_hours: form.duration_hours,
        payment_type: form.payment_type,
        sessions_purchased: form.sessions_purchased,
        days_of_week: form.days_of_week?.length ? form.days_of_week : (form.day_of_week ? [form.day_of_week] : []),
        start_time: form.start_time,
        day_time_slots: (form.day_time_slots || [])
          .map((slot) => ({
            day: Number(slot?.day || 0),
            start_time: normalizeTo30Min(slot?.start_time || form.start_time || '16:00'),
            duration_minutes: Number(slot?.duration_hours || 0) > 0 ? Math.round(Number(slot.duration_hours) * 60) : undefined,
          }))
          .filter((slot) => slot.day >= 1 && slot.day <= 7),
        end_time: computeEndTime(form.start_time, form.duration_hours),
        first_class_date: form.first_class_date || null,
        force_rebuild_if_mismatch: true,
        room_id: form.room_id || null,
        settlement_day: form.payment_type === 'monthly' ? form.settlement_day : null,
        monthly_sessions: form.payment_type === 'monthly' ? form.monthly_sessions : null,
        Memo: form.memo || null
      };
      if (isPackageCourse) delete body.remaining_sessions;
      body.paid_at = form.paid_at ? form.paid_at : null;
      const res = await fetch(`/api/v1/student-classes/${editingCourseId.value}`, {
        method: 'PUT',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
        body: JSON.stringify(body)
      });
      if (res.ok) {
        const payload = await res.json().catch(() => ({}));
        const sync = payload?.session_sync || {};
        let successMsg = '課程已更新。';
        if (sync?.rebuilt) {
          successMsg += ` 已重排 ${Number(sync.created_sessions || 0)} 堂。`;
          if (sync?.reason === 'start_date_changed' || sync?.reason === 'start_date_aligned') {
            successMsg += '（依開課日調整）';
          } else if (sync?.reason === 'schedule_changed') {
            successMsg += '（依固定星期/時段調整）';
          }
          if (sync?.reason === 'start_date_aligned') {
            successMsg += '（堂次首日已與開課日重新對齊）';
          }
        } else if (sync?.reason === 'future_schedule_times_synced') {
          successMsg += ` 已同步 ${Number(sync.updated_future_sessions || 0)} 堂未來課程時段。`;
        } else if (sync?.reason === 'history_exists') {
          successMsg += ' 本課已有出缺勤/核准紀錄，為保留歷史資料未重排堂次。';
          if (Number(sync?.updated_future_sessions || 0) > 0) {
            successMsg += ` 已同步 ${Number(sync.updated_future_sessions || 0)} 堂未來課程時段。`;
          }
        } else if (sync?.reason === 'start_date_unchanged') {
          successMsg += ' 開課日未變更，故未重排堂次。';
        } else if (sync?.reason === 'start_date_not_updated') {
          successMsg += ' 本次未更新開課日，故未重排堂次。';
        }
        if (payload?.scope_warning) {
          successMsg += `\n\n⚠️ 學段提示：${payload.scope_warning}`;
        }
        closeCourseModal();
        toastRef.value?.show?.({ title: '已儲存', description: successMsg, variant: 'success', durationMs: 4000 });
        await loadAllStudentCourses();
        await loadStudentCourses(student.id);
        return;
      }
      const err = await res.json().catch(() => ({}));
      toastRef.value?.show?.({ title: '儲存失敗', description: parseApiErrorMessage(err, '更新課程失敗'), variant: 'error', durationMs: 5000 });
      return;
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
    end_time: computeEndTime(form.start_time, form.duration_hours),
    first_class_date: form.first_class_date || null
  };
  base.day_of_week = form.day_of_week;
  const { error } = await supabase
    .from('student-classes')
    .update(base)
    .eq('id', editingCourseId.value);
  if (error) {
    alert('更新課程失敗：' + (error?.message || '請稍後再試'));
    return;
  }
  closeCourseModal();
  alert('課程已更新。');
  await loadAllStudentCourses();
};

const deleteCourse = async (course) => {
  if (!confirm('確定刪除此課程設定？')) return;
  if (isLaravelCourse(course)) {
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (!token) {
        alert('請重新登入後再試');
        return;
      }
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
    } catch (_) {}
  }
  await supabase.from('student-classes').delete().eq('id', course.id);
  await loadAllStudentCourses();
};

// --- Quick Add Session (single extra lesson, no total increase) ---
function openQuickAddSession() {
  const form = courseForm.value;
  quickAddConflict.value = null;
  quickAddChecking.value = false;
  quickAddSessionForm.value = {
    session_date: new Date().toISOString().slice(0, 10),
    start_time: normalizeTo30Min(form.start_time || '16:00'),
    duration_minutes: Math.max(30, Math.round((Number(form.duration_hours) || 2) * 60)),
    note: '',
    auto_approve: true,
    student_name: selectedStudent.value?.name || '—',
    subject: form.subject || 'Math',
  };
  showQuickAddSession.value = true;
  runQuickAddCheck();
}

let _quickAddCheckTimer = null;
async function runQuickAddCheck() {
  const courseId = editingCourseId.value;
  if (!courseId) return;
  const form = quickAddSessionForm.value;
  if (!form.session_date || !form.start_time) return;
  clearTimeout(_quickAddCheckTimer);
  _quickAddCheckTimer = setTimeout(async () => {
    quickAddChecking.value = true;
    quickAddConflict.value = null;
    try {
      const { data: { session: sess } } = await supabase.auth.getSession();
      const token = sess?.access_token;
      if (!token) return;
      const res = await fetch(`/api/v1/student-classes/${courseId}/add-session/check`, {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({ session_date: form.session_date, start_time: form.start_time }),
      });
      const json = await res.json().catch(() => ({}));
      quickAddConflict.value = json;
    } catch (_) {
      quickAddConflict.value = null;
    } finally {
      quickAddChecking.value = false;
    }
  }, 300);
}

async function submitQuickAddSession() {
  const courseId = editingCourseId.value;
  if (!courseId) return;
  if (!quickAddSessionForm.value.session_date) { alert('請選擇上課日期'); return; }
  if (!quickAddSessionForm.value.start_time) { alert('請選擇開始時間'); return; }
  const durationMinutes = Number(quickAddSessionForm.value.duration_minutes || 0);
  if (!Number.isFinite(durationMinutes) || durationMinutes < 30) { alert('時長至少 30 分鐘'); return; }
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { alert('請重新登入後再試'); return; }
    const res = await fetch(`/api/v1/student-classes/${courseId}/add-session`, {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
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
      if (res.status === 409 && json?.suggested_actions?.length) {
        quickAddConflict.value = json;
        runQuickAddCheck();
      } else {
        const details = json?.errors ? Object.values(json.errors || {}).flat().join(' ') : '';
        alert(details || json?.message || '加課失敗');
      }
      return;
    }
    showQuickAddSession.value = false;
    quickAddConflict.value = null;
    const movedFrom = String(json?.moved_from_date || '').slice(0, 10);
    const defaultMsg = movedFrom
      ? `已加課完成，已將原 ${movedFrom} 的堂次調整到新日期（總堂數不變）。`
      : (json?.no_total_increase ? '已加課完成（總堂數不變）。' : '已加課完成。');
    alert(json?.message ? `${json.message}\n${defaultMsg}` : defaultMsg);
    const sid = selectedStudent.value?.id;
    if (sid) await loadStudentCourses(sid);
    await loadAllStudentCourses();
  } catch (e) {
    alert('加課失敗：' + (e?.message || '請稍後再試'));
  }
}

// --- Add Sessions (per-course) ---
const openAddSessionsForCourse = (course) => {
  if (course?.payment_type === 'monthly') {
    renewMonthlyTargetCourse.value = course;
    renewMonthlyForm.value = {
      student_name: students.value.find(s => s.id === course.student_id)?.name || '—',
      subject: course?.subject || 'Math',
      settlement_day: course?.settlement_day ?? null,
      monthly_sessions: course?.monthly_sessions ?? null,
      current_end_date: course?.end_date || course?.EndDate || null,
      months: 1,
      end_date: '',
    };
    showRenewMonthlyModal.value = true;
    return;
  }
  selectedStudent.value = students.value.find(s => s.id === course.student_id);
  selectedCourse.value = course;
  addSessionCount.value = 8;
  addSessionStartDate.value = new Date().toISOString().slice(0, 10);
  showSessionsModal.value = true;
};

const submitAddSessions = async () => {
  if (!selectedCourse.value) return;
  if (addSessionCount.value <= 0) {
    alert('請輸入正確堂數');
    return;
  }
  if (!addSessionStartDate.value) {
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

    const res = await fetch(`/api/v1/student-classes/${selectedCourse.value.id}/purchase-batch`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify({
        sessions: Number(addSessionCount.value),
        start_date: addSessionStartDate.value
      })
    });

    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      const details = json?.errors ? Object.values(json.errors || {}).flat().join(' ') : '';
      const msg = details || json?.message || '操作失敗';
      alert(msg);
      return;
    }

    showSessionsModal.value = false;
    await loadAllStudentCourses();
    if (selectedStudent.value?.id) {
      await loadStudentCourses(selectedStudent.value.id);
    }
  } catch (e) {
    alert('操作失敗：' + (e?.message || '請稍後再試'));
  }
};

const submitRenewMonthly = async (endDate) => {
  const course = renewMonthlyTargetCourse.value;
  if (!course?.id) return;
  if (!endDate) { alert('請選擇新到期日或延長月數'); return; }
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { alert('請重新登入後再試'); return; }
    const res = await fetch(`/api/v1/student-classes/${course.id}/renew-monthly`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({ end_date: endDate }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      const details = json?.errors ? Object.values(json.errors || {}).flat().join(' ') : '';
      alert(details || json?.message || '續約失敗');
      return;
    }
    showRenewMonthlyModal.value = false;
    alert('月結續約成功，到期日已更新為 ' + endDate);
    await loadAllStudentCourses();
  } catch (e) {
    alert('續約失敗：' + (e?.message || '請稍後再試'));
  }
};

// --- Toggle Payment Status ---
const togglePaymentStatus = async (course, studentName = '') => {
  const courseId = course?.id;
  if (!courseId || isPaymentStatusPending(courseId)) return;
  const newStatus = course.payment_status === 'paid' ? 'unpaid' : 'paid';
  const fromLabel = course.payment_status === 'paid' ? '已繳費' : '未繳費';
  const toLabel = newStatus === 'paid' ? '已繳費' : '未繳費';
  const subjectLabel = getSubjectLabel(course.subject).split('(')[0].trim();
  const targetLabel = studentName || '此學生';
  const confirmText = `確定將「${targetLabel}」${subjectLabel ? `的${subjectLabel}課程` : '課程'}由「${fromLabel}」改為「${toLabel}」嗎？`;
  if (!confirm(confirmText)) return;

  setPaymentStatusPending(courseId, true);
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    let apiOk = false;
    if (token) {
      const payload = { payment_status: newStatus };
      if (newStatus === 'unpaid') {
        payload.paid_at = null;
      }
      const res = await fetch(`/api/v1/student-classes/${courseId}`, {
        method: 'PUT',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(payload)
      });
      if (res.ok) {
        apiOk = true;
      } else {
        const errBody = await res.json().catch(() => ({}));
        console.warn('Payment status API error:', res.status, errBody);
      }
    }

    // Also update Supabase to keep both in sync
    await supabase
      .from('student-classes')
      .update({ payment_status: newStatus })
      .eq('id', courseId);

    if (!apiOk && !token) {
      throw new Error('無法取得登入狀態，請重新登入');
    }

    course.payment_status = newStatus;
    if (newStatus === 'unpaid') {
      course.last_paid_at = null;
      course.paid_at = null;
    }
  } catch (e) {
    alert('更新繳費狀態失敗：' + (e?.message || '請稍後再試'));
  } finally {
    setPaymentStatusPending(courseId, false);
  }
};

// --- CSV Import ---
const importStudents = async (event) => {
  const file = event.target.files[0];
  if (!file) return;
  if (!props.branchId) {
    alert('請先在左下角切換分校，再進行匯入');
    event.target.value = '';
    return;
  }

  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) {
      alert('請重新登入後再試');
      return;
    }

    const formData = new FormData();
    formData.append('file', file);
    formData.append('branch_id', String(props.branchId));

    const res = await fetch('/api/v1/students/import', {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}` },
      body: formData
    });
    const json = await res.json().catch(() => ({}));

    if (!res.ok) {
      const msg = json?.message || json?.error || '匯入失敗';
      alert(typeof msg === 'string' ? msg : '匯入失敗');
      return;
    }

    const result = json?.result || {};
    const created = Number(result?.created || 0);
    const updated = Number(result?.updated || 0);
    const skipped = Number(result?.skipped || 0);
    const errors = Array.isArray(result?.errors) ? result.errors : [];
    const warnings = Array.isArray(result?.warnings) ? result.warnings : [];
    const lowConfidence = Number(result?.low_confidence_matches || 0);

    const lines = [
      '匯入完成',
      `新增：${created} 筆`,
      `更新：${updated} 筆`,
      `略過：${skipped} 筆`,
      `低信心比對（無手機）：${lowConfidence} 筆`,
    ];

    if (warnings.length > 0) {
      lines.push('', `警告（前 ${Math.min(3, warnings.length)} 筆）：`);
      warnings.slice(0, 3).forEach(w => lines.push(`- ${w}`));
    }
    if (errors.length > 0) {
      lines.push('', `錯誤（前 ${Math.min(3, errors.length)} 筆）：`);
      errors.slice(0, 3).forEach(err => lines.push(`- ${err}`));
    }

    alert(lines.join('\n'));
    await loadStudents();
  } catch (e) {
    alert('匯入失敗：' + (e?.message || '請稍後再試'));
  } finally {
    event.target.value = '';
  }
};

watch(() => props.branchId, () => { loadStudents(); loadTeachers(); loadAllStudentCourses(); });
watch(displayStudents, () => {
  syncSelectedStudentIdsWithCurrentList();
  if (expandedId.value != null && !displayStudents.value.some((s) => s.id === expandedId.value)) {
    expandedId.value = null;
  }
});
onMounted(async () => {
  loadStudents(); loadTeachers(); loadAllStudentCourses();
  try { const opts = await fetchSubjectOptions({ branchId: props.branchId }); if (opts.length > 0) subjectOptions.value = opts; } catch { /* keep defaults */ }
});
</script>

<style scoped>
/* Page-level table font bump for readability */
table td { font-size: 14px; }
table th { font-size: 12.5px; }

.text-secondary { color: var(--text-light); font-size: 0.9rem; }
.computed-end-time { margin: 0; font-weight: 600; font-size: 1rem; }
.close-btn { color: #92400e; border-color: #d97706; }
.close-btn:hover { background: #fef3c7; }
.paid-date-hint { display: inline-block; font-size: 12px; color: var(--success, #2e7d32); margin-left: 4px; white-space: nowrap; }

/* ═══ Header ═══ */
.header-actions {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 20px;
}
.header-title-area h2 {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
}
.header-icon {
  font-size: 26px;
  color: var(--primary);
}
.header-stats {
  display: flex;
  gap: 8px;
  margin-top: 8px;
  flex-wrap: wrap;
}
.stat-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 10px;
  border-radius: 6px;
  font-size: 13px;
  background: #FFF3E0;
  color: #E65100;
  font-weight: 600;
}
.stat-badge-light {
  background: #f5f5f5;
  color: #78909c;
  font-weight: 500;
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
  gap: 4px;
  transition: var(--transition);
}
.button-outline:hover {
  background: var(--bg);
  border-color: var(--accent);
}
.btn-icon {
  font-size: 18px;
  vertical-align: middle;
}

/* ═══ Bulk Action Toolbar ═══ */
.bulk-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 16px;
  margin-bottom: 12px;
  background: #E3F2FD;
  border: 1px solid #90CAF9;
  border-radius: 8px;
  animation: slideDown 0.2s ease;
}
@keyframes slideDown {
  from { opacity: 0; transform: translateY(-8px); }
  to { opacity: 1; transform: translateY(0); }
}
.bulk-count {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
  color: #1565c0;
}
.bulk-btns {
  display: flex;
  gap: 6px;
}

/* ═══ Filter Bar ═══ */
.filter-bar {
  margin-bottom: 20px;
  background: #FAFAFA;
  padding: 16px;
  border-radius: 10px;
  border: 1px solid var(--border);
}
.filter-search { position: relative; }
.search-input-wrap { position: relative; }
.search-icon {
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 20px;
  color: #bdbdbd;
  pointer-events: none;
}
.search-input-wrap input { padding-left: 36px; }
.filter-toggles {
  display: flex;
  gap: 8px;
  align-items: flex-end;
}

/* ═══ Student Row ═══ */
.student-select-head,
.student-select-cell {
  width: 44px;
  text-align: center;
}
.student-select-checkbox {
  width: 16px;
  height: 16px;
  cursor: pointer;
  accent-color: var(--primary);
}
.student-row {
  cursor: pointer;
  transition: background 0.2s ease;
}
.student-row:hover td {
  background: #FFF8E1 !important;
}
.student-row.expanded td {
  background: #FFF3E0;
  border-bottom-color: var(--accent);
}

/* Status left border on student rows */
.student-row td:first-child {
  border-left: 3px solid transparent;
}
.student-row.status-active td:first-child { border-left-color: #43a047; }
.student-row.status-graduated td:first-child { border-left-color: #1565c0; }
.student-row.status-paused td:first-child { border-left-color: #e65100; }
.student-row.status-transferred td:first-child { border-left-color: #7b1fa2; }

/* Expand icon */
.expand-icon {
  text-align: center;
  width: 30px;
}
.expand-chevron {
  font-size: 20px;
  color: #90a4ae;
  transition: transform 0.2s ease;
  display: inline-block;
}
.expand-chevron.rotated {
  transform: rotate(180deg);
}

/* Student name cell with avatar */
.student-name-cell {
  display: flex;
  align-items: center;
  gap: 8px;
  white-space: nowrap;
}
.student-name-cell strong {
  font-size: 15px;
  white-space: nowrap;
}
.student-avatar-mini {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  font-weight: 700;
  color: #fff;
  flex-shrink: 0;
  background: linear-gradient(135deg, #43a047, #66bb6a);
}
.student-avatar-mini.graduated { background: linear-gradient(135deg, #1565c0, #42a5f5); }
.student-avatar-mini.paused { background: linear-gradient(135deg, #e65100, #ff9800); }
.student-avatar-mini.transferred { background: linear-gradient(135deg, #7b1fa2, #ab47bc); }

.text-red {
  color: var(--danger) !important;
}

/* Subject pills */
.subject-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}
.subject-pill {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 3px 9px;
  border-radius: 12px;
  font-size: 12.5px;
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
  margin-left: 4px;
  cursor: help;
  color: #ffab00;
}

.student-status-badge {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 8px;
  font-size: 11px;
  margin-left: 6px;
  font-weight: 600;
}
.student-status-badge.graduated { background: #E3F2FD; color: #1565C0; }
.student-status-badge.paused { background: #FFF3E0; color: #E65100; }
.student-status-badge.transferred { background: #F3E5F5; color: #6A1B9A; }

/* RFID */
.student-binding-badges {
  display: inline-flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
}
.rfid-tag {
  font-size: 12px;
  font-family: monospace;
  color: var(--primary);
  display: inline-flex;
  align-items: center;
  gap: 3px;
}
.rfid-unbound {
  font-size: 12px;
  color: #bdbdbd;
  display: inline-flex;
  align-items: center;
  gap: 3px;
}

/* Action buttons */
.action-cell {
  white-space: nowrap;
  vertical-align: middle;
}
.action-cell-buttons {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.icon-btn {
  display: inline-flex !important;
  align-items: center;
  justify-content: center;
  padding: 4px 6px !important;
  min-width: 30px;
}
.icon-btn .material-symbols-outlined {
  font-size: 18px;
}

/* ═══ Mini Progress Bar (in course table) ═══ */
.sessions-cell {
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.mini-progress {
  height: 4px;
  background: #e8e8e8;
  border-radius: 2px;
  overflow: hidden;
  width: 80px;
}
.mini-progress-fill {
  height: 100%;
  border-radius: 2px;
  transition: width 0.3s ease;
}

/* ═══ Day Chips ═══ */
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

/* ═══ Course Detail Panel ═══ */
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
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 16px;
  font-weight: 700;
  color: var(--primary);
  margin: 0;
}
.student-note-line {
  margin-bottom: 12px;
  font-size: 13px;
  color: #64748b;
}
.student-note-label {
  font-weight: 700;
}
.course-inner-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 14px;
}
.course-inner-table th {
  background: #F0F0F0;
  font-size: 12px;
  padding: 9px 8px;
}
.course-inner-table td {
  padding: 10px 8px;
  border-bottom: 1px solid #EEEEEE;
}
.status-tag.one_on_one { background: #FFF3E0; color: #E65100; }
.status-tag.one_on_two { background: #FFF8E1; color: #F57F17; }
.status-tag.one_on_three { background: #FBE9E7; color: #BF360C; }
.status-tag.tutoring { background: #E8F5E9; color: #2E7D32; }
.status-tag.trial { background: #E8EAF6; color: #3949AB; }
.course-memo-line {
  margin-top: 4px;
  font-size: 12px;
  color: #64748b;
  line-height: 1.4;
  word-break: break-word;
}

.cell-schedule-slots .schedule-slot-lines {
  display: flex;
  flex-direction: column;
  gap: 3px;
  align-items: flex-start;
}

.cell-schedule-slots .schedule-slot-line {
  font-size: 13px;
  line-height: 1.4;
}

/* ═══ Form Section ═══ */
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
.required {
  color: var(--danger);
}

/* ═══ Cost Preview ═══ */
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

/* ═══ Table Scroll ═══ */
.table-scroll-wrap {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}
.table-scroll-wrap > table {
  min-width: 820px;
}

/* Prevent wrapping in key columns */
.student-row td:nth-child(4),
.student-row td:nth-child(5),
.student-row td:nth-child(6) {
  white-space: nowrap;
}

/* ═══ Mobile Responsive ═══ */
@media (max-width: 768px) {
  .header-actions {
    flex-direction: column;
    gap: 10px;
  }
  .header-buttons {
    flex-wrap: wrap;
    width: 100%;
  }
  .header-buttons > * {
    flex: 1;
    justify-content: center;
    min-width: 0;
    text-align: center;
  }
  .filter-bar {
    padding: 12px;
  }
  .filter-toggles {
    flex-wrap: wrap;
  }
  .bulk-toolbar {
    flex-direction: column;
    gap: 8px;
    align-items: stretch;
  }
  .bulk-btns {
    justify-content: flex-end;
  }

  /* Icon-only action buttons on mobile */
  .action-cell button span:not(.material-symbols-outlined) {
    display: none;
  }
}

.tag-package {
  background: #ede9fe;
  color: #6d28d9;
  border: 1px solid #c4b5fd;
  font-size: 0.65rem;
  cursor: help;
}
.tag-package-hint {
  font-size: 0.7em;
  color: #6d28d9;
  font-weight: 400;
}
.package-pool-hint {
  display: block;
  font-size: 0.7em;
  color: #7c3aed;
  font-weight: 400;
  margin-top: 2px;
}
.tag-paused-sm {
  background: #ffedd5;
  color: #7c2d12;
  border: 1px solid #ea580c;
  border-radius: 6px;
  font-size: 11px;
  padding: 2px 7px;
  font-weight: 600;
  margin-left: 4px;
}
.tag-expiring {
  background: #fff3e0;
  color: #e65100;
  border: 1px solid #ff9800;
  border-radius: 6px;
  font-size: 11px;
  padding: 2px 7px;
  font-weight: 600;
  margin-left: 4px;
  animation: pulse-warn 2s ease-in-out infinite;
}
@keyframes pulse-warn {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.7; }
}
.btn-renew-warn {
  background: #ff9800 !important;
  color: #fff !important;
  border: 1px solid #e65100 !important;
  font-weight: 600;
  border-radius: 6px;
  padding: 4px 10px;
  font-size: 12px;
  cursor: pointer;
}
.btn-renew-warn:hover {
  background: #e65100 !important;
}

/* ── Empty active courses ── */
.sl-empty-active {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 20px 16px;
  color: #94a3b8;
  font-size: 14px;
}
.sl-empty-active__icon {
  font-size: 24px;
  color: #cbd5e1;
}

/* ── History section ── */
.sl-history-section {
  border-top: 1px dashed #e2e8f0;
  margin-top: 8px;
  background: #fafbfc;
  border-radius: 0 0 8px 8px;
}
.sl-history-toggle {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 10px 14px;
  border: none;
  background: transparent;
  font-family: inherit;
  font-size: 13px;
  font-weight: 600;
  color: #64748b;
  cursor: pointer;
  transition: background 0.15s;
}
.sl-history-toggle:hover {
  background: #f1f5f9;
}
.sl-history-toggle__icon {
  font-size: 18px;
  color: #94a3b8;
}
.sl-history-toggle__count {
  font-weight: 400;
  font-size: 12px;
  color: #94a3b8;
}
.sl-history-toggle__chevron {
  margin-left: auto;
  font-size: 11px;
  color: #94a3b8;
}
.sl-history-body {
  padding: 4px 14px 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.sl-history-card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-left: 3px solid #d1d5db;
  border-radius: 10px;
  padding: 10px 14px;
  transition: box-shadow 0.15s;
}
.sl-history-card:hover {
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}
.sl-history-card__header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
}
.sl-history-card__subject {
  background: #f1f5f9 !important;
  color: #475569 !important;
  border: 1px solid #cbd5e1 !important;
}
.sl-tag-history {
  border-radius: 6px;
  font-size: 11px;
  padding: 2px 8px;
  font-weight: 700;
  letter-spacing: 0.02em;
}
.sl-tag-history--settled {
  background: #f0fdf4;
  color: #15803d;
  border: 1px solid #86efac;
}
.sl-tag-history--completed {
  background: #eff6ff;
  color: #1d4ed8;
  border: 1px solid #93c5fd;
}
.sl-history-card__details {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 14px;
  font-size: 13px;
  color: #64748b;
}
.sl-history-card__label {
  font-weight: 600;
  color: #94a3b8;
  margin-right: 4px;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.sl-history-card__actions {
  display: flex;
  gap: 6px;
  margin-top: 8px;
}
@media (max-width: 640px) {
  .sl-history-body {
    padding: 4px 8px 12px;
  }
  .sl-history-card {
    padding: 8px 10px;
  }
  .sl-history-card__details {
    flex-direction: column;
    gap: 2px;
  }
}

/* LINE bound badge in student list */
.line-bound-badge {
  display: inline-flex;
  align-items: center;
  background: #06C755;
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  padding: 1px 6px;
  border-radius: 10px;
  letter-spacing: 0.3px;
  vertical-align: middle;
}

/* LINE bindings section */
.line-bindings-section {
  margin-bottom: 12px;
}
.line-bindings-empty {
  color: #9e9e9e;
  font-size: 13px;
  padding: 6px 0;
}
.line-bindings-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.line-binding-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 10px;
  background: #f5f5f5;
  border-radius: 6px;
  font-size: 13px;
}
.line-binding-id {
  font-family: monospace;
  font-size: 12px;
  color: #06C755;
  font-weight: 600;
}
.line-binding-time {
  color: #757575;
  font-size: 12px;
  flex: 1;
  text-align: right;
}
.line-binding-remove {
  background: none;
  border: 1px solid #ef5350;
  color: #ef5350;
  font-size: 12px;
  padding: 2px 10px;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.15s;
}
.line-binding-remove:hover {
  background: #ef5350;
  color: #fff;
}

/* Toast notification */
.toast-notification {
  position: fixed;
  bottom: 32px;
  left: 50%;
  transform: translateX(-50%);
  background: #323232;
  color: #fff;
  padding: 10px 24px;
  border-radius: 8px;
  font-size: 14px;
  z-index: 10001;
  box-shadow: 0 4px 12px rgba(0,0,0,0.2);
  animation: toast-in 0.25s ease;
}
@keyframes toast-in {
  from { opacity: 0; transform: translateX(-50%) translateY(12px); }
  to   { opacity: 1; transform: translateX(-50%) translateY(0); }
}

/* ── Dark mode: history section ── */
[data-theme="dark"] .sl-history-section {
  border-top-color: #334155;
  background: #0f172a;
}
[data-theme="dark"] .sl-history-toggle {
  color: #94a3b8;
}
[data-theme="dark"] .sl-history-toggle:hover {
  background: #1e293b;
}
[data-theme="dark"] .sl-history-card {
  background: #1e293b;
  border-color: #334155;
  border-left-color: #475569;
}
[data-theme="dark"] .sl-history-card:hover {
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}
[data-theme="dark"] .sl-history-card__subject {
  background: #334155 !important;
  color: #e2e8f0 !important;
  border-color: #475569 !important;
}
[data-theme="dark"] .sl-tag-history--settled {
  background: #052e16;
  color: #4ade80;
  border-color: #166534;
}
[data-theme="dark"] .sl-tag-history--completed {
  background: #172554;
  color: #60a5fa;
  border-color: #1e40af;
}
[data-theme="dark"] .sl-history-card__details {
  color: #94a3b8;
}
[data-theme="dark"] .sl-history-card__label {
  color: #64748b;
}
[data-theme="dark"] .sl-empty-active {
  color: #64748b;
}
[data-theme="dark"] .sl-empty-active__icon {
  color: #475569;
}
</style>
