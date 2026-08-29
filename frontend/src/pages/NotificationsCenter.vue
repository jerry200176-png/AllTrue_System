<template>
  <div class="notifications-page at-ops-page">
    <AtPageHeader
      title="主任收件匣"
      description="集中查看待辦案件與營運通知，優先處理即將到期或已逾期項目。"
      icon="inbox"
      data-guide="notifications-header"
    >
      <template v-if="branchId != null" #meta>
        <span aria-live="polite">已逾期 <strong>{{ casesOverdueCount }}</strong></span>
        <span>即將到期 {{ casesDueSoonCount }}</span>
        <span>方案待確認 {{ casesCandidateReadyCount }}</span>
        <span>新通知 {{ unreadCount }}</span>
      </template>
      <template #actions>
        <AtButton
          shape="rect"
          size="sm"
          variant="ghost"
          data-guide="notifications-settings-button"
          @click="goToNotificationSettings"
        >
          通知設定
        </AtButton>
      </template>
    </AtPageHeader>

    <AtInlineAlert
      v-if="countsStale"
      tone="warning"
      title="案件狀態暫時無法更新"
    >
      仍顯示上次成功資料。
      <template #action>
        <AtButton shape="rect" size="sm" variant="ghost" @click="retryLoad">重試</AtButton>
      </template>
    </AtInlineAlert>

    <AtEmpty
      v-if="branchId == null"
      icon="domain"
      title="請先選擇分校"
      description="選擇分校後即可查看待辦案件與營運通知。"
    />

    <template v-else>
      <!-- 登記已回報 Modal（待對帳，非立刻已繳） -->
      <AtDialog
        :open="tuitionModal.visible"
        title="登記已回報"
        title-id="tuition-report-dialog-title"
        panel-class="payment-modal"
        close-label="關閉登記已回報視窗"
        @close="tuitionModal.visible = false"
      >
          <p class="modal-desc">依家長回報填寫；送出後仍是未繳費，對到帳後由主任按確認入帳才開收據。</p>
          <div class="modal-item-name">
            <span><strong>學生</strong>{{ tuitionPaymentRow.student_name || '-' }}</span>
            <span><strong>科目</strong>{{ tuitionPaymentRow.subject || '學費' }}</span>
            <span><strong>應繳</strong>{{ formatCurrency(tuitionPaymentRow.charge || tuitionModal.form.amount || 0) }}</span>
          </div>

          <form @submit.prevent="confirmTuitionPaid">
            <label class="modal-field">
              繳費日期 <span class="required">*</span>
              <input v-model="tuitionModal.form.payment_date" type="date" required :max="todayYmd" />
            </label>

            <div class="modal-field">
              <span>繳費方式 <span class="required">*</span></span>
              <div class="payment-method-row">
                <label :class="['payment-method-option', { active: tuitionModal.form.payment_method === 'transfer' }]">
                  <input v-model="tuitionModal.form.payment_method" type="radio" value="transfer" />
                  匯款
                </label>
                <label :class="['payment-method-option', { active: tuitionModal.form.payment_method === 'cash' }]">
                  <input v-model="tuitionModal.form.payment_method" type="radio" value="cash" />
                  現金
                </label>
              </div>
            </div>

            <label v-if="tuitionModal.form.payment_method === 'transfer'" class="modal-field">
              帳號後5碼（選填）
              <input
                v-model="tuitionModal.form.account_last5"
                type="text"
                maxlength="5"
                inputmode="numeric"
                pattern="[0-9]*"
                placeholder="例如 45688"
              />
            </label>

            <label class="modal-field">
              繳費金額 <span class="required">*</span>
              <input v-model.number="tuitionModal.form.amount" type="number" required min="0" max="999999" step="1" />
            </label>
            <p v-if="Number(tuitionModal.form.amount || 0) === 0" class="modal-hint">免費課程，金額為 NT$ 0，送出後仍待對帳。</p>

            <label class="modal-field">
              備註（選填）
              <textarea v-model="tuitionModal.form.note" rows="2" placeholder="例如：官方 LINE 已回報"></textarea>
            </label>

            <div v-if="tuitionModal.error" class="modal-error">{{ tuitionModal.error }}</div>

            <div class="modal-actions">
              <AtButton shape="rect" size="sm" variant="ghost" type="button" @click="tuitionModal.visible = false">取消</AtButton>
              <AtButton shape="rect" size="sm" variant="primary" type="submit" :loading="tuitionModal.processing" :disabled="tuitionModal.processing">
                {{ tuitionModal.processing ? '處理中...' : '送出已回報' }}
              </AtButton>
            </div>
          </form>
      </AtDialog>

      <AtSection class="controls-card" data-guide="notifications-controls">
        <!-- 主 tabs：待辦案件 / 營運通知（全部僅次要 overview，不作為預設） -->
        <div class="type-tabs" role="tablist" aria-label="收件匣分類" aria-orientation="horizontal">
          <button
            v-for="tab in typeTabs"
            :key="tab.value"
            :id="tab.id"
            type="button"
            class="type-tab"
            role="tab"
            :aria-selected="typeFilter === tab.value"
            :aria-controls="tab.panelId"
            :tabindex="typeFilter === tab.value ? 0 : -1"
            :class="{ active: typeFilter === tab.value }"
            @click="onTabClick(tab.value)"
            @keydown="onTypeTabKeydown($event, tab.value)"
          >
            {{ tab.label }}
            <span v-if="tab.count > 0" class="tab-badge" aria-label="數量">{{ tab.count }}</span>
          </button>
        </div>

        <AtFilterBar v-if="laneFilter !== 'case'" label="通知篩選">
          <label>
            企業視圖
            <select v-model="focusMode">
              <option value="all">全部通知</option>
              <option value="actionable">待處理優先</option>
              <option value="sla">SLA／逾期優先</option>
              <option value="high">僅高風險</option>
            </select>
          </label>

          <label>
            狀態
            <select v-model="readFilter">
              <option value="unread">未讀</option>
              <option value="all">全部</option>
              <option value="read">已讀</option>
            </select>
          </label>

          <label class="checkbox-wrap">
            <input v-model="includeResolved" type="checkbox" />
            <span>包含已解除</span>
          </label>

          <label class="checkbox-wrap">
            <input v-model="soundEnabled" type="checkbox" />
            <span>急件提醒音</span>
          </label>

          <label class="checkbox-wrap">
            <input v-model="quietMinor" type="checkbox" />
            <span>次要提醒降噪</span>
          </label>

          <div class="stats-box">
            未讀 <strong>{{ unreadCount }}</strong>
            <span class="urgent-stat">急件 <strong>{{ urgentUnreadCount }}</strong></span>
          </div>
        </AtFilterBar>

        <AtToolbar v-if="laneFilter !== 'case'" label="通知動作">
          <template #end>
            <AtButton shape="rect" size="sm" variant="ghost" :disabled="syncing" :loading="syncing" @click="syncNotifications(true)">
              {{ syncing ? '同步中...' : '同步通知' }}
            </AtButton>
            <AtButton shape="rect" size="sm" variant="ghost" :disabled="clearingResolved" :loading="clearingResolved" @click="clearResolved">
              {{ clearingResolved ? '清除中...' : '清除已解除' }}
            </AtButton>
            <AtButton shape="rect" size="sm" variant="primary" :disabled="markingAllRead || notifications.length === 0" :loading="markingAllRead" @click="markAllRead">
              {{ markingAllRead ? '處理中...' : '全部標記已讀' }}
            </AtButton>
          </template>
        </AtToolbar>
      </AtSection>

      <AtInlineAlert
        v-if="caseLaneError"
        tone="danger"
        title="案件狀態暫時無法更新"
      >
        <template #action>
          <AtButton shape="rect" size="sm" variant="ghost" @click="retryLoad">重試</AtButton>
        </template>
      </AtInlineAlert>
      <AtInlineAlert v-else-if="errorMessage" tone="danger">{{ errorMessage }}</AtInlineAlert>

      <AtSection
        :id="activeNotificationPanelId"
        flush
        class="list-card"
        data-guide="notifications-list"
        role="tabpanel"
        :aria-labelledby="activeNotificationTabId"
        tabindex="0"
        :aria-busy="loading ? 'true' : 'false'"
      >
        <AtSkeleton v-if="loading && visibleCaseItems.length === 0 && notifications.length === 0" :rows="4" />
        <AtEmpty
          v-else-if="laneFilter === 'case' && caseLaneError && visibleCaseItems.length === 0"
          icon="error"
          title="案件狀態暫時無法更新"
          description="請稍後重試，或先切換分校後再回來。"
        />
        <AtEmpty
          v-else-if="laneFilter === 'case' && !caseLaneError && visibleCaseItems.length === 0"
          icon="task_alt"
          title="目前沒有待辦案件"
          description="新的請假／補課案件會出現在這裡。"
        />
        <AtEmpty
          v-else-if="laneFilter !== 'case' && displayNotifications.length === 0"
          icon="notifications_off"
          title="目前沒有符合條件的通知"
          description="可調整篩選條件，或同步通知後再查看。"
        />

        <div v-else>
          <!-- 請假／補課案件 -->
          <template v-if="laneFilter === 'case' || laneFilter === ''">
            <div v-if="visibleCaseItems.length > 0" class="case-panel">
              <div class="leave-case-list__intro">
                <div>
                  <h4>家長請假待主任處理</h4>
                  <p>先選補課時段，再核准請假；所有決策都在主任處理頁完成。</p>
                </div>
                <span>{{ casesOpenCount }} 筆</span>
              </div>
              <div
                v-for="item in visibleCaseItems"
                :key="item.id"
                class="notification-item case-item leave-case-item"
                :class="{ 'severity-high-item': item.overdue, 'priority-due-soon': item.priority === 'due_soon' && !item.overdue }"
              >
                <div class="title-row">
                  <AtBadge tone="warning" label="請假申請" />
                  <AtBadge
                    :tone="item.overdue ? 'danger' : (item.priority === 'due_soon' ? 'warning' : 'neutral')"
                    :label="casePriorityLabel(item)"
                  />
                  <span class="status-chip">{{ item.status_label }}</span>
                </div>
                <div class="main-title">{{ item.student_name || item.title }}</div>
                <div v-if="item.summary" class="notification-context">{{ item.summary }}</div>
                <div v-if="item.reason_preview" class="main-body case-body case-reason">{{ item.reason_preview }}</div>
                <div class="meta-row">
                  <span>{{ formatDateTime(item.occurred_at) }}</span>
                  <span v-if="item.due_at">期限：{{ formatDateTime(item.due_at) }}</span>
                </div>
                <div class="leave-case-step">
                  <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                  <span>開啟後可選補課、核准不補課，或填寫原因退回請假。</span>
                </div>
                <div class="item-actions">
                  <AtButton shape="rect" size="sm" variant="primary" class="case-cta" @click="goToLeaveCase(item)">開啟主任處理頁<span aria-hidden="true">→</span></AtButton>
                </div>
              </div>
              <div v-if="casesLastPage > 1" class="pager-row" data-testid="case-pager">
                <AtButton shape="rect" size="sm" variant="ghost" class="case-cta" :disabled="casesPage <= 1 || loading" @click="loadCasePage(casesPage - 1)">上一頁</AtButton>
                <span aria-live="polite">第 {{ casesPage }} / {{ casesLastPage }} 頁（共 {{ casesOpenCount }}）</span>
                <AtButton shape="rect" size="sm" variant="ghost" class="case-cta" :disabled="!casesHasMore || loading" @click="loadCasePage(casesPage + 1)">下一頁</AtButton>
              </div>
            </div>
          </template>

          <template v-if="laneFilter !== 'case'">
          <div v-if="urgentNotifications.length > 0" class="urgent-panel">
            <h4>急件置頂</h4>
            <div v-for="item in urgentNotifications" :key="`urgent-${item.id}`" class="urgent-row">
              <div class="urgent-copy">
                <span class="urgent-title">{{ item.Title }}</span>
                <span v-if="notificationSummary(item)" class="notification-context">{{ notificationSummary(item) }}</span>
              </div>
              <div class="urgent-actions">
                <button v-if="!item.read_at" type="button" class="small" @click="markRead(item.id)">標記已讀</button>
                <button
                  v-if="canMarkTuitionPaid(item)"
                  type="button"
                  class="small primary"
                  :disabled="isMarkingTuitionPaid(item.id)"
                  @click="openTuitionModal(item)"
                >
                  {{ isMarkingTuitionPaid(item.id) ? '處理中...' : '標記已繳費' }}
                </button>
                <button v-if="canCopyTuition(item)" type="button" class="small ghost" @click="copyTuitionMessage(item)">複製繳費通知</button>
                <button v-if="targetPage(item.Type)" type="button" class="small ghost" @click="goToTarget(item.Type, item)">前往處理</button>
              </div>
            </div>
          </div>

          <div
            v-for="item in mainNotifications"
            :key="item.id"
            class="notification-item"
            :class="{
              unread: !item.read_at,
              resolved: !!item.ResolvedAt,
              'severity-high-item': item.Severity === 'high' && !item.read_at,
            }"
          >
            <div class="title-row">
              <span class="type-tag" :class="`type-${item.Type}`">{{ typeLabel(item.Type) }}</span>
              <span class="severity-tag" :class="`severity-${item.Severity}`">{{ severityLabel(item.Severity) }}</span>
              <span v-if="item.ResolvedAt" class="resolved-tag">已解除</span>
              <span v-if="!item.read_at" class="unread-dot"></span>
            </div>

            <div class="main-title" :class="{ 'title-resolved': !!item.ResolvedAt }">{{ item.Title }}</div>
            <div v-if="item.extra_count > 0" class="grouped-tag">同類 {{ item.extra_count + 1 }} 筆</div>
            <div v-if="notificationSummary(item)" class="notification-context">{{ notificationSummary(item) }}</div>
            <div v-if="item.Body" class="main-body">{{ item.Body }}</div>

            <div class="meta-row">
              <span>{{ formatDateTime(item.OccurredAt || item.created_at) }}</span>
              <span>來源：{{ item.SourceType || '-' }}</span>
            </div>

            <div class="item-actions">
              <button v-if="!item.read_at" type="button" class="small" @click="markRead(item.id)">標記已讀</button>
              <button
                v-if="canMarkTuitionPaid(item)"
                type="button"
                class="small primary"
                :disabled="isMarkingTuitionPaid(item.id)"
                @click="openTuitionModal(item)"
              >
                {{ isMarkingTuitionPaid(item.id) ? '處理中...' : '標記已繳費' }}
              </button>
              <button v-if="canCopyTuition(item)" type="button" class="small ghost" @click="copyTuitionMessage(item)">複製繳費通知</button>
              <button v-if="targetPage(item.Type)" type="button" class="small ghost" @click="goToTarget(item.Type, item)">前往處理</button>
            </div>
          </div>
          </template>
        </div>

        <div v-if="lastPage > 1" class="pagination-row">
          <AtButton shape="rect" size="sm" variant="ghost" :disabled="currentPage <= 1 || loading" @click="loadNotifications(currentPage - 1)">上一頁</AtButton>
          <span>第 {{ currentPage }} / {{ lastPage }} 頁</span>
          <AtButton shape="rect" size="sm" variant="ghost" :disabled="currentPage >= lastPage || loading" @click="loadNotifications(currentPage + 1)">下一頁</AtButton>
        </div>
      </AtSection>
    </template>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import {
  casePriorityLabel,
  extractCaseItems,
  extractCasePageMeta,
  extractCaseTotal,
  inboxScopeKey,
  mergeInboxCountState,
  parseInboxCount,
  resolveDefaultLane,
  shouldShowCachedCases,
} from '../lib/actionInboxContract.js';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';
import AtSection from '../components/design-system/AtSection.vue';
import AtFilterBar from '../components/design-system/AtFilterBar.vue';
import AtToolbar from '../components/design-system/AtToolbar.vue';
import AtButton from '../components/design-system/AtButton.vue';
import AtDialog from '../components/design-system/AtDialog.vue';
import AtBadge from '../components/design-system/AtBadge.vue';
import AtEmpty from '../components/design-system/AtEmpty.vue';
import AtInlineAlert from '../components/design-system/AtInlineAlert.vue';
import AtSkeleton from '../components/design-system/AtSkeleton.vue';

const props = defineProps({
  branchId: [String, Number],
});

const emit = defineEmits(['navigate', 'unread-change']);

const loading = ref(false);
const syncing = ref(false);
const markingAllRead = ref(false);
const errorMessage = ref('');
const caseLaneError = ref(false);
const countsStale = ref(false);
const notifications = ref([]);
const caseItems = ref([]);
const caseItemsScopeKey = ref('');
const countsScopeKey = ref('');
const unreadCount = ref(0);
const urgentUnreadCount = ref(0);
const casesOpenCount = ref(0);
const casesOverdueCount = ref(0);
const casesDueSoonCount = ref(0);
const casesCandidateReadyCount = ref(0);
const needsAttentionCount = ref(0);
const inboxUrgentTotal = ref(0);
const currentPage = ref(1);
const lastPage = ref(1);
const casesPage = ref(1);
const casesLastPage = ref(1);
const casesHasMore = ref(false);
const caseFilter = ref('unresolved');
const userPickedLane = ref(false);
const initialLaneResolved = ref(false);

const readFilter = ref('unread');
const typeFilter = ref('lane:case');
const laneFilter = ref('case');
const includeResolved = ref(false);
const soundEnabled = ref(localStorage.getItem('notifications_sound_enabled') !== '0');
const quietMinor = ref(localStorage.getItem('notifications_quiet_minor') === '1');
const focusMode = ref(localStorage.getItem('notifications_focus_mode') || 'all');
const clearingResolved = ref(false);
const severityRank = { high: 3, medium: 2, low: 1, info: 0 };
const hasUrgentWatchInitialized = ref(false);
const lastUrgentDigest = ref('');
const markingTuitionPaidIds = ref(new Set());

const todayYmd = computed(() => new Date().toISOString().slice(0, 10));
const tuitionModal = ref({
  visible: false,
  item: null,
  processing: false,
  error: '',
  form: {
    payment_date: todayYmd.value,
    payment_method: 'transfer',
    account_last5: '',
    amount: 0,
    note: '',
  },
});
const tuitionPaymentRow = computed(() => paymentRowFromNotification(tuitionModal.value.item));

const getToken = () => {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
  return session?.access_token || '';
};

const getBaseUrl = () => import.meta.env.VITE_API_BASE || '/api';

const TYPE_META = {
  tuition:          { label: '繳費',    tab: '繳費' },
  low_sessions:     { label: '堂數',    tab: '堂數' },
  learning_review:  { label: '評量',    tab: '評量' },
  pending_swipe:    { label: '刷卡',    tab: '刷卡' },
  schedule_change:  { label: '課程變更', tab: '系統' },
  substitute:       { label: '代課通知', tab: '系統' },
  substitute_confirm: { label: '代課通知', tab: '系統' },
  student_leave:    { label: '請假申請', tab: '請假／補課' },
};

const typeLabel = (type) => TYPE_META[type]?.label || type || '其他';

const typeTabs = computed(() => [
  { value: 'lane:case', id: 'notifications-tab-cases', panelId: 'notifications-panel-cases', label: '待辦案件', count: casesOpenCount.value },
  { value: '', id: 'notifications-tab-ops', panelId: 'notifications-panel-ops', label: '營運通知', count: unreadCount.value },
]);

const activeNotificationTab = computed(() => typeTabs.value.find((tab) => tab.value === typeFilter.value) || typeTabs.value[0]);
const activeNotificationTabId = computed(() => activeNotificationTab.value.id);
const activeNotificationPanelId = computed(() => activeNotificationTab.value.panelId);

const visibleCaseItems = computed(() => (
  shouldShowCachedCases({ scopeKey: inboxScopeKey(props.branchId), cachedScopeKey: caseItemsScopeKey.value })
    ? caseItems.value : []
));

const onTabClick = (value) => {
  userPickedLane.value = true;
  typeFilter.value = value;
  laneFilter.value = value === 'lane:case' ? 'case' : 'ops';
  if (laneFilter.value === 'case') casesPage.value = 1;
  loadNotifications(1);
};

const onTypeTabKeydown = (event, currentValue) => {
  const navigationKeys = ['ArrowRight', 'ArrowLeft', 'Home', 'End'];
  if (!navigationKeys.includes(event.key)) return;

  event.preventDefault();
  const currentIndex = typeTabs.value.findIndex((tab) => tab.value === currentValue);
  if (currentIndex < 0) return;

  let nextIndex = currentIndex;
  if (event.key === 'ArrowRight') nextIndex = (currentIndex + 1) % typeTabs.value.length;
  if (event.key === 'ArrowLeft') nextIndex = (currentIndex - 1 + typeTabs.value.length) % typeTabs.value.length;
  if (event.key === 'Home') nextIndex = 0;
  if (event.key === 'End') nextIndex = typeTabs.value.length - 1;

  const nextTab = typeTabs.value[nextIndex];
  onTabClick(nextTab.value);
  nextTick(() => document.getElementById(nextTab.id)?.focus());
};

const retryLoad = () => loadNotifications(currentPage.value || 1);

const severityLabel = (severity) => {
  const map = {
    high: '高',
    medium: '中',
    low: '低',
    info: '資訊',
  };
  return map[severity] || '資訊';
};

const formatDateTime = (value) => {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  const hh = String(date.getHours()).padStart(2, '0');
  const mm = String(date.getMinutes()).padStart(2, '0');
  return `${y}-${m}-${d} ${hh}:${mm}`;
};

const targetPage = (type) => {
  if (type === 'pending_swipe') return 'attendance';
  if (type === 'learning_review') return 'learning';
  if (type === 'tuition' || type === 'low_sessions') return 'tuition-collect';
  if (type === 'schedule_change' || type === 'substitute' || type === 'substitute_confirm') return 'calendar';
  return null;
};

const goToTarget = (type, item) => {
  const target = targetPage(type);
  if (!target) return;
  const payload = item ? payloadOf(item) : {};
  const recordId = type === 'learning_review' ? (payload.record_id || null) : null;
  const studentId = payload.student_id || null;
  const courseId = payload.student_class_id || payload.course_id || payload.studentClassId || null;
  const date = payload.session_date || payload.schedule_date || payload.date || '';
  const intent = target === 'tuition-collect'
    ? (type === 'low_sessions' ? 'renewal' : 'unpaid')
    : (target === 'calendar' ? 'reschedule' : '');
  emit('navigate', { target, recordId, studentId, courseId, date, intent });
};

const goToNotificationSettings = () => {
  emit('navigate', { target: 'profile', section: 'notifications' });
};

const goToLeaveCase = (item) => {
  const workflowId = Number(item?.action?.workflow_id || item?.workflow_id || item?.source_id || 0);
  emit('navigate', {
    target: 'director',
    section: 'exception-workflows',
    workflowId: workflowId > 0 ? workflowId : null,
  });
};

const payloadOf = (item) => {
  if (!item) return {};
  if (item.Payload && typeof item.Payload === 'object') return item.Payload;
  if (typeof item.Payload === 'string') {
    try {
      return JSON.parse(item.Payload);
    } catch {
      return {};
    }
  }
  return {};
};

const parseMoney = (value) => {
  const n = Number(value);
  return Number.isFinite(n) && n > 0 ? n : 0;
};

const formatCurrency = (value) => `NT$ ${Number(value || 0).toLocaleString('zh-TW')}`;

const paymentAmountFromPayload = (payload) => {
  const total = parseMoney(payload?.total_amount ?? payload?.charge ?? payload?.amount);
  const paid = parseMoney(payload?.paid_amount);
  const outstanding = parseMoney(payload?.outstanding);
  if (outstanding > 0) return outstanding;
  if (total > 0 && paid > 0) return Math.max(0, total - paid);
  return total;
};

const paymentRowFromNotification = (item) => {
  const payload = payloadOf(item);
  const amount = paymentAmountFromPayload(payload);
  return {
    student_name: payload.student_name || '',
    subject: payload.subject || (item?.SourceType === 'Invoice' ? '學費' : ''),
    charge: amount,
  };
};

const notificationSummary = (item) => {
  const payload = payloadOf(item);
  const parts = [];
  if (payload.student_name) parts.push(payload.student_name);
  if (payload.subject) parts.push(payload.subject);
  const amount = paymentAmountFromPayload(payload);
  if (amount > 0) parts.push(formatCurrency(amount));
  if (payload.overdue_days) parts.push(`逾期 ${payload.overdue_days} 天`);
  if (payload.remaining_sessions != null && item?.Type === 'low_sessions') {
    parts.push(`剩餘 ${payload.remaining_sessions} 堂`);
  }
  return parts.join(' ｜ ');
};

const canCopyTuition = (item) => {
  if (!item || item.Type !== 'tuition') return false;
  const payload = payloadOf(item);
  return Number(payload?.student_id || 0) > 0;
};

const canMarkTuitionPaid = (item) => {
  if (!item || item.ResolvedAt) return false;
  // low_sessions = 已繳但堂數偏低（續課提醒），不應出現「標記已繳費」
  if (item.Type === 'low_sessions') return false;
  return item.SourceType === 'StudentClass' || item.SourceType === 'Invoice';
};

const isMarkingTuitionPaid = (notificationId) => markingTuitionPaidIds.value.has(String(notificationId));

const setMarkingTuitionPaid = (notificationId, pending) => {
  const next = new Set(markingTuitionPaidIds.value);
  const key = String(notificationId);
  if (pending) next.add(key);
  else next.delete(key);
  markingTuitionPaidIds.value = next;
};

const copyText = async (text) => {
  if (!text) return;
  try {
    await navigator.clipboard.writeText(text);
    alert('繳費通知已複製到剪貼簿！');
  } catch {
    prompt('請手動複製以下訊息：', text);
  }
};

const copyTuitionMessage = async (item) => {
  const payload = payloadOf(item);
  const studentId = Number(payload?.student_id || 0);
  if (!studentId) {
    errorMessage.value = '此通知缺少學生資料，無法產生繳費通知';
    return;
  }

  try {
    const token = getToken();
    const baseUrl = getBaseUrl();
    const res = await fetch(`${baseUrl}/v1/parent/payment-message/${studentId}`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json?.message || '無法取得繳費通知');
    await copyText(String(json?.message || ''));
  } catch (err) {
    errorMessage.value = err.message || '無法取得繳費通知';
  }
};

const byPriority = (a, b) => {
  const aRead = a.read_at ? 1 : 0;
  const bRead = b.read_at ? 1 : 0;
  if (aRead !== bRead) return aRead - bRead;

  const aSla = String(payloadOf(a).overdue_tier || '');
  const bSla = String(payloadOf(b).overdue_tier || '');
  const slaRank = { '急件': 3, '高': 2, '中': 1 };
  const as = slaRank[aSla] ?? 0;
  const bs = slaRank[bSla] ?? 0;
  if (as !== bs) return bs - as;

  const aSeverity = severityRank[a.Severity] ?? 0;
  const bSeverity = severityRank[b.Severity] ?? 0;
  if (aSeverity !== bSeverity) return bSeverity - aSeverity;

  const aOccurred = new Date(a.OccurredAt || a.created_at || 0).getTime();
  const bOccurred = new Date(b.OccurredAt || b.created_at || 0).getTime();
  return bOccurred - aOccurred;
};

const displayNotifications = computed(() => {
  const sorted = [...notifications.value].sort(byPriority);
  const filtered = sorted.filter((item) => {
    const sev = severityRank[item.Severity] ?? 0;
    const unresolved = !item.ResolvedAt && !item.read_at;
    if (focusMode.value === 'high') return sev >= 3;
    if (focusMode.value === 'sla') return sev >= 2 || String(payloadOf(item).overdue_tier || '') === '急件';
    if (focusMode.value === 'actionable') return unresolved;
    return true;
  }).filter((item) => {
    if (!quietMinor.value) return true;
    const sev = severityRank[item.Severity] ?? 0;
    const unresolved = !item.ResolvedAt && !item.read_at;
    return unresolved || sev >= 2;
  });

  const groupedMap = new Map();
  for (const item of filtered) {
    const p = payloadOf(item);
    const signature = [
      item.Type || '',
      item.SourceType || '',
      p.student_id || p.student_name || '',
      p.subject || '',
      item.Title || '',
    ].join('|');
    if (!groupedMap.has(signature)) {
      groupedMap.set(signature, { ...item, extra_count: 0 });
      continue;
    }
    const current = groupedMap.get(signature);
    current.extra_count += 1;
    if ((new Date(item.OccurredAt || item.created_at || 0)).getTime() > (new Date(current.OccurredAt || current.created_at || 0)).getTime()) {
      groupedMap.set(signature, { ...item, extra_count: current.extra_count });
    } else {
      groupedMap.set(signature, current);
    }
  }
  return Array.from(groupedMap.values()).sort(byPriority);
});

const urgentNotifications = computed(() => {
  return displayNotifications.value
    .filter((item) => {
      if (item.read_at) return false;
      const payload = payloadOf(item);
      return item.Severity === 'high' || payload.overdue_tier === '急件';
    })
    .slice(0, 5);
});

const mainNotifications = computed(() => {
  if (urgentNotifications.value.length === 0) return displayNotifications.value;
  const urgentIdSet = new Set(urgentNotifications.value.map((item) => item.id));
  return displayNotifications.value.filter((item) => !urgentIdSet.has(item.id));
});

const buildUrgentDigest = () => {
  return urgentNotifications.value.map((item) => `${item.id}:${item.read_at ? 'r' : 'u'}`).join('|');
};

const playUrgentSound = async () => {
  if (!soundEnabled.value) return;
  try {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return;
    const ctx = new Ctx();
    if (ctx.state === 'suspended') await ctx.resume();

    const now = ctx.currentTime;
    const beep = (start, freq, gain = 0.05) => {
      const osc = ctx.createOscillator();
      const g = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(freq, start);
      g.gain.setValueAtTime(0, start);
      g.gain.linearRampToValueAtTime(gain, start + 0.02);
      g.gain.linearRampToValueAtTime(0, start + 0.18);
      osc.connect(g);
      g.connect(ctx.destination);
      osc.start(start);
      osc.stop(start + 0.2);
    };

    beep(now + 0.01, 880);
    beep(now + 0.25, 988);
  } catch {
    // Ignore browser audio permission issues silently
  }
};

const buildQuery = (page = 1) => {
  const params = new URLSearchParams();
  params.set('branch_id', String(props.branchId));
  params.set('read', readFilter.value);
  params.set('per_page', '20');
  params.set('page', String(page));
  if (typeFilter.value && !String(typeFilter.value).startsWith('lane:')) {
    params.set('type', typeFilter.value);
  }
  if (includeResolved.value) params.set('include_resolved', '1');
  return params.toString();
};

const applyParsedCounts = (parsed, { stale = false, scope = '' } = {}) => {
  unreadCount.value = parsed.notificationsUnread;
  casesOpenCount.value = parsed.casesUnresolved;
  casesOverdueCount.value = parsed.casesOverdue;
  casesDueSoonCount.value = parsed.casesDueSoon;
  casesCandidateReadyCount.value = parsed.casesCandidateReady;
  needsAttentionCount.value = parsed.badgeTotal;
  inboxUrgentTotal.value = parsed.urgentTotal;
  countsStale.value = stale;
  if (scope) countsScopeKey.value = scope;
  emit('unread-change', {
    unread: unreadCount.value, urgent: inboxUrgentTotal.value, casesOpen: casesOpenCount.value,
    needsAttention: needsAttentionCount.value, urgentTotal: inboxUrgentTotal.value,
    casesOverdue: casesOverdueCount.value, casesDueSoon: casesDueSoonCount.value,
  });
};

const refreshInboxCounts = async () => {
  if (!props.branchId) return false;
  const scope = inboxScopeKey(props.branchId);
  const prevScope = countsScopeKey.value;
  try {
    const token = getToken();
    const baseUrl = getBaseUrl();
    const res = await fetch(`${baseUrl}/v1/action-inbox/count?branch_id=${props.branchId}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error('count failed');
    const parsed = parseInboxCount(json);
    applyParsedCounts(parsed, { scope });
    if (!userPickedLane.value && !initialLaneResolved.value) {
      const lane = resolveDefaultLane({ casesUnresolved: parsed.casesUnresolved });
      laneFilter.value = lane;
      typeFilter.value = lane === 'case' ? 'lane:case' : '';
      initialLaneResolved.value = true;
    }
    return true;
  } catch {
    const prev = {
      notificationsUnread: unreadCount.value, casesUnresolved: casesOpenCount.value,
      casesOverdue: casesOverdueCount.value, casesDueSoon: casesDueSoonCount.value,
      casesCandidateReady: casesCandidateReadyCount.value, urgentTotal: inboxUrgentTotal.value,
      badgeTotal: needsAttentionCount.value,
    };
    const merged = mergeInboxCountState(prev, null, { failed: true, scopeKey: scope, prevScopeKey: prevScope });
    applyParsedCounts(merged, { stale: true, scope: merged.scopeInvalidated ? scope : prevScope });
    return false;
  }
};

const loadCasePage = (page) => {
  casesPage.value = Math.max(1, Number(page) || 1);
  return loadCaseItems();
};

const loadCaseItems = async () => {
  if (!props.branchId) {
    caseItems.value = [];
    caseItemsScopeKey.value = '';
    return;
  }
  const requestScope = inboxScopeKey(props.branchId);
  if (caseItemsScopeKey.value && caseItemsScopeKey.value !== requestScope) {
    caseItems.value = []; caseItemsScopeKey.value = ''; caseLaneError.value = false;
  }
  try {
    const token = getToken();
    const baseUrl = getBaseUrl();
    const params = new URLSearchParams({
      branch_id: String(props.branchId),
      lane: 'case',
      page: String(casesPage.value || 1),
      per_page: '20',
      case_filter: caseFilter.value || 'unresolved',
    });
    const res = await fetch(`${baseUrl}/v1/action-inbox?${params}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json?.message || '請假案件載入失敗');
    if (inboxScopeKey(props.branchId) !== requestScope) return;
    caseItems.value = extractCaseItems(json);
    caseItemsScopeKey.value = requestScope;
    const meta = extractCasePageMeta(json);
    casesOpenCount.value = extractCaseTotal(json, caseItems.value.length);
    casesPage.value = meta.currentPage; casesLastPage.value = meta.lastPage; casesHasMore.value = meta.hasMore;
    caseLaneError.value = false;
    if (json?.summary?.cases_candidate_ready != null) {
      casesCandidateReadyCount.value = Number(json.summary.cases_candidate_ready);
    }
  } catch (err) {
    caseLaneError.value = true;
    if (caseItemsScopeKey.value !== requestScope) { caseItems.value = []; caseItemsScopeKey.value = ''; }
    if (laneFilter.value === 'case') {
      errorMessage.value = '';
    }
  }
};

const loadNotifications = async (page = 1) => {
  if (!props.branchId) {
    notifications.value = [];
    caseItems.value = [];
    caseItemsScopeKey.value = '';
    return;
  }

  loading.value = true;
  errorMessage.value = '';
  try {
    const countOk = await refreshInboxCounts();
    void countOk;
    const caseP = loadCaseItems();

    if (laneFilter.value === 'case') {
      await Promise.allSettled([caseP]);
      notifications.value = [];
      currentPage.value = 1;
      lastPage.value = 1;
      return;
    }

    const token = getToken();
    const baseUrl = getBaseUrl();
    const notifP = fetch(`${baseUrl}/v1/notifications?${buildQuery(page)}`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    }).then(async (res) => {
      const json = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(json?.message || '通知載入失敗');
      return json;
    });

    const settled = await Promise.allSettled([caseP, notifP]);
    const notifResult = settled[1];
    if (notifResult.status === 'fulfilled') {
      const json = notifResult.value;
      notifications.value = json.data || [];
      urgentUnreadCount.value = Number(json.urgent_unread_count || 0);
      currentPage.value = Number(json.current_page || 1);
      lastPage.value = Number(json.last_page || 1);
    } else {
      errorMessage.value = notifResult.reason?.message || '通知載入失敗';
    }
    emit('unread-change', {
      unread: unreadCount.value,
      urgent: inboxUrgentTotal.value,
      casesOpen: casesOpenCount.value,
      needsAttention: needsAttentionCount.value,
      urgentTotal: inboxUrgentTotal.value,
    });
  } catch (err) {
    errorMessage.value = err.message || '收件匣載入失敗';
  } finally {
    loading.value = false;
  }
};

const syncNotifications = async (showAlert = false) => {
  if (!props.branchId) return;
  syncing.value = true;
  errorMessage.value = '';
  try {
    const token = getToken();
    const baseUrl = getBaseUrl();
    const res = await fetch(`${baseUrl}/v1/notifications/sync`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
      body: JSON.stringify({ branch_id: Number(props.branchId) }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json?.message || '通知同步失敗');

    await loadNotifications(1);
    if (showAlert) {
      alert(`同步完成：新增 ${json.created || 0}、更新 ${json.updated || 0}、解除 ${json.resolved || 0}`);
    }
  } catch (err) {
    errorMessage.value = err.message || '通知同步失敗';
  } finally {
    syncing.value = false;
  }
};

const markRead = async (notificationId) => {
  try {
    const token = getToken();
    const baseUrl = getBaseUrl();
    const res = await fetch(`${baseUrl}/v1/notifications/${notificationId}/read`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json?.message || '標記已讀失敗');
    await loadNotifications(currentPage.value);
  } catch (err) {
    errorMessage.value = err.message || '標記已讀失敗';
  }
};

const markAllRead = async () => {
  if (!props.branchId) return;
  markingAllRead.value = true;
  errorMessage.value = '';
  try {
    const token = getToken();
    const baseUrl = getBaseUrl();
    const body = { branch_id: Number(props.branchId) };
    if (typeFilter.value) body.type = typeFilter.value;
    if (includeResolved.value) body.include_resolved = true;

    const res = await fetch(`${baseUrl}/v1/notifications/read-all`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
      body: JSON.stringify(body),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json?.message || '全部已讀失敗');
    await loadNotifications(1);
  } catch (err) {
    errorMessage.value = err.message || '全部已讀失敗';
  } finally {
    markingAllRead.value = false;
  }
};

const openTuitionModal = (item) => {
  if (!item?.id || isMarkingTuitionPaid(item.id)) return;
  const amount = paymentAmountFromPayload(payloadOf(item));
  tuitionModal.value = {
    visible: true,
    item,
    processing: false,
    error: '',
    form: {
      payment_date: todayYmd.value,
      payment_method: 'transfer',
      account_last5: '',
      amount,
      note: '',
    },
  };
};

const confirmTuitionPaid = async () => {
  const item = tuitionModal.value.item;
  if (!item?.id || !props.branchId) return;
  const form = tuitionModal.value.form;
  tuitionModal.value.error = '';
  const amount = Number(form.amount);
  if (!form.payment_date || form.amount === '' || form.amount == null || !Number.isFinite(amount) || amount < 0) {
    tuitionModal.value.error = '請填寫繳費日期與金額，金額不可小於 0';
    return;
  }

  tuitionModal.value.processing = true;
  setMarkingTuitionPaid(item.id, true);
  errorMessage.value = '';
  try {
    const token = getToken();
    const baseUrl = getBaseUrl();
    const res = await fetch(`${baseUrl}/v1/notifications/${item.id}/tuition-paid`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
      body: JSON.stringify({
        branch_id: Number(props.branchId),
        payment_date: form.payment_date,
        payment_method: form.payment_method,
        account_last5: form.payment_method === 'transfer' ? form.account_last5 : '',
        amount,
        note: form.note?.trim() || '',
      }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json?.message || '送出已回報失敗');

    tuitionModal.value.visible = false;
    await syncNotifications(false);
  } catch (err) {
    tuitionModal.value.error = err.message || '送出已回報失敗';
  } finally {
    setMarkingTuitionPaid(item.id, false);
    tuitionModal.value.processing = false;
  }
};

const clearResolved = async () => {
  if (!props.branchId || clearingResolved.value) return;
  clearingResolved.value = true;
  errorMessage.value = '';
  try {
    const token = getToken();
    const baseUrl = getBaseUrl();
    const res = await fetch(`${baseUrl}/v1/notifications/read-all`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
      body: JSON.stringify({ branch_id: Number(props.branchId), resolved_only: true }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json?.message || '清除失敗');
    await loadNotifications(1);
  } catch (err) {
    errorMessage.value = err.message || '清除已解除通知失敗';
  } finally {
    clearingResolved.value = false;
  }
};

watch(() => props.branchId, (next, prev) => {
  if (String(next ?? '') === String(prev ?? '')) return;
  caseItems.value = []; caseItemsScopeKey.value = ''; caseLaneError.value = false; countsScopeKey.value = '';
  unreadCount.value = 0; casesOpenCount.value = 0; casesOverdueCount.value = 0; casesDueSoonCount.value = 0;
  casesCandidateReadyCount.value = 0; needsAttentionCount.value = 0; inboxUrgentTotal.value = 0;
  initialLaneResolved.value = false; userPickedLane.value = false; casesPage.value = 1;
  loadNotifications(1);
});

watch([readFilter, typeFilter, includeResolved, laneFilter], () => {
  loadNotifications(laneFilter.value === 'case' ? 1 : currentPage.value);
});

watch(soundEnabled, (value) => {
  localStorage.setItem('notifications_sound_enabled', value ? '1' : '0');
});

watch(quietMinor, (value) => {
  localStorage.setItem('notifications_quiet_minor', value ? '1' : '0');
});

watch(focusMode, (value) => {
  localStorage.setItem('notifications_focus_mode', value || 'all');
});

watch(urgentNotifications, async () => {
  const digest = buildUrgentDigest();
  if (!hasUrgentWatchInitialized.value) {
    hasUrgentWatchInitialized.value = true;
    lastUrgentDigest.value = digest;
    return;
  }
  if (digest && digest !== lastUrgentDigest.value) {
    lastUrgentDigest.value = digest;
    await playUrgentSound();
    return;
  }
  lastUrgentDigest.value = digest;
});

let refreshTimer = null;

onMounted(async () => {
  await loadNotifications(1);
  refreshTimer = window.setInterval(() => {
    loadNotifications(laneFilter.value === 'case' ? casesPage.value : currentPage.value);
  }, 60000);
});

onUnmounted(() => {
  if (refreshTimer) {
    clearInterval(refreshTimer);
    refreshTimer = null;
  }
});
</script>

<style scoped>
.notifications-page {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.empty-card {
  text-align: center;
  color: var(--text-light);
}

:global(.payment-modal) {
  max-width: 460px;
}

.modal-desc {
  color: var(--text-light);
  font-size: 13px;
  margin: 0 0 12px;
}

.modal-item-name {
  background: var(--ds-canvas-soft);
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 14px;
  margin-bottom: 18px;
  font-weight: 600;
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.modal-item-name strong {
  color: var(--text-light);
  font-size: 12px;
  margin-right: 4px;
}

.modal-field {
  display: block;
  margin-bottom: 12px;
  font-size: 13px;
  font-weight: 600;
  color: var(--text);
}

.required {
  color: var(--danger);
}

.modal-field input,
.modal-field textarea {
  width: 100%;
  box-sizing: border-box;
  margin-top: 5px;
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 8px 10px;
  font: inherit;
  background: var(--ds-canvas);
}

.payment-method-row {
  display: flex;
  gap: 8px;
  margin-top: 6px;
}

.payment-method-option {
  flex: 1;
  text-align: center;
  border: 1.5px solid var(--border);
  border-radius: 10px;
  padding: 10px 12px;
  cursor: pointer;
  background: var(--ds-canvas);
}

.payment-method-option input {
  display: none;
}

.payment-method-option.active {
  border-color: var(--primary);
  background: var(--primary-bg);
  color: var(--primary);
}

.modal-hint {
  margin: -6px 0 12px;
  font-size: 12px;
  color: var(--text-light);
}

.modal-error {
  color: var(--danger);
  background: var(--danger-bg);
  border-radius: 8px;
  padding: 8px 10px;
  font-size: 13px;
  margin-bottom: 12px;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

/* ── 分類 Tab（Pajamas-style underline, not pills）── */
.type-tabs {
  display: flex;
  gap: 0;
  flex-wrap: wrap;
  border-bottom: 1px solid var(--ds-hairline);
  margin-bottom: var(--ds-space-3, 12px);
}

.type-tab {
  padding: 8px 14px;
  border: 0;
  border-bottom: 2px solid transparent;
  border-radius: 0;
  background: transparent;
  color: var(--ds-text-tertiary, var(--text-light));
  cursor: pointer;
  font-size: var(--ds-font-size-base, 14px);
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: -1px;
  transition: color var(--ds-motion-fast, 120ms) var(--ds-ease-standard, ease),
    border-color var(--ds-motion-fast, 120ms) var(--ds-ease-standard, ease);
}

.type-tab:hover {
  color: var(--ds-text-primary, var(--ds-ink));
  background: transparent;
}

.type-tab:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px var(--ds-focus-ring);
}

.type-tab.active {
  background: transparent;
  color: var(--ds-primary-deep);
  border-bottom-color: var(--ds-primary);
}

.tab-badge {
  background: var(--ds-surface-2, var(--ds-canvas-soft));
  color: var(--ds-text-secondary, var(--ds-ink-secondary));
  border-radius: var(--ds-radius-sm, 4px);
  font-size: 11px;
  padding: 1px 6px;
  font-weight: 700;
  min-width: 18px;
  text-align: center;
  font-variant-numeric: tabular-nums;
}

.type-tab.active .tab-badge {
  background: var(--ds-primary-wash);
  color: var(--ds-primary-deep);
}

/* ── Controls ── */
.controls-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.controls-row {
  display: flex;
  gap: 14px;
  align-items: flex-end;
  flex-wrap: wrap;
}

.checkbox-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
}

.checkbox-wrap input {
  width: auto;
}

.stats-box {
  font-size: 14px;
  color: var(--text-light);
  margin-bottom: 10px;
}

.urgent-stat {
  margin-left: 10px;
  color: var(--ds-danger);
}

.actions-row {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}

.error-card {
  border-left: 4px solid var(--danger);
  color: var(--danger);
}

.list-card {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.urgent-panel {
  border: 1px solid var(--ds-danger);
  border-radius: var(--ds-radius-md, 6px);
  background: var(--ds-danger-wash);
  padding: 10px;
  margin-bottom: 10px;
}

.urgent-panel h4 {
  color: var(--ds-danger);
  margin: 0 0 8px;
  font-size: 13px;
}

.urgent-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  padding: 8px 0;
  border-top: 1px dashed var(--ds-danger-wash);
}

.urgent-row:first-of-type {
  border-top: 0;
}

.urgent-title {
  font-size: 13px;
  color: var(--ds-danger);
  font-weight: 600;
}

.urgent-copy {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

.notification-context {
  color: var(--text-light);
  font-size: 12px;
}

.urgent-actions {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.notification-item {
  border: 1px solid var(--ds-hairline);
  border-left: 3px solid transparent;
  border-radius: var(--ds-radius-md, 6px);
  padding: 10px 12px;
  margin-bottom: 8px;
  background: var(--ds-surface-1, var(--ds-canvas));
  transition: background var(--ds-motion-fast, 120ms) var(--ds-ease-standard, ease);
}

.notification-item.unread {
  border-color: var(--ds-ink-mute);
  border-left-color: var(--ds-ink-mute);
  background: var(--ds-canvas-soft);
}

.notification-item.severity-high-item {
  border-left-color: var(--ds-danger);
  background: var(--ds-danger-wash);
}

.notification-item.resolved {
  opacity: 0.65;
  background: var(--ds-canvas);
}

.title-resolved {
  text-decoration: line-through;
  color: var(--text-light);
}

.title-row {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 8px;
}

.type-tag,
.severity-tag,
.resolved-tag,
.unread-dot {
  font-size: 11px;
  border-radius: 999px;
  padding: 2px 8px;
}

.type-tag {
  background: var(--ds-canvas-soft);
  color: var(--ds-ink);
}

.type-tuition {
  background: var(--ds-warning-wash);
  color: var(--ds-primary);
}

.type-learning_review {
  background: var(--ds-canvas-soft);
  color: var(--ds-ink-mute);
}

.type-pending_swipe {
  background: var(--ds-danger-wash);
  color: var(--ds-danger);
}

.type-low_sessions {
  background: var(--ds-canvas-soft);
  color: var(--ds-ink-mute);
}

.type-schedule_change,
.type-substitute,
.type-substitute_confirm {
  background: var(--ds-canvas-soft);
  color: var(--ds-success);
}

.type-student_leave { background: var(--ds-warning-wash); color: var(--ds-warning); }
.case-panel { margin-bottom: 16px; }
.leave-case-list__intro { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 0 0 10px; padding: 0 2px; }
.leave-case-list__intro h4 { margin: 0; color: var(--ds-ink); font-size: 15px; }
.leave-case-list__intro p { margin: 3px 0 0; color: var(--ds-ink-mute); font-size: 12px; line-height: 1.45; }
.leave-case-list__intro > span { flex: 0 0 auto; padding: 5px 9px; border: 1px solid var(--ds-warning); border-radius: 999px; background: var(--ds-warning-wash); color: var(--ds-warning); font-size: 11px; font-weight: 800; }
.case-body { white-space: pre-line; }
.status-chip { font-size: 12px; color: var(--text-light); }
.inbox-count-row { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 8px; font-size: 13px; color: var(--text-light); }
.inbox-count-sep::before { content: '·'; margin-right: 10px; color: var(--border); }
.inbox-stale { margin: 8px 0 0; color: var(--ds-warning); font-size: 13px; }
.case-cta { min-width: 44px; min-height: var(--ds-control-height-md, 32px); }
.at-ops-page { display: flex; flex-direction: column; gap: var(--ds-space-3, 12px); }
.controls-card { margin: 0; }
.list-card { margin: 0; }
.checkbox-wrap { flex-direction: row !important; align-items: center; margin-bottom: 0; }
.stats-box { margin-bottom: 0; font-variant-numeric: tabular-nums; }
.case-reason { opacity: 0.75; font-size: 13px; }
.pager-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 12px; flex-wrap: wrap; }
.case-item { border-left-color: var(--ds-warning); }
.leave-case-item { overflow: hidden; border: 1px solid var(--ds-hairline); border-left: 3px solid var(--ds-warning); border-radius: 14px; background: var(--ds-canvas); box-shadow: 0 6px 18px rgba(15, 23, 42, 0.05); }
.leave-case-item .title-row { padding-top: 2px; }
.leave-case-item .main-title { color: var(--ds-ink); font-size: 17px; }
.leave-case-step { display: flex; align-items: flex-start; gap: 7px; margin-top: 12px; padding: 9px 10px; border-radius: 9px; background: var(--ds-canvas-soft); color: var(--ds-ink-secondary); font-size: 12px; line-height: 1.5; }
.leave-case-step .material-symbols-outlined { color: var(--ds-warning); font-size: 17px; }
.leave-case-item .item-actions { margin: 14px -16px -16px; padding: 12px 16px; border-top: 1px solid var(--ds-hairline); background: var(--ds-canvas-soft); justify-content: flex-start; }
.leave-case-item .case-cta { min-width: 160px; }

.severity-high {
  background: var(--ds-danger-wash);
  color: var(--ds-danger);
}

.severity-medium {
  background: var(--ds-primary-wash);
  color: var(--ds-primary);
}

.severity-low,
.severity-info {
  background: var(--ds-canvas-soft);
  color: var(--ds-ink-mute);
}

.resolved-tag {
  background: var(--ds-success-wash);
  color: var(--ds-success);
}

.unread-dot {
  width: 8px;
  height: 8px;
  background: var(--ds-ink-mute);
  border-radius: 50%;
  display: inline-block;
  flex-shrink: 0;
}

.main-title {
  font-size: 15px;
  font-weight: 700;
}
.grouped-tag {
  margin-top: 4px;
  font-size: 11px;
  color: var(--ds-ink-mute);
}

.main-body {
  margin-top: 4px;
  color: var(--text);
}

.meta-row {
  margin-top: 8px;
  display: flex;
  justify-content: space-between;
  gap: 8px;
  color: var(--text-light);
  font-size: 12px;
  flex-wrap: wrap;
}

.item-actions {
  margin-top: 10px;
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}

.pagination-row {
  border-top: 1px solid var(--border);
  margin-top: 8px;
  padding-top: 10px;
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 10px;
}

@media (max-width: 640px) {
  .type-tabs {
    gap: 4px;
  }

  .type-tab {
    padding: 4px 10px;
    font-size: 12px;
  }

  .actions-row {
    justify-content: stretch;
    flex-wrap: wrap;
  }

  .actions-row button {
    flex: 1;
  }

  .leave-case-list__intro { align-items: flex-start; flex-direction: column; }
  .leave-case-item .item-actions { margin-left: -14px; margin-right: -14px; padding-left: 14px; padding-right: 14px; }
  .leave-case-item .case-cta { width: 100%; }
}
</style>
