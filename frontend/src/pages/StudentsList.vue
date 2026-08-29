<template>
  <div class="students-page at-ops-page">
    <div class="students-shell">
      <AtPageHeader
        title="學生管理"
        description="搜尋、篩選與管理本分校學生資料與課程安排。"
        icon="school"
        data-guide="students-header"
      >
        <template #meta>
          <span>本分校 <strong>{{ branchStudentTotal }}</strong> 人</span>
          <span>目前列表 {{ displayStudents.length }} 人</span>
        </template>
        <template #actions>
          <label class="button-outline">
            <span class="material-symbols-outlined btn-icon" aria-hidden="true">upload_file</span>
            匯入名單
            <input type="file" @change="importStudents" accept=".csv,.xlsx" style="display: none;" />
          </label>
          <AtButton shape="rect" variant="primary" icon="add" @click="openAddStudent">新增學生</AtButton>
          <button type="button" class="small ghost" @click="openIdentityModal">
            <span class="material-symbols-outlined btn-icon">merge</span>
            跨分校身份
          </button>
        </template>
      </AtPageHeader>

      <!-- Bulk Action Toolbar (appears when students selected) -->
      <div v-if="hasSelectedStudents" class="bulk-toolbar">
        <span class="bulk-count">
          <span class="material-symbols-outlined" style="font-size:18px;">check_circle</span>
          已選 {{ selectedStudentCount }} 位
        </span>
        <div class="bulk-btns">
          <AtButton shape="rect" size="sm" variant="ghost" @click="clearSelectedStudents">清除勾選</AtButton>
          <AtButton shape="rect" size="sm" variant="danger" icon="delete" @click="deleteSelectedStudents">批量刪除</AtButton>
        </div>
      </div>

      <AtFilterBar label="學生篩選" data-guide="students-filters">
        <div class="filter-search">
          <label>搜尋姓名</label>
          <div class="search-input-wrap">
            <span class="material-symbols-outlined search-icon" aria-hidden="true">search</span>
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
          <AtButton shape="rect" size="sm" variant="ghost" icon="school" @click="showGradePromotion = true">年級升級</AtButton>
          <AtButton
            shape="rect"
            size="sm"
            :variant="showHistoricalCourses ? 'primary' : 'ghost'"
            @click="toggleHistoricalCourses"
          >
            {{ showHistoricalCourses ? '隱藏已結業/歷史課程' : '顯示已結業/歷史課程' }}
          </AtButton>
        </div>
      </AtFilterBar>

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
            :data-student-id="student.id"
            tabindex="0"
            :aria-expanded="expandedId === student.id"
            :aria-controls="`student-course-detail-${student.id}`"
            @click="toggleExpand(student, $event)"
            @keydown.enter.prevent="toggleExpand(student, $event)"
            @keydown.space.prevent="toggleExpand(student, $event)"
          >
            <td class="student-select-cell" @click.stop @keydown.stop>
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
                  <span class="material-symbols-outlined rfid-unbound-icon">contactless</span>
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
            <td @click.stop @keydown.stop class="action-cell">
              <div class="action-cell-buttons">
                <AtIconButton icon="edit" label="編輯" @click="editStudent(student)" />
                <AtIconButton icon="delete" label="刪除" variant="danger" @click="deleteStudent(student)" />
              </div>
            </td>
          </tr>

          <!-- Expanded Course Detail -->
          <tr v-if="expandedId === student.id" :id="`student-course-detail-${student.id}`" class="course-detail-row">
            <td colspan="10">
              <div class="course-panel">
                <div class="course-panel-header">
                  <h4>
                    <span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;">menu_book</span>
                    {{ student.name }} 的課程安排
                  </h4>
                  <button type="button" class="primary small" @click="openAddCourse(student)">
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
                <!-- Phase 2A: make the record answer count / attention / next action before
                     exposing one course's full detail. Existing course and payment handlers
                     remain on the focused card and are not changed. -->
                <div v-if="getActiveStudentCourses(student.id).length > 0" class="student-course-workspace" data-testid="student-course-workspace">
                  <section class="student-course-overview" aria-labelledby="student-course-overview-title">
                    <div class="student-course-overview__header">
                      <div>
                        <span class="student-course-overview__eyebrow">課程總覽</span>
                        <h5 id="student-course-overview-title">先看需要處理的課程</h5>
                      </div>
                      <span class="student-course-overview__hint">選一門查看完整資料</span>
                    </div>
                    <div class="student-course-overview__metrics" role="list" aria-label="課程摘要">
                      <div role="listitem" class="student-course-overview__metric">
                        <strong>{{ getActiveStudentCourses(student.id).length }}</strong>
                        <span>進行中</span>
                      </div>
                      <div role="listitem" class="student-course-overview__metric" :class="{ 'student-course-overview__metric--attention': getStudentCourseAttentionCount(student.id) > 0 }">
                        <strong>{{ getStudentCourseAttentionCount(student.id) }}</strong>
                        <span>需要處理</span>
                      </div>
                      <div role="listitem" class="student-course-overview__metric">
                        <strong>{{ getStudentHistoryCourseCount(student.id) }}</strong>
                        <span>歷史</span>
                      </div>
                    </div>
                    <div class="student-course-picker" role="list" aria-label="進行中的課程">
                      <div
                        v-for="course in getActiveStudentCourses(student.id)"
                        :key="`picker-${course.id}`"
                        class="student-course-picker__item"
                        :class="{ 'student-course-picker__item--selected': getFocusedStudentCourse(student.id)?.id === course.id, 'student-course-picker__item--attention': isCourseNeedsAttention(course) }"
                        :data-course-id="course.id"
                        role="listitem"
                      >
                        <button
                          type="button"
                          class="student-course-picker__button"
                          :aria-pressed="getFocusedStudentCourse(student.id)?.id === course.id"
                          @click.stop="selectStudentCourse(student.id, course.id, $event)"
                        >
                          <span class="student-course-picker__subject">{{ getSubjectLabel(course.subject) }}</span>
                          <span class="student-course-picker__status">{{ getCourseAttentionLabel(course) }}</span>
                          <span class="student-course-picker__detail">{{ getCourseProgressSummary(course) }}</span>
                          <span class="student-course-picker__chevron material-symbols-outlined" aria-hidden="true">chevron_right</span>
                        </button>
                      </div>
                    </div>
                  </section>

                  <!-- Active courses: task-first card keeps the next action visible without
                       changing any existing course or payment handlers. -->
                  <section class="student-course-detail" aria-labelledby="student-course-detail-title">
                    <div class="student-course-detail__heading">
                      <div>
                        <span class="student-course-detail__eyebrow">目前課程工作區</span>
                        <h5 id="student-course-detail-title">查看選定課程的完整資料</h5>
                      </div>
                      <span class="student-course-detail__hint">下一步與更多操作都在這裡</span>
                    </div>
                    <div class="student-course-cards" data-testid="student-course-cards">
                  <template v-for="course in getActiveStudentCourses(student.id)" :key="course.id">
                  <article
                    v-if="getFocusedStudentCourse(student.id)?.id === course.id"
                    class="student-course-card student-course-card--focused"
                    :class="{ 'student-course-card--attention': isCourseNeedsAttention(course) }"
                    :data-course-id="course.id"
                  >
                    <header class="student-course-card__header">
                      <div class="student-course-card__identity">
                        <span class="student-course-card__eyebrow">學生課程</span>
                        <h5>{{ getSubjectLabel(course.subject) }}</h5>
                        <div class="student-course-card__badges">
                          <span class="status-tag" :class="course.class_type">{{ classTypeLabel(course.class_type) }}</span>
                          <span v-if="course.PackageID" class="tag tag-package" :title="course.PackageName || '多科方案'">方案</span>
                          <span v-if="course.status === 'inactive'" class="tag tag-paused-sm">已暫停</span>
                          <span v-else-if="isSessionPaymentLowRemaining(course)" class="tag tag-expiring">即將用完</span>
                        </div>
                      </div>
                      <button
                        type="button"
                        :class="['student-course-card__primary', getCoursePrimaryAction(course).tone === 'warning' ? 'btn-renew-warn' : 'primary']"
                        @click="openCoursePrimaryAction(course, student.name)"
                      >
                        <span class="material-symbols-outlined" aria-hidden="true">{{ getCoursePrimaryAction(course).icon }}</span>
                        {{ getCoursePrimaryAction(course).label }}
                      </button>
                    </header>

                    <div
                      class="student-course-card__next-step"
                      :class="{ 'student-course-card__next-step--attention': isCourseNeedsAttention(course) }"
                      role="note"
                    >
                      <span class="material-symbols-outlined" aria-hidden="true">{{ getCoursePrimaryAction(course).icon }}</span>
                      <div>
                        <span class="student-course-card__next-step-label">現在先處理</span>
                        <strong>{{ getCoursePrimaryAction(course).title }}</strong>
                        <p>{{ getCoursePrimaryAction(course).description }}</p>
                      </div>
                    </div>

                    <section v-if="courseProgress(course)" class="student-course-card__progress" aria-label="課程堂數進度">
                      <div class="student-course-card__progress-head">
                        <span>課程進度</span>
                        <strong>{{ courseProgress(course).remaining }} / {{ courseProgress(course).total }} 堂剩餘</strong>
                      </div>
                      <div
                        class="student-course-card__progress-track"
                        role="progressbar"
                        :aria-valuemin="0"
                        :aria-valuemax="courseProgress(course).total"
                        :aria-valuenow="courseProgress(course).used"
                        :aria-label="`已使用 ${courseProgress(course).used} 堂，共 ${courseProgress(course).total} 堂`"
                      >
                        <span class="student-course-card__progress-fill" :style="{ width: `${courseProgress(course).percent}%` }"></span>
                      </div>
                      <span class="student-course-card__progress-caption">已使用 {{ courseProgress(course).used }} 堂<span v-if="course.PackageID"> · 方案共用堂數</span></span>
                    </section>
                    <div v-else-if="String(course.payment_type || '').toLowerCase() === 'session'" class="student-course-card__progress-empty" role="status">
                      堂數未設定，請編輯課程確認。
                    </div>
                    <div v-else class="student-course-card__cadence">
                      <span class="material-symbols-outlined" aria-hidden="true">event_repeat</span>
                      <strong>月結</strong>
                      <span v-if="course.settlement_day">每月{{ course.settlement_day }}號結算</span>
                      <span v-if="parseCourseNumber(course.monthly_sessions) != null && parseCourseNumber(course.monthly_sessions) > 0">每月 {{ parseCourseNumber(course.monthly_sessions) }} 堂</span>
                    </div>

                    <dl class="student-course-card__meta">
                      <div>
                        <dt>老師</dt>
                        <dd>{{ course.teacher_name || '待指派' }}</dd>
                      </div>
                      <div>
                        <dt>上課時段</dt>
                        <dd>
                          <span v-if="scheduleDisplayLines(course).length > 0">{{ scheduleDisplayLines(course).join('、') }}</span>
                          <span v-else-if="course.days_of_week && course.days_of_week.length">{{ scheduleDisplay(course) }}</span>
                          <span v-else-if="course.day_of_week">{{ dayLabel(course.day_of_week) }} {{ scheduleTimeRange(course) }}</span>
                          <span v-else class="hint">未排定</span>
                        </dd>
                      </div>
                      <div>
                        <dt>地點</dt>
                        <dd>{{ [course.branch_name, course.room_name].filter(Boolean).join(' － ') || '尚未指定' }}</dd>
                      </div>
                      <div>
                        <dt>費用</dt>
                        <dd class="student-course-card__money">${{ sessionFeeDisplay(course) }}／堂 · {{ course.duration_hours }} 小時</dd>
                      </div>
                      <div>
                        <dt>付款</dt>
                        <dd>
                          <span :class="['student-course-card__payment', `student-course-card__payment--${course.payment_status || 'unpaid'}`]">{{ paymentStatusButtonLabel(course) }}</span>
                          <span v-if="course.last_paid_at" class="paid-date-hint">{{ course.last_paid_at }}</span>
                        </dd>
                      </div>
                    </dl>

                    <div v-if="courseMemo(course) || coursePaymentSummary(course)" class="student-course-card__context" role="note">
                      <div v-if="courseMemo(course)"><strong>備註</strong>{{ courseMemo(course) }}</div>
                      <div v-if="coursePaymentSummary(course)"><strong>最近繳費：</strong>{{ formatPaymentSummary(course.latest_payment_summary) }}</div>
                    </div>

                    <details class="student-course-card__actions">
                      <summary>更多操作</summary>
                      <div class="student-course-card__actions-body">
                        <button
                          type="button"
                          :class="['small', paymentStatusButtonClass(course)]"
                          title="點擊切換繳費狀態"
                          :disabled="isPaymentStatusPending(course.id)"
                          @click="togglePaymentStatus(course, student.name)"
                        >{{ paymentStatusButtonLabel(course) }}</button>
                        <button type="button" class="small ghost" @click="openAddSessionsForCourse(course)">{{ isSessionPaymentLowRemaining(course) ? '再次續報加購' : '加購' }}</button>
                        <button v-if="course.payment_type === 'monthly'" type="button" class="small ghost" @click="openInvoiceModal(course)">帳單</button>
                        <button type="button" class="small ghost" @click="openLatestPaymentInfo(course, student.name)">繳費資訊</button>
                        <button v-if="isSessionPaymentLowRemaining(course)" type="button" class="small ghost" @click="editCourse(course)">編輯課程</button>
                        <button v-if="canCloseCourse(course)" type="button" class="small close-btn" @click="closeCourseNoRenew(course, student.name)">結案</button>
                        <button type="button" class="small danger" @click="deleteCourse(course)">刪除</button>
                      </div>
                    </details>
                  </article>
                  </template>
                    </div>
                  </section>
                </div>
                <div v-else-if="getHistoryStudentCourses(student.id).length > 0" class="sl-empty-active">
                  <span class="material-symbols-outlined sl-empty-active__icon" aria-hidden="true">school</span>
                  <span>目前沒有進行中的課程</span>
                </div>

                <!-- History courses collapsible section -->
                <div v-if="getHistoryStudentCourses(student.id).length > 0" class="sl-history-section">
                  <button
                    type="button"
                    class="sl-history-toggle"
                    :aria-expanded="expandedHistoryCourses.has(student.id)"
                    :aria-controls="`student-history-${student.id}`"
                    @click.stop="toggleHistoryCourses(student.id)"
                  >
                    <span class="material-symbols-outlined sl-history-toggle__icon" aria-hidden="true">inventory_2</span>
                    <span>歷史課程</span>
                    <span class="sl-history-toggle__count">{{ getHistoryStudentCourses(student.id).length }} 筆</span>
                    <span class="sl-history-toggle__chevron">{{ expandedHistoryCourses.has(student.id) ? '▲' : '▼' }}</span>
                  </button>
                  <div
                    v-if="expandedHistoryCourses.has(student.id)"
                    :id="`student-history-${student.id}`"
                    class="sl-history-body"
                  >
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
                        <button type="button" class="small ghost" @click="editCourse(hc)">編輯</button>
                        <button type="button" class="small danger" @click="deleteCourse(hc)">刪除</button>
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
      <AtEmpty
        v-else
        icon="school"
        :title="showHistoricalCourses ? '目前無學生資料' : '目前沒有進行中的學生/課程'"
        :description="showHistoricalCourses ? '請點擊「新增學生」或匯入 CSV。' : '可切換「顯示已結業/歷史課程」查看歷史資料。'"
      >
        <template #action>
          <AtButton shape="rect" variant="primary" icon="add" @click="openAddStudent">新增學生</AtButton>
        </template>
      </AtEmpty>
    </div>

    <!-- Add/Edit Student Modal -->
    <div v-if="showStudentModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="student-modal-title" @click.self="closeStudentModal">
      <div class="modal" style="width: 520px;">
        <h3 id="student-modal-title">{{ editingStudentId ? '編輯學生' : '新增學生' }}</h3>
        
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
          <div v-if="studentForm.latest_payment_note" class="student-latest-payment-note" role="note">
            <span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>
            <div>
              <strong>最近已確認入帳備註（自動）</strong>
              <p>{{ studentForm.latest_payment_note }}</p>
              <small>由帳務回報自動帶入，不會覆蓋上方的學生長期備註。</small>
            </div>
          </div>
        </div>

        <div class="actions">
          <button type="button" class="ghost" @click="closeStudentModal">取消</button>
          <button type="button" class="primary" @click="submitStudent">儲存</button>
        </div>
      </div>
    </div>

    <!-- Edit Course Modal -->
    <div v-if="showCourseModal && editingCourseId" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="course-modal-title" @click.self="closeCourseModal">
      <div class="modal" style="width: 520px;">
        <h3 id="course-modal-title">編輯課程</h3>
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
          <button type="button" class="ghost small" @click="openQuickAddSession">＋ 補課 / 補登（總堂數不變）</button>
        </div>

        <div class="actions">
          <button type="button" class="ghost" @click="closeCourseModal">取消</button>
          <button type="button" class="primary" :disabled="editFormRef?.hasErrors" @click="submitCourse">儲存</button>
        </div>
      </div>
    </div>

    <ToastWithUndo ref="toastRef" />

    <!-- Payment Entry Modal — 核帳登記（未繳費→已繳費必須填繳款日期） -->
    <PaymentEntryModal
      :show="paymentEntryOpen"
      :row="paymentEntryRow"
      @close="paymentEntryOpen = false"
      @confirmed="onPaymentEntryConfirmed"
    />

    <ReceiptModal
      :show="receiptOpen"
      :report-id="receiptReportId"
      @close="receiptOpen = false"
    />

    <LatestPaymentInfoModal
      :show="latestPaymentOpen"
      :course="latestPaymentCourse"
      @close="latestPaymentOpen = false"
      @view-receipt="openReceiptByReport"
    />

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
      :allow-package-mode="true"
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

    <!-- 月結帳單記錄 Modal -->
    <div v-if="showInvoiceModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="invoice-modal-title" @click.self="showInvoiceModal = false">
      <div class="modal" style="max-width: 480px;">
        <h3 id="invoice-modal-title" style="margin-bottom: 4px;">月結帳單記錄</h3>
        <p class="invoice-modal-subtitle">
          {{ invoiceModalCourse?.student_name || '' }} — {{ getSubjectLabel(invoiceModalCourse?.subject) }}
        </p>

        <div v-if="invoiceModalLoading" class="invoice-modal-loading">
          <div class="invoice-skeleton"></div>
          <div class="invoice-skeleton" style="width: 70%;"></div>
        </div>

        <div v-else-if="invoiceModalList.length === 0" class="invoice-modal-empty">
          尚無帳單記錄（舊有課程）
        </div>

        <table v-else class="course-inner-table">
          <thead>
            <tr>
              <th>期別</th>
              <th style="text-align: right;">金額</th>
              <th style="text-align: center;">狀態</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="inv in invoiceModalList" :key="inv.id">
              <td style="font-size: 13px;">
                {{ inv.billing_period ? formatBillingPeriod(inv.billing_period) : (inv.issue_date ? inv.issue_date.slice(0, 7) : '—') }}
                <span v-if="inv.due_date" class="invoice-due-date-hint">繳費日 {{ inv.due_date }}</span>
              </td>
              <td style="text-align: right; font-weight: 600;">${{ inv.total_amount.toLocaleString() }}</td>
              <td style="text-align: center;">
                <span :class="['invoice-status-chip', inv.status]">
                  {{ { paid: '已繳', unpaid: '未繳', partial: '部分繳' }[inv.status] || inv.status }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>

        <div class="actions" style="margin-top: 20px;">
          <button type="button" class="ghost" @click="showInvoiceModal = false">關閉</button>
        </div>
      </div>
    </div>

    <!-- Add Sessions Modal -->
    <div v-if="showSessionsModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="sessions-modal-title" @click.self="showSessionsModal = false">
      <div class="modal">
        <h3 id="sessions-modal-title">加購堂數 — {{ getSubjectLabel(selectedCourse?.subject) }}</h3>
        <div class="form-group">
          <label>學生</label>
          <p style="font-weight: 600;">{{ selectedStudent?.name }}</p>
        </div>
        <div class="form-group">
          <label>{{ selectedCourse?.PackageID ? '目前剩餘（方案池）' : '目前剩餘（此課程）' }}</label>
          <p :style="{ fontSize: '20px', fontWeight: 700, color: (selectedCourse?.PackageID ? (selectedCourse?.package_remaining_sessions ?? 0) : (selectedCourse?.remaining_sessions ?? 0)) <= 2 ? '#e65100' : 'var(--primary)' }">
            {{ selectedCourse?.PackageID ? (selectedCourse?.package_remaining_sessions ?? 0) : (selectedCourse?.remaining_sessions ?? 0) }} 堂
            <span v-if="(selectedCourse?.PackageID ? (selectedCourse?.package_remaining_sessions ?? 0) : (selectedCourse?.remaining_sessions ?? 0)) <= 2" class="sessions-near-empty-hint">（即將用完，建議盡快加購）</span>
          </p>
        </div>
        <p class="hint sessions-package-hint">
          {{ selectedCourse?.PackageID
            ? '此課程屬於多科共用方案，加購會增加整個方案的共用總堂數，所有方案科目一起沿用同一個堂數池。'
            : '此加購會建立新的未繳課程批次，並在新批次詳情顯示上課日期；原課程堂數不會被改寫。'
          }}
        </p>
        <div class="form-group">
          <label>加購堂數</label>
          <input v-model.number="addSessionCount" type="number" placeholder="8" />
        </div>
        <div v-if="!selectedCourse?.PackageID" class="form-group">
          <label>新批次開始日期</label>
          <input v-model="addSessionStartDate" type="date" />
        </div>
        <p class="hint" v-if="addSessionCount > 0">
          <template v-if="selectedCourse?.PackageID">
            將共用方案總堂數增加 <strong>{{ addSessionCount }}</strong> 堂（不拆成單科新契約）
          </template>
          <template v-else>
            將新增一筆 <strong>{{ addSessionCount }}</strong> 堂的未繳課程批次（不再併入原課程）
          </template>
        </p>
        <div class="actions">
          <button type="button" class="ghost" @click="showSessionsModal = false">取消</button>
          <button type="button" class="primary" @click="submitAddSessions">
            確認加購
          </button>
        </div>
      </div>
    </div>
    <EnrollmentConflictDecisionModal
      :show="showDuplicateInterceptModal"
      :conflicts="duplicateConflicts"
      :student-name="interceptPendingStudent?.name || ''"
      :class-type="interceptPendingClassType"
      :submitting="forceSubmitting"
      :subject-label-fn="getSubjectLabel"
      @cancel="showDuplicateInterceptModal = false"
      @purchase="interceptGoToPurchase"
      @decision="onEnrollmentConflictDecision"
    />
    <!-- Grade Promotion Modal -->
    <div v-if="showGradePromotion" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="grade-promotion-modal-title" @click.self="showGradePromotion = false">
      <div class="modal" style="width: 500px;">
        <h3 id="grade-promotion-modal-title">年級升級</h3>
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
          <button type="button" class="ghost" @click="showGradePromotion = false">取消</button>
          <button type="button" class="primary" @click="executeGradePromotion" :disabled="promotionPreview.length === 0">確認升級</button>
        </div>
      </div>
    </div>

    <!-- Cross-campus identity bridge: explicit director confirmation only. -->
    <div v-if="showIdentityModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="identity-modal-title" @click.self="closeIdentityModal">
      <div class="modal" style="width: 680px; max-width: calc(100vw - 32px);">
        <div class="modal-header-row">
          <h3 id="identity-modal-title">跨分校學生身份關聯</h3>
          <button type="button" class="ghost small" @click="closeIdentityModal">關閉</button>
        </div>
        <p class="hint">只把主任已確認為同一位學生的兩筆分校資料關聯；姓名或手機相同不會自動合併。</p>
        <div class="identity-search-row">
          <input v-model="identityQuery" placeholder="搜尋學生姓名" @keyup.enter="searchIdentityStudents" />
          <button type="button" class="small ghost" @click="searchIdentityStudents" :disabled="identityLoading">搜尋</button>
        </div>
        <div v-if="identityError" class="error-text">{{ identityError }}</div>
        <div v-if="identityLoading" class="empty-text">載入中…</div>
        <div v-else class="identity-candidate-list">
          <button
            v-for="candidate in identityStudents"
            :key="candidate.student_id"
            type="button"
            class="identity-candidate"
            :class="{ selected: selectedIdentityStudentIds.includes(candidate.student_id) }"
            @click="toggleIdentityCandidate(candidate.student_id)"
          >
            <span><strong>{{ candidate.name }}</strong> · {{ candidate.campus_name || `分校 ${candidate.campus_id}` }}</span>
            <small>{{ candidate.identity_group_id ? `群組 #${candidate.identity_group_id}` : '尚未關聯' }}</small>
          </button>
          <div v-if="!identityStudents.length" class="empty-text">請搜尋可管理的學生資料</div>
        </div>
        <div class="identity-selected-summary">已選 {{ selectedIdentityStudentIds.length }} / 2 筆</div>
        <div class="actions">
          <button type="button" class="ghost" @click="closeIdentityModal">取消</button>
          <button type="button" class="primary" :disabled="selectedIdentityStudentIds.length !== 2 || identitySaving" @click="linkIdentityStudents">
            {{ identitySaving ? '建立中…' : '建立身份關聯' }}
          </button>
        </div>

        <div class="identity-group-list" v-if="identityGroups.length">
          <h4>已建立的身份群組</h4>
          <div v-for="group in identityGroups" :key="group.id" class="identity-group-card">
            <div class="identity-group-head">
              <strong>{{ group.display_name || `身份群組 #${group.id}` }}</strong>
              <select v-model="group.mode" @change="updateIdentityMode(group)">
                <option value="off">關閉</option>
                <option value="readonly">唯讀試點</option>
                <option value="actions">開放操作</option>
              </select>
            </div>
            <div class="identity-group-members">
              <span v-for="member in group.members" :key="member.student_id" class="pp-campus-label">
                {{ member.name }} · {{ member.campus_name || `分校 ${member.campus_id}` }}
              </span>
            </div>
            <button type="button" class="small ghost" @click="loadIdentityAudit(group.id)">查看稽核紀錄</button>
          </div>
        </div>
        <pre v-if="identityAudit.length" class="identity-audit">{{ JSON.stringify(identityAudit, null, 2) }}</pre>
      </div>
    </div>
  </div>
  <div v-if="toastVisible" class="toast-notification">{{ toastMsg }}</div>
</template>

<script setup>
import { ref, onMounted, watch, computed, nextTick } from 'vue';
import { supabase } from '../supabase';
import { GRADES, SUBJECTS, getSubjectLabel as getSubjectText } from '../lib/constants';
import { fetchSubjectOptions } from '../lib/subjectsApi';
import { getPerSessionFee } from '../lib/coursePricing';
import { formatRenewSuccessMessage } from '../lib/studentClassDisplay.js';
import { fetchAllPages } from '../lib/pagedFetchAll';
import { createUniversalClassSchedule } from '../lib/universalSchedulerApi';
import { updatePackage } from '../lib/coursePackagesApi';
import CourseEditForm from '../components/CourseEditForm.vue';
import UniversalClassScheduler from '../components/UniversalClassScheduler.vue';
import EnrollmentConflictDecisionModal from '../components/EnrollmentConflictDecisionModal.vue';
import {
  buildForceOverrideFields,
  collectStudentCourses,
  findCourseForPurchase,
  normalizeActiveCourseConflicts,
} from '../lib/enrollmentConflictDecision';
import QuickAddSessionModal from '../components/course-management/QuickAddSessionModal.vue';
import RenewMonthlyModal from '../components/course-management/RenewMonthlyModal.vue';
import ToastWithUndo from '../components/substitute/ToastWithUndo.vue';
import PaymentEntryModal from '../components/PaymentEntryModal.vue';
import ReceiptModal from '../components/ReceiptModal.vue';
import LatestPaymentInfoModal from '../components/LatestPaymentInfoModal.vue';
import { useReceiptFlow } from '../composables/useReceiptFlow.js';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';
import AtFilterBar from '../components/design-system/AtFilterBar.vue';
import AtButton from '../components/design-system/AtButton.vue';
import AtIconButton from '../components/design-system/AtIconButton.vue';
import AtEmpty from '../components/design-system/AtEmpty.vue';

const props = defineProps({
  branchId: [String, Number],
  initialStudentId: [String, Number],
  initialCourseId: [String, Number],
  initialStudentIntent: String,
});
const emit = defineEmits(['navigate', 'clear-initial-student']);

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

// Cross-campus identity bridge (director/super_admin API; no auto-merge).
const showIdentityModal = ref(false);
const identityQuery = ref('');
const identityStudents = ref([]);
const identityGroups = ref([]);
const selectedIdentityStudentIds = ref([]);
const identityAudit = ref([]);
const identityLoading = ref(false);
const identitySaving = ref(false);
const identityError = ref('');

// Student modal
const showStudentModal = ref(false);
const editingStudentId = ref(null);
const studentForm = ref({ name: '', grade: 'J1', phone: '', school: '', parent_name: '', parent_phone: '', status: 'active', notes: '', latest_payment_note: '' });

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
const initialStudentFocusInFlight = ref(false);
const handledInitialFocusKey = ref(null);
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
const isPackageMember = (course) => Number(course?.PackageID ?? course?.package_id ?? 0) > 0;
const getPackageTotalSessions = (course) => {
  const total = Number(course?.package_total_sessions ?? course?.PackageTotalSessions ?? course?.sessions_purchased ?? 0);
  return Number.isFinite(total) && total > 0 ? total : 0;
};

// Grade promotion
const showGradePromotion = ref(false);

// Sessions modal
const showSessionsModal = ref(false);
const addSessionCount = ref(8);
const addSessionStartDate = ref(new Date().toISOString().slice(0, 10));
const showRenewMonthlyModal = ref(false);
const renewMonthlyTargetCourse = ref(null);
const renewMonthlyForm = ref({});

// --- Monthly Invoice Modal ---
const showInvoiceModal = ref(false);
const invoiceModalCourse = ref(null);
const invoiceModalList = ref([]);
const invoiceModalLoading = ref(false);
const selectedCourse = ref(null);

// Duplicate course intercept modal
const showDuplicateInterceptModal = ref(false);
const duplicateConflicts = ref([]);
const interceptPendingStudent = ref(null);
const interceptOriginalPayload = ref(null);
const interceptPendingClassType = ref('');
const forceSubmitting = ref(false);
const pendingPaymentStatusIds = ref(new Set());
const receiptFlow = useReceiptFlow({
  refreshCourses: (studentId) => loadStudentCourses(studentId),
  toast: (msg) => showToast(msg),
});
const {
  paymentEntryOpen,
  paymentEntryRow,
  receiptOpen,
  receiptReportId,
  latestPaymentOpen,
  latestPaymentCourse,
  openReceiptByReport,
  onPaymentEntryConfirmed,
} = receiptFlow;

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
  if (course?.payment_status === 'paid') return 'ghost';
  if (course?.payment_status === 'pending_report') return 'ghost';
  return 'primary';
};
const paymentStatusButtonLabel = (course) => {
  if (course?.payment_status === 'paid') return '已繳費';
  if (course?.payment_status === 'pending_report') return '待對帳';
  if (course?.payment_status === 'partial') return '部分繳';
  return '未繳費';
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
const coursePaymentSummary = (course) => course?.latest_payment_summary || null;
const formatPaymentSummary = (summary) => {
  if (!summary) return '';
  const parts = [];
  if (summary.payment_date) parts.push(`日期 ${summary.payment_date}`);
  if (summary.amount !== null && summary.amount !== undefined && summary.amount !== '') {
    parts.push(`金額 $${Number(summary.amount).toLocaleString('zh-TW')}`);
  }
  if (summary.account_last5) parts.push(`後5碼 ${summary.account_last5}`);
  if (summary.note) parts.push(`備註 ${summary.note}`);
  if (summary.status === 'pending') parts.push('待對帳');
  return parts.join(' · ') || '已有繳費回報';
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
/** 堂數制才顯示可驗證的進度；月結制不把月份或剩餘欄位誤換算成百分比。 */
const courseProgress = (course) => {
  if (String(course?.payment_type || '').toLowerCase() === 'monthly') return null;

  const total = parseCourseNumber(
    course?.PackageID ? course?.package_total_sessions : course?.sessions_purchased,
  );
  const remaining = parseCourseNumber(
    course?.PackageID
      ? course?.package_remaining_sessions
      : (course?.remaining_sessions ?? course?.RemainingSessions),
  );
  if (total == null || total <= 0 || remaining == null || remaining < 0) return null;

  const reportedUsed = parseCourseNumber(
    course?.PackageID
      ? course?.package_used_sessions
      : (course?.used_sessions ?? course?.sessions_used),
  );
  const used = Math.max(0, reportedUsed == null ? total - remaining : reportedUsed);
  const boundedUsed = Math.min(total, used);
  return {
    total,
    remaining,
    used: boundedUsed,
    percent: Math.min(100, Math.max(0, Math.round((boundedUsed / total) * 100))),
  };
};
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
  // 月結制課程 RemainingSessions 通常為 0（月結不扣堂），不可用 remaining ≤ 0 判斷歷史。
  // 月結課程只有明確停課（status=inactive，即 Stop=1）才視為歷史課程。
  if (String(course?.payment_type || '').toLowerCase() === 'monthly') {
    return String(course?.status || '').toLowerCase() === 'inactive';
  }
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
  // History is a detail disclosure inside an expanded student, so it must remain
  // available even when the top-level list is showing active courses only.
  return getStudentAllCourses(id).filter(c => isHistoryCourseByReason(c));
};
const getStudentHistoryCourseCount = (id) => (
  getStudentAllCourses(id).filter(c => isHistoryCourseByReason(c)).length
);
const selectedCourseIdByStudent = ref(new Map());
const hasCourseSchedule = (course) => (
  scheduleDisplayLines(course).length > 0
  || (Array.isArray(course?.days_of_week) && course.days_of_week.length > 0)
  || Boolean(course?.day_of_week)
);
const isCourseNeedsAttention = (course) => {
  const paymentStatus = String(course?.payment_status || '').toLowerCase();
  const courseStatus = String(course?.status || '').toLowerCase();
  return isSessionPaymentLowRemaining(course)
    || ['overdue', 'unpaid', 'pending'].includes(paymentStatus)
    || courseStatus === 'inactive'
    || !course?.teacher_name
    || !hasCourseSchedule(course)
    || !course?.branch_name
    || !course?.room_name;
};
const getCourseAttentionLabel = (course) => {
  if (isSessionPaymentLowRemaining(course)) return '需要續報';
  const paymentStatus = String(course?.payment_status || '').toLowerCase();
  if (['overdue', 'unpaid', 'pending'].includes(paymentStatus)) return '付款待確認';
  if (String(course?.status || '').toLowerCase() === 'inactive') return '已暫停';
  if (!course?.teacher_name || !hasCourseSchedule(course) || !course?.branch_name || !course?.room_name) {
    return '資料待確認';
  }
  return '進行中';
};
const getCoursePrimaryAction = (course) => {
  if (isSessionPaymentLowRemaining(course)) {
    return {
      key: 'renew',
      icon: 'add_circle',
      label: '續報加購',
      title: '先處理課程續報',
      description: `剩餘 ${getCourseRemainingSessions(course)} 堂，先補充堂數可避免後續排課中斷。`,
      tone: 'warning',
    };
  }
  const paymentStatus = String(course?.payment_status || '').toLowerCase();
  if (['overdue', 'unpaid', 'pending'].includes(paymentStatus)) {
    return {
      key: 'payment',
      icon: 'receipt_long',
      label: '查看繳費資訊',
      title: '先確認付款狀態',
      description: '付款狀態尚未確認，先查看繳費資訊再進行後續處理。',
      tone: 'warning',
    };
  }
  if (String(course?.status || '').toLowerCase() === 'inactive') {
    return {
      key: 'edit',
      icon: 'pause_circle',
      label: '查看課程設定',
      title: '先確認課程狀態',
      description: '這門課目前已暫停，請查看課程設定。',
      tone: 'warning',
    };
  }
  if (!course?.teacher_name || !hasCourseSchedule(course) || !course?.branch_name || !course?.room_name) {
    return {
      key: 'edit',
      icon: 'fact_check',
      label: '補齊課程資料',
      title: '先補齊課程資料',
      description: '老師、時段或地點尚未完整，請補齊後再安排後續工作。',
      tone: 'warning',
    };
  }
  return {
    key: 'edit',
    icon: 'edit',
    label: '編輯課程',
    title: '課程資料已齊全',
    description: '目前沒有待處理提醒；需要調整時可編輯課程。',
    tone: 'primary',
  };
};
const openCoursePrimaryAction = (course, studentName = '') => {
  const action = getCoursePrimaryAction(course);
  if (action.key === 'renew') return openAddSessionsForCourse(course);
  if (action.key === 'payment') return openLatestPaymentInfo(course, studentName);
  return editCourse(course);
};
const getCourseProgressSummary = (course) => {
  const progress = courseProgress(course);
  if (progress) return `剩餘 ${progress.remaining} / ${progress.total} 堂`;
  if (String(course?.payment_type || '').toLowerCase() === 'monthly') {
    const monthlySessions = parseCourseNumber(course?.monthly_sessions);
    return monthlySessions ? `月結 · 每月 ${monthlySessions} 堂` : '月結課程';
  }
  return '堂數待確認';
};
const getStudentCourseAttentionCount = (studentId) => (
  getActiveStudentCourses(studentId).filter(isCourseNeedsAttention).length
);
const restoreTableScroll = (scrollWrap, scrollLeft) => {
  if (!scrollWrap) return;
  nextTick(() => {
    scrollWrap.scrollLeft = scrollLeft;
    if (typeof window !== 'undefined' && typeof window.requestAnimationFrame === 'function') {
      window.requestAnimationFrame(() => { scrollWrap.scrollLeft = scrollLeft; });
    }
  });
};
const getFocusedStudentCourse = (studentId) => {
  const activeCourses = getActiveStudentCourses(studentId);
  if (!activeCourses.length) return null;
  const selectedId = selectedCourseIdByStudent.value.get(studentId);
  return activeCourses.find((course) => String(course.id) === String(selectedId))
    || activeCourses.find(isCourseNeedsAttention)
    || activeCourses[0];
};
const selectStudentCourse = (studentId, courseId, event) => {
  const scrollWrap = event?.currentTarget?.closest?.('.table-scroll-wrap');
  const scrollLeft = scrollWrap?.scrollLeft ?? 0;
  const selected = new Map(selectedCourseIdByStudent.value);
  selected.set(studentId, courseId);
  selectedCourseIdByStudent.value = selected;
  restoreTableScroll(scrollWrap, scrollLeft);
};
const expandedHistoryCourses = ref(new Set());
const toggleHistoryCourses = (studentId) => {
  const s = new Set(expandedHistoryCourses.value);
  if (s.has(studentId)) s.delete(studentId);
  else s.add(studentId);
  expandedHistoryCourses.value = s;
};

const canCloseCourse = (course) => {
  return ['session', 'monthly'].includes(String(course?.payment_type || '').toLowerCase())
    && isCourseSettled(course)
    && String(course?.status || '').toLowerCase() !== 'inactive';
};

async function closeCourseNoRenew(course, studentName) {
  const courseId = Number(course?.id ?? course?.ID ?? 0);
  if (!courseId) { alert('課程資料缺少識別碼，請重新整理後再試'); return; }
  const subject = getSubjectLabel(course?.subject);
  const remaining = Math.max(0, Number(getCourseRemainingSessions(course) ?? 0));
  const balanceWarning = remaining > 0
    ? `\n\n目前還有 ${remaining} 堂未使用。結案會取消未來排課，並放棄這 ${remaining} 堂剩餘額度。`
    : '';
  if (!confirm(`確定要結案「${studentName || '學生'}」的 ${subject} 課程嗎？${balanceWarning}\n\n結案後此課程不再出現在繳費／續課提醒中，已繳費與已上課紀錄仍會保留。`)) return;
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) { alert('請重新登入'); return; }
    const res = await fetch(`/api/v1/student-classes/${courseId}/pause`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({
        action: 'pause',
        reason: 'settled',
        ...(remaining > 0 ? { forfeit_remaining: true } : {}),
      }),
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
          remaining_sessions: c.remaining_sessions ?? c.RemainingSessions ?? null,
          sessions_used: c.sessions_used ?? c.UsedSessions ?? null,
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
          PackageID: c.PackageID ?? c.package_id ?? null,
          PackageName: c.PackageName ?? c.package_name ?? null,
          package_remaining_sessions: c.package_remaining_sessions ?? null,
          package_total_sessions: c.package_total_sessions ?? null,
          package_used_sessions: c.package_used_sessions ?? null,
          status: c.status ?? null,
          closed_reason: c.closed_reason ?? null,
          paid_at: c.paid_at ?? null,
          last_paid_at: c.last_paid_at ?? null,
          charge: c.Charge ?? c.charge ?? 0,
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
            sessions_used: c.sessions_used ?? c.UsedSessions ?? null,
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
            PackageID: c.PackageID ?? c.package_id ?? null,
            PackageName: c.PackageName ?? c.package_name ?? null,
            package_remaining_sessions: c.package_remaining_sessions ?? null,
            package_total_sessions: c.package_total_sessions ?? null,
            package_used_sessions: c.package_used_sessions ?? null,
            status: c.status ?? null,
            closed_reason: c.closed_reason ?? null,
            paid_at: c.paid_at ?? null,
            last_paid_at: c.last_paid_at ?? null,
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
const toggleExpand = async (student, event) => {
  const scrollWrap = event?.currentTarget?.closest?.('.table-scroll-wrap');
  const scrollLeft = scrollWrap?.scrollLeft ?? 0;
  if (expandedId.value === student.id) {
    expandedId.value = null;
  } else {
    expandedId.value = student.id;
    await loadStudentCourses(student.id);
  }
  restoreTableScroll(scrollWrap, scrollLeft);
};

const focusInitialStudent = async () => {
  const targetId = Number(props.initialStudentId);
  const targetCourseId = Number(props.initialCourseId);
  const focusKey = `${targetId}:${Number.isSafeInteger(targetCourseId) && targetCourseId > 0 ? targetCourseId : ''}:${props.initialStudentIntent || ''}`;
  if (!Number.isSafeInteger(targetId) || targetId <= 0 || initialStudentFocusInFlight.value || handledInitialFocusKey.value === focusKey) return;
  const student = students.value.find((candidate) => Number(candidate?._laravelId ?? candidate?.id ?? 0) === targetId);
  if (!student) return;

  initialStudentFocusInFlight.value = true;
  try {
    if (expandedId.value !== student.id) {
      expandedId.value = student.id;
      await loadStudentCourses(student.id);
    }
    await nextTick();
    const row = typeof document !== 'undefined'
      ? document.querySelector(`[data-student-id="${student.id}"]`)
      : null;
    row?.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
    const targetCourse = Number.isSafeInteger(targetCourseId) && targetCourseId > 0
      ? (studentCourses.value[student.id] || []).find((course) => Number(course?.id) === targetCourseId)
      : null;
    if (props.initialStudentIntent === 'edit' && targetCourse) {
      editCourse(targetCourse);
    }
    handledInitialFocusKey.value = focusKey;
    emit('clear-initial-student');
  } finally {
    initialStudentFocusInFlight.value = false;
  }
};

// --- Student CRUD ---
const openAddStudent = () => {
  editingStudentId.value = null;
  studentForm.value = { name: '', grade: 'J1', phone: '', school: '', parent_name: '', parent_phone: '', status: 'active', notes: '', latest_payment_note: '', rfid: '' };
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
    latest_payment_note: student.latest_payment_note || '',
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

const identityAuthHeaders = () => {
  const sess = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
  return {
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${sess?.access_token || ''}`,
  };
};

const loadIdentityData = async () => {
  identityLoading.value = true;
  identityError.value = '';
  try {
    const query = identityQuery.value.trim();
    const res = await fetch(`/api/v1/student-identities${query ? `?q=${encodeURIComponent(query)}` : ''}`, {
      headers: identityAuthHeaders(),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data?.message || '無法載入身份資料');
    identityStudents.value = data.students || [];
    identityGroups.value = data.groups || [];
  } catch (e) {
    identityError.value = e?.message || '無法載入身份資料';
  } finally {
    identityLoading.value = false;
  }
};

const openIdentityModal = async () => {
  showIdentityModal.value = true;
  selectedIdentityStudentIds.value = [];
  identityAudit.value = [];
  await loadIdentityData();
};

const closeIdentityModal = () => {
  showIdentityModal.value = false;
  selectedIdentityStudentIds.value = [];
  identityAudit.value = [];
};

const searchIdentityStudents = () => loadIdentityData();

const toggleIdentityCandidate = (studentId) => {
  const ids = selectedIdentityStudentIds.value;
  if (ids.includes(studentId)) {
    selectedIdentityStudentIds.value = ids.filter((id) => id !== studentId);
  } else if (ids.length < 2) {
    selectedIdentityStudentIds.value = [...ids, studentId];
  }
};

const linkIdentityStudents = async () => {
  if (selectedIdentityStudentIds.value.length !== 2 || identitySaving.value) return;
  identitySaving.value = true;
  identityError.value = '';
  try {
    const res = await fetch('/api/v1/student-identities/link', {
      method: 'POST',
      headers: identityAuthHeaders(),
      body: JSON.stringify({
        first_student_id: selectedIdentityStudentIds.value[0],
        second_student_id: selectedIdentityStudentIds.value[1],
      }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data?.message || Object.values(data?.errors || {}).flat().join('、') || '建立身份關聯失敗');
    selectedIdentityStudentIds.value = [];
    showToast('身份關聯已建立，預設為關閉狀態');
    await loadIdentityData();
  } catch (e) {
    identityError.value = e?.message || '建立身份關聯失敗';
  } finally {
    identitySaving.value = false;
  }
};

const updateIdentityMode = async (group) => {
  identityError.value = '';
  try {
    const res = await fetch(`/api/v1/student-identities/${group.id}/access`, {
      method: 'PUT',
      headers: identityAuthHeaders(),
      body: JSON.stringify({ mode: group.mode }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data?.message || '更新試點狀態失敗');
    showToast(`群組狀態已更新為 ${group.mode}`);
  } catch (e) {
    identityError.value = e?.message || '更新試點狀態失敗';
    await loadIdentityData();
  }
};

const loadIdentityAudit = async (groupId) => {
  try {
    const res = await fetch(`/api/v1/student-identities/${groupId}/audit`, { headers: identityAuthHeaders() });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data?.message || '無法載入稽核紀錄');
    identityAudit.value = data.audit || [];
  } catch (e) {
    identityError.value = e?.message || '無法載入稽核紀錄';
  }
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
            duplicateConflicts.value = normalizeActiveCourseConflicts(active);
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

async function onEnrollmentConflictDecision(decision) {
  const payload = interceptOriginalPayload.value;
  if (!payload) {
    showDuplicateInterceptModal.value = false;
    if (interceptPendingStudent.value) {
      proceedOpenAddCourse(interceptPendingStudent.value);
    }
    return;
  }
  forceSubmitting.value = true;
  try {
    const result = await createUniversalClassSchedule({
      ...payload,
      ...buildForceOverrideFields(decision),
    });
    showDuplicateInterceptModal.value = false;
    interceptOriginalPayload.value = null;
    const created = Number(result?.created_confirmed_sessions ?? 0) + Number(result?.created_future_sessions ?? 0);
    const label = decision?.force_reason === 'create_trial'
      ? '已建立試聽'
      : decision?.force_reason === 'renewal_next_term'
        ? '已建立下一期續報'
        : '已建立獨立課程';
    alert(`${label}（${created} 堂）`);
    await loadStudents();
  } catch (err) {
    alert(err?.message || '建立失敗，請稍後再試');
  } finally {
    forceSubmitting.value = false;
  }
}

const interceptGoToPurchase = (conflict) => {
  showDuplicateInterceptModal.value = false;
  const student = interceptPendingStudent.value;
  if (!student) {
    alert('找不到這位學生，請重新整理後再試。');
    return;
  }
  const target = findCourseForPurchase(
    collectStudentCourses(studentCourses.value, student),
    conflict,
  );
  if (target) {
    openAddSessionsForCourse(target);
    return;
  }
  alert('找不到要加購的課程，請重新整理後再從課程列點「加購」。');
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
  interceptPendingClassType.value = String(evt?.originalPayload?.class_type || '');
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
        alert(details || json?.message || '補課失敗');
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
    alert('補課失敗：' + (e?.message || '請稍後再試'));
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
  if (!isPackageMember(selectedCourse.value) && !addSessionStartDate.value) {
    alert('請選擇新批次開始日期');
    return;
  }

  try {
    if (isPackageMember(selectedCourse.value)) {
      const packageId = Number(selectedCourse.value.PackageID ?? selectedCourse.value.package_id);
      const addSessions = Number(addSessionCount.value);
      const currentTotal = getPackageTotalSessions(selectedCourse.value);
      const nextTotal = currentTotal + addSessions;
      if (!packageId || currentTotal <= 0) {
        alert('找不到方案總堂數，請先重新整理後再試');
        return;
      }
      await updatePackage(packageId, { total_sessions: nextTotal });
      showSessionsModal.value = false;
      await loadAllStudentCourses();
      if (selectedStudent.value?.id) {
        await loadStudentCourses(selectedStudent.value.id);
      }
      alert(`已加購共用方案堂數：總堂數由 ${currentTotal} 堂增加為 ${nextTotal} 堂。所有方案科目共用同一個堂數池。`);
      return;
    }

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
        start_date: addSessionStartDate.value,
        mode: 'new_purchase'
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
    const newCourse = json?.new_course || {};
    const studentName = selectedStudent.value?.name || '';
    alert(formatRenewSuccessMessage({
      kind: 'purchase',
      studentName,
      subject: selectedCourse.value?.subject_name || selectedCourse.value?.subject || '',
      sessions: newCourse.created_sessions,
      firstDate: newCourse.first_session_date || '',
      lastDate: newCourse.last_session_date || '',
    }));
  } catch (e) {
    alert('操作失敗：' + (e?.message || '請稍後再試'));
  }
};

const formatBillingPeriod = (period) => {
  if (!period || period.length < 7) return period;
  const [y, m] = period.split('-');
  return `${y}年${parseInt(m)}月`;
};

const openInvoiceModal = async (course) => {
  const studentName = students.value.find(s => s.id === course.student_id)?.name || '';
  invoiceModalCourse.value = { ...course, student_name: studentName };
  invoiceModalList.value = [];
  invoiceModalLoading.value = true;
  showInvoiceModal.value = true;

  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) return;

    const res = await fetch(`/api/v1/student-classes/${course.id}/invoices`, {
      credentials: 'include',
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    const json = await res.json().catch(() => ({}));
    if (res.ok) {
      invoiceModalList.value = json.invoices || [];
    }
  } catch (_) {
    // silent — modal shows empty state
  } finally {
    invoiceModalLoading.value = false;
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
    alert(formatRenewSuccessMessage({
      kind: 'monthly',
      studentName: selectedStudent.value?.name || students.value.find((s) => s.id === course.student_id)?.name || '',
      subject: course?.subject_name || course?.subject || '',
    }));
    await loadAllStudentCourses();
  } catch (e) {
    alert('續約失敗：' + (e?.message || '請稍後再試'));
  }
};

// --- Toggle Payment Status ---
const togglePaymentStatus = async (course, studentName = '') => {
  const courseId = course?.id;
  if (!courseId || isPaymentStatusPending(courseId)) return;

  if (course.payment_status === 'pending_report') {
    alert('此課程已有待對帳回報，請到帳務中心確認入帳或退回後再登錄。');
    return;
  }

  // 未繳費 → 已回報：走登記 Modal；確認入帳後才變已繳費
  if (course.payment_status !== 'paid') {
    const subjectLabel = getSubjectLabel(course.subject).split('(')[0].trim();
    receiptFlow.openPaymentEntry({
      id: courseId,
      student_name: studentName || '此學生',
      subject: subjectLabel || course.subject || '',
      charge: course.charge ?? 0,
    }, course.student_id ?? null);
    return;
  }

  // 已繳費 → 未繳費：保留原有 confirm 流程
  const subjectLabel = getSubjectLabel(course.subject).split('(')[0].trim();
  const targetLabel = studentName || '此學生';
  if (!confirm(`確定將「${targetLabel}」${subjectLabel ? `的${subjectLabel}課程` : '課程'}改為「未繳費」嗎？`)) return;

  setPaymentStatusPending(courseId, true);
  try {
    const { data: { session: sess } } = await supabase.auth.getSession();
    const token = sess?.access_token;
    if (!token) {
      alert('登入狀態已過期，請重新登入後再試。');
      return;
    }
    const res = await fetch(`/api/v1/student-classes/${courseId}`, {
      method: 'PUT',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ payment_status: 'unpaid', paid_at: null }),
    });
    // #799 阻擋＋導引：有收款入帳紀錄時後端回 409，提示去收費頁作廢，不再靜默回跳
    if (!res.ok) {
      const errBody = await res.json().catch(() => ({}));
      alert(errBody?.message || '改為未繳費失敗，請稍後再試。');
      return;
    }
    course.payment_status = 'unpaid';
    course.last_paid_at = null;
    course.paid_at = null;
  } catch (e) {
    alert('更新繳費狀態失敗：' + (e?.message || '請稍後再試'));
  } finally {
    setPaymentStatusPending(courseId, false);
  }
};

function openLatestPaymentInfo(course, studentName = '') {
  const subjectLabel = getSubjectLabel(course.subject).split('(')[0].trim();
  receiptFlow.openLatestPaymentInfo({
    id: course.id,
    student_name: studentName || '此學生',
    subject: subjectLabel || course.subject || '',
  });
}

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
      const msg = json?.error || json?.job?.ErrorLog || json?.message || '匯入失敗';
      alert(typeof msg === 'string' ? msg : '匯入失敗');
      return;
    }

    if (json?.job?.Status === 'failed') {
      const msg = json?.error || json?.job?.ErrorLog || json?.message || '匯入失敗';
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
watch(() => [props.initialStudentId, props.initialCourseId, props.initialStudentIntent], () => {
  handledInitialFocusKey.value = null;
  focusInitialStudent();
}, { immediate: true });
watch(students, focusInitialStudent, { flush: 'post' });
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
.close-btn { color: var(--ds-warning); border-color: var(--ds-warning); }
.close-btn:hover { background: var(--ds-warning-wash); }
.paid-date-hint { display: inline-block; font-size: 12px; color: var(--ds-success); margin-left: 4px; white-space: nowrap; }

/* ── Monthly Invoice Modal ── */
.invoice-modal-subtitle {
  font-size: 13px;
  color: var(--ds-ink-mute);
  margin-bottom: 16px;
}
.invoice-modal-loading {
  padding: 24px 0;
  text-align: center;
  color: var(--ds-ink-mute);
}
.invoice-modal-empty {
  padding: 16px 0;
  text-align: center;
  color: var(--ds-ink-mute);
  font-size: 14px;
}
.invoice-due-date-hint {
  font-size: 11px;
  color: var(--ds-ink-mute);
  display: block;
}
.sessions-near-empty-hint {
  font-size: 13px;
  color: var(--ds-warning);
}
.sessions-package-hint {
  color: var(--ds-warning);
  margin-bottom: 8px;
}
.duplicate-course-heading {
  color: var(--ds-warning);
}
.invoice-status-chip {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
}
.invoice-status-chip.paid    { background: var(--ds-success-wash); color: var(--ds-success); }
.invoice-status-chip.unpaid  { background: var(--ds-warning-wash); color: var(--ds-warning); }
.invoice-status-chip.partial { background: var(--ds-primary-wash); color: var(--ds-primary-deep); }
.invoice-skeleton {
  height: 20px;
  width: 100%;
  background: linear-gradient(90deg, var(--ds-canvas-soft) 25%, var(--ds-hairline) 50%, var(--ds-canvas-soft) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.2s infinite;
  border-radius: 4px;
  margin-bottom: 10px;
}
@keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

/* ═══ Ops page shell ═══ */
.students-page.at-ops-page {
  display: flex;
  flex-direction: column;
  gap: var(--ds-space-3, 12px);
}
.students-shell {
  background: var(--ds-surface-1, var(--ds-canvas));
  border: 1px solid var(--ds-hairline);
  border-radius: var(--ds-radius-lg, 8px);
  padding: var(--ds-space-4, 16px);
}
.button-outline {
  border: 1px solid var(--ds-hairline);
  padding: 0 12px;
  min-height: var(--ds-control-height-md, 32px);
  border-radius: var(--ds-radius-md, 6px);
  cursor: pointer;
  background: var(--ds-canvas);
  color: var(--ds-ink-secondary);
  font-size: 13px;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  transition: background-color var(--ds-motion-fast, 120ms) var(--ds-ease-standard, ease);
}
.button-outline:hover {
  background: var(--ds-canvas-soft);
  border-color: var(--ds-hairline-input);
  color: var(--ds-ink);
}
.button-outline:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px var(--ds-focus-ring);
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
  background: var(--ds-primary-wash);
  border: 1px solid var(--ds-hairline-input);
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
  color: var(--ds-primary-deep);
  font-variant-numeric: tabular-nums;
}
.bulk-btns {
  display: flex;
  gap: 6px;
}

/* ═══ Filter Bar ═══ */
.filter-bar {
  margin-bottom: 20px;
  background: var(--ds-canvas-soft);
  padding: 16px;
  border-radius: 10px;
  border: 1px solid var(--ds-hairline);
}
.filter-search { position: relative; }
.search-input-wrap { position: relative; }
.search-icon {
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 20px;
  color: var(--ds-ink-mute);
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
  accent-color: var(--ds-primary);
}
.student-row {
  cursor: pointer;
  transition: background 0.2s ease;
}
.student-row:hover td {
  background: var(--ds-primary-wash) !important;
}
.student-row.expanded td {
  background: var(--ds-primary-wash);
  border-bottom-color: var(--ds-primary);
}

/* Status left border on student rows.
   active/paused 對應 ds-success/ds-warning；graduated 藍、transferred 紫
   屬多態語意色（無對應 ds semantic token），維持 raw 待 token 擴充。*/
.student-row td:first-child {
  border-left: 3px solid transparent;
}
.student-row.status-active td:first-child { border-left-color: var(--ds-success); }
.student-row.status-graduated td:first-child { border-left-color: #1565c0; }
.student-row.status-paused td:first-child { border-left-color: var(--ds-warning); }
.student-row.status-transferred td:first-child { border-left-color: #7b1fa2; }

/* Expand icon */
.expand-icon {
  text-align: center;
  width: 30px;
}
.expand-chevron {
  font-size: 20px;
  color: var(--ds-ink-mute);
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
  color: var(--ds-on-primary);
  flex-shrink: 0;
  background: var(--ds-success);
}
/* graduated/transferred 屬多態語意色（無對應 ds token），維持 raw 待 token 擴充。
   移除原裝飾性 linear-gradient → 改用實色，與 RULE_DESIGN_SYSTEM §7 一致。 */
.student-avatar-mini.graduated { background: #1565c0; }
.student-avatar-mini.paused { background: var(--ds-warning); }
.student-avatar-mini.transferred { background: #7b1fa2; }

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
  padding: 2px 7px;
  border-radius: var(--ds-radius-sm, 4px);
  font-size: 12px;
  background: var(--ds-success-wash);
  color: var(--ds-success);
  white-space: nowrap;
  border: 1px solid transparent;
}
.subject-pill strong {
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.subject-pill.low {
  background: var(--ds-danger-wash);
  color: var(--ds-danger);
}
.table-scroll-wrap {
  border: 1px solid var(--ds-hairline);
  border-radius: var(--ds-radius-md, 6px);
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}
.table-scroll-wrap th {
  font-size: 12px;
  color: var(--ds-text-tertiary, var(--ds-ink-mute));
  background: var(--ds-surface-0, var(--ds-canvas-soft));
}
.table-scroll-wrap td {
  font-size: 13.5px;
  padding-top: 8px;
  padding-bottom: 8px;
}
.student-status-badge {
  border-radius: var(--ds-radius-sm, 4px) !important;
}

.note-icon {
  margin-left: 4px;
  cursor: help;
  color: var(--ds-warning);
}

.student-status-badge {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 8px;
  font-size: 11px;
  margin-left: 6px;
  font-weight: 600;
}
/* paused 對應 ds-warning；graduated 藍、transferred 紫無 ds token，維持 raw。 */
.student-status-badge.graduated { background: #E3F2FD; color: #1565C0; }
.student-status-badge.paused { background: var(--ds-warning-wash); color: var(--ds-warning); }
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
  color: var(--ds-primary);
  display: inline-flex;
  align-items: center;
  gap: 3px;
}
.rfid-unbound {
  font-size: 12px;
  color: var(--ds-ink-mute);
  display: inline-flex;
  align-items: center;
  gap: 3px;
}
.rfid-unbound-icon {
  font-size: 14px;
  vertical-align: middle;
  color: var(--ds-ink-mute);
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
  background: var(--ds-hairline);
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
  border: 2px solid var(--ds-hairline);
  background: var(--ds-canvas-soft);
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  color: var(--ds-ink-secondary);
  transition: all 0.2s;
  user-select: none;
}
.day-chip:hover {
  border-color: var(--ds-primary);
  background: var(--ds-primary-wash);
}
.day-chip.selected {
  border-color: var(--ds-primary-deep);
  background: var(--ds-primary);
  color: var(--ds-on-primary);
}

/* ═══ Course Detail Panel ═══ */
.course-detail-row td {
  padding: 0 !important;
  background: var(--ds-canvas-soft) !important;
}
.course-panel {
  padding: 20px 24px;
  border-left: 3px solid var(--ds-primary);
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
  color: var(--ds-primary);
  margin: 0;
}
.student-note-line {
  margin-bottom: 12px;
  font-size: 13px;
  color: var(--ds-ink-mute);
}
.student-note-label {
  font-weight: 700;
}
.student-latest-payment-note {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin-top: 8px;
  padding: 9px 10px;
  border: 1px solid var(--ds-hairline);
  border-radius: 8px;
  background: var(--ds-success-wash);
  color: var(--ds-ink-secondary);
  font-size: 12px;
  line-height: 1.5;
}
.student-latest-payment-note > .material-symbols-outlined {
  flex: 0 0 auto;
  color: var(--ds-success);
  font-size: 17px;
}
.student-latest-payment-note strong { color: var(--ds-success); }
.student-latest-payment-note p { margin: 2px 0; white-space: pre-wrap; word-break: break-word; }
.student-latest-payment-note small { color: var(--ds-ink-mute); }
.student-course-workspace {
  display: grid;
  gap: 12px;
}
.student-course-overview {
  background: var(--ds-canvas-soft);
  border: 1px solid var(--ds-hairline);
  border-radius: 12px;
  padding: 16px;
}
.student-course-overview__header {
  align-items: flex-start;
  display: flex;
  gap: 16px;
  justify-content: space-between;
}
.student-course-overview__eyebrow {
  color: var(--ds-primary-deep);
  display: block;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.04em;
  margin-bottom: 2px;
}
.student-course-overview h5 {
  color: var(--ds-ink);
  font-size: 16px;
  line-height: 1.4;
  margin: 0;
}
.student-course-overview__hint {
  color: var(--ds-ink-mute);
  font-size: 12px;
  line-height: 1.5;
  text-align: right;
}
.student-course-overview__metrics {
  border-bottom: 1px solid var(--ds-hairline);
  display: grid;
  gap: 8px;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  margin-top: 14px;
  padding-bottom: 14px;
}
.student-course-overview__metric {
  align-items: baseline;
  color: var(--ds-ink-mute);
  display: flex;
  gap: 6px;
  min-width: 0;
}
.student-course-overview__metric strong {
  color: var(--ds-ink);
  font-size: 20px;
  font-variant-numeric: tabular-nums;
  line-height: 1.2;
}
.student-course-overview__metric span {
  font-size: 12px;
  white-space: nowrap;
}
.student-course-overview__metric--attention strong,
.student-course-overview__metric--attention span {
  color: var(--ds-warning);
}
.student-course-picker {
  display: grid;
  gap: 8px;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  margin-top: 12px;
}
.student-course-picker__item {
  min-width: 0;
}
.student-course-picker__button {
  align-items: center;
  background: var(--ds-canvas);
  border: 1px solid var(--ds-hairline);
  border-radius: 8px;
  color: var(--ds-ink);
  cursor: pointer;
  display: grid;
  gap: 3px 8px;
  grid-template-columns: minmax(0, 1fr) auto;
  min-height: 68px;
  padding: 10px 12px;
  text-align: left;
  transition: border-color var(--ds-motion-fast, 120ms) var(--ds-ease-standard, ease), background-color var(--ds-motion-fast, 120ms) var(--ds-ease-standard, ease), box-shadow var(--ds-motion-fast, 120ms) var(--ds-ease-standard, ease);
  width: 100%;
}
.student-course-picker__button:hover {
  background: var(--ds-canvas);
  border-color: var(--ds-primary);
}
.student-course-picker__button:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px var(--ds-focus-ring);
}
.student-course-picker__item--selected .student-course-picker__button {
  background: var(--ds-primary-wash);
  border-color: var(--ds-primary);
  box-shadow: inset 3px 0 0 var(--ds-primary);
}
.student-course-picker__item--attention:not(.student-course-picker__item--selected) .student-course-picker__button {
  border-color: var(--ds-warning);
}
.student-course-picker__subject {
  font-size: 13px;
  font-weight: 800;
  min-width: 0;
  overflow-wrap: anywhere;
}
.student-course-picker__status {
  color: var(--ds-ink-mute);
  font-size: 11px;
  font-weight: 700;
  white-space: nowrap;
}
.student-course-picker__item--attention .student-course-picker__status {
  color: var(--ds-warning);
}
.student-course-picker__detail {
  color: var(--ds-ink-secondary);
  font-size: 12px;
  font-variant-numeric: tabular-nums;
  grid-column: 1;
}
.student-course-picker__chevron {
  color: var(--ds-ink-mute);
  grid-column: 2;
  grid-row: 1 / span 2;
}
.student-course-detail {
  display: grid;
  gap: 10px;
}
.student-course-detail__heading {
  align-items: baseline;
  display: flex;
  gap: 16px;
  justify-content: space-between;
  padding: 2px 2px 0;
}
.student-course-detail__eyebrow {
  color: var(--ds-primary-deep);
  display: block;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.04em;
  margin-bottom: 2px;
}
.student-course-detail__heading h5 {
  color: var(--ds-ink);
  font-size: 15px;
  line-height: 1.4;
  margin: 0;
}
.student-course-detail__hint {
  color: var(--ds-ink-mute);
  font-size: 12px;
  line-height: 1.5;
  text-align: right;
}
.student-course-cards {
  display: grid;
  gap: 12px;
}
.student-course-card {
  background: var(--ds-canvas);
  border: 1px solid var(--ds-hairline);
  border-radius: 12px;
  box-shadow: var(--ds-shadow-1);
  padding: 16px;
}
.student-course-card--attention {
  border-color: var(--ds-warning);
  box-shadow: 0 0 0 1px var(--ds-warning-wash), var(--ds-shadow-1);
}
.student-course-card--focused {
  box-shadow: 0 0 0 2px var(--ds-primary-wash), var(--ds-shadow-1);
}
.student-course-card--focused.student-course-card--attention {
  box-shadow: 0 0 0 2px var(--ds-warning-wash), var(--ds-shadow-1);
}
.student-course-card__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}
.student-course-card__identity {
  min-width: 0;
}
.student-course-card__eyebrow {
  display: block;
  color: var(--ds-ink-mute);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.04em;
  margin-bottom: 2px;
}
.student-course-card__identity h5 {
  color: var(--ds-ink);
  font-size: 17px;
  line-height: 1.35;
  margin: 0;
  overflow-wrap: anywhere;
}
.student-course-card__badges {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 7px;
}
.student-course-card__badges .tag-expiring,
.student-course-card__badges .tag-paused-sm {
  margin-left: 0;
}
.student-course-card__primary {
  align-items: center;
  flex: 0 0 auto;
  gap: 6px;
  min-height: 44px;
  min-width: 112px;
  white-space: nowrap;
}
.student-course-card__primary .material-symbols-outlined {
  font-size: 18px;
}
.student-course-card__next-step {
  align-items: flex-start;
  background: var(--ds-canvas-soft);
  border: 1px solid var(--ds-hairline);
  border-radius: 10px;
  color: var(--ds-ink-secondary);
  display: grid;
  gap: 10px;
  grid-template-columns: auto minmax(0, 1fr);
  margin-top: 14px;
  padding: 12px 14px;
}
.student-course-card__next-step--attention {
  background: var(--ds-warning-wash);
  border-color: var(--ds-warning-wash);
}
.student-course-card__next-step > .material-symbols-outlined {
  color: var(--ds-primary);
  font-size: 21px;
  margin-top: 1px;
}
.student-course-card__next-step--attention > .material-symbols-outlined {
  color: var(--ds-warning);
}
.student-course-card__next-step-label {
  color: var(--ds-ink-mute);
  display: block;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.04em;
  line-height: 1.4;
}
.student-course-card__next-step strong {
  color: var(--ds-ink);
  display: block;
  font-size: 14px;
  line-height: 1.4;
  margin-top: 1px;
}
.student-course-card__next-step p {
  color: var(--ds-ink-secondary);
  font-size: 12px;
  line-height: 1.5;
  margin: 3px 0 0;
}
.student-course-card__progress {
  margin-top: 16px;
}
.student-course-card__progress-head {
  align-items: baseline;
  color: var(--ds-ink-secondary);
  display: flex;
  font-size: 13px;
  justify-content: space-between;
  gap: 12px;
}
.student-course-card__progress-head strong,
.student-course-card__progress-caption,
.student-course-card__money {
  font-variant-numeric: tabular-nums;
}
.student-course-card__progress-head strong {
  color: var(--ds-ink);
  font-size: 14px;
}
.student-course-card__progress-track {
  background: var(--ds-canvas-soft);
  border: 1px solid var(--ds-hairline);
  border-radius: 999px;
  height: 10px;
  margin-top: 8px;
  overflow: hidden;
}
.student-course-card__progress-fill {
  background: var(--ds-primary);
  border-radius: inherit;
  display: block;
  height: 100%;
  transition: width 180ms ease;
}
.student-course-card--attention .student-course-card__progress-fill {
  background: var(--ds-warning);
}
.student-course-card__progress-caption {
  color: var(--ds-ink-mute);
  display: block;
  font-size: 12px;
  margin-top: 5px;
}
.student-course-card__progress-empty,
.student-course-card__cadence {
  align-items: center;
  background: var(--ds-canvas-soft);
  border-radius: 8px;
  color: var(--ds-ink-secondary);
  display: flex;
  gap: 7px;
  font-size: 13px;
  line-height: 1.5;
  margin-top: 16px;
  padding: 10px 12px;
}
.student-course-card__progress-empty {
  color: var(--ds-warning);
}
.student-course-card__cadence .material-symbols-outlined {
  color: var(--ds-primary);
  font-size: 19px;
}
.student-course-card__meta {
  border-top: 1px solid var(--ds-hairline);
  display: grid;
  gap: 12px 18px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  margin: 16px 0 0;
  padding-top: 14px;
}
.student-course-card__meta > div {
  min-width: 0;
}
.student-course-card__meta dt {
  color: var(--ds-ink-mute);
  font-size: 11px;
  font-weight: 700;
  margin-bottom: 2px;
}
.student-course-card__meta dd {
  color: var(--ds-ink-secondary);
  font-size: 13px;
  line-height: 1.45;
  margin: 0;
  overflow-wrap: anywhere;
}
.student-course-card__money {
  color: var(--ds-ink) !important;
  font-weight: 700;
}
.student-course-card__payment {
  border: 1px solid currentColor;
  border-radius: 999px;
  display: inline-block;
  font-size: 12px;
  font-weight: 700;
  line-height: 1.3;
  padding: 3px 8px;
}
.student-course-card__payment--paid {
  background: var(--ds-success-wash);
  color: var(--ds-success);
}
.student-course-card__payment--unpaid,
.student-course-card__payment--pending {
  background: var(--ds-warning-wash);
  color: var(--ds-warning);
}
.student-course-card__payment--overdue {
  background: var(--ds-danger-wash);
  color: var(--ds-danger);
}
.student-course-card__context {
  background: var(--ds-canvas-soft);
  border-left: 3px solid var(--ds-hairline-input);
  color: var(--ds-ink-secondary);
  display: grid;
  font-size: 12px;
  gap: 4px;
  line-height: 1.5;
  margin-top: 14px;
  padding: 9px 12px;
}
.student-course-card__context strong {
  color: var(--ds-ink-mute);
  margin-right: 7px;
}
.student-course-card__actions {
  border-top: 1px solid var(--ds-hairline);
  margin-top: 14px;
  padding-top: 10px;
}
.student-course-card__actions summary {
  color: var(--ds-primary-deep);
  cursor: pointer;
  font-size: 13px;
  font-weight: 700;
  min-height: 32px;
  padding: 6px 0;
}
.student-course-card__actions summary:focus-visible {
  border-radius: 4px;
  outline: 3px solid var(--ds-primary-wash);
  outline-offset: 2px;
}
.student-course-card__actions-body {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding-top: 10px;
}
.student-course-card__actions-body button {
  min-height: 44px;
}
.course-inner-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 14px;
}
.course-inner-table th {
  background: var(--ds-canvas-soft);
  font-size: 12px;
  padding: 9px 8px;
}
.course-inner-table td {
  padding: 10px 8px;
  border-bottom: 1px solid var(--ds-hairline);
}
/* one_on_one 對應 ds-primary（1對1=主打）、tutoring 對應 ds-success；
   1對2/1對3/trial 屬多態語意色（無對應 ds token），維持 raw。 */
.status-tag.one_on_one { background: var(--ds-primary-wash); color: var(--ds-primary-deep); }
.status-tag.one_on_two { background: #FFF8E1; color: #F57F17; }
.status-tag.one_on_three { background: #FBE9E7; color: #BF360C; }
.status-tag.tutoring { background: var(--ds-success-wash); color: var(--ds-success); }
.status-tag.trial { background: #E8EAF6; color: #3949AB; }
.course-memo-line {
  margin-top: 4px;
  font-size: 12px;
  color: var(--ds-ink-mute);
  line-height: 1.4;
  word-break: break-word;
}
.course-payment-summary {
  margin-top: 4px;
  font-size: 12px;
  color: var(--ds-success);
  line-height: 1.45;
  word-break: break-word;
}
.course-payment-summary__label { font-weight: 800; }

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
  color: var(--ds-primary);
  margin: 16px 0 8px 0;
  padding-bottom: 4px;
  border-bottom: 1px solid var(--ds-hairline);
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
  border: 1px solid var(--ds-hairline-input);
  border-radius: 6px;
}
.rfid-bind-row input[readonly] {
  background: var(--ds-canvas-soft);
  color: var(--ds-ink);
  cursor: default;
}
.required {
  color: var(--ds-danger);
}

/* ═══ Cost Preview ═══ */
.cost-preview {
  background: var(--ds-primary-wash);
  border: 1px solid var(--ds-hairline-input);
  border-radius: 10px;
  padding: 16px;
  text-align: center;
  margin-top: 16px;
}
.cost-preview-label {
  font-size: 12px;
  color: var(--ds-ink-secondary);
  font-weight: 600;
}
.cost-preview-value {
  font-size: 28px;
  font-weight: 800;
  color: var(--ds-primary);
  margin: 4px 0;
  font-variant-numeric: tabular-nums;
}
.cost-preview-formula {
  font-size: 12px;
  color: var(--ds-ink-mute);
  font-variant-numeric: tabular-nums;
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
  .course-panel {
    box-sizing: border-box;
    padding: 16px;
    max-width: calc(100vw - 32px);
    width: calc(100vw - 32px);
  }
  .student-course-overview {
    padding: 14px;
  }
  .student-course-overview__header {
    display: block;
  }
  .student-course-overview__hint {
    display: block;
    margin-top: 4px;
    text-align: left;
  }
  .student-course-detail__heading {
    align-items: flex-start;
    display: block;
  }
  .student-course-detail__hint {
    display: block;
    margin-top: 4px;
    text-align: left;
  }
  .student-course-overview__metrics {
    gap: 4px;
  }
  .student-course-overview__metric {
    align-items: flex-start;
    display: flex;
    flex-direction: column;
    gap: 1px;
  }
  .student-course-picker {
    grid-template-columns: minmax(0, 1fr);
  }
  .student-course-card__header {
    display: block;
  }
  .student-course-card__primary {
    margin-top: 12px;
    width: 100%;
  }
  .student-course-card__meta {
    grid-template-columns: minmax(0, 1fr);
  }
  .student-course-card__actions-body button {
    flex: 1 1 calc(50% - 8px);
  }
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

/* tag-package 紫色屬包套餐多態語意色（無對應 ds token），維持 raw 待 token 擴充。 */
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
  background: var(--ds-warning-wash);
  color: var(--ds-warning);
  border: 1px solid var(--ds-warning);
  border-radius: 6px;
  font-size: 11px;
  padding: 2px 7px;
  font-weight: 600;
  margin-left: 4px;
}
.tag-expiring {
  background: var(--ds-warning-wash);
  color: var(--ds-warning);
  border: 1px solid var(--ds-warning);
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
  background: var(--ds-warning) !important;
  color: var(--ds-on-primary) !important;
  border: 1px solid var(--ds-warning) !important;
  font-weight: 600;
  border-radius: 6px;
  padding: 4px 10px;
  font-size: 12px;
  cursor: pointer;
}
.btn-renew-warn:hover {
  filter: brightness(0.92);
}

/* ── Empty active courses ── */
.sl-empty-active {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 20px 16px;
  color: var(--ds-ink-mute);
  font-size: 14px;
}
.sl-empty-active__icon {
  font-size: 24px;
  color: var(--ds-hairline-input);
}

/* ── History section ── */
.sl-history-section {
  border-top: 1px dashed var(--ds-hairline);
  margin-top: 8px;
  background: var(--ds-canvas-soft);
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
  color: var(--ds-ink-secondary);
  cursor: pointer;
  transition: background 0.15s;
}
.sl-history-toggle:hover {
  background: var(--ds-hairline);
}
.sl-history-toggle__icon {
  font-size: 18px;
  color: var(--ds-ink-mute);
}
.sl-history-toggle__count {
  font-weight: 400;
  font-size: 12px;
  color: var(--ds-ink-mute);
}
.sl-history-toggle__chevron {
  margin-left: auto;
  font-size: 11px;
  color: var(--ds-ink-mute);
}
.sl-history-body {
  padding: 4px 14px 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.sl-history-card {
  background: var(--ds-canvas);
  border: 1px solid var(--ds-hairline);
  border-left: 3px solid var(--ds-hairline-input);
  border-radius: 10px;
  padding: 10px 14px;
  transition: box-shadow 0.15s;
}
.sl-history-card:hover {
  box-shadow: var(--ds-shadow-1);
}
.sl-history-card__header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
}
.sl-history-card__subject {
  background: var(--ds-canvas-soft) !important;
  color: var(--ds-ink-secondary) !important;
  border: 1px solid var(--ds-hairline-input) !important;
}
.sl-tag-history {
  border-radius: 6px;
  font-size: 11px;
  padding: 2px 8px;
  font-weight: 700;
  letter-spacing: 0.02em;
}
.sl-tag-history--settled {
  background: var(--ds-success-wash);
  color: var(--ds-success);
  border: 1px solid var(--ds-success);
}
/* completed 藍屬多態語意色（無對應 ds token），維持 raw 待 token 擴充。 */
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
  color: var(--ds-ink-mute);
}
.sl-history-card__label {
  font-weight: 600;
  color: var(--ds-ink-mute);
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

/* LINE bound badge in student list.
   #06C755 為 LINE 官方品牌色（third-party brand），不可替換為 ds token。 */
.line-bound-badge {
  display: inline-flex;
  align-items: center;
  background: #06C755;
  color: var(--ds-on-primary);
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
  color: var(--ds-ink-mute);
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
  background: var(--ds-canvas-soft);
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
  color: var(--ds-ink-mute);
  font-size: 12px;
  flex: 1;
  text-align: right;
}
.line-binding-remove {
  background: none;
  border: 1px solid var(--ds-danger);
  color: var(--ds-danger);
  font-size: 12px;
  padding: 2px 10px;
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.15s;
}
.line-binding-remove:hover {
  background: var(--ds-danger);
  color: var(--ds-on-primary);
}

/* Toast notification */
.toast-notification {
  position: fixed;
  bottom: 32px;
  left: 50%;
  transform: translateX(-50%);
  background: var(--ds-ink);
  color: var(--ds-on-primary);
  padding: 10px 24px;
  border-radius: 8px;
  font-size: 14px;
  z-index: 10001;
  box-shadow: var(--ds-shadow-2);
  animation: toast-in 0.25s ease;
}
@keyframes toast-in {
  from { opacity: 0; transform: translateX(-50%) translateY(12px); }
  to   { opacity: 1; transform: translateX(-50%) translateY(0); }
}

/* Dark mode override：只剩無 ds token 的多態色（completed 藍 / tag-package 紫）。
   其餘 history section / card / empty 已改用 --ds-* 自動適應 dark mode（styles.css 已定義 [data-theme="dark"] 變體），原 override 為 token 化前殘留，全部移除。 */
[data-theme="dark"] .sl-tag-history--completed {
   background: #172554;
  color: #60a5fa;
  border-color: #1e40af;
}
.modal-header-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.identity-search-row { display: flex; gap: 8px; margin: 14px 0 8px; }
.identity-search-row input { flex: 1; min-width: 0; }
.identity-candidate-list { max-height: 190px; overflow: auto; border: 1px solid var(--ds-border); border-radius: 8px; }
.identity-candidate { width: 100%; display: flex; justify-content: space-between; gap: 12px; padding: 10px 12px; border: 0; border-bottom: 1px solid var(--ds-border); background: var(--ds-surface); color: var(--ds-ink); text-align: left; cursor: pointer; }
.identity-candidate:last-child { border-bottom: 0; }
.identity-candidate.selected { background: var(--ds-success-wash); }
.identity-candidate small { color: var(--ds-ink-mute); white-space: nowrap; }
.identity-selected-summary { margin-top: 8px; color: var(--ds-ink-mute); font-size: 13px; }
.identity-group-list { margin-top: 18px; border-top: 1px solid var(--ds-border); padding-top: 14px; }
.identity-group-card { border: 1px solid var(--ds-border); border-radius: 8px; padding: 10px; margin-top: 8px; }
.identity-group-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.identity-group-head select { min-height: 32px; }
.identity-group-members { display: flex; gap: 6px; flex-wrap: wrap; margin: 8px 0; }
.identity-audit { max-height: 180px; overflow: auto; margin-top: 10px; padding: 10px; background: var(--ds-surface-2); border-radius: 6px; font-size: 11px; }
</style>
