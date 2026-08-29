<template>
  <div>
    <template v-if="branchId == null">
      <div class="card no-branch-card enterprise-empty">
        <h2>尚無分校資料</h2>
        <p>系統尚未載入您的分校權限，或您尚未被指派到任何分校。</p>
        <p class="hint">請聯繫系統管理員設定您的分校權限後重新整理頁面。</p>
      </div>
    </template>

    <template v-else>
      <main class="director-workbench-v2" aria-labelledby="director-workbench-v2-title">
        <header class="director-workbench-v2__header">
          <div class="director-workbench-v2__heading">
            <h1 id="director-workbench-v2-title">主任總覽</h1>
            <p>{{ branchName }} <span aria-hidden="true">·</span> {{ todayDisplay }}</p>
          </div>
          <div class="director-workbench-v2__header-actions">
            <span class="director-workbench-v2__updated" role="status">
              {{ dashboardLoading ? '更新中…' : (dashboardLastUpdated ? `更新於 ${dashboardLastUpdated}` : '尚未更新') }}
            </span>
            <button
              type="button"
              class="director-workbench-v2__refresh"
              :disabled="dashboardLoading"
              @click="refreshDashboard"
            >
              <span class="material-symbols-outlined" aria-hidden="true">refresh</span>
              重新整理
            </button>
          </div>
        </header>

        <nav class="director-workbench-v2__nav" role="tablist" aria-label="總覽檢視模式">
          <button
            type="button"
            role="tab"
            id="director-workbench-tab-focus"
            aria-controls="director-workbench-panel-focus"
            :aria-selected="dashboardViewMode === 'focus'"
            :class="{ 'is-active': dashboardViewMode === 'focus' }"
            @click="setDashboardViewMode('focus')"
          >
            今天
          </button>
          <button
            type="button"
            role="tab"
            id="director-workbench-tab-full"
            aria-controls="director-workbench-panel-full"
            :aria-selected="dashboardViewMode === 'full'"
            :class="{ 'is-active': dashboardViewMode === 'full' }"
            @click="setDashboardViewMode('full')"
          >
            完整營運
          </button>
        </nav>

        <section
          v-if="dashboardViewMode === 'focus'"
          id="director-workbench-panel-focus"
          class="director-workbench-v2__focus"
          role="tabpanel"
          aria-label="今天的主任工作"
          aria-labelledby="director-workbench-tab-focus"
          tabindex="0"
        >
          <TodayProgressCard
            :completed="attendedCount"
            :total="todaySchedules.length"
            :next-task="dashboardPrimaryTasks[0] || null"
            :loading="dashboardLoading"
            @next="openDashboardTask"
          />

          <section class="director-workbench-v2__primary surface-panel" aria-labelledby="director-focus-title">
            <header class="surface-panel__header">
              <div>
                <h2 id="director-focus-title">今天要處理的事</h2>
                <p>只列出需要主任做決定或跟進的工作，依影響與期限排序。</p>
              </div>
              <span class="surface-panel__count">{{ dashboardPrimaryTasks.length }} 項</span>
            </header>

            <details v-if="!dashboardLoading && dashboardTopRisks.length" class="director-risk-disclosure">
              <summary>
                <span>查看為什麼這些工作排在前面</span>
                <strong>{{ dashboardTopRisks.length }} 項高優先</strong>
              </summary>
              <section class="director-top-risks" aria-labelledby="director-top-risks-title">
                <h3 id="director-top-risks-title">排序依據</h3>
                <ol>
                  <li v-for="risk in dashboardTopRisks" :key="`risk-${risk.id}`" :class="`director-top-risks__item director-top-risks__item--${risk.severity}`">
                    <strong>{{ risk.title }}</strong>
                    <span>{{ risk.summary }}</span>
                  </li>
                </ol>
              </section>
            </details>

            <div v-if="dashboardLoading" class="director-task-list" aria-live="polite" aria-label="正在載入今日待辦">
              <div v-for="n in 3" :key="n" class="director-task director-task--loading" aria-hidden="true">
                <span class="director-skeleton__index"></span>
                <span class="director-skeleton__body">
                  <span class="director-skeleton__title"></span>
                  <span class="director-skeleton__copy"></span>
                  <span class="director-skeleton__meta"></span>
                </span>
                <span class="director-skeleton__action"></span>
              </div>
            </div>
            <div v-else-if="dashboardPrimaryError" class="director-state director-state--error" role="alert">
              <strong>今日資料暫時無法載入</strong>
              <span>{{ dashboardPrimaryError }}</span>
              <button type="button" class="text-action" @click="refreshDashboard">再試一次</button>
            </div>
            <div v-else-if="!dashboardPrimaryTasks.length" class="director-state">
              <span class="material-symbols-outlined" aria-hidden="true">task_alt</span>
              <strong>今天沒有需要主任處理的事</strong>
              <span>課務與待辦都已經有明確狀態。</span>
            </div>
            <ol v-else class="director-task-list">
              <li
                v-for="(task, index) in dashboardPrimaryTasks"
                :key="task.id"
                class="director-task"
                :class="`director-task--${task.severity}`"
              >
                <span class="director-task__index" aria-hidden="true">{{ String(index + 1).padStart(2, '0') }}</span>
                <div class="director-task__body">
                  <div class="director-task__title-row">
                    <h3>{{ task.title }}</h3>
                    <strong v-if="task.count" class="director-task__count">{{ task.count }}</strong>
                  </div>
                  <p>{{ task.summary }}</p>
                  <div class="director-task__meta">
                    <span>{{ task.owner }}</span>
                    <span>{{ task.dueAt }}</span>
                  </div>
                </div>
                <button type="button" class="director-task__action" @click="openDashboardTask(task)">
                  {{ task.actionLabel }}
                  <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                </button>
              </li>
            </ol>

            <button
              v-if="dashboardAdditionalTaskCount"
              type="button"
              class="director-workbench-v2__more"
              @click="setDashboardViewMode('full')"
            >
              查看其他 {{ dashboardAdditionalTaskCount }} 項營運工作
              <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
            </button>
          </section>

          <aside class="director-workbench-v2__aside" aria-label="今日摘要">
            <section class="surface-panel surface-panel--summary" aria-labelledby="director-summary-title">
              <header class="surface-panel__header">
                <div>
                  <h2 id="director-summary-title">今日摘要</h2>
                  <p>只保留幫助判斷下一步的數字</p>
                </div>
              </header>
              <dl class="director-summary-list">
                <div><dt :title="DASHBOARD_SUMMARY_DEFINITIONS.todaySchedules">今日課程<span class="material-symbols-outlined director-summary-list__info" aria-hidden="true">info</span></dt><dd>{{ todaySchedules.length }}</dd><small>{{ attendedCount }} 堂已完成</small></div>
                <div><dt :title="DASHBOARD_SUMMARY_DEFINITIONS.pendingEvaluations">待審評量<span class="material-symbols-outlined director-summary-list__info" aria-hidden="true">info</span></dt><dd>{{ pendingEvaluations.length }}</dd><small>需要確認的紀錄</small></div>
                <div><dt :title="DASHBOARD_SUMMARY_DEFINITIONS.unreadNotifications">未讀通知<span class="material-symbols-outlined director-summary-list__info" aria-hidden="true">info</span></dt><dd>{{ unreadNotificationCount }}</dd><small>通知中心待查看</small></div>
                <div><dt :title="DASHBOARD_SUMMARY_DEFINITIONS.workflowDaily">今日工作量<span class="material-symbols-outlined director-summary-list__info" aria-hidden="true">info</span></dt><dd>{{ workflowDailySummary.due_total }}</dd><small>已完成 {{ workflowDailySummary.done_total }} 件</small></div>
              </dl>
            </section>

            <details v-if="operationsTrust?.decision_center" class="director-trust-note">
              <summary>
                <span>課表可信度</span>
                <strong :class="`director-trust-note__score--${decisionCenter.status}`">{{ decisionCenter.score }}/{{ decisionCenter.max }}</strong>
              </summary>
              <p>{{ decisionCenter.headline }}</p>
              <div v-if="trustDecisions.length" ref="trustDecisionsEl" class="director-trust-note__list">
                <article v-for="item in trustDecisions" :key="item.key" class="director-trust-note__item">
                  <div><strong>{{ trustDecisionTitle(item) }}</strong><p>{{ item.why }}</p></div>
                  <button type="button" class="text-action" @click="handleTrustDecision(item)">{{ item.action_label }}</button>
                </article>
              </div>
            </details>
          </aside>
        </section>

        <section
          v-else
          id="director-workbench-panel-full"
          class="director-workbench-v2__full"
          role="tabpanel"
          aria-labelledby="director-workbench-tab-full"
          tabindex="0"
        >
          <header class="director-workbench-v2__subheader">
            <div><h2 id="director-full-title">完整營運</h2><p>需要查完整課表、案件、帳務與分析時，再從這裡展開。</p></div>
            <span v-if="secondaryLoading" class="director-workbench-v2__updated" role="status">載入營運資料中…</span>
          </header>

          <details class="director-secondary-tools" @toggle="secondaryToolsOpen = $event.currentTarget.open">
            <summary>
              <span class="director-secondary-tools__title">營運指標與常用入口</span>
              <span class="director-secondary-tools__hint">評量完成率、收款、排課與調課</span>
              <span class="material-symbols-outlined" aria-hidden="true">expand_more</span>
            </summary>
            <div v-if="secondaryToolsOpen" class="director-secondary-tools__body">
              <section class="surface-panel director-fill-rate-panel" aria-label="老師評量完成率">
                <TeacherAssessmentFillRateCard
                  :branch-id="branchId"
                  :fetch-report="fetchTeacherAssessmentFillRates"
                  @view-learning="emit('navigate', { target: 'learning' })"
                />
              </section>
              <OperationsQuickStart
                compact
                eyebrow="常用流程"
                heading="直接前往工作入口"
                description="只在需要時使用，日常待辦請先處理上方案件。"
                :steps="directorQuickStartSteps"
                @select="openQuickStart"
              />
            </div>
          </details>

          <div class="director-workbench-v2__full-grid">
            <div class="director-workbench-v2__full-main">
              <section id="schedule-sec" class="surface-panel" aria-labelledby="director-schedule-title">
                <header class="surface-panel__header"><div><h3 id="director-schedule-title">今日課表</h3><p>先看今天的課務節奏與未完成點名。</p></div><span class="surface-panel__count">{{ todaySchedules.length }} 堂</span></header>
                <div v-if="!todaySchedules.length" class="director-state director-state--compact"><span class="material-symbols-outlined" aria-hidden="true">calendar_today</span><span>今天沒有課程。</span></div>
                <div v-else class="director-schedule-list">
                  <div v-for="schedule in todaySchedules.slice(0, 12)" :key="schedule.id" class="director-schedule-row">
                    <time>{{ formatTime(schedule.start_time) }}</time>
                    <strong>{{ schedule.student_name || '未命名學生' }}</strong>
                    <span>{{ schedule.subject || schedule.subject_name || '—' }}</span>
                    <span>{{ schedule.teacher_name || '—' }}</span>
                    <span :class="`director-schedule-row__status director-schedule-row__status--${schedule.status}`">{{ formatScheduleStatus(schedule.status) }}</span>
                  </div>
                </div>
                <footer v-if="pendingAttendanceCount" class="surface-panel__footer"><span>{{ pendingAttendanceCount }} 堂尚未完成點名</span><button type="button" class="text-action" @click="goToAttendance">前往點名</button></footer>
              </section>

              <section id="exception-workflows-sec" class="surface-panel director-inbox" aria-labelledby="director-leave-title">
                <header class="surface-panel__header"><div><h3 id="director-leave-title">家長請假</h3><p>每筆案件只在這裡做決策：安排補課、核准不補課，或退回。</p></div><span class="surface-panel__count">{{ exceptionWorkflowCount }} 筆</span></header>
                <div v-if="workflowFocusError" class="director-inline-error" role="alert">{{ workflowFocusError }}</div>
                <div v-if="exceptionWorkflowLoading" class="director-state director-state--compact" role="status">案件載入中…</div>
                <div v-else-if="exceptionWorkflowError" class="director-inline-error" role="alert">{{ exceptionWorkflowError }}</div>
                <div v-else-if="!exceptionWorkflows.length" class="director-state director-state--compact"><span class="material-symbols-outlined" aria-hidden="true">task_alt</span><span>目前沒有待處理的家長請假。</span></div>
                <div v-else class="director-leave-list">
                  <article v-for="workflow in exceptionWorkflows" :key="workflow.id" :id="`exception-workflow-${workflow.id}`" class="director-leave-case">
                    <header class="director-leave-case__header"><div><strong>{{ workflow.student?.name || '未命名學生' }}</strong><span>{{ workflowStatusLabel(workflow.status) }}</span></div><span>案件 #{{ workflow.id }}</span></header>
                    <dl class="director-leave-case__details"><div><dt>原堂次</dt><dd>{{ workflow.class_session?.date || '未提供日期' }} {{ workflow.class_session?.start_time || '' }}–{{ workflow.class_session?.end_time || '' }}</dd></div><div><dt>原因</dt><dd>{{ workflow.payload?.reason || '未提供原因' }}</dd></div></dl>
                    <p class="director-leave-case__window">
                      <span class="material-symbols-outlined" aria-hidden="true">date_range</span>
                      補課候選範圍：{{ makeupWindowLabel(workflow) }}（原堂後一天起）
                    </p>
                    <div v-if="workflowCandidates[workflow.id]?.length" class="director-candidate-picker">
                      <div class="director-candidate-dates" role="tablist" :aria-label="`${workflow.student?.name || '學生'}可補課日期`">
                        <button v-for="date in workflowCandidateGroups[workflow.id]?.dates" :key="date" type="button" role="tab" class="director-candidate-date" :class="{ 'is-selected': selectedWorkflowCandidateDates[workflow.id] === date }" :aria-selected="selectedWorkflowCandidateDates[workflow.id] === date" @click="selectCandidateDate(workflow.id, date)">
                          {{ candidateDateLabel(date) }}
                        </button>
                      </div>
                      <div class="director-candidate-list" role="radiogroup" :aria-label="`${workflow.student?.name || '學生'}補課候選`">
                        <label v-for="candidate in (workflowCandidateGroups[workflow.id]?.byDate[selectedWorkflowCandidateDates[workflow.id]] || [])" :key="candidate.id" class="director-candidate" :class="{ 'is-selected': selectedWorkflowCandidates[workflow.id] === candidate.id }"><input v-model="selectedWorkflowCandidates[workflow.id]" type="radio" :name="`leave-candidate-${workflow.id}`" :value="candidate.id" :disabled="workflowActionId === workflow.id" /><span><strong>{{ candidate.start_time }}–{{ candidate.end_time }}</strong><small v-if="candidate.status === 'warning'">已有其他課程</small></span></label>
                      </div>
                    </div>
                    <p v-else class="director-leave-case__hint"><span class="material-symbols-outlined" aria-hidden="true">lightbulb</span>先搜尋沒有衝堂的可用時段，也可以直接核准不補課。</p>
                    <div class="director-leave-case__actions">
                      <button v-if="!workflowCandidates[workflow.id]?.length" type="button" class="button button--primary" :disabled="workflowActionId === workflow.id" @click="generateCandidates(workflow)"><span class="material-symbols-outlined" aria-hidden="true">search</span>{{ workflowActionId === workflow.id ? '搜尋中…' : '尋找補課時段' }}</button>
                      <button v-else type="button" class="button button--primary" :disabled="workflowActionId === workflow.id || !selectedWorkflowCandidates[workflow.id]" @click="openWorkflowDecision('candidate', workflow)">確認補課</button>
                      <button type="button" class="button button--quiet" :disabled="workflowActionId === workflow.id" @click="openWorkflowDecision('waive', workflow)">核准不補課</button>
                      <button type="button" class="button button--danger" :disabled="workflowActionId === workflow.id" @click="openWorkflowDecision('reject', workflow)">退回</button>
                    </div>
                  </article>
                </div>
              </section>

              <section id="evals-sec" class="surface-panel" aria-labelledby="director-evals-title">
                <header class="surface-panel__header"><div><h3 id="director-evals-title">評量待審核</h3><p>確認後，家長才能看到完整回饋。</p></div><span class="surface-panel__count">{{ pendingEvaluations.length }} 筆</span></header>
                <div v-if="!pendingEvaluations.length" class="director-state director-state--compact"><span class="material-symbols-outlined" aria-hidden="true">task_alt</span><span>目前沒有待審核評量。</span></div>
                <div v-else class="director-evaluation-list"><article v-for="evaluation in pendingEvaluations.slice(0, 8)" :key="evaluation.id" class="director-evaluation-row"><div><strong>{{ evaluation.student_name }}</strong><span>{{ evaluation.student_class_label || evaluation.Subject || '—' }} · {{ evaluation.SessionDate || '未提供日期' }}</span></div><div><button type="button" class="button button--quiet" @click="emit('navigate', { target: 'learning', recordId: evaluation.id })">查看</button><button type="button" class="button button--primary" @click="approveEvaluation(evaluation)">核准</button><button type="button" class="button button--danger" @click="rejectEvaluation(evaluation)">退回</button></div></article></div>
                <footer v-if="pendingEvaluations.length > 8" class="surface-panel__footer"><span>還有 {{ pendingEvaluations.length - 8 }} 筆</span><button type="button" class="text-action" @click="emit('navigate', { target: 'learning' })">查看全部</button></footer>
              </section>
            </div>

            <aside class="director-workbench-v2__full-side">
              <section id="payments-sec" class="surface-panel" aria-labelledby="director-payments-title"><header class="surface-panel__header"><div><h3 id="director-payments-title">繳費與續課</h3><p>{{ paymentActionLaneLabel }}</p></div><span class="surface-panel__count">{{ lowBalanceStudents.length }}</span></header><div v-if="!lowBalanceStudents.length" class="director-state director-state--compact"><span>目前沒有需要跟進的繳費提醒。</span></div><div v-else class="director-payment-list"><div v-for="student in displayPaymentAlerts" :key="student.id" class="director-payment-row"><div><strong>{{ student.name }}</strong><span :class="paymentAlertBadgeClass(student)">{{ paymentAlertBadgeText(student) }}</span></div><div class="director-payment-row__actions"><button v-if="isPaymentNoticeAvailable(student)" type="button" class="text-action" @click="openPaymentSlip(student)">繳費通知</button><button type="button" class="text-action" @click="openPaymentLedger(student)">繳費明細</button><button type="button" class="text-action" @click="copyPaymentMessage(student)">複製通知</button></div></div></div><footer v-if="lowBalanceStudents.length > paymentAlertLimit" class="surface-panel__footer"><button type="button" class="text-action" @click="showAllPayments = !showAllPayments">{{ showAllPayments ? '收起' : `顯示全部 ${lowBalanceStudents.length} 筆` }}</button></footer></section>
              <section class="surface-panel" aria-labelledby="director-discrepancy-title"><header class="surface-panel__header"><div><h3 id="director-discrepancy-title">課表回報</h3><p>老師回報的差異需要主任留下處理結果。</p></div><span class="surface-panel__count">{{ sdSummary.pending }}</span></header><div v-if="sdLoading" class="director-state director-state--compact" role="status">載入中…</div><div v-else-if="sdSummary.pending" class="director-state director-state--compact"><span>{{ sdSummary.pending }} 筆待確認</span><button type="button" class="text-action" @click="goToScheduleDiscrepancy">查看回報</button></div><div v-else class="director-state director-state--compact"><span class="material-symbols-outlined" aria-hidden="true">task_alt</span><span>目前沒有待確認的課表回報。</span></div></section>
              <section id="notifications-sec" class="surface-panel" aria-labelledby="director-notifications-title"><header class="surface-panel__header"><div><h3 id="director-notifications-title">通知摘要</h3><p>只顯示未讀通知的最新幾筆。</p></div><span class="surface-panel__count">{{ unreadNotificationCount }}</span></header><div v-if="!notificationSummary.length" class="director-state director-state--compact"><span>目前沒有未讀通知。</span></div><div v-else class="director-notification-list"><div v-for="notification in notificationSummary" :key="notification.id"><strong>{{ notification.title }}</strong><span>{{ notification.typeLabel }}</span></div></div><footer class="surface-panel__footer"><button type="button" class="text-action" @click="goToNotifications">前往通知中心</button></footer></section>
              <section v-if="directorTodoCards.length" class="surface-panel" aria-labelledby="director-other-work-title"><header class="surface-panel__header"><div><h3 id="director-other-work-title">其他營運工作</h3><p>需要追蹤的項目，不佔用今天的主佇列。</p></div><span class="surface-panel__count">{{ directorTodoCards.length }}</span></header><div v-if="adoptionTaskLoading" class="director-state director-state--compact" role="status">載入中…</div><div v-else class="director-other-work-list"><button v-for="work in directorTodoCards.slice(0, 6)" :key="work.id" type="button" @click="handleDirectorTodoClick(work)"><span>{{ work.title }}</span><small>{{ work.description }}</small><span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></button></div></section>
              <details class="director-analysis" @toggle="analysisOpen = $event.currentTarget.open">
                <summary>近期紀錄與分析</summary>
                <div class="director-analysis__body">
                  <RecentSubstitutesCard v-if="analysisOpen" :branch-id="branchId" :fetch-recent="fetchRecentSubstitutes" />
                </div>
              </details>
            </aside>
          </div>
        </section>

        <div v-if="workflowDecisionModal" class="director-modal-backdrop" role="presentation" @click.self="closeWorkflowDecision">
          <section class="director-modal" role="dialog" aria-modal="true" aria-labelledby="director-modal-title">
            <button type="button" class="director-modal__close" aria-label="關閉" @click="closeWorkflowDecision">×</button>
            <h3 id="director-modal-title">{{ workflowDecisionTitle }}</h3>
            <p>{{ workflowDecisionDescription }}</p>
            <div v-if="workflowDecisionModal.kind === 'candidate'" class="director-modal__selection"><span>補課時段</span><strong>{{ selectedWorkflowCandidate?.candidate_date }} {{ selectedWorkflowCandidate?.start_time }}–{{ selectedWorkflowCandidate?.end_time }}</strong></div>
            <label v-else class="director-modal__field"><span>{{ workflowDecisionModal.kind === 'reject' ? '退回原因' : '備註（選填）' }}</span><textarea v-model="workflowDecisionReason" rows="3" :placeholder="workflowDecisionModal.kind === 'reject' ? '請說明退回原因' : '可補充處理備註'" /></label>
            <p v-if="workflowDecisionError" class="director-inline-error" role="alert">{{ workflowDecisionError }}</p>
            <div class="director-modal__actions"><button type="button" class="button button--quiet" :disabled="workflowActionId === workflowDecisionModal.workflow.id" @click="closeWorkflowDecision">取消</button><button type="button" class="button" :class="workflowDecisionModal.kind === 'reject' ? 'button--danger' : 'button--primary'" :disabled="workflowActionId === workflowDecisionModal.workflow.id" @click="submitWorkflowDecision">{{ workflowDecisionModal.kind === 'candidate' ? '確認補課' : workflowDecisionModal.kind === 'reject' ? '確認退回' : '確認核准' }}</button></div>
          </section>
        </div>
        <div v-if="workflowToast" class="director-toast" role="status">{{ workflowToast }}</div>

        <PaymentSlipModal
          :show="paymentSlipOpen"
          :invoice-id="paymentSlipInvoiceId"
          :student-class-id="paymentSlipStudentClassId"
          @close="paymentSlipOpen = false"
        />

        <AccountingLedgerModal
          :show="paymentLedgerOpen"
          :student-class-id="paymentLedgerStudentClassId"
          :branch-id="branchId"
          @close="paymentLedgerOpen = false"
        />
      </main>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, watch, computed, nextTick } from 'vue';
import { supabase } from '../supabase';
import { getBranchName } from '../lib/useBranches';
import { getSubjectLabel as getSubjectText } from '../lib/constants';
import { fetchDiscrepancySummary } from '../lib/scheduleDiscrepanciesApi';
import TodayProgressCard from '../components/TodayProgressCard.vue';
import RecentSubstitutesCard from '../components/substitute/RecentSubstitutesCard.vue';
import TeacherAssessmentFillRateCard from '../components/TeacherAssessmentFillRateCard.vue';
import OperationsQuickStart from '../components/OperationsQuickStart.vue';
import PaymentSlipModal from '../components/PaymentSlipModal.vue';
import AccountingLedgerModal from '../components/AccountingLedgerModal.vue';
import { recentSubstitutes as fetchRecentSubstitutes } from '../lib/substituteApi.js';
import { sortTodoCards, markTodoAcknowledged, isTodoAcknowledged } from '../lib/adoptionTodo';
import {
  trackAdoptionEvent,
  trackTrustEventOnce,
  markTrustSeen,
  markTrustProvidedPathUsed,
} from '../lib/adoptionTelemetry';
import { getMakeupCandidateWindow } from '../lib/makeupCandidateWindow';
import {
  listExceptionWorkflows,
  getExceptionWorkflow,
  generateExceptionWorkflowCandidates,
  confirmExceptionWorkflowCandidate,
  waiveExceptionWorkflow,
  rejectExceptionWorkflow,
} from '../api';
import { isPendingWorkflowStatus, reconcileFocusedWorkflowList } from '../lib/exceptionWorkflowFocus.js';
import {
  trustPeopleSlice as trustPeople,
  trustDecisionTitle,
} from '../lib/trustDecisionDisplay.js';
import { buildDirectorDashboardTasks } from '../lib/directorDashboardTasks.js';
import { runDashboardLoaders } from '../lib/dashboardLoadPlan.js';

const props = defineProps({
  branchId: [String, Number],
  unreadFeedbackCount: { type: Number, default: 0 },
  initialEngagement: { type: Object, default: null },
  focusWorkflowId: { type: [Number, String], default: null },
  focusSection: { type: String, default: null },
});
const emit = defineEmits(['navigate']);
const workflowFocusError = ref('');

const todaySchedules = ref([]);
const pendingEvaluations = ref([]);
const operationsTrust = ref(null);
const lowBalanceStudents = ref([]);
const unreadNotificationCount = ref(0);
const notificationSummary = ref([]);
const showAllPayments = ref(false);
const paymentAlertLimit = 5;
const paymentSlipOpen = ref(false);
const paymentSlipInvoiceId = ref(null);
const paymentSlipStudentClassId = ref(null);
const paymentLedgerOpen = ref(false);
const paymentLedgerStudentClassId = ref(null);
const pendingMakeupCount = ref(0);
const MAKEUP_CANDIDATE_LIMIT = 20;
const exceptionWorkflows = ref([]);
const exceptionWorkflowError = ref('');
const exceptionWorkflowLoading = ref(false);
const workflowCandidates = ref({});
const selectedWorkflowCandidates = ref({});
const selectedWorkflowCandidateDates = ref({});
const workflowActionId = ref(null);
const workflowDecisionModal = ref(null);
const workflowDecisionReason = ref('');
const workflowDecisionError = ref('');
const workflowToast = ref('');
let workflowToastTimer = null;

const showWorkflowToast = (message) => {
  workflowToast.value = message;
  if (workflowToastTimer) clearTimeout(workflowToastTimer);
  workflowToastTimer = setTimeout(() => { workflowToast.value = ''; }, 4200);
};

const selectedWorkflowCandidate = computed(() => {
  const modal = workflowDecisionModal.value;
  if (!modal || modal.kind !== 'candidate') return null;
  return (workflowCandidates.value[modal.workflow.id] || [])
    .find((candidate) => Number(candidate.id) === Number(selectedWorkflowCandidates.value[modal.workflow.id])) || null;
});
const workflowCandidateGroups = computed(() => Object.fromEntries(
  Object.entries(workflowCandidates.value).map(([workflowId, candidates]) => {
    const normalizedCandidates = Array.isArray(candidates) ? candidates : [];
    const dates = [...new Set(normalizedCandidates.map((candidate) => candidate.candidate_date).filter(Boolean))].sort();
    const byDate = Object.fromEntries(dates.map((date) => [date, normalizedCandidates.filter((candidate) => candidate.candidate_date === date)]));
    return [workflowId, { dates, byDate }];
  }),
));
const candidateDateLabel = (date) => {
  const parsed = new Date(`${date}T00:00:00`);
  if (Number.isNaN(parsed.getTime())) return date;
  return new Intl.DateTimeFormat('zh-TW', { month: 'numeric', day: 'numeric', weekday: 'short' }).format(parsed);
};
const selectCandidateDate = (workflowId, date) => {
  selectedWorkflowCandidateDates.value = {
    ...selectedWorkflowCandidateDates.value,
    [workflowId]: date,
  };
};
const workflowDecisionTitle = computed(() => {
  const modal = workflowDecisionModal.value;
  if (!modal) return '';
  const studentName = modal.workflow?.student?.name || '此學生';
  if (modal.kind === 'candidate') return `確認安排「${studentName}」補課`;
  if (modal.kind === 'reject') return `退回「${studentName}」請假`;
  return `核准「${studentName}」請假`;
});
const workflowDecisionDescription = computed(() => {
  const modal = workflowDecisionModal.value;
  if (!modal) return '';
  if (modal.kind === 'candidate') return '確認後原堂次會標記為已核准請假，並建立補課堂次。';
  if (modal.kind === 'reject') return '退回後原堂次會恢復排課，家長可依原因重新提出申請。';
  return '核准後原堂次會標記為已核准請假，不會建立補課堂次。';
});

// Schedule-discrepancy summary card
const sdSummary = ref({ pending: 0, acknowledged: 0, resolved: 0, withdrawn: 0 });
const sdLoading = ref(true);

const adoptionTaskRows = ref([]);
const adoptionTaskLoading = ref(false);
const adoptionActivityRows = ref([]);
const adoptionActivityLoading = ref(false);
const adoptionWeeklyMetrics = ref(null);
const adoptionWeeklyMetricsLoading = ref(false);
const dashboardLoading = ref(false);
const dashboardPrimaryError = ref('');
const dashboardLastUpdated = ref('');
const secondaryLoading = ref(false);
const secondaryLoaded = ref(false);
const secondaryToolsOpen = ref(false);
const analysisOpen = ref(false);
// Returning to the dashboard always starts at today's work. Full operations is
// an intentional, in-session drill-down and should never become the next
// session's default just because it was opened once.
const dashboardViewMode = ref('focus');

function setDashboardViewMode(mode) {
  dashboardViewMode.value = mode === 'full' ? 'full' : 'focus';
  if (dashboardViewMode.value === 'full' && !secondaryLoaded.value) loadSecondaryData();
}

async function loadScheduleDiscrepancySummary() {
  if (props.branchId == null) return;
  sdLoading.value = true;
  try {
    sdSummary.value = await fetchDiscrepancySummary(props.branchId);
  } catch (e) {
    console.warn('loadScheduleDiscrepancySummary', e);
    sdSummary.value = { pending: 0, acknowledged: 0, resolved: 0, withdrawn: 0 };
  } finally {
    sdLoading.value = false;
  }
}

function goToScheduleDiscrepancy() {
  emit('navigate', { target: 'schedule-discrepancy' });
}

async function loadAdoptionTaskTracker(token, baseUrl) {
  if (!props.branchId) return;
  adoptionTaskLoading.value = true;
  try {
    const params = new URLSearchParams({ branch_id: String(props.branchId) });
    const res = await fetch(`${baseUrl}/v1/adoption/task-tracker?${params}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    if (!res.ok) return;
    const json = await res.json().catch(() => ({}));
    adoptionTaskRows.value = Array.isArray(json?.data) ? json.data : [];
  } catch (e) {
    console.warn('loadAdoptionTaskTracker', e);
  } finally {
    adoptionTaskLoading.value = false;
  }
}

async function loadAdoptionActivityLog(token, baseUrl) {
  if (!props.branchId) return;
  adoptionActivityLoading.value = true;
  try {
    const params = new URLSearchParams({ branch_id: String(props.branchId) });
    const res = await fetch(`${baseUrl}/v1/adoption/activity-log?${params}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    if (!res.ok) return;
    const json = await res.json().catch(() => ({}));
    adoptionActivityRows.value = Array.isArray(json?.data) ? json.data : [];
  } catch (e) {
    console.warn('loadAdoptionActivityLog', e);
  } finally {
    adoptionActivityLoading.value = false;
  }
}

async function loadAdoptionWeeklyMetrics(token, baseUrl) {
  if (!props.branchId) return;
  adoptionWeeklyMetricsLoading.value = true;
  try {
    const params = new URLSearchParams({ branch_id: String(props.branchId) });
    const res = await fetch(`${baseUrl}/v1/adoption/weekly-metrics?${params}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    if (!res.ok) return;
    const json = await res.json().catch(() => ({}));
    adoptionWeeklyMetrics.value = json?.data || null;
  } catch (e) {
    console.warn('loadAdoptionWeeklyMetrics', e);
  } finally {
    adoptionWeeklyMetricsLoading.value = false;
  }
}

function handleDirectorTodoClick(task) {
  if (!task) return;
  markTodoAcknowledged(task.id);
  trackAdoptionEvent('todo_card_clicked', props.branchId, {
    todo_id: task.id,
    status: task.status,
    owner: task.owner,
  });
  if (task?.target?.page === 'learning') {
    emit('navigate', { target: 'learning', recordId: task.target.recordId || null });
    return;
  }
  if (task?.target?.page === 'schedule-discrepancy') {
    emit('navigate', { target: 'schedule-discrepancy' });
    return;
  }
  if (task?.target?.section === 'exception-workflows') {
    scrollTo('exception-workflows');
    return;
  }
}

const branchName = computed(() => getBranchName(props.branchId));

const todayDisplay = computed(() => {
  const d = new Date();
  const days = ['日', '一', '二', '三', '四', '五', '六'];
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}/${m}/${day} (${days[d.getDay()]})`;
});

const pendingAttendanceCount = computed(() =>
  todaySchedules.value.filter(s => s.status === 'scheduled').length
);

const attendedCount = computed(() =>
  todaySchedules.value.filter(s => s.status === 'attended').length
);

const exceptionWorkflowCount = computed(() => exceptionWorkflows.value.length);

const displayPaymentAlerts = computed(() =>
  showAllPayments.value
    ? lowBalanceStudents.value
    : lowBalanceStudents.value.slice(0, paymentAlertLimit)
);

const directorTodoCards = computed(() =>
  sortTodoCards((adoptionTaskRows.value || []).map((task) => {
    const dueAt = String(task?.due_at || '');
    const now = localTodayYmd();
    const slaLevel = String(task?.sla_level || '');
    const status = slaLevel === 'breached'
      ? 'breached'
      : (slaLevel === 'warning' ? 'warning' : (dueAt && dueAt < now ? 'overdue' : String(task?.status || 'pending')));
    const blockedCount = Number(task?.blocked_count || 0);
    return {
      id: task.id,
      title: task.title || '待辦',
      description: `${task.owner || '主任'} · 截止 ${dueAt || '未設定'}${blockedCount > 0 ? ` · 影響 ${blockedCount} 項` : ''}`,
      owner: task.owner || '主任',
      status,
      dueAt,
      slaLevel: task?.sla_level || 'normal',
      blockedCount,
      target: task.target || {},
      acknowledged: isTodoAcknowledged(task.id),
    };
 })));

const dashboardTasks = computed(() => buildDirectorDashboardTasks({
  pendingAttendanceCount: pendingAttendanceCount.value,
  lowBalanceCount: lowBalanceStudents.value.length,
  paymentLabel: paymentActionLaneLabel.value,
  pendingMakeupCount: pendingMakeupCount.value,
  exceptionWorkflowCount: exceptionWorkflowCount.value,
  pendingEvaluationsCount: pendingEvaluations.value.length,
  unreadFeedbackCount: props.unreadFeedbackCount,
  scheduleDiscrepancyCount: sdSummary.value.pending,
  adoptionTasks: directorTodoCards.value.map((item) => ({
    ...item,
    actionLabel: item.target?.page === 'learning' ? '前往回饋' : '查看詳情',
  })),
}));

// The daily view intentionally excludes granular adoption rows. Those rows remain
// available in the secondary operations view, while the primary queue stays at
// one row per director decision instead of expanding into a student-by-student feed.
const dashboardPrimaryTasks = computed(() => dashboardTasks.value.filter((item) => item.source !== 'adoption'));
const dashboardAdditionalTaskCount = computed(() => dashboardTasks.value.filter((item) => item.source === 'adoption').length);

// Top 3 today's operating risks: highest-severity primary tasks, excluding pure
// info-level items (those are queue work, not risk). Reuses the already-sorted
// dashboardPrimaryTasks order (severity, then due date) rather than re-sorting.
const dashboardTopRisks = computed(() => dashboardPrimaryTasks.value
  .filter((item) => item.severity === 'critical' || item.severity === 'danger' || item.severity === 'warning')
  .slice(0, 3));

const DASHBOARD_SUMMARY_DEFINITIONS = Object.freeze({
  todaySchedules: '今天已排定的所有課程堂數，含已完成與未開始的。',
  pendingEvaluations: '已上課但主任尚未核准或退回的評量紀錄；核准後家長才看得到。',
  unreadNotifications: '通知中心裡尚未標記已讀的系統通知，例如繳費提醒、課表異動。',
  workflowDaily: '今天需要主任處理並完成的營運工作總數，例如請假案件、補課登記。',
});

const workflowDailySummary = computed(() => ({
  due_total: Number(adoptionWeeklyMetrics.value?.workflow_daily?.due_total || 0),
  done_total: Number(adoptionWeeklyMetrics.value?.workflow_daily?.done_total || 0),
  breached_total: Number(adoptionWeeklyMetrics.value?.workflow_daily?.breached_total || 0),
}));

const decisionCenter = computed(() => {
  const dc = operationsTrust.value?.decision_center;
  if (dc && typeof dc.score === 'number') return dc;
  return {
    score: 100,
    max: 100,
    status: 'green',
    headline: '正在載入信任狀態…',
    decisions: [],
    policy_notes: [],
  };
});

const trustDecisions = computed(() =>
  Array.isArray(decisionCenter.value.decisions) ? decisionCenter.value.decisions : []
);

const trustDecisionsEl = ref(null);
let trustImpressionObserver = null;

function teardownTrustImpressions() {
  if (trustImpressionObserver) {
    trustImpressionObserver.disconnect();
    trustImpressionObserver = null;
  }
}

function setupTrustImpressions() {
  teardownTrustImpressions();
  if (typeof IntersectionObserver === 'undefined') return;
  const root = trustDecisionsEl.value;
  if (!root) return;
  const branch = Number(props.branchId) || 0;
  trustImpressionObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting || entry.intersectionRatio < 0.4) return;
      const key = entry.target?.getAttribute?.('data-trust-key') || '';
      if (!key) return;
      const item = trustDecisions.value.find((d) => d.key === key);
      trackTrustEventOnce('director_trust_decision_impression', branch, {
        key,
        severity: item?.severity || '',
        target: item?.target || '',
        has_drilldown: Boolean(item?.has_drilldown),
        people_total: Number(item?.people_total) || 0,
        viewport: 1,
      }, key).then((sent) => {
        if (sent) markTrustSeen(branch, key);
      });
      trustImpressionObserver?.unobserve(entry.target);
    });
  }, { threshold: [0.4] });
  root.querySelectorAll('[data-trust-key]').forEach((el) => {
    trustImpressionObserver.observe(el);
  });
}

function setOpsTrustFocus({ studentName = '', studentId = 0, decisionKey = '' } = {}) {
  try {
    sessionStorage.setItem('alltrue_ops_trust_focus', JSON.stringify({
      student_name: String(studentName || '').slice(0, 40),
      student_id: Number(studentId) || 0,
      decision_key: String(decisionKey || ''),
      at: Date.now(),
    }));
  } catch (_) { /* ignore */ }
}

function navigateTrustTarget(target) {
  if (target === 'calendar') {
    emit('navigate', { target: 'calendar' });
    return;
  }
  if (target === 'duplicate-review') {
    emit('navigate', { target: 'duplicate-review' });
    return;
  }
  if (target === 'course-mgmt') {
    emit('navigate', { target: 'course-mgmt' });
    return;
  }
  if (target === 'tuition') {
    goToTuitionCollect();
  }
}

/** First valid action on this decision instance (CTA or person row) = click. */
function handleTrustDecision(item) {
  const branch = Number(props.branchId) || 0;
  const key = item?.key || '';
  const people = trustPeople(item);
  trackTrustEventOnce('director_trust_decision_click', branch, {
    key,
    target: item?.target || '',
    severity: item?.severity || '',
    has_drilldown: Boolean(item?.has_drilldown),
    people_shown: people.length,
    from: 'decision_cta',
  }, key);
  markTrustProvidedPathUsed(branch, key);

  // Shortest loop: pass first person into the target page (course-mgmt or duplicate-review).
  if (people.length > 0 && (item?.target === 'course-mgmt' || item?.target === 'duplicate-review' || !item?.target)) {
    const p = people[0];
    setOpsTrustFocus({
      studentName: p?.student_name || '',
      studentId: p?.student_id,
      decisionKey: key,
    });
    navigateTrustTarget(item?.target || 'course-mgmt');
    return;
  }
  navigateTrustTarget(item?.target);
}

/** 上方快捷列用：區分「真的未繳」與「已繳但低堂數／月結」避免誤以為催繳失敗 */
const paymentActionLaneLabel = computed(() => {
  const rows = lowBalanceStudents.value;
  if (!rows.length) return '';
  const hasUnpaid = rows.some(s => s.alert_type === 'unpaid');
  const allLow = rows.every(s => s.alert_type === 'low_sessions');
  const allMonthly = rows.every(s => s.alert_type === 'monthly_due_soon');
  if (hasUnpaid) return '筆含未繳費';
  if (allLow) return '筆低堂數／續課';
  if (allMonthly) return '筆月結將届';
  return '筆待留意';
});

const paymentAlertBadgeClass = (s) => {
  if (s.alert_type === 'monthly_due_soon') return 'badge-amber';
  if (s.alert_type === 'low_sessions') return 'badge-orange';
  return 'badge-red';
};

const paymentAlertBadgeText = (s) => {
  if (s.alert_type === 'monthly_due_soon') {
    const d = Number(s.days_until_settlement);
    if (Number.isFinite(d) && d < 0) return `月結 · 已逾期 ${Math.abs(d)} 天`;
    if (d === 0) return '月結 · 今日繳費日';
    return `月結 · 剩 ${d} 天`;
  }
  if (s.alert_type === 'low_sessions') {
    const n = Number(s.remaining_lessons ?? 0);
    if (!Number.isFinite(n) || n <= 0) return '已繳 · 堂數已用完';
    return `已繳 · 剩 ${n} 堂`;
  }
  return `未繳 · ${s.remaining_lessons} 堂`;
};

const isPaymentNoticeAvailable = (student) =>
  ['unpaid', 'partial', 'pending_report'].includes(student?.payment_status)
  || (student?.payment_status == null && student?.alert_type === 'unpaid');

const paymentStudentClassId = (student) => Number(student?.student_class_id || student?.id || 0);

const openPaymentSlip = (student) => {
  if (!isPaymentNoticeAvailable(student)) {
    alert('這筆是續課／月結提醒，目前沒有可開啟的未繳通知單；可直接複製通知。');
    return;
  }
  const invoiceId = Number(student?.invoice_id || 0);
  const studentClassId = paymentStudentClassId(student);
  if (!invoiceId && !studentClassId) {
    alert('這筆提醒缺少課程資料，請到帳務中心搜尋後查看。');
    return;
  }
  paymentSlipInvoiceId.value = invoiceId || null;
  paymentSlipStudentClassId.value = invoiceId ? null : studentClassId;
  paymentSlipOpen.value = true;
};

const openPaymentLedger = (student) => {
  const studentClassId = paymentStudentClassId(student);
  if (!studentClassId) {
    alert('方案合併提醒沒有單一課程明細，請到帳務中心查看方案資料。');
    return;
  }
  paymentLedgerStudentClassId.value = studentClassId;
  paymentLedgerOpen.value = true;
};

const formatTime = (timeStr) => timeStr || '--:--';

const formatScheduleStatus = (status) => {
  const map = {
    scheduled: '待到班', attended: '已到班', completed: '已下課',
    cancelled: '已取消', leave: '請假', leave_adjusted: '請假(順延)',
    excused: '請假', absent: '缺席', late: '遲到',
  };
  return map[String(status || '').toLowerCase()] || String(status || '—');
};

const localTodayYmd = () => {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
};

const getToken = () => {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
  return session?.access_token || '';
};
const getBaseUrl = () => import.meta.env.VITE_API_BASE || '/api';

const workflowStatusLabel = (status) => ({
  open: '待主任處理',
  candidate_ready: '候選已產生，待確認',
  confirmed: '已確認',
  closed: '已關閉',
}[String(status || '')] || String(status || '—'));

const makeupWindowLabel = (workflow) => {
  const window = getMakeupCandidateWindow(workflow);
  if (!window.startDate || !window.endDate) return '請先確認原堂日期';
  return `${window.startDate}～${window.endDate}`;
};

const addDaysYmd = (days) => {
  const d = new Date();
  d.setDate(d.getDate() + days);
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
};

const loadExceptionWorkflows = async () => {
  if (!props.branchId) return;
  const token = getToken();
  exceptionWorkflowLoading.value = true;
  exceptionWorkflowError.value = '';
  try {
    const rows = await listExceptionWorkflows(token, { branchId: props.branchId, type: 'student_leave' });
    exceptionWorkflows.value = rows
      .filter(row => isPendingWorkflowStatus(row.status))
      .sort((a, b) => String(a.due_at || a.created_at || '').localeCompare(String(b.due_at || b.created_at || '')));
  } catch (e) {
    exceptionWorkflowError.value = e?.message || '家長請假載入失敗';
    exceptionWorkflows.value = [];
  } finally {
    exceptionWorkflowLoading.value = false;
  }
};

const setWorkflowCandidates = (workflowId, candidates) => {
  const normalizedCandidates = Array.isArray(candidates) ? candidates : [];
  workflowCandidates.value = {
    ...workflowCandidates.value,
    [workflowId]: normalizedCandidates,
  };
  selectedWorkflowCandidates.value = {
    ...selectedWorkflowCandidates.value,
    [workflowId]: null,
  };
  selectedWorkflowCandidateDates.value = {
    ...selectedWorkflowCandidateDates.value,
    [workflowId]: normalizedCandidates[0]?.candidate_date || null,
  };
};

const openWorkflowDecision = (kind, workflow) => {
  workflowDecisionReason.value = '';
  workflowDecisionError.value = '';
  workflowDecisionModal.value = { kind, workflow };
};

const closeWorkflowDecision = () => {
  if (workflowActionId.value) return;
  workflowDecisionModal.value = null;
  workflowDecisionReason.value = '';
  workflowDecisionError.value = '';
};

const loadWorkflowDetail = async (workflow) => {
  const token = getToken();
  workflowActionId.value = workflow.id;
  exceptionWorkflowError.value = '';
  try {
    const detail = await getExceptionWorkflow(token, workflow.id);
    setWorkflowCandidates(workflow.id, detail?.candidates || []);
  } catch (e) {
    exceptionWorkflowError.value = e?.message || '案件詳情載入失敗';
  } finally {
    workflowActionId.value = null;
  }
};

const generateCandidates = async (workflow) => {
  const token = getToken();
  workflowActionId.value = workflow.id;
  exceptionWorkflowError.value = '';
  try {
    const window = getMakeupCandidateWindow(workflow);
    const result = await generateExceptionWorkflowCandidates(token, workflow.id, {
      startDate: window.startDate,
      endDate: window.endDate,
      limit: MAKEUP_CANDIDATE_LIMIT,
    });
    setWorkflowCandidates(workflow.id, result?.candidates || []);
    await loadExceptionWorkflows();
  } catch (e) {
    exceptionWorkflowError.value = e?.message || '產生候選時段失敗';
  } finally {
    workflowActionId.value = null;
  }
};

const confirmCandidate = async (workflow, candidate) => {
  const token = getToken();
  workflowActionId.value = workflow.id;
  exceptionWorkflowError.value = '';
  try {
    await confirmExceptionWorkflowCandidate(token, workflow.id, candidate.id);
    setWorkflowCandidates(workflow.id, []);
    await loadExceptionWorkflows();
    loadData();
    workflowDecisionModal.value = null;
    showWorkflowToast('補課已安排，原請假案件已結案');
    window.dispatchEvent(new CustomEvent('alltrue-refresh-badges'));
  } catch (e) {
    workflowDecisionError.value = e?.message || '確認補課失敗';
  } finally {
    workflowActionId.value = null;
  }
};

const waiveWorkflow = async (workflow) => {
  const reason = workflowDecisionReason.value.trim();
  const token = getToken();
  workflowActionId.value = workflow.id;
  exceptionWorkflowError.value = '';
  try {
    await waiveExceptionWorkflow(token, workflow.id, { reason });
    setWorkflowCandidates(workflow.id, []);
    await loadExceptionWorkflows();
    loadData();
    workflowDecisionModal.value = null;
    showWorkflowToast('請假已核准，案件已結案');
    window.dispatchEvent(new CustomEvent('alltrue-refresh-badges'));
  } catch (e) {
    workflowDecisionError.value = e?.message || '標記不補課失敗';
  } finally {
    workflowActionId.value = null;
  }
};

const rejectWorkflow = async (workflow) => {
  const reason = workflowDecisionReason.value.trim();
  if (!reason) {
    workflowDecisionError.value = '請填寫退回原因';
    return;
  }
  const token = getToken();
  workflowActionId.value = workflow.id;
  exceptionWorkflowError.value = '';
  try {
    await rejectExceptionWorkflow(token, workflow.id, { reason });
    setWorkflowCandidates(workflow.id, []);
    await loadExceptionWorkflows();
    loadData();
    workflowDecisionModal.value = null;
    showWorkflowToast('請假已退回，原堂次已恢復排課');
  } catch (e) {
    workflowDecisionError.value = e?.message || '退回請假失敗';
  } finally {
    workflowActionId.value = null;
  }
};

const submitWorkflowDecision = async () => {
  const modal = workflowDecisionModal.value;
  if (!modal) return;
  if (modal.kind === 'candidate') {
    if (!selectedWorkflowCandidate.value) {
      workflowDecisionError.value = '請先選擇一個補課時段';
      return;
    }
    await confirmCandidate(modal.workflow, selectedWorkflowCandidate.value);
    return;
  }
  if (modal.kind === 'reject') {
    await rejectWorkflow(modal.workflow);
    return;
  }
  await waiveWorkflow(modal.workflow);
};

const loadData = async () => {
  if (!props.branchId) return;

  const token = getToken();
  const baseUrl = getBaseUrl();
  dashboardLoading.value = true;
  dashboardPrimaryError.value = '';

  try {
    const alertsPromise = (async () => {
      try {
    const alertsParams = new URLSearchParams({ branch_id: String(props.branchId) });
    const alertsResp = await fetch(`${baseUrl}/v1/alerts/tuition?${alertsParams}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    });
    if (alertsResp.ok) {
      const alertsJson = await alertsResp.json();
      const alertList = Array.isArray(alertsJson) ? alertsJson : (alertsJson.low_balance || []);
      const currentBranchId = Number(props.branchId) || 0;
      lowBalanceStudents.value = alertList
        .filter(c => {
          if (!c.student_name) return false;
          const campusId = Number(c.campus_id ?? c.CampusID ?? 0);
          return !currentBranchId || !campusId || campusId === currentBranchId;
        })
        .map(c => ({
          id: c.id || c.class_id,
          student_class_id: c.student_class_id || c.id || c.class_id || null,
          invoice_id: c.invoice_id || null,
          student_id: c.student_id || null,
          raw_name: c.student_name,
          name: `${c.student_name} — ${c.subject || getSubjectLabel(c.SubjectID) || ''}`,
          remaining_lessons: c.remaining_sessions ?? c.RemainingSessions ?? 0,
          alert_type: c.alert_type || 'unpaid',
          payment_status: c.payment_status || null,
          days_until_settlement: c.days_until_settlement ?? null,
          due_date: c.due_date ?? null,
          settlement_day: c.settlement_day ?? null,
          schedule_mode: c.schedule_mode ?? 'count',
        }));
    }
      } catch (err) {
        console.error('Failed to load alerts:', err);
      }
    })();

    const trustPromise = (async () => {
      try {
        const trustParams = new URLSearchParams({ branch_id: String(props.branchId) });
        const trustResp = await fetch(`${baseUrl}/v1/director/operations-trust?${trustParams}`, {
          headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
        });
        if (trustResp.ok) {
          const trustJson = await trustResp.json();
          operationsTrust.value = trustJson?.data || null;
          const dc = operationsTrust.value?.decision_center;
          if (dc) {
            const branch = Number(props.branchId) || 0;
            const keys = Array.isArray(dc.decisions) ? dc.decisions.map((d) => d.key).filter(Boolean) : [];
            await trackTrustEventOnce('director_trust_score_shown', branch, {
              score: Number(dc.score) || 0,
              status: String(dc.status || ''),
              critical_count: Number(dc.critical_count) || 0,
              warning_count: Number(dc.warning_count) || 0,
              decision_count: keys.length,
              decision_keys: keys,
            }, 'score');
            await nextTick();
            setupTrustImpressions();
          }
        } else {
          operationsTrust.value = null;
        }
      } catch (err) {
        console.error('Failed to load operations trust:', err);
        operationsTrust.value = null;
      }
    })();

    await runDashboardLoaders({
      alerts: () => alertsPromise,
      trust: () => trustPromise,
      notifications: () => loadNotificationSummary(token, baseUrl),
      workflows: () => loadExceptionWorkflows(),
    });

  const today = localTodayYmd();
  try {
    const params = new URLSearchParams({
      branch_id: String(props.branchId), start: today, end: today, per_page: '500',
    });
    const res = await fetch(`${baseUrl}/v1/class-sessions?${params}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    });
    if (res.ok) {
      const json = await res.json().catch(() => ({}));
      const rows = Array.isArray(json?.data) ? json.data : [];
      todaySchedules.value = rows
        .map(row => ({
          id: Number(row?.id || 0),
          class_session_id: Number(row?.id || 0),
          student_class_id: Number(row?.student_class_id || 0),
          student_name: row?.student_name || '',
          teacher_name: row?.teacher_name || '',
          start_time: String(row?.start_time || '').slice(0, 5),
          end_time: String(row?.end_time || '').slice(0, 5),
          status: String(row?.status || '').toLowerCase(),
          subject: row?.subject || '',
          subject_name: row?.subject_name || '',
        }))
        .filter(s => s.id > 0 && !['cancelled'].includes(s.status))
        .sort((a, b) => (a.start_time || '').localeCompare(b.start_time || ''));
    } else {
      todaySchedules.value = [];
    }
  } catch (_) {
    const todayDow = new Date().getDay() || 7;
    const { data: dateSchedules } = await supabase
      .from('schedules').select('*')
      .eq('branch_id', props.branchId).eq('schedule_date', today);
    const { data: dowSchedules } = await supabase
      .from('schedules').select('*')
      .eq('branch_id', props.branchId).eq('day_of_week', todayDow)
      .is('schedule_date', null);
    const allToday = [...(dateSchedules || []), ...(dowSchedules || [])];
    const seenIds = new Set();
    todaySchedules.value = allToday
      .filter(s => {
        if (seenIds.has(s.id)) return false;
        seenIds.add(s.id);
        return !['cancelled', 'leave'].includes(String(s.status || '').toLowerCase());
      })
      .sort((a, b) => (a.start_time || '').localeCompare(b.start_time || ''));
  }

  try {
    const pendingRes = await fetch(
      `${baseUrl}/v1/learning-records?branch_id=${props.branchId}&status=pending,changes_requested&only_started=1&per_page=100&sort=session_date`,
      { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } }
    );
    if (pendingRes.ok) {
      const pendingJson = await pendingRes.json();
      pendingEvaluations.value = pendingJson.data || [];
    }
  } catch (err) {
    console.error('Failed to load pending evaluations:', err);
  }

  try {
    const makeupParams = new URLSearchParams({ branch_id: String(props.branchId), per_page: '1' });
    const makeupRes = await fetch(`${baseUrl}/v1/attendance/ended-sessions?${makeupParams}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    });
    if (makeupRes.ok) {
      const makeupJson = await makeupRes.json();
      pendingMakeupCount.value = Number(makeupJson?.meta?.total ?? makeupJson?.total ?? 0);
    }
  } catch (err) {
    console.error('Failed to load makeup count:', err);
  }

    dashboardLastUpdated.value = new Date().toLocaleTimeString('zh-TW', {
      hour: '2-digit', minute: '2-digit', hour12: false,
    });
  } catch (err) {
    dashboardPrimaryError.value = err?.message || '部分今日資料無法載入';
  } finally {
    dashboardLoading.value = false;
  }
};

const loadSecondaryData = async () => {
  if (!props.branchId || secondaryLoading.value || secondaryLoaded.value) return;
  const token = getToken();
  const baseUrl = getBaseUrl();
  secondaryLoading.value = true;
  await Promise.all([
    loadAdoptionTaskTracker(token, baseUrl),
    loadAdoptionActivityLog(token, baseUrl),
    loadAdoptionWeeklyMetrics(token, baseUrl),
  ]);

  secondaryLoaded.value = true;
  secondaryLoading.value = false;
};

const fetchTeacherAssessmentFillRates = async ({ branch_id, days = 14 }) => {
  const params = new URLSearchParams({ branch_id: String(branch_id), days: String(days) });
  const res = await fetch(`${getBaseUrl()}/v1/reports/teacher-learning-fill-rates?${params}`, {
    headers: { Authorization: `Bearer ${getToken()}`, Accept: 'application/json' },
  });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(json?.message || '評量完成率載入失敗');
  return json;
};

const refreshDashboard = async () => {
  secondaryLoaded.value = false;
  await loadData();
  await loadScheduleDiscrepancySummary();
  if (dashboardViewMode.value === 'full') await loadSecondaryData();
};

const notificationTypeLabel = (type) => {
  const map = { tuition: '繳費', learning_review: '評量', pending_swipe: '刷卡', low_sessions: '堂數不足' };
  return map[type] || '通知';
};

const loadNotificationSummary = async (token, baseUrl) => {
  try {
    const params = new URLSearchParams({
      branch_id: String(props.branchId), read: 'unread', per_page: '3',
    });
    const res = await fetch(`${baseUrl}/v1/notifications?${params}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    });
    if (!res.ok) { unreadNotificationCount.value = 0; notificationSummary.value = []; return; }
    const json = await res.json();
    unreadNotificationCount.value = Number(json.unread_count || 0);
    notificationSummary.value = (json.data || []).map(item => ({
      id: item.id, title: item.Title || '通知', typeLabel: notificationTypeLabel(item.Type),
    }));
  } catch (err) {
    console.error('Failed to load notification summary:', err);
    unreadNotificationCount.value = 0; notificationSummary.value = [];
  }
};

const goToNotifications = () => emit('navigate', { target: 'notifications' });
const goToAttendance = () => emit('navigate', { target: 'attendance' });
const goToTuitionCollect = () => emit('navigate', { target: 'tuition-collect' });

const directorQuickStartSteps = [
  { id: 'billing', icon: 'payments', title: '收款與核帳', description: '處理未繳、回報與待確認入帳。', action: '前往帳務中心' },
  { id: 'create-schedule', icon: 'event_available', title: '新增排課', description: '建立學生、老師與固定上課時段。', action: '開始快速排課' },
  { id: 'adjust-schedule', icon: 'event_repeat', title: '調課／代課', description: '先點課卡，再選時間或代課老師。', action: '開啟班級行事曆' },
];

const openQuickStart = (stepId) => {
  if (stepId === 'billing') {
    emit('navigate', { target: 'tuition-collect', intent: 'pending' });
  } else if (stepId === 'create-schedule') {
    emit('navigate', { target: 'calendar', intent: 'quick-add' });
  } else if (stepId === 'adjust-schedule') {
    emit('navigate', { target: 'calendar', intent: 'reschedule' });
  }
};

const openDashboardTask = async (task) => {
  if (!task) return;
  if (task.source === 'adoption' && task.sourceItem) {
    handleDirectorTodoClick(task.sourceItem);
    return;
  }
  if (task.target?.page === 'learning') {
    emit('navigate', { target: 'learning', focus: task.target.focus || null });
    return;
  }
  if (task.target?.page === 'attendance') return goToAttendance();
  if (task.target?.page === 'tuition-collect') return goToTuitionCollect();
  if (task.target?.page === 'schedule-discrepancy') return goToScheduleDiscrepancy();
  if (task.target?.section) {
    if (dashboardViewMode.value !== 'full') setDashboardViewMode('full');
    await nextTick();
    scrollTo(task.target.section);
  }
};

const getSubjectLabel = (val) => {
  const map = {
    '1': '國文', '2': '英文', '3': '數學', '4': '理化', '5': '社會',
    Chinese: '國文', English: '英文', Math: '數學',
    Science: '理化', Physics: '物理', Chemistry: '化學', Biology: '生物', Social: '社會',
  };
  return map[val] || getSubjectText(val);
};

const approveEvaluation = async (evalItem) => {
  if (!confirm('確認核准此評量？')) return;
  const token = getToken();
  const baseUrl = getBaseUrl();
  const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
  try {
    const res = await fetch(`${baseUrl}/v1/learning-records/${evalItem.id}/approve`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({ DirectorID: session?.user?.id }),
    });
    if (res.ok) {
      pendingEvaluations.value = pendingEvaluations.value.filter(e => e.id !== evalItem.id);
      loadData();
    } else { const err = await res.json(); alert('核准失敗: ' + (err.message || '')); }
  } catch { alert('核准失敗'); }
};

const rejectEvaluation = async (evalItem) => {
  const note = prompt('退回原因：');
  if (!note) return;
  const token = getToken();
  const baseUrl = getBaseUrl();
  try {
    await fetch(`${baseUrl}/v1/learning-records/${evalItem.id}/reject`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({ ReviewNote: note }),
    });
    loadData();
  } catch (e) { console.error(e); }
};

const copyPaymentMessage = async (student) => {
  const token = getToken();
  const baseUrl = getBaseUrl();
  const studentId = student.student_id;

  if (student.alert_type === 'low_sessions') {
    const n = Number(student.remaining_lessons ?? 0);
    const name = student.raw_name || String(student.name || '').split(' — ')[0];
    const line = !Number.isFinite(n) || n <= 0
      ? '課程堂數已用完；若需繼續上課，請協助聯繫加購堂數。'
      : `課程目前僅剩 ${n} 堂，即將用完；如需續課請協助聯繫加購。`;
    const msg = `親愛的家長您好，\n\n${name} 同學：${line}\n\n如有疑問，歡迎聯繫補習班，謝謝！`;
    try { await navigator.clipboard.writeText(msg); alert('續課／加購提醒已複製到剪貼簿！'); }
    catch { prompt('請手動複製以下訊息：', msg); }
    return;
  }

  if (student.alert_type === 'monthly_due_soon') {
    const d = Number(student.days_until_settlement);
    let line = '';
    if (Number.isFinite(d) && d < 0) line = `本月月結費用已逾期 ${Math.abs(d)} 天，請盡快完成繳費。`;
    else if (d === 0) line = '今日為月結繳費日，請於今日完成繳費。';
    else line = `月結繳費日將至（剩 ${d} 天），請留意於期限內完成繳費。`;
    const due = student.due_date ? `（繳費日：${student.due_date}）` : '';
    const msg = `親愛的家長您好，\n\n${student.raw_name || student.name.split(' — ')[0]} 同學${due}\n${line}\n\n如有疑問，歡迎聯繫補習班，謝謝！`;
    try { await navigator.clipboard.writeText(msg); alert('繳費通知已複製到剪貼簿！'); }
    catch { prompt('請手動複製以下訊息：', msg); }
    return;
  }

  if (!studentId) {
    const msg = `親愛的家長您好，\n\n${student.raw_name || student.name.split(' — ')[0]} 同學的課程剩餘 ${student.remaining_lessons} 堂，請盡速繳費，以免影響上課。\n\n如有疑問，歡迎聯繫補習班，謝謝！`;
    try { await navigator.clipboard.writeText(msg); alert('繳費通知已複製到剪貼簿！'); }
    catch { prompt('請手動複製以下訊息：', msg); }
    return;
  }

  try {
    const res = await fetch(`${baseUrl}/v1/parent/payment-message/${studentId}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.message || '取得失敗');
    try { await navigator.clipboard.writeText(data.message); alert('繳費通知已複製到剪貼簿！'); }
    catch { prompt('請手動複製以下訊息：', data.message); }
  } catch (e) {
    alert('無法取得繳費通知：' + (e.message || ''));
  }
};

const scrollTo = (section) => {
  const el = document.getElementById(section + '-sec');
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

const applyFocusFromInbox = async () => {
  const section = props.focusSection || (props.focusWorkflowId ? 'exception-workflows' : null);
  const wfId = Number(props.focusWorkflowId || 0);
  if (!section && wfId <= 0) return;
  await nextTick();
  if (section) scrollTo(section);
  if (wfId <= 0) return;
  workflowFocusError.value = '';
  let match = exceptionWorkflows.value.find((w) => Number(w.id) === wfId);
  if (!match) {
    try {
      match = await getExceptionWorkflow(getToken(), wfId);
      if (!match) { workflowFocusError.value = '找不到此案件或沒有權限'; return; }
      exceptionWorkflows.value = reconcileFocusedWorkflowList(exceptionWorkflows.value, match);
      if (!isPendingWorkflowStatus(match.status)) {
        // Already handled (waived/confirmed/rejected) — do not resurrect it into the
        // actionable queue. See GitHub #1625 / in-app #215.
        workflowFocusError.value = '此案件已於稍早處理完畢';
        return;
      }
    } catch (err) {
      workflowFocusError.value = err?.message || '無法載入指定案件';
      return;
    }
  }
  await nextTick();
  const row = document.getElementById(`exception-workflow-${wfId}`);
  if (row) {
    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    const title = row.querySelector('h3, .ew-title, button, a') || row;
    if (title?.focus) { title.setAttribute('tabindex', '-1'); title.focus({ preventScroll: true }); }
  }
  if (match) {
    try { await loadWorkflowDetail(match); }
    catch (err) { workflowFocusError.value = err?.message || '案件詳情載入失敗'; }
  }
};

watch(() => [props.focusWorkflowId, props.focusSection, exceptionWorkflows.value.length], () => { applyFocusFromInbox(); });

watch(() => props.branchId, () => {
  teardownTrustImpressions();
  secondaryLoaded.value = false;
  analysisOpen.value = false;
  adoptionTaskRows.value = [];
  adoptionActivityRows.value = [];
  adoptionWeeklyMetrics.value = null;
  loadData();
  loadScheduleDiscrepancySummary();
  if (dashboardViewMode.value === 'full') loadSecondaryData();
});
onMounted(async () => {
  trackTrustEventOnce('dashboard_opened', Number(props.branchId) || 0, {
    role: 'director',
    page: 'director-dashboard',
  }, 'open');
  await loadData();
  await loadScheduleDiscrepancySummary();
  if (dashboardViewMode.value === 'full') await loadSecondaryData();
  applyFocusFromInbox();
});

onBeforeUnmount(() => {
  teardownTrustImpressions();
  if (workflowToastTimer) clearTimeout(workflowToastTimer);
});
</script>

<style scoped>
/* ===== Schedule Discrepancy card ===== */
.sd-card {
  cursor: pointer;
  transition: background 120ms ease, border-color 120ms ease, transform 120ms ease, box-shadow 120ms ease;
  outline: none;
}
.sd-card:hover {
  background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,250,252,0.96));
  box-shadow: 0 16px 36px rgba(15, 23, 42, 0.09);
}
.sd-card:focus-visible { box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.25); }
.sd-card--alert { border-left: 4px solid var(--warning, var(--ds-warning)); }

.sd-dash-body {
  display: flex;
  align-items: center;
  gap: 14px;
}
.sd-dash-num {
  font-size: 32px;
  font-weight: 800;
  color: var(--ds-ink);
  min-width: 54px;
  text-align: center;
  background: linear-gradient(145deg, var(--ds-canvas), var(--ds-canvas-soft));
  border: 1px solid rgba(245, 158, 11, 0.36);
  border-radius: 14px;
  padding: 6px 10px;
}
.sd-dash-text { flex: 1; min-width: 0; }
.sd-dash-title { font-weight: 700; font-size: 14px; color: var(--text, var(--ds-ink)); }
.sd-dash-sub { font-size: 12px; color: var(--text-light, var(--ds-ink-mute)); margin-top: 2px; }
.sd-dash-cta { align-self: center; }

.sd-dash-empty {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  margin: 0 14px 14px;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.18);
  background: rgba(248, 250, 252, 0.72);
}
.sd-dash-empty .material-symbols-outlined {
  font-size: 28px;
  color: var(--success-strong, var(--ds-success));
}

.sd-skel-wrap { padding: 6px 4px; display: flex; flex-direction: column; gap: 8px; }
.sd-skel-num, .sd-skel-line {
  background: linear-gradient(90deg, rgba(0,0,0,0.06) 25%, rgba(0,0,0,0.12) 50%, rgba(0,0,0,0.06) 75%);
  background-size: 200% 100%;
  border-radius: 6px;
  animation: sd-skel 1.4s ease-in-out infinite;
}
.sd-skel-num { width: 50%; height: 36px; }
.sd-skel-line { width: 80%; height: 12px; }
@keyframes sd-skel {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

/* ===== Parent leave inbox ===== */
.leave-inbox {
  position: relative;
  overflow: hidden;
  border: 1px solid var(--ds-hairline);
  border-top: 3px solid var(--ds-warning);
  border-radius: 16px;
  background: var(--ds-canvas);
  box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
}
.leave-inbox__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 18px;
  padding: 18px 20px;
  background: var(--ds-warning-wash);
  border-bottom: 1px solid var(--ds-hairline);
}
.leave-inbox__heading { display: flex; align-items: flex-start; gap: 12px; min-width: 0; }
.leave-inbox__icon {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  flex: 0 0 36px;
  border-radius: 10px;
  background: var(--ds-warning);
  color: var(--ds-on-primary);
  font-size: 20px;
}
.leave-inbox__eyebrow, .leave-modal__eyebrow {
  margin: 0 0 3px;
  color: var(--ds-warning);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
.leave-inbox h3, .leave-modal h3 { margin: 0; color: var(--ds-ink); font-size: 18px; }
.leave-inbox__description { margin: 4px 0 0; color: var(--ds-ink-mute); font-size: 13px; }
.leave-inbox__count {
  display: grid;
  justify-items: center;
  min-width: 78px;
  padding: 8px 12px;
  border: 1px solid var(--ds-warning);
  border-radius: 12px;
  background: var(--ds-canvas);
  color: var(--ds-warning);
}
.leave-inbox__count strong { font-size: 22px; line-height: 1; }
.leave-inbox__count span { margin-top: 4px; font-size: 11px; font-weight: 700; }
.leave-inbox__state, .leave-inbox__empty { padding: 22px 20px; color: var(--ds-ink-mute); font-size: 13px; }
.leave-inbox__empty { display: flex; align-items: center; gap: 10px; }
.leave-inbox__empty .material-symbols-outlined { color: var(--ds-success); font-size: 24px; }
.leave-inbox__empty strong, .leave-inbox__empty span { display: block; }
.leave-inbox__empty strong { color: var(--ds-ink); }
.leave-inbox__empty span { margin-top: 2px; }
.leave-inbox__error { margin: 14px 20px 0; padding: 10px 12px; border: 1px solid var(--ds-danger); border-radius: 10px; background: var(--ds-danger-wash); color: var(--ds-danger); font-size: 13px; }
.leave-inbox__list { display: grid; gap: 12px; padding: 16px 20px 20px; }
.leave-case { overflow: hidden; border: 1px solid var(--ds-hairline); border-radius: 14px; background: var(--ds-canvas); }
.leave-case__topline { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px 10px; }
.leave-case__identity { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; min-width: 0; color: var(--ds-ink); }
.leave-case__dot { width: 8px; height: 8px; flex: 0 0 8px; border-radius: 50%; background: var(--ds-warning); box-shadow: 0 0 0 4px var(--ds-warning-wash); }
.leave-case__status { padding: 4px 8px; border-radius: 6px; background: var(--ds-warning-wash); color: var(--ds-warning); font-size: 11px; font-weight: 800; }
.leave-case__id { color: var(--ds-ink-mute); font-size: 11px; white-space: nowrap; }
.leave-case__details { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 12px; margin: 0; padding: 0 16px 14px; }
.leave-case__details div { min-width: 0; }
.leave-case__details dt { color: var(--ds-ink-mute); font-size: 11px; font-weight: 700; }
.leave-case__details dd { margin: 3px 0 0; color: var(--ds-ink); font-size: 13px; overflow-wrap: anywhere; }
.leave-case__next-step { display: flex; align-items: flex-start; gap: 8px; margin: 0 16px 14px; padding: 10px 12px; border-radius: 9px; background: var(--ds-canvas-soft); color: var(--ds-ink-secondary); font-size: 12px; line-height: 1.5; }
.leave-case__next-step .material-symbols-outlined { color: var(--ds-warning); font-size: 18px; }
.leave-case__candidate-panel { margin: 0 16px 14px; padding: 12px; border: 1px solid var(--ds-hairline); border-radius: 10px; background: var(--ds-canvas-soft); }
.leave-case__candidate-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 9px; }
.leave-case__candidate-heading strong, .leave-case__candidate-heading span { display: block; }
.leave-case__candidate-heading strong { color: var(--ds-ink); font-size: 13px; }
.leave-case__candidate-heading span { margin-top: 2px; color: var(--ds-ink-mute); font-size: 11px; }
.leave-case__text-button { border: 0; background: transparent; color: var(--ds-primary-deep); font-size: 12px; font-weight: 700; cursor: pointer; }
.leave-case__text-button:disabled { opacity: 0.5; cursor: not-allowed; }
.leave-case__candidate-list { display: grid; gap: 6px; }
.leave-candidate { position: relative; display: flex; align-items: center; gap: 9px; min-height: 46px; padding: 8px 10px; border: 1px solid var(--ds-hairline); border-radius: 9px; background: var(--ds-canvas); cursor: pointer; }
.leave-candidate:hover, .leave-candidate:focus-within { border-color: var(--ds-primary); }
.leave-candidate--selected { border-color: var(--ds-primary); background: var(--ds-primary-wash); box-shadow: 0 0 0 2px var(--ds-primary-wash); }
.leave-candidate input { position: absolute; width: 1px; height: 1px; opacity: 0; }
.leave-candidate__radio { width: 16px; height: 16px; flex: 0 0 16px; border: 2px solid var(--ds-ink-mute); border-radius: 50%; }
.leave-candidate--selected .leave-candidate__radio { border: 5px solid var(--ds-primary); }
.leave-candidate__body { display: flex; align-items: baseline; gap: 8px; min-width: 0; color: var(--ds-ink); }
.leave-candidate__body strong { font-size: 13px; }
.leave-candidate__body span { color: var(--ds-ink-secondary); font-size: 13px; }
.leave-candidate__rank { margin-left: auto; color: var(--ds-ink-mute); font-size: 11px; white-space: nowrap; }
.leave-case__actions { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; padding: 12px 16px; border-top: 1px solid var(--ds-hairline); background: var(--ds-canvas-soft); }
.leave-button { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 34px; padding: 7px 12px; border: 1px solid transparent; border-radius: 8px; font-size: 12px; font-weight: 800; cursor: pointer; }
.leave-button .material-symbols-outlined { font-size: 17px; }
.leave-button--primary { background: var(--ds-cta); color: var(--ds-on-cta); }
.leave-button--primary:hover { background: var(--ds-cta-hover); }
.leave-button--secondary { border-color: var(--ds-hairline-input); background: var(--ds-canvas); color: var(--ds-ink); }
.leave-button--secondary:hover { border-color: var(--ds-primary); }
.leave-button--danger { border-color: var(--ds-danger); background: transparent; color: var(--ds-danger); }
.leave-button--danger:hover { background: var(--ds-danger-wash); }
.leave-button:disabled { opacity: 0.5; cursor: not-allowed; }
.leave-modal-backdrop { position: fixed; inset: 0; z-index: 1000; display: grid; place-items: center; padding: 20px; background: rgba(15, 23, 42, 0.42); }
.leave-modal { position: relative; width: min(100%, 460px); padding: 24px; border: 1px solid var(--ds-hairline); border-radius: 16px; background: var(--ds-canvas); box-shadow: 0 24px 64px rgba(15, 23, 42, 0.22); }
.leave-modal__close { position: absolute; top: 10px; right: 12px; width: 30px; height: 30px; border: 0; border-radius: 50%; background: transparent; color: var(--ds-ink-mute); font-size: 24px; cursor: pointer; }
.leave-modal__description { margin: 8px 0 16px; color: var(--ds-ink-secondary); font-size: 13px; line-height: 1.6; }
.leave-modal__selection { display: grid; gap: 4px; margin-bottom: 16px; padding: 12px; border-radius: 10px; background: var(--ds-primary-wash); color: var(--ds-ink); }
.leave-modal__selection span { color: var(--ds-ink-mute); font-size: 11px; }
.leave-modal__selection strong { font-size: 14px; }
.leave-modal__field { display: grid; gap: 6px; margin-bottom: 16px; color: var(--ds-ink); font-size: 12px; font-weight: 700; }
.leave-modal__field textarea { width: 100%; box-sizing: border-box; resize: vertical; border: 1px solid var(--ds-hairline-input); border-radius: 8px; padding: 9px 10px; background: var(--ds-canvas); color: var(--ds-ink); font: inherit; font-weight: 400; }
.leave-modal__field textarea:focus { outline: 2px solid var(--ds-primary-wash); border-color: var(--ds-primary); }
.leave-modal__error { margin: 0 0 12px; color: var(--ds-danger); font-size: 12px; }
.leave-modal__actions { display: flex; justify-content: flex-end; gap: 8px; }
.leave-toast { position: fixed; right: 24px; bottom: 24px; z-index: 1010; max-width: min(360px, calc(100vw - 48px)); padding: 12px 16px; border: 1px solid var(--ds-success); border-radius: 10px; background: var(--ds-canvas); color: var(--ds-ink); box-shadow: 0 12px 30px rgba(15, 23, 42, 0.16); font-size: 13px; font-weight: 700; }
@media (max-width: 860px) {
  .leave-inbox__header { padding: 16px; }
  .leave-inbox__list { padding: 12px 14px 14px; }
  .leave-case__details { grid-template-columns: 1fr; gap: 8px; }
  .leave-case__actions { align-items: stretch; }
  .leave-button { flex: 1 1 auto; }
}
@media (max-width: 520px) {
  .leave-inbox__header, .leave-case__topline { align-items: flex-start; }
  .leave-inbox__header { flex-direction: column; }
  .leave-inbox__count { align-self: flex-start; }
  .leave-case__topline { flex-direction: column; gap: 8px; }
  .leave-case__id { align-self: flex-start; }
  .leave-case__actions, .leave-modal__actions { flex-direction: column; }
  .leave-button { width: 100%; }
  .leave-candidate__body { display: grid; gap: 2px; }
  .leave-candidate__rank { font-size: 10px; }
}

/* ===== Layout ===== */
.dash {
  display: flex;
  flex-direction: column;
  gap: 22px;
  max-width: 1260px;
  margin: 0 auto;
  position: relative;
  padding: 0 12px 34px;
}
/* (DS) 移除後台不採用的彩色 gradient mesh 背景，回歸淺色乾淨底。 */

.no-branch-card {
  max-width: 480px;
  margin: 2rem auto;
  padding: 2rem;
  text-align: center;
}
.no-branch-card h2 { margin-bottom: 1rem; }
.no-branch-card .hint { margin-top: 1rem; font-size: 0.9rem; color: var(--text-light); }

/* ===== Header ===== */
/* (DS) 淺色乾淨面板、細邊框、輕陰影；頂端一條品牌暖色（logo 橘黃）做識別。 */
.dash-header {
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 22px;
  flex-wrap: wrap;
  padding: 24px 28px;
  border-radius: 16px;
  border: 1px solid var(--ds-hairline);
  background: var(--ds-canvas);
  box-shadow: 0 1px 3px rgba(0, 55, 112, 0.08);
  color: var(--ds-ink);
}
.dash-header::before {
  content: '';
  position: absolute;
  inset: 0 0 auto;
  height: 4px;
  background: var(--ds-brand-gradient);
}
.dash-title-block,
.dash-date-panel {
  position: relative;
  z-index: 1;
}
.dash-kicker {
  margin: 0 0 6px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--ds-brand-orange);
}
.dash-title {
  font-size: clamp(1.75rem, 3vw, 2.5rem);
  font-weight: 700;
  color: var(--ds-ink);
  margin: 0;
  letter-spacing: -0.02em;
  line-height: 1.1;
}
.dash-subtitle {
  margin: 10px 0 0;
  color: var(--ds-ink-mute);
  font-size: 14px;
  font-weight: 500;
  max-width: 42rem;
}
.dash-engagement-strip {
  margin-top: 14px;
  padding: 10px 14px;
  border-radius: 14px;
  border: 1px solid color-mix(in srgb, var(--porsche-border) 88%, transparent);
  background: rgba(255, 255, 255, 0.55);
  max-width: 42rem;
}
.dash-date-panel {
  display: grid;
  gap: 4px;
  min-width: 180px;
  padding: 12px 16px;
  border-radius: 12px;
  border: 1px solid var(--ds-hairline);
  background: var(--ds-canvas-soft);
}
.dash-date-label {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--ds-ink-mute);
}
.dash-date {
  color: var(--ds-ink);
  font-size: 15px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

/* ===== Action Lane ===== */
.action-lane {
  display: flex;
  gap: 12px;
  overflow-x: auto;
  padding: 12px;
  scroll-snap-type: x mandatory;
  border-radius: 16px;
  border: 1px solid var(--ds-hairline);
  background: var(--ds-canvas-soft);
}

/* (DS) 待辦卡：統一白卡 + 細邊框，語意僅靠左側細條與 icon 色，不再用 7 色彩虹。 */
.ac {
  position: relative;
  overflow: hidden;
  flex: 1 1 0;
  min-width: 170px;
  max-width: 260px;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 16px;
  border-radius: 12px;
  border: 1px solid var(--ds-hairline);
  border-left: 3px solid var(--ds-ink-mute);
  background: var(--ds-canvas);
  box-shadow: 0 1px 2px rgba(0, 55, 112, 0.06);
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
  scroll-snap-align: start;
}
.ac:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 55, 112, 0.1);
}
.ac:focus-visible { outline: 2px solid var(--ds-primary); outline-offset: 2px; }

.ac--attend  { border-left-color: var(--ds-danger); }
.ac--pay     { border-left-color: var(--ds-warning); }
.ac--eval    { border-left-color: var(--ds-info); }
.ac--feedback{ border-left-color: var(--ds-warning); }
.ac--makeup  { border-left-color: var(--ds-primary-soft); }
.ac--workflow{ border-left-color: var(--ds-success); }
.ac--import  { border-left-color: var(--ds-success); }
.ac--clear  {
  border-left-color: var(--ds-success);
  background: var(--ds-canvas);
  cursor: default;
}
.ac--clear:hover { transform: none; box-shadow: 0 1px 2px rgba(0, 55, 112, 0.06); }

.ac__icon {
  font-size: 26px;
  flex-shrink: 0;
}
.ac--attend .ac__icon { color: var(--ds-danger); }
.ac--pay    .ac__icon { color: var(--ds-warning); }
.ac--eval   .ac__icon { color: var(--ds-info); }
.ac--feedback .ac__icon { color: var(--ds-warning); }
.ac--makeup .ac__icon { color: var(--ds-primary-soft); }
.ac--workflow .ac__icon { color: var(--ds-success); }
.ac--import .ac__icon { color: var(--ds-success); }
.ac--clear  .ac__icon { color: var(--ds-success); }

.ac__body {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  flex: 1;
}
.ac__count {
  font-size: 28px;
  font-weight: 800;
  line-height: 1.1;
  color: var(--text, var(--ds-ink));
  font-variant-numeric: tabular-nums;
}
.ac__label {
  font-size: 12px;
  font-weight: 700;
  color: var(--text-light, var(--ds-ink-mute));
  white-space: nowrap;
}
.ac__sub {
  font-size: 11px;
  color: var(--ds-ink-mute);
}

.ac__cta {
  flex-shrink: 0;
  padding: 6px 12px;
  font-size: 12px;
  font-weight: 800;
  border: none;
  border-radius: 999px;
  cursor: pointer;
  white-space: nowrap;
  transition: background 0.15s;
}
.ac--attend .ac__cta { background: var(--ds-danger-wash); color: var(--ds-danger); }
.ac--attend .ac__cta:hover { background: var(--ds-danger-wash); }
.ac--pay .ac__cta    { background: var(--ds-warning-wash); color: var(--ds-warning); }
.ac--pay .ac__cta:hover { background: var(--ds-warning-wash); }
.ac--eval .ac__cta   { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); }
.ac--eval .ac__cta:hover { background: var(--ds-canvas-soft); }
.ac--feedback .ac__cta { background: var(--ds-warning-wash); color: var(--ds-warning); }
.ac--feedback .ac__cta:hover { background: var(--ds-warning-wash); }
.ac--makeup .ac__cta { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); }
.ac--makeup .ac__cta:hover { background: var(--ds-canvas-soft); }
.ac--workflow .ac__cta { background: var(--ds-success-wash); color: var(--ds-ink); }
.ac--workflow .ac__cta:hover { background: var(--ds-success-wash); }
.ac--import .ac__cta { background: var(--ds-success-wash); color: var(--ds-success); }
.ac--import .ac__cta:hover { background: var(--ds-success-wash); }
.ac__cta:disabled { opacity: 0.5; cursor: not-allowed; }

/* Decision Center */
.ops-trust{margin:0 0 16px;padding:14px;border:1px solid var(--ds-hairline);border-radius:16px;background:var(--ds-canvas);box-shadow:var(--ds-shadow-1)}
.ops-trust__head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:10px}
.ops-trust__kicker{margin:0 0 4px;font-size:12px;color:var(--ds-ink-mute);font-weight:600}
.ops-trust__score{margin:0;display:flex;align-items:baseline;gap:4px;line-height:1}
.ops-trust__score-num{font-size:40px;font-weight:800}
.ops-trust__score-max{font-size:16px;color:var(--ds-ink-mute);font-weight:600}
.ops-trust__score--green .ops-trust__score-num{color:var(--ds-success)}.ops-trust__score--yellow .ops-trust__score-num{color:var(--ds-warning)}.ops-trust__score--red .ops-trust__score-num{color:var(--ds-danger)}
.ops-trust__headline{margin:8px 0 0;font-size:14px;max-width:36rem}.ops-trust__stamp{font-size:12px;color:var(--ds-ink-mute);white-space:nowrap}
.ops-trust__decisions{display:flex;flex-direction:column;gap:10px}
.ops-decision{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;border-radius:12px;border:1px solid var(--ds-hairline);border-left-width:4px;padding:12px 14px;background:var(--ds-surface)}
.ops-decision--critical{border-left-color:var(--ds-danger)}.ops-decision--warning{border-left-color:var(--ds-warning)}
.ops-decision__body{flex:1;min-width:0}.ops-decision__body strong{display:block;font-size:14px;margin-bottom:4px}
.ops-decision__involved{margin:0 0 6px;font-size:13px;font-weight:700;color:var(--ds-ink);line-height:1.4}
.ops-decision__why,.ops-decision__next{margin:0 0 4px;font-size:13px;color:var(--ds-ink-mute);line-height:1.4}
.ops-decision__detail,.ops-decision__owner{display:inline-block;margin-right:10px;font-size:12px;color:var(--ds-ink-mute)}
.ops-decision__cta{flex-shrink:0;padding:8px 12px;border:none;border-radius:999px;font-size:12px;font-weight:800;cursor:pointer;background:var(--ds-warning-wash);color:var(--ds-warning)}
.ops-decision--critical .ops-decision__cta{background:var(--ds-danger-wash);color:var(--ds-danger)}
.ops-decision__people{list-style:none;margin:10px 0 0;padding:0;display:flex;flex-direction:column;gap:6px}
.ops-person{width:100%;text-align:left;border:1px solid var(--ds-hairline);border-radius:10px;padding:8px 10px;background:var(--ds-canvas);cursor:pointer;display:flex;flex-direction:column;gap:2px}
.ops-person:hover{border-color:var(--ds-warning)}
.ops-person__who{font-size:13px;font-weight:700;color:var(--ds-ink)}
.ops-person__meta,.ops-person__why,.ops-person__next{font-size:12px;color:var(--ds-ink-mute);line-height:1.35}
.ops-person__next{color:var(--ds-ink)}
.ops-decision__empty-people{margin:8px 0 0;font-size:12px;color:var(--ds-ink-mute)}
.ops-trust__policy{margin-top:12px;font-size:12px;color:var(--ds-ink-mute)}.ops-trust__policy ul{margin:6px 0 0;padding-left:1.2rem}


/* ===== Import Banner ===== */
.import-banner {
  background: var(--ds-success-wash);
  border: 1px solid var(--ds-success-wash);
  border-radius: 10px;
  padding: 14px 16px;
}
.import-banner--err {
  background: var(--ds-danger-wash);
  border-color: var(--ds-danger-wash);
}
.import-banner__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}
.import-banner__head strong { font-size: 14px; }
.import-banner__x {
  border: none;
  background: none;
  font-size: 20px;
  cursor: pointer;
  color: var(--ds-ink-mute);
  line-height: 1;
  padding: 0 4px;
}
.import-banner__x:hover { color: var(--ds-ink); }

.import-banner__stats {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 6px;
}
.ib-chip {
  display: inline-flex;
  align-items: center;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  background: var(--ds-canvas-soft);
  color: var(--ds-ink);
}
.ib-chip--ok   { background: var(--ds-success-wash); color: var(--ds-success); }
.ib-chip--info { background: var(--ds-canvas-soft); color: var(--ds-ink-mute); }
.ib-chip--warn { background: var(--ds-warning-wash); color: var(--ds-warning); }
.ib-chip--err  { background: var(--ds-danger-wash); color: var(--ds-danger); }

.import-banner__list {
  font-size: 12px;
  color: var(--ds-ink-mute);
  padding: 6px 0;
  border-top: 1px solid var(--ds-canvas-soft);
  margin-top: 6px;
}
.import-banner__list > div { padding: 2px 0; }
.import-banner__list--warn { color: var(--ds-warning); }
.import-banner__list--err  { color: var(--ds-danger); }
.import-banner__foot {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
  margin-top: 8px;
}

/* ===== Progress Board ===== */
.progress-board {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  background: var(--ds-canvas-soft);
  border: 1px solid var(--ds-hairline);
  border-radius: var(--ds-radius-lg);
  padding: 12px;
}

.pb-cell {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.pb-cell :deep(.at-metric) {
  flex: 1;
}
.pb__bar {
  height: 5px;
  border-radius: 999px;
  background: var(--ds-hairline);
  overflow: hidden;
}
.pb__fill {
  height: 100%;
  border-radius: 999px;
  background: var(--ds-primary);
  transition: width 0.4s ease;
}

.work-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.view-switch {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px;
  background: rgba(248, 250, 252, 0.9);
  border: 1px solid rgba(148, 163, 184, 0.3);
  border-radius: 999px;
}
.view-switch__btn {
  border: 0;
  background: transparent;
  color: var(--ds-ink);
  font-size: 12px;
  font-weight: 700;
  padding: 7px 12px;
  border-radius: 999px;
  cursor: pointer;
  transition: background 0.15s ease, color 0.15s ease;
}
.view-switch__btn:hover {
  background: rgba(255, 255, 255, 0.9);
  color: var(--ds-ink);
}
.view-switch__btn--active {
  background: linear-gradient(135deg, var(--ds-ink), var(--ds-ink));
  color: var(--ds-canvas);
}
.work-toolbar__hint {
  margin: 0;
  font-size: 12px;
  color: var(--ds-ink-mute);
  text-align: right;
}

/* ===== Work Grid ===== */
.work-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20px;
}
.work-col {
  display: flex;
  flex-direction: column;
  gap: 20px;
}
.work-col--side .wp__hint {
  font-size: 11px;
}

/* ===== Work Panel ===== */
.wp {
  position: relative;
  background: var(--ds-canvas);
  border: 1px solid var(--ds-hairline);
  border-radius: 12px;
  box-shadow: 0 1px 3px rgba(0, 55, 112, 0.08);
  overflow: hidden;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.wp:hover {
  border-color: var(--ds-hairline-input);
  box-shadow: 0 4px 12px rgba(0, 55, 112, 0.08);
}
.wp--warn { border-top: 2px solid var(--ds-warning); }

.wp__head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 16px 18px 12px;
  border-bottom: 1px solid var(--ds-hairline);
}
.wp__head h3 {
  font-size: 15px;
  font-weight: 700;
  color: var(--ds-ink);
  margin: 0;
  flex: 1;
  letter-spacing: -0.01em;
}
.wp__hi {
  font-size: 20px;
  color: var(--ds-ink-mute);
}
.wp__badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 22px;
  height: 22px;
  padding: 0 6px;
  font-size: 11px;
  font-weight: 800;
  border-radius: 999px;
  background: var(--ds-canvas-soft);
  color: var(--ds-ink);
  border: 1px solid rgba(148, 163, 184, 0.28);
}
.wp__badge--danger { background: var(--ds-danger-wash); color: var(--ds-danger); border-color: var(--ds-danger-wash); }
.wp__badge--warn { background: var(--ds-warning-wash); color: var(--ds-warning); border-color: var(--ds-warning); }

.wp__hint {
  margin: 0;
  padding: 0 18px 10px;
  font-size: 12px;
  color: var(--ds-ink-mute);
  line-height: 1.55;
}
.wp__empty {
  margin: 0 14px 14px;
  padding: 18px 16px;
  text-align: center;
  font-size: 13px;
  color: var(--ds-ink-mute);
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.18);
  background: rgba(248, 250, 252, 0.72);
}
.wp__foot {
  padding: 12px 18px;
  border-top: 1px solid rgba(226, 232, 240, 0.82);
  display: flex;
  justify-content: flex-end;
}

/* ===== AtCard-based work-grid cards (Wave B) ===== */
.dash-card-head-title {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.dash-card-head-title h3 {
  font-size: 15px;
  font-weight: 700;
  color: var(--ds-ink);
  margin: 0;
  letter-spacing: -0.01em;
}
.dash-card-foot {
  margin: 16px -24px -24px;
  padding: 12px 18px;
  border-top: 1px solid rgba(226, 232, 240, 0.82);
  display: flex;
  justify-content: flex-end;
}
.dash-card-warn :deep(.at-card) {
  border-top: 2px solid var(--ds-warning);
}

/* ===== Schedule Row ===== */
.sched-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 12px 8px;
  padding: 11px 12px;
  font-size: 13px;
  border: 1px solid rgba(226, 232, 240, 0.9);
  border-radius: 14px;
  background: rgba(255,255,255,0.82);
  transition: background 0.1s, border-color 0.1s, transform 0.1s;
}
.sched-row:hover {
  background: var(--ds-canvas);
  border-color: rgba(15, 23, 42, 0.14);
  transform: translateY(-1px);
}
.sched-row__time {
  font-weight: 800;
  color: var(--text, var(--ds-ink));
  min-width: 44px;
  flex-shrink: 0;
}
.sched-row__name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sched-row__subj {
  font-size: 11px;
  padding: 3px 9px;
  border-radius: 999px;
  background: var(--ds-canvas-soft);
  color: var(--ds-ink);
  border: 1px solid rgba(148, 163, 184, 0.22);
  white-space: nowrap;
}
.sched-row__tchr {
  font-size: 11px;
  color: var(--ds-ink-mute);
  white-space: nowrap;
}
.sched-row__st {
  font-size: 11px;
  font-weight: 900;
  white-space: nowrap;
}
.st--scheduled { color: var(--ds-warning); }
.st--attended  { color: var(--ds-success); }
.st--completed { color: var(--ds-ink-mute); }
.st--leave, .st--leave_adjusted, .st--excused { color: var(--ds-ink-mute); }
.st--absent    { color: var(--ds-danger); }
.st--late      { color: var(--ds-warning); }
.st--cancelled { color: var(--ds-ink-mute); }

/* ===== Payment Row ===== */
.pay-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 12px 8px;
  padding: 11px 12px;
  border: 1px solid rgba(226, 232, 240, 0.9);
  border-radius: 14px;
  background: rgba(255,255,255,0.84);
  transition: background 0.1s, border-color 0.1s, transform 0.1s;
}
.pay-row:hover {
  background: var(--ds-warning-wash);
  border-color: rgba(245, 158, 11, 0.32);
  transform: translateY(-1px);
}
.pay-row__info {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.pay-row__name {
  font-size: 13px;
  font-weight: 700;
  color: var(--ds-ink);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* ===== Eval Card ===== */
.eval-card {
  margin: 0 12px 10px;
  padding: 13px 14px;
  border: 1px solid rgba(226, 232, 240, 0.9);
  border-radius: 16px;
  background: rgba(255,255,255,0.86);
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.82);
  transition: border-color 0.1s, transform 0.1s;
}
.eval-card:last-of-type { border-bottom: 1px solid rgba(226, 232, 240, 0.9); }
.eval-card:hover {
  border-color: rgba(37, 99, 235, 0.22);
  transform: translateY(-1px);
}
.eval-card__top {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 4px;
}
.eval-card__top strong { font-size: 13px; font-weight: 900; color: var(--ds-ink); }
.eval-card__tag {
  font-size: 11px;
  padding: 3px 9px;
  border-radius: 999px;
  background: var(--ds-canvas-soft);
  color: var(--ds-ink-mute);
  border: 1px solid var(--ds-canvas-soft);
  font-weight: 800;
}
.eval-card__tchr {
  font-size: 11px;
  color: var(--ds-ink-mute);
  margin-left: auto;
}
.eval-card__mid {
  font-size: 12px;
  color: var(--ds-ink-mute);
  margin-bottom: 4px;
}
.eval-card__comment {
  font-size: 12px;
  color: var(--ds-ink);
  background: rgba(248, 250, 252, 0.86);
  padding: 8px 10px;
  border-radius: 12px;
  border: 1px solid rgba(226, 232, 240, 0.9);
  margin-bottom: 6px;
  line-height: 1.5;
}
.eval-card__acts {
  display: flex;
  gap: 6px;
  justify-content: flex-end;
}

/* ===== Notification Row ===== */
.notif-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  margin: 0 12px 8px;
  padding: 11px 12px;
  font-size: 13px;
  border: 1px solid rgba(226, 232, 240, 0.9);
  border-radius: 14px;
  background: rgba(255,255,255,0.84);
}

.todo-row {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
  width: calc(100% - 24px);
  margin: 0 12px 8px;
  padding: 11px 12px;
  border: 1px solid rgba(226, 232, 240, 0.9);
  border-radius: 14px;
  background: rgba(255,255,255,0.84);
  cursor: pointer;
}
.todo-row--breached {
  border-left: 3px solid var(--ds-danger);
}
.todo-row--warning {
  border-left: 3px solid var(--ds-warning);
}
.todo-row:hover {
  border-color: rgba(37, 99, 235, 0.22);
  background: var(--ds-canvas-soft);
}
.todo-row__title {
  font-size: 13px;
  font-weight: 700;
  color: var(--ds-ink);
}
.todo-row__meta {
  font-size: 12px;
  color: var(--ds-ink-mute);
}

/* ===== KPI Panel ===== */
.kpi {
  background:
    radial-gradient(circle at top right, rgba(15, 23, 42, 0.035), transparent 34%),
    linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,250,252,0.94));
  border: 1px solid rgba(15, 23, 42, 0.08);
  border-radius: 22px;
  box-shadow: 0 18px 46px rgba(15,23,42,0.07), inset 0 1px 0 rgba(255,255,255,0.9);
  overflow: hidden;
}
.kpi__sum {
  list-style: none;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 16px 18px;
  cursor: pointer;
  user-select: none;
  font-size: 14px;
  font-weight: 800;
  color: var(--text, var(--ds-ink));
  transition: background 0.15s;
}
.kpi__sum::-webkit-details-marker { display: none; }
.kpi__sum:hover { background: rgba(248,250,252,0.78); }
.kpi__sum-icon { font-size: 20px; color: var(--ds-ink); }
.kpi__sum-hint {
  margin-left: auto;
  font-size: 12px;
  font-weight: 400;
  color: var(--ds-ink-mute);
}
.kpi__chev {
  font-size: 14px;
  color: var(--ds-ink-mute);
  transition: transform 0.2s ease;
}
.kpi[open] .kpi__chev { transform: rotate(180deg); }

.kpi__body {
  padding: 0 18px 18px;
}

.kpi-totals {
  display: flex;
  gap: 24px;
  margin-bottom: 16px;
  padding-bottom: 14px;
  border-bottom: 1px solid rgba(226, 232, 240, 0.9);
}
.kpi-totals--adoption {
  margin-bottom: 10px;
}
.kpi-t__label {
  font-size: 11px;
  font-weight: 800;
  color: var(--text-light, var(--ds-ink-mute));
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.kpi-t__val {
  font-size: 26px;
  font-weight: 800;
  color: var(--text, var(--ds-ink));
  margin-top: 2px;
}
.kpi-t__delta {
  margin-top: 2px;
  font-size: 12px;
  color: var(--ds-ink-mute);
}

.kpi-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
  margin-bottom: 12px;
}
.kpi-table th {
  text-align: left;
  font-size: 11px;
  font-weight: 600;
  color: var(--text-light, var(--ds-ink-mute));
  padding: 8px 8px;
  border-bottom: 2px solid var(--ds-canvas-soft);
}
.kpi-table td {
  padding: 8px 8px;
  border-bottom: 1px solid var(--ds-canvas-soft);
}
.kpi-table tr:hover td { background: rgba(248,250,252,0.86); }

.kpi__link {
  margin-top: 14px;
  display: flex;
  justify-content: flex-end;
}

/* ===== Level Chips ===== */
.level-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 10px;
}
.level-chip {
  display: inline-block;
  background: var(--ds-canvas-soft);
  color: var(--ds-ink);
  padding: 4px 12px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 800;
  border: 1px solid rgba(148, 163, 184, 0.28);
}

/* ===== Badges ===== */
.badge-blue {
  display: inline-block;
  background: var(--ds-canvas-soft);
  color: var(--ds-ink-mute);
  font-size: 11px;
  padding: 3px 9px;
  border-radius: 999px;
  border: 1px solid var(--ds-canvas-soft);
  font-weight: 800;
}
.badge-red {
  display: inline-block;
  background: var(--ds-danger-wash);
  color: var(--ds-danger);
  font-size: 11px;
  padding: 3px 9px;
  border-radius: 999px;
  font-weight: 800;
  border: 1px solid var(--ds-danger-wash);
}
.badge-orange {
  display: inline-block;
  background: var(--ds-warning-wash);
  color: var(--ds-primary);
  font-size: 11px;
  padding: 3px 9px;
  border-radius: 999px;
  font-weight: 800;
  border: 1px solid var(--ds-warning-wash);
}
.badge-amber {
  display: inline-block;
  background: var(--ds-warning-wash);
  color: var(--ds-warning);
  font-size: 11px;
  padding: 3px 9px;
  border-radius: 999px;
  font-weight: 800;
  border: 1px solid var(--ds-warning);
}

/* ===== Buttons ===== */
.btn-p {
  border: none;
  border-radius: 999px;
  font-weight: 800;
  cursor: pointer;
  background: var(--ds-primary, var(--ds-primary));
  color: var(--ds-canvas);
  transition: background 0.15s, transform 0.1s;
}
.btn-p:hover { background: var(--ds-primary-deep, var(--ds-primary)); }
.btn-p:active { transform: scale(0.98); }
.btn-d {
  border: none;
  border-radius: 999px;
  font-weight: 800;
  cursor: pointer;
  background: var(--ds-danger, var(--ds-danger));
  color: var(--ds-canvas);
  transition: background 0.15s, transform 0.1s;
}
.btn-d:hover { background: color-mix(in srgb, var(--ds-danger, var(--ds-danger)) 86%, var(--ds-ink)); }
.btn-d:active { transform: scale(0.98); }
.btn-o {
  border: 1px solid var(--ds-hairline, var(--ds-hairline));
  border-radius: 999px;
  font-weight: 800;
  cursor: pointer;
  background: var(--ds-canvas, var(--ds-canvas));
  color: var(--ds-ink-secondary, var(--ds-ink-secondary));
  transition: background 0.15s, border-color 0.15s, color 0.15s;
}
.btn-o:hover { background: var(--ds-canvas-soft, var(--ds-canvas-soft)); border-color: color-mix(in srgb, var(--ds-primary, var(--ds-primary)) 28%, var(--ds-canvas)); color: var(--ds-ink, var(--ds-ink)); }

.btn-sm { padding: 6px 14px; font-size: 12px; }
.btn-xs { padding: 4px 10px; font-size: 11px; }

/* ===== Desktop: shorter vertical rhythm + scrollable long lists ===== */
@media (min-width: 1100px) {
  .dash.dash--desktop-dense {
    gap: 14px;
    padding: 0 12px 22px;
    max-width: 1320px;
  }
  .dash.dash--desktop-dense .dash-header {
    min-height: 148px;
    padding: 22px 26px;
    border-radius: 26px;
  }
  .dash.dash--desktop-dense .dash-subtitle {
    font-size: 13px;
    max-width: 52ch;
    line-height: 1.45;
  }
  .dash.dash--desktop-dense .action-lane {
    padding: 8px;
    gap: 10px;
    border-radius: 22px;
  }
  .dash.dash--desktop-dense .ac {
    min-width: 150px;
    padding: 12px 14px;
  }
  .dash.dash--desktop-dense .ac__count {
    font-size: 24px;
  }
  .dash.dash--desktop-dense .progress-board {
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 8px;
    padding: 10px;
    border-radius: var(--ds-radius-lg);
  }
  .dash.dash--desktop-dense .work-grid {
    gap: 14px;
    align-items: flex-start;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .dash.dash--desktop-dense .work-toolbar {
    margin-top: -2px;
  }
  .dash.dash--desktop-dense .work-col {
    gap: 14px;
  }
  .dash.dash--desktop-dense .wp__head {
    padding: 12px 14px 10px;
  }
  .dash.dash--desktop-dense .wp-list-scroll-desktop {
    max-height: min(280px, 34vh);
    overflow-y: auto;
    overscroll-behavior: contain;
    scrollbar-gutter: stable;
  }
  .dash.dash--desktop-dense .wp-list-scroll-desktop--primary {
    max-height: min(340px, 42vh);
  }
  .dash.dash--desktop-dense .work-col--side .wp-list-scroll-desktop {
    max-height: min(220px, 28vh);
  }
}

/* ===== Responsive ===== */
@media (max-width: 900px) {
  .dash-header {
    align-items: flex-start;
    flex-direction: column;
    padding: 20px;
  }
  .dash-date-panel {
    width: 100%;
  }
  .work-grid { grid-template-columns: 1fr; }
  .progress-board { grid-template-columns: repeat(2, 1fr); }
  .work-toolbar {
    flex-direction: column;
    align-items: flex-start;
  }
  .work-toolbar__hint {
    text-align: left;
  }
}

@media (max-width: 600px) {
  .action-lane {
    gap: 10px;
  }
  .ac {
    min-width: 150px;
    padding: 10px 12px;
  }
  .ac__count { font-size: 18px; }
  .ac__icon  { font-size: 22px; }
  .progress-board {
    grid-template-columns: 1fr 1fr;
    padding: 12px 14px;
  }
  .dash { gap: 14px; }
}

@media (max-width: 420px) {
  .progress-board { grid-template-columns: 1fr; }
  .ac { min-width: 130px; }
  .ac__cta { display: none; }
}

/* ===== 每日待辦 section label ===== */
.section-label-row {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin-bottom: -4px;
  padding: 0 4px;
}
.section-label-row--sub {
  margin-top: 8px;
  margin-bottom: 4px;
}
.section-label {
  font-size: 12px;
  font-weight: 700;
  color: var(--ds-ink);
  text-transform: uppercase;
  letter-spacing: 0.1em;
}
.section-sublabel {
  font-size: 11px;
  color: var(--ds-ink-mute);
  font-weight: 500;
}

/* ===== 匯入格式連結 ===== */
.ac__format-link {
  font-size: 11px;
  color: var(--ds-primary);
  background: none;
  border: none;
  cursor: pointer;
  padding: 0;
  text-decoration: underline;
  font-family: inherit;
  text-align: left;
  white-space: nowrap;
}

/* ===== 匯入格式 Modal ===== */
.import-format-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.import-format-modal {
  background: var(--card-bg, var(--ds-canvas));
  border-radius: 14px;
  padding: 20px 24px;
  max-width: 500px;
  width: 100%;
  box-shadow: 0 8px 40px rgba(0, 0, 0, 0.22);
  max-height: 90vh;
  overflow-y: auto;
}
.import-format-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
  font-size: 15px;
}
.import-format-close {
  background: none;
  border: none;
  font-size: 20px;
  cursor: pointer;
  color: var(--text-light);
  line-height: 1;
  padding: 0 4px;
}
.import-format-desc {
  font-size: 13px;
  color: var(--text-light);
  margin-bottom: 10px;
}
.import-format-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
  margin-bottom: 14px;
}
.import-format-table th,
.import-format-table td {
  border: 1px solid var(--border);
  padding: 6px 10px;
  text-align: left;
}
.import-format-table thead th {
  background: var(--bg);
  font-weight: 600;
}
.import-format-example {
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 12px;
  font-family: monospace;
  overflow-x: auto;
  margin: 4px 0 12px;
  white-space: pre;
}
.import-format-note {
  font-size: 12px;
  color: var(--text-light);
  margin: 6px 0 2px;
}
/* ===== Director workbench redesign ===== */
.dash {
  gap: 16px;
  border-left: 0;
}
.dash-header.enterprise-page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 24px;
  min-height: 0;
  padding: 18px 0 4px;
  background: transparent;
  border: 0;
  box-shadow: none;
}
.dash-kicker, .workbench__eyebrow {
  margin: 0 0 5px;
  color: var(--ds-ink-mute);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}
.dash-title-block { min-width: 0; }
.dash-title { margin: 0; color: var(--ds-ink); font-size: clamp(24px, 3vw, 32px); letter-spacing: -0.025em; }
.dash-subtitle { max-width: 42rem; margin: 6px 0 0; color: var(--ds-ink-mute); font-size: 13px; }
.dash-date-panel { display: grid; gap: 3px; justify-items: end; padding-top: 3px; color: var(--ds-ink-mute); }
.dash-date-label { font-size: 11px; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; }
.dash-date { color: var(--ds-ink); font-size: 14px; font-variant-numeric: tabular-nums; }
.workbench { display: grid; gap: 14px; }
.workbench__intro { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; }
.workbench__intro h1 { margin: 0; color: var(--ds-ink); font-size: clamp(22px, 3vw, 30px); letter-spacing: -0.025em; }
.workbench__subtitle { margin: 5px 0 0; color: var(--ds-ink-mute); font-size: 13px; }
.workbench__controls { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
.workbench__updated { color: var(--ds-ink-mute); font-size: 12px; font-variant-numeric: tabular-nums; }
.workbench__refresh, .workbench__view-switch button, .trust-summary__action, .workbench-state__action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 36px;
  padding: 7px 12px;
  border: 1px solid var(--ds-hairline-input);
  border-radius: 8px;
  background: var(--ds-canvas);
  color: var(--ds-ink);
  font: inherit;
  font-size: 12px;
  font-weight: 800;
  cursor: pointer;
}
.workbench__refresh:hover, .workbench__view-switch button:hover, .trust-summary__action:hover, .workbench-state__action:hover { border-color: var(--ds-cta); color: var(--ds-cta); }
.workbench__refresh:disabled { cursor: wait; opacity: 0.6; }
.workbench__refresh .material-symbols-outlined { font-size: 17px; }
.trust-summary { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px 18px; align-items: center; padding: 14px 16px; border: 1px solid var(--ds-hairline); border-left: 4px solid var(--ds-warning); border-radius: 10px; background: var(--ds-canvas); box-shadow: var(--ds-shadow-1); }
.trust-summary__status { display: flex; align-items: center; gap: 10px; min-width: 0; }
.trust-summary__dot { width: 10px; height: 10px; flex: 0 0 10px; border-radius: 50%; background: var(--ds-ink-mute); }
.trust-summary__dot--green { background: var(--ds-success); }
.trust-summary__dot--yellow { background: var(--ds-warning); }
.trust-summary__dot--red { background: var(--ds-danger); }
.trust-summary__eyebrow { margin: 0 0 2px; color: var(--ds-ink-mute); font-size: 11px; font-weight: 800; }
.trust-summary h2 { overflow: hidden; margin: 0; color: var(--ds-ink); font-size: 14px; text-overflow: ellipsis; white-space: nowrap; }
.trust-summary__score { color: var(--ds-ink); font-variant-numeric: tabular-nums; white-space: nowrap; }
.trust-summary__score strong { font-size: 24px; }
.trust-summary__score span { color: var(--ds-ink-mute); font-size: 12px; font-weight: 700; }
.trust-summary__score--green strong { color: var(--ds-success); }
.trust-summary__score--yellow strong { color: var(--ds-warning); }
.trust-summary__score--red strong { color: var(--ds-danger); }
.trust-summary__details { grid-column: 1 / -1; border-top: 1px solid var(--ds-hairline); padding-top: 10px; }
.trust-summary__details summary { color: var(--ds-ink-secondary); cursor: pointer; font-size: 12px; font-weight: 800; }
.trust-summary__list { display: grid; gap: 8px; margin-top: 10px; }
.trust-summary__item { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-top: 1px solid var(--ds-hairline); }
.trust-summary__item strong { color: var(--ds-ink); font-size: 13px; }
.trust-summary__item p { margin: 3px 0 0; color: var(--ds-ink-mute); font-size: 12px; }
.trust-summary__action { min-height: 32px; padding-inline: 10px; color: var(--ds-cta); border-color: var(--ds-cta); white-space: nowrap; }
.workbench__layout { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(280px, 0.7fr); gap: 16px; align-items: start; }
.workbench__queue, .workbench__snapshot { min-width: 0; padding: 16px; border: 1px solid var(--ds-hairline); border-radius: 10px; background: var(--ds-canvas); box-shadow: var(--ds-shadow-1); }
.workbench__section-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.workbench__section-head h2 { margin: 0; color: var(--ds-ink); font-size: 18px; letter-spacing: -0.015em; }
.workbench__count { color: var(--ds-ink-mute); font-size: 12px; font-variant-numeric: tabular-nums; }
.workbench-task-list { display: grid; gap: 8px; }
.workbench-task { display: grid; grid-template-columns: 4px minmax(0, 1fr) auto; gap: 12px; align-items: center; min-width: 0; padding: 12px; border: 1px solid var(--ds-hairline); border-radius: 8px; background: var(--ds-canvas); }
.workbench-task--critical, .workbench-task--danger { border-left: 4px solid var(--ds-danger); }
.workbench-task--warning { border-left: 4px solid var(--ds-warning); }
.workbench-task--info { border-left: 4px solid var(--ds-info); }
.workbench-task--neutral { border-left: 4px solid var(--ds-hairline-input); }
.workbench-task__status { width: 4px; height: 42px; border-radius: 2px; background: var(--ds-hairline); }
.workbench-task--critical .workbench-task__status, .workbench-task--danger .workbench-task__status { background: var(--ds-danger); }
.workbench-task--warning .workbench-task__status { background: var(--ds-warning); }
.workbench-task--info .workbench-task__status { background: var(--ds-info); }
.workbench-task__body { min-width: 0; }
.workbench-task__heading { display: flex; align-items: center; gap: 8px; min-width: 0; }
.workbench-task__heading h3 { overflow: hidden; margin: 0; color: var(--ds-ink); font-size: 14px; text-overflow: ellipsis; white-space: nowrap; }
.workbench-task__heading p, .workbench-task__body > p { margin: 4px 0 0; color: var(--ds-ink-secondary); font-size: 12px; line-height: 1.5; }
.workbench-task__count { flex: 0 0 auto; min-width: 24px; color: var(--ds-ink); font-size: 14px; font-weight: 900; text-align: right; font-variant-numeric: tabular-nums; }
.workbench-task__meta { display: flex; flex-wrap: wrap; gap: 5px 14px; margin-top: 6px; color: var(--ds-ink-mute); font-size: 11px; }
.workbench-task__cta { display: inline-flex; align-items: center; justify-content: center; min-width: 92px; min-height: 44px; padding: 9px 12px; border: 1px solid var(--ds-cta); border-radius: 8px; background: var(--ds-cta); color: var(--ds-on-cta); font: inherit; font-size: 12px; font-weight: 900; cursor: pointer; white-space: nowrap; }
.workbench-task__cta:hover { background: var(--ds-cta-hover); border-color: var(--ds-cta-hover); }
.workbench-task__cta:focus-visible, .workbench__refresh:focus-visible, .workbench__view-switch button:focus-visible, .trust-summary__action:focus-visible, .workbench-state__action:focus-visible { outline: 3px solid var(--ds-info-wash); outline-offset: 2px; }
.workbench-task--loading { height: 78px; background: var(--ds-canvas-soft); border-color: transparent; }
.workbench-state { display: grid; justify-items: start; gap: 5px; padding: 24px 8px 12px; color: var(--ds-ink-mute); font-size: 12px; }
.workbench-state .material-symbols-outlined { color: var(--ds-success); font-size: 28px; }
.workbench-state strong { color: var(--ds-ink); font-size: 14px; }
.workbench-state__action { margin-top: 5px; color: var(--ds-cta); border-color: var(--ds-cta); }
.workbench-state--error strong { color: var(--ds-danger); }
.snapshot-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); border-top: 1px solid var(--ds-hairline); border-left: 1px solid var(--ds-hairline); }
.snapshot-item { min-width: 0; padding: 12px 10px; border-right: 1px solid var(--ds-hairline); border-bottom: 1px solid var(--ds-hairline); }
.snapshot-item span, .snapshot-item small { display: block; color: var(--ds-ink-mute); font-size: 11px; }
.snapshot-item strong { display: block; margin: 4px 0 2px; color: var(--ds-ink); font-size: 22px; font-variant-numeric: tabular-nums; }
.workbench__view-switch { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; margin-top: 16px; padding: 3px; border: 1px solid var(--ds-hairline); border-radius: 8px; background: var(--ds-canvas-soft); }
.workbench__view-switch button { min-height: 36px; border-color: transparent; background: transparent; }
.workbench__view-switch button.is-active { border-color: var(--ds-hairline); background: var(--ds-canvas); color: var(--ds-ink); box-shadow: var(--ds-shadow-1); }
.workbench__view-hint { margin: 8px 2px 0; color: var(--ds-ink-mute); font-size: 11px; line-height: 1.5; }

/* Director workbench v2: one surface, one hierarchy, one action language. */
.director-workbench-v2 {
  max-width: 1360px;
  margin: 0 auto;
  padding: 30px 36px 64px;
  color: var(--ds-ink);
}
.director-workbench-v2__header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 24px;
  padding-bottom: 22px;
  border-bottom: 1px solid var(--ds-hairline);
}
.director-workbench-v2__heading h1,
.director-workbench-v2__subheader h2,
.surface-panel__header h2,
.surface-panel__header h3 { margin: 0; color: var(--ds-ink); letter-spacing: -0.018em; }
.director-workbench-v2__heading h1 { font-size: 30px; font-weight: 800; letter-spacing: -0.035em; }
.director-workbench-v2__heading p,
.director-workbench-v2__subheader p,
.surface-panel__header p { margin: 7px 0 0; color: var(--ds-ink-mute); font-size: 13px; line-height: 1.5; }
.director-workbench-v2__header-actions { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; justify-content: flex-end; }
.director-workbench-v2__updated { color: var(--ds-ink-mute); font-size: 12px; font-variant-numeric: tabular-nums; }
.director-workbench-v2__refresh,
.director-workbench-v2__nav button,
.text-action,
.button { font: inherit; cursor: pointer; }
.director-workbench-v2__refresh {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  min-height: 36px;
  padding: 7px 12px;
  border: 1px solid var(--ds-hairline-input);
  border-radius: 7px;
  background: var(--ds-canvas);
  color: var(--ds-ink-secondary);
  font-size: 12px;
  font-weight: 700;
}
.director-workbench-v2__refresh:hover { border-color: var(--ds-cta); color: var(--ds-cta); }
.director-workbench-v2__refresh:disabled { cursor: wait; opacity: 0.6; }
.director-workbench-v2__refresh:focus-visible,
.director-workbench-v2__nav button:focus-visible,
.text-action:focus-visible,
.button:focus-visible,
.director-task__action:focus-visible,
.director-other-work-list button:focus-visible,
.director-candidate input:focus-visible + span,
.director-modal__close:focus-visible { outline: 3px solid var(--ds-info-wash); outline-offset: 2px; }
.director-workbench-v2__nav { display: flex; gap: 22px; min-height: 52px; border-bottom: 1px solid var(--ds-hairline); }
.director-workbench-v2__nav button { min-height: 52px; padding: 0 2px; border: 0; border-bottom: 2px solid transparent; background: transparent; color: var(--ds-ink-mute); font-size: 14px; font-weight: 800; }
.director-workbench-v2__nav button:hover { color: var(--ds-ink); }
.director-workbench-v2__nav button.is-active { border-bottom-color: var(--ds-cta); color: var(--ds-ink); }
.director-workbench-v2__focus { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(260px, 0.72fr); gap: 24px; align-items: start; padding-top: 24px; }
.director-workbench-v2__primary { min-width: 0; }
.director-workbench-v2__aside { display: grid; gap: 18px; min-width: 0; }
.surface-panel { min-width: 0; border: 1px solid var(--ds-hairline); border-radius: 12px; background: var(--ds-canvas); box-shadow: var(--ds-shadow-1); }
.surface-panel__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 20px 22px 16px; }
.surface-panel__header h2 { font-size: 20px; font-weight: 800; }
.surface-panel__header h3 { font-size: 17px; font-weight: 800; }
.surface-panel__count { flex: 0 0 auto; color: var(--ds-ink-mute); font-size: 12px; font-variant-numeric: tabular-nums; white-space: nowrap; }
.director-task-list { margin: 0; padding: 0 22px; list-style: none; }
.director-workbench-v2__primary > .surface-panel__header { position: relative; padding-left: 28px; }
.director-workbench-v2__primary > .surface-panel__header::before { content: ''; position: absolute; top: 20px; bottom: 16px; left: 16px; width: 3px; border-radius: 3px; background: var(--ds-brand-orange); }
.surface-panel--summary { border-top: 2px solid var(--ds-ink); }
.director-task { display: grid; grid-template-columns: 34px minmax(0, 1fr) auto; gap: 14px; align-items: center; min-width: 0; margin-inline: -10px; padding: 17px 10px; border-top: 1px solid var(--ds-hairline); border-radius: 8px; transition: background-color 160ms ease, box-shadow 160ms ease; }
.director-task:hover { background: var(--ds-canvas-soft); box-shadow: inset 3px 0 0 var(--ds-hairline-input); }
.director-task--critical:hover, .director-task--danger:hover { box-shadow: inset 3px 0 0 var(--ds-danger); }
.director-task--warning:hover { box-shadow: inset 3px 0 0 var(--ds-warning); }
.director-task__index { align-self: start; padding-top: 2px; color: var(--ds-ink-mute); font-size: 11px; font-variant-numeric: tabular-nums; }
.director-task--critical .director-task__index,
.director-task--danger .director-task__index { color: var(--ds-danger); font-weight: 900; }
.director-task--warning .director-task__index { color: var(--ds-warning); font-weight: 900; }
.director-task__body { min-width: 0; }
.director-task__title-row { display: flex; align-items: baseline; gap: 9px; min-width: 0; }
.director-task__title-row h3 { overflow: hidden; margin: 0; color: var(--ds-ink); font-size: 14px; font-weight: 800; text-overflow: ellipsis; white-space: nowrap; }
.director-task__count { flex: 0 0 auto; color: var(--ds-ink); font-size: 15px; font-variant-numeric: tabular-nums; }
.director-task__body > p { margin: 5px 0 0; color: var(--ds-ink-secondary); font-size: 12px; line-height: 1.55; }
.director-task__meta { display: flex; flex-wrap: wrap; gap: 4px 16px; margin-top: 7px; color: var(--ds-ink-mute); font-size: 11px; }
.director-task__action { display: inline-flex; align-items: center; gap: 5px; min-height: 34px; padding: 5px 0; border: 0; border-bottom: 1px solid transparent; background: transparent; color: var(--ds-cta); font-size: 12px; font-weight: 800; white-space: nowrap; }
.director-task__action .material-symbols-outlined { font-size: 16px; transition: transform 160ms ease; }
.director-task__action:hover .material-symbols-outlined { transform: translateX(2px); }
.director-task__action:hover { border-bottom-color: currentColor; }
.director-task--loading { min-height: 92px; background: transparent; }
.director-task--loading > span { display: block; background: var(--ds-canvas-soft); animation: director-workbench-loading 1.4s ease-in-out infinite; }
.director-skeleton__index { width: 20px; height: 12px; border-radius: 3px; }
.director-skeleton__body { display: grid; gap: 9px; }
.director-skeleton__title { width: min(42%, 180px); height: 14px; border-radius: 3px; }
.director-skeleton__copy { width: min(76%, 310px); height: 10px; border-radius: 3px; }
.director-skeleton__meta { width: 30%; height: 9px; border-radius: 3px; }
.director-skeleton__action { width: 56px; height: 12px; border-radius: 3px; }
@keyframes director-workbench-loading { 0%, 100% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } }
.director-state { display: grid; justify-items: start; gap: 6px; padding: 34px 22px; color: var(--ds-ink-mute); font-size: 12px; }
.director-state strong { color: var(--ds-ink); font-size: 14px; }
.director-state .material-symbols-outlined { color: var(--ds-success); font-size: 25px; }
.director-state--compact { display: flex; align-items: center; gap: 9px; min-height: 52px; padding: 18px 22px; }
.director-state--error strong, .director-inline-error { color: var(--ds-danger); }
.director-inline-error { margin: 0 22px 16px; padding: 10px 12px; border: 1px solid color-mix(in srgb, var(--ds-danger) 35%, var(--ds-hairline)); border-radius: 6px; background: var(--ds-danger-wash); font-size: 12px; }
.director-risk-disclosure { margin: 0 22px 10px; border-top: 1px solid var(--ds-hairline); border-bottom: 1px solid var(--ds-hairline); }
.director-risk-disclosure summary { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 42px; color: var(--ds-ink-secondary); font-size: 11px; font-weight: 800; cursor: pointer; list-style: none; }
.director-risk-disclosure summary::-webkit-details-marker { display: none; }
.director-risk-disclosure summary::after { content: '＋'; color: var(--ds-ink-mute); font-size: 15px; }
.director-risk-disclosure[open] summary::after { content: '−'; }
.director-risk-disclosure summary strong { color: var(--ds-warning); font-size: 11px; font-weight: 800; }
.director-risk-disclosure summary:focus-visible,
.director-secondary-tools summary:focus-visible { outline: 3px solid var(--ds-info-wash); outline-offset: 2px; border-radius: 4px; }
.text-action { display: inline-flex; align-items: center; min-height: 32px; padding: 4px 0; border: 0; border-bottom: 1px solid transparent; background: transparent; color: var(--ds-cta); font-size: 12px; font-weight: 800; }
.text-action:hover { border-bottom-color: currentColor; }
.director-workbench-v2__more { display: inline-flex; align-items: center; gap: 7px; margin: 2px 22px 19px 70px; padding: 8px 0; border: 0; border-bottom: 1px solid var(--ds-hairline-input); background: transparent; color: var(--ds-ink-secondary); font: inherit; font-size: 12px; font-weight: 800; cursor: pointer; }
.director-workbench-v2__more:hover { border-bottom-color: var(--ds-cta); color: var(--ds-cta); }
.director-summary-list { margin: 0; padding: 0 22px; }
.director-summary-list > div { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 2px 12px; padding: 14px 0; border-top: 1px solid var(--ds-hairline); }
.director-summary-list dt { display: inline-flex; align-items: center; gap: 4px; color: var(--ds-ink-secondary); font-size: 12px; cursor: help; }
.director-summary-list dd { margin: 0; color: var(--ds-ink); font-size: 22px; font-weight: 800; font-variant-numeric: tabular-nums; }
.director-summary-list small { grid-column: 1 / -1; color: var(--ds-ink-mute); font-size: 11px; }
.director-summary-list__info { font-size: 14px; color: var(--ds-ink-mute); }

.director-top-risks { margin: 0 22px 16px; padding: 14px 16px; border: 1px solid var(--ds-hairline); border-radius: 12px; background: var(--ds-canvas-soft); }
.director-top-risks h3 { margin: 0 0 8px; color: var(--ds-ink-secondary); font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.02em; }
.director-top-risks ol { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 8px; }
.director-top-risks__item { display: flex; flex-direction: column; gap: 2px; padding: 8px 10px; border-radius: 8px; background: var(--ds-canvas); border-left: 3px solid var(--ds-ink-mute); }
.director-top-risks__item strong { color: var(--ds-ink); font-size: 13px; font-weight: 700; }
.director-top-risks__item span { color: var(--ds-ink-mute); font-size: 12px; }
.director-top-risks__item--critical, .director-top-risks__item--danger { border-left-color: var(--ds-danger); }
.director-top-risks__item--warning { border-left-color: var(--ds-warning); }
.director-trust-note { border-top: 1px solid var(--ds-hairline); border-bottom: 1px solid var(--ds-hairline); }
.director-trust-note summary { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 44px; padding: 0 2px; color: var(--ds-ink-secondary); font-size: 12px; font-weight: 800; cursor: pointer; list-style: none; }
.director-trust-note summary::-webkit-details-marker { display: none; }
.director-trust-note summary::after { content: '＋'; color: var(--ds-ink-mute); font-size: 15px; }
.director-trust-note[open] summary::after { content: '−'; }
.director-trust-note > p { margin: 0 0 10px; color: var(--ds-ink-mute); font-size: 12px; line-height: 1.5; }
.director-trust-note__score--green { color: var(--ds-success); }
.director-trust-note__score--yellow { color: var(--ds-warning); }
.director-trust-note__score--red { color: var(--ds-danger); }
.director-trust-note__list { display: grid; gap: 10px; padding-bottom: 12px; }
.director-trust-note__item { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding-top: 10px; border-top: 1px solid var(--ds-hairline); }
.director-trust-note__item strong { font-size: 12px; }
.director-trust-note__item p { margin: 3px 0 0; color: var(--ds-ink-mute); font-size: 11px; line-height: 1.5; }
.director-workbench-v2__full { padding-top: 24px; }
.director-workbench-v2__subheader { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; margin-bottom: 18px; }
.director-workbench-v2__subheader h2 { font-size: 22px; font-weight: 800; }
.director-secondary-tools { margin-bottom: 20px; border: 1px solid var(--ds-hairline); border-radius: 10px; background: var(--ds-canvas-soft); }
.director-secondary-tools summary { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; gap: 10px; min-height: 48px; padding: 0 16px; color: var(--ds-ink-secondary); cursor: pointer; list-style: none; }
.director-secondary-tools summary::-webkit-details-marker { display: none; }
.director-secondary-tools summary > .material-symbols-outlined { color: var(--ds-ink-mute); font-size: 19px; transition: transform 160ms ease; }
.director-secondary-tools[open] summary > .material-symbols-outlined { transform: rotate(180deg); }
.director-secondary-tools__title { color: var(--ds-ink); font-size: 13px; font-weight: 800; }
.director-secondary-tools__hint { overflow: hidden; color: var(--ds-ink-mute); font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
.director-secondary-tools__body { display: grid; gap: 16px; padding: 0 16px 16px; }
.director-secondary-tools__body .surface-panel { box-shadow: none; }
.director-secondary-tools__body .operations-quick-start { margin-top: 0; }
.director-workbench-v2__full-grid { display: grid; grid-template-columns: minmax(0, 1.45fr) minmax(280px, 0.75fr); gap: 20px; align-items: start; }
.director-workbench-v2__full-main, .director-workbench-v2__full-side { display: grid; gap: 18px; min-width: 0; }
.director-schedule-list { padding: 0 22px; }
.director-schedule-row { display: grid; grid-template-columns: 54px minmax(130px, 1fr) minmax(80px, 0.7fr) minmax(80px, 0.7fr) auto; gap: 12px; align-items: center; min-width: 0; padding: 12px 0; border-top: 1px solid var(--ds-hairline); font-size: 12px; }
.director-schedule-row time { color: var(--ds-ink-mute); font-variant-numeric: tabular-nums; }
.director-schedule-row strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.director-schedule-row > span { overflow: hidden; color: var(--ds-ink-secondary); text-overflow: ellipsis; white-space: nowrap; }
.director-schedule-row__status { color: var(--ds-ink-mute) !important; font-size: 11px; }
.director-schedule-row__status--attended { color: var(--ds-success) !important; }
.director-schedule-row__status--scheduled { color: var(--ds-warning) !important; }
.surface-panel__footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 0 22px; padding: 13px 0 16px; border-top: 1px solid var(--ds-hairline); color: var(--ds-ink-mute); font-size: 11px; }
.director-leave-list { display: grid; gap: 14px; padding: 0 22px 20px; }
.director-leave-case { min-width: 0; padding: 16px; border: 1px solid var(--ds-hairline); border-radius: 8px; background: var(--ds-canvas-soft); }
.director-leave-case__header { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; }
.director-leave-case__header > div { display: flex; align-items: center; gap: 9px; min-width: 0; }
.director-leave-case__header strong { font-size: 14px; }
.director-leave-case__header span { color: var(--ds-ink-mute); font-size: 11px; }
.director-leave-case__header > span { color: var(--ds-ink-mute); font-size: 11px; font-variant-numeric: tabular-nums; }
.director-leave-case__details { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin: 14px 0 0; }
.director-leave-case__details div { min-width: 0; }
.director-leave-case__details dt { color: var(--ds-ink-mute); font-size: 11px; }
.director-leave-case__details dd { margin: 4px 0 0; color: var(--ds-ink-secondary); font-size: 12px; }
.director-leave-case__hint { display: flex; align-items: flex-start; gap: 7px; margin: 14px 0 0; color: var(--ds-ink-mute); font-size: 11px; line-height: 1.5; }
.director-leave-case__hint .material-symbols-outlined { color: var(--ds-warning); font-size: 17px; }
.director-leave-case__window { display: flex; align-items: center; gap: 6px; margin: 14px 0 0; color: var(--ds-ink-mute); font-size: 11px; line-height: 1.5; }
.director-leave-case__window .material-symbols-outlined { color: var(--ds-primary); font-size: 17px; }
.director-leave-case__actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; }
.button { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 34px; padding: 7px 11px; border: 1px solid var(--ds-hairline-input); border-radius: 6px; background: var(--ds-canvas); color: var(--ds-ink-secondary); font-size: 12px; font-weight: 800; }
.button:hover { border-color: var(--ds-ink-secondary); color: var(--ds-ink); }
.button--primary { border-color: var(--ds-cta); background: var(--ds-cta); color: var(--ds-on-cta); }
.button--primary:hover { border-color: var(--ds-cta-hover); background: var(--ds-cta-hover); color: var(--ds-on-cta); }
.button--quiet { background: transparent; }
.button--danger { border-color: var(--ds-danger); color: var(--ds-danger); }
.button--danger:hover { background: var(--ds-danger-wash); }
.director-candidate-picker { min-width: 0; margin-top: 14px; }
.director-candidate-dates { display: flex; gap: 7px; overflow-x: auto; padding: 2px 1px 8px; scrollbar-width: thin; }
.director-candidate-date { flex: 0 0 auto; min-height: 34px; padding: 7px 10px; border: 1px solid var(--ds-hairline); border-radius: 999px; background: var(--ds-canvas); color: var(--ds-ink-mute); font-size: 12px; font-weight: 800; cursor: pointer; }
.director-candidate-date:hover { border-color: var(--ds-ink-secondary); color: var(--ds-ink); }
.director-candidate-date.is-selected { border-color: var(--ds-cta); background: var(--ds-primary-wash); color: var(--ds-ink); }
.director-candidate-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 7px; margin-top: 2px; }
.director-candidate { display: flex; align-items: center; gap: 9px; min-height: 48px; padding: 9px 10px; border: 1px solid var(--ds-hairline); border-radius: 6px; background: var(--ds-canvas); color: var(--ds-ink-secondary); font-size: 12px; cursor: pointer; }
.director-candidate.is-selected { border-color: var(--ds-cta); }
.director-candidate input { accent-color: var(--ds-cta); }
.director-candidate strong { color: var(--ds-ink); }
.director-candidate span { display: grid; gap: 3px; }
.director-candidate small { color: var(--ds-warning); font-size: 10px; }
.director-evaluation-list, .director-payment-list, .director-notification-list { padding: 0 22px; }
.director-evaluation-row, .director-payment-row, .director-notification-list > div { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 12px 0; border-top: 1px solid var(--ds-hairline); }
.director-evaluation-row > div:first-child, .director-payment-row > div { display: grid; gap: 3px; min-width: 0; }
.director-evaluation-row strong, .director-payment-row strong, .director-notification-list strong { overflow: hidden; color: var(--ds-ink); font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
.director-evaluation-row span, .director-payment-row span, .director-notification-list span { color: var(--ds-ink-mute); font-size: 11px; }
.director-evaluation-row > div:last-child { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
.director-payment-row span { display: inline-block; margin-top: 2px; }
.director-payment-row__actions { display: flex !important; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
.director-other-work-list { padding: 0 22px 8px; }
.director-other-work-list button { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 3px 10px; width: 100%; padding: 12px 0; border: 0; border-top: 1px solid var(--ds-hairline); background: transparent; color: var(--ds-ink); text-align: left; cursor: pointer; }
.director-other-work-list button span:first-child { overflow: hidden; font-size: 12px; font-weight: 800; text-overflow: ellipsis; white-space: nowrap; }
.director-other-work-list button small { grid-column: 1; overflow: hidden; color: var(--ds-ink-mute); font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
.director-other-work-list button .material-symbols-outlined { grid-column: 2; grid-row: 1 / span 2; align-self: center; color: var(--ds-ink-mute); font-size: 17px; }
.director-analysis { border: 1px solid var(--ds-hairline); border-radius: 10px; background: var(--ds-canvas); }
.director-analysis summary { padding: 16px 18px; color: var(--ds-ink); font-size: 13px; font-weight: 800; cursor: pointer; }
.director-analysis__body { display: grid; gap: 12px; padding: 0 18px 18px; color: var(--ds-ink-mute); font-size: 11px; line-height: 1.5; }
.director-modal-backdrop { position: fixed; inset: 0; z-index: var(--ds-z-modal, 1000); display: grid; place-items: center; padding: 20px; background: rgb(11 24 43 / 42%); }
.director-modal { position: relative; width: min(100%, 520px); padding: 24px; border: 1px solid var(--ds-hairline); border-radius: 10px; background: var(--ds-canvas); box-shadow: var(--ds-shadow-2); }
.director-modal h3 { margin: 0; color: var(--ds-ink); font-size: 18px; }
.director-modal > p { margin: 8px 0 0; color: var(--ds-ink-secondary); font-size: 13px; line-height: 1.55; }
.director-modal__close { position: absolute; top: 12px; right: 14px; border: 0; background: transparent; color: var(--ds-ink-mute); font-size: 24px; cursor: pointer; }
.director-modal__selection, .director-modal__field { display: grid; gap: 6px; margin-top: 18px; color: var(--ds-ink-mute); font-size: 11px; }
.director-modal__selection strong { color: var(--ds-ink); font-size: 13px; }
.director-modal__field textarea { width: 100%; box-sizing: border-box; resize: vertical; padding: 10px; border: 1px solid var(--ds-hairline-input); border-radius: 6px; background: var(--ds-canvas); color: var(--ds-ink); font: inherit; font-size: 13px; }
.director-modal__actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
.director-toast { position: fixed; right: 24px; bottom: 24px; z-index: var(--ds-z-toast, 1100); max-width: min(360px, calc(100vw - 48px)); padding: 12px 14px; border: 1px solid var(--ds-hairline); border-radius: 7px; background: var(--ds-ink); color: var(--ds-canvas); font-size: 12px; box-shadow: var(--ds-shadow-2); }
@media (prefers-reduced-motion: reduce) { .director-task--loading { animation: none; } }
@media (max-width: 960px) { .director-workbench-v2__focus, .director-workbench-v2__full-grid { grid-template-columns: 1fr; } .director-workbench-v2__aside { grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: start; } .director-trust-note { grid-column: 1 / -1; } }
@media (max-width: 680px) {
  .director-workbench-v2 { padding: 22px 16px 44px; }
  .director-workbench-v2__header { align-items: flex-start; flex-direction: column; gap: 14px; }
  .director-workbench-v2__header-actions { width: 100%; justify-content: space-between; }
  .director-workbench-v2__focus, .director-workbench-v2__full { padding-top: 16px; }
  .director-risk-disclosure { margin-inline: 16px; }
  .director-workbench-v2__aside { grid-template-columns: 1fr; gap: 12px; }
  .surface-panel__header { padding: 17px 16px 14px; }
  .director-task-list, .director-schedule-list, .director-evaluation-list, .director-payment-list, .director-notification-list, .director-other-work-list { padding-inline: 16px; }
  .director-workbench-v2__primary > .surface-panel__header { padding-left: 24px; }
  .director-workbench-v2__primary > .surface-panel__header::before { left: 12px; top: 17px; bottom: 14px; }
  .director-task { grid-template-columns: 28px minmax(0, 1fr); gap: 10px; align-items: start; margin-inline: -6px; padding: 15px 6px; }
  .director-task__title-row h3 { white-space: normal; }
  /* Mobile primary actions must look and measure like actions, not inline links.
     Keep the task hierarchy intact while guaranteeing a comfortable touch target. */
  .director-task__action {
    grid-column: 2;
    justify-self: start;
    min-height: 44px;
    margin-top: 3px;
    padding: 9px 12px;
    border: 1px solid var(--ds-cta);
    border-radius: 7px;
    background: var(--ds-cta);
    color: var(--ds-on-cta);
  }
  .director-task__action:hover { border-color: var(--ds-cta-hover); background: var(--ds-cta-hover); color: var(--ds-on-cta); }
  .director-workbench-v2__more { margin-left: 54px; margin-right: 16px; }
  .director-summary-list { padding-inline: 16px; }
  .director-schedule-row { grid-template-columns: 48px minmax(0, 1fr) auto; gap: 7px; }
  .director-schedule-row > span:nth-of-type(1), .director-schedule-row > span:nth-of-type(2) { grid-column: 2; }
  .director-schedule-row__status { grid-column: 3; grid-row: 1 / span 2; }
  .surface-panel__footer { margin-inline: 16px; }
  .director-leave-list { padding-inline: 16px; }
  .director-leave-case { padding: 13px; }
  .director-leave-case__details { grid-template-columns: 1fr; gap: 8px; }
  .director-candidate-list { grid-template-columns: 1fr; }
  .director-leave-case__actions, .director-evaluation-row > div:last-child { align-items: stretch; flex-direction: column; }
  .director-leave-case__actions .button, .director-evaluation-row > div:last-child .button { width: 100%; }
  .director-evaluation-row { align-items: flex-start; flex-direction: column; }
  .director-evaluation-row > div:last-child { width: 100%; }
  .director-modal-backdrop { padding: 12px; }
  .director-modal { padding: 21px 18px; }
  .director-secondary-tools summary { grid-template-columns: auto minmax(0, 1fr) auto; padding-inline: 12px; }
  .director-secondary-tools__body { padding-inline: 12px; }
}
@media (max-width: 860px) {
  .workbench__layout { grid-template-columns: 1fr; }
  .workbench__snapshot { order: -1; }
}
@media (max-width: 600px) {
  .dash-header.enterprise-page-header, .workbench__intro { align-items: flex-start; flex-direction: column; gap: 10px; }
  .dash-date-panel { justify-items: start; }
  .workbench__controls { width: 100%; justify-content: space-between; }
  .workbench__queue, .workbench__snapshot { padding: 12px; }
  .workbench-task { grid-template-columns: 4px minmax(0, 1fr); gap: 9px; align-items: start; }
  .workbench-task__cta { grid-column: 2; width: 100%; }
  .workbench-task__heading h3 { white-space: normal; }
  .trust-summary { grid-template-columns: minmax(0, 1fr) auto; }
  .trust-summary__item { align-items: flex-start; flex-direction: column; }
  .trust-summary__action { width: 100%; }
}
@media (max-width: 420px) {
  .workbench__intro h1 { font-size: 24px; }
  .workbench-task__cta { min-height: 44px; }
  .snapshot-grid { grid-template-columns: 1fr 1fr; }
}
</style>
