<template>
  <div class="notifications-page">
    <div class="page-header" data-guide="notifications-header">
      <h2>🔔 通知中心</h2>
      <div class="page-desc">集中處理繳費、待審評量與未識別刷卡通知</div>
    </div>

    <div v-if="branchId == null" class="card empty-card">
      請先選擇分校後再查看通知。
    </div>

    <template v-else>
      <!-- 核帳確認 Modal -->
      <div v-if="tuitionModal.visible" class="modal-overlay" @click.self="tuitionModal.visible = false">
        <div class="modal-box payment-modal">
          <h3>核帳登記</h3>
          <p class="modal-desc">請依實際入帳資訊填寫，系統會同步更新催繳與通知狀態。</p>
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
              <input v-model.number="tuitionModal.form.amount" type="number" required min="1" max="999999" step="1" />
            </label>

            <label class="modal-field">
              備註（選填）
              <textarea v-model="tuitionModal.form.note" rows="2" placeholder="例如：通知中心核帳"></textarea>
            </label>

            <div v-if="tuitionModal.error" class="modal-error">{{ tuitionModal.error }}</div>

            <div class="modal-actions">
              <button type="button" class="small ghost" @click="tuitionModal.visible = false">取消</button>
              <button type="submit" class="small primary" :disabled="tuitionModal.processing">
                {{ tuitionModal.processing ? '處理中...' : '確認已繳費' }}
              </button>
            </div>
          </form>
        </div>
      </div>

      <div class="card controls-card" data-guide="notifications-controls">
        <!-- 分類 Tab -->
        <div class="type-tabs">
          <button
            v-for="tab in typeTabs"
            :key="tab.value"
            class="type-tab"
            :class="{ active: typeFilter === tab.value }"
            @click="typeFilter = tab.value"
          >
            {{ tab.label }}
            <span v-if="tab.count > 0" class="tab-badge">{{ tab.count }}</span>
          </button>
        </div>

        <div class="controls-row">
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

          <div class="stats-box">
            未讀 <strong>{{ unreadCount }}</strong>
            <span class="urgent-stat">急件 <strong>{{ urgentUnreadCount }}</strong></span>
          </div>
        </div>

        <div class="actions-row">
          <button class="small ghost" :disabled="syncing" @click="syncNotifications(true)">
            {{ syncing ? '同步中...' : '同步通知' }}
          </button>
          <button class="small ghost" :disabled="clearingResolved" @click="clearResolved">
            {{ clearingResolved ? '清除中...' : '清除已解除' }}
          </button>
          <button class="small primary" :disabled="markingAllRead || notifications.length === 0" @click="markAllRead">
            {{ markingAllRead ? '處理中...' : '全部標記已讀' }}
          </button>
        </div>
      </div>

      <div v-if="errorMessage" class="card error-card">{{ errorMessage }}</div>

      <div class="card list-card" data-guide="notifications-list">
        <div v-if="loading" class="empty">載入通知中...</div>
        <div v-else-if="displayNotifications.length === 0" class="empty">目前沒有符合條件的通知</div>

        <div v-else>
          <div v-if="urgentNotifications.length > 0" class="urgent-panel">
            <h4>🚨 急件置頂</h4>
            <div v-for="item in urgentNotifications" :key="`urgent-${item.id}`" class="urgent-row">
              <div class="urgent-copy">
                <span class="urgent-title">{{ item.Title }}</span>
                <span v-if="notificationSummary(item)" class="notification-context">{{ notificationSummary(item) }}</span>
              </div>
              <div class="urgent-actions">
                <button v-if="!item.read_at" class="small" @click="markRead(item.id)">標記已讀</button>
                <button
                  v-if="canMarkTuitionPaid(item)"
                  class="small primary"
                  :disabled="isMarkingTuitionPaid(item.id)"
                  @click="openTuitionModal(item)"
                >
                  {{ isMarkingTuitionPaid(item.id) ? '處理中...' : '標記已繳費' }}
                </button>
                <button v-if="canCopyTuition(item)" class="small ghost" @click="copyTuitionMessage(item)">複製繳費通知</button>
                <button v-if="targetPage(item.Type)" class="small ghost" @click="goToTarget(item.Type, item)">前往處理</button>
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
            <div v-if="notificationSummary(item)" class="notification-context">{{ notificationSummary(item) }}</div>
            <div v-if="item.Body" class="main-body">{{ item.Body }}</div>

            <div class="meta-row">
              <span>{{ formatDateTime(item.OccurredAt || item.created_at) }}</span>
              <span>來源：{{ item.SourceType || '-' }}</span>
            </div>

            <div class="item-actions">
              <button v-if="!item.read_at" class="small" @click="markRead(item.id)">標記已讀</button>
              <button
                v-if="canMarkTuitionPaid(item)"
                class="small primary"
                :disabled="isMarkingTuitionPaid(item.id)"
                @click="openTuitionModal(item)"
              >
                {{ isMarkingTuitionPaid(item.id) ? '處理中...' : '標記已繳費' }}
              </button>
              <button v-if="canCopyTuition(item)" class="small ghost" @click="copyTuitionMessage(item)">複製繳費通知</button>
              <button v-if="targetPage(item.Type)" class="small ghost" @click="goToTarget(item.Type, item)">前往處理</button>
            </div>
          </div>
        </div>

        <div v-if="lastPage > 1" class="pagination-row">
          <button class="small ghost" :disabled="currentPage <= 1 || loading" @click="loadNotifications(currentPage - 1)">上一頁</button>
          <span>第 {{ currentPage }} / {{ lastPage }} 頁</span>
          <button class="small ghost" :disabled="currentPage >= lastPage || loading" @click="loadNotifications(currentPage + 1)">下一頁</button>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
  branchId: [String, Number],
});

const emit = defineEmits(['navigate', 'unread-change']);

const loading = ref(false);
const syncing = ref(false);
const markingAllRead = ref(false);
const errorMessage = ref('');
const notifications = ref([]);
const unreadCount = ref(0);
const urgentUnreadCount = ref(0);
const currentPage = ref(1);
const lastPage = ref(1);

const readFilter = ref('unread');
const typeFilter = ref('');
const includeResolved = ref(false);
const soundEnabled = ref(localStorage.getItem('notifications_sound_enabled') !== '0');
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
  substitute_confirm: { label: '代課確認', tab: '系統' },
};

const typeLabel = (type) => TYPE_META[type]?.label || type || '其他';

const typeTabs = computed(() => {
  const counts = {};
  notifications.value.forEach((n) => {
    const tab = TYPE_META[n.Type]?.tab || '系統';
    if (!n.read_at) counts[tab] = (counts[tab] || 0) + 1;
  });
  return [
    { value: '',               label: '全部',    count: unreadCount.value },
    { value: 'tuition',        label: '繳費',    count: counts['繳費'] || 0 },
    { value: 'learning_review',label: '評量',    count: counts['評量'] || 0 },
    { value: 'pending_swipe',  label: '刷卡',    count: counts['刷卡'] || 0 },
    { value: 'low_sessions',   label: '堂數',    count: counts['堂數'] || 0 },
    { value: 'schedule_change,substitute_confirm', label: '系統', count: counts['系統'] || 0 },
  ];
});

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
  if (type === 'schedule_change' || type === 'substitute_confirm') return 'calendar';
  return null;
};

const goToTarget = (type, item) => {
  const target = targetPage(type);
  if (!target) return;
  const payload = item ? payloadOf(item) : {};
  const recordId = type === 'learning_review' ? (payload.record_id || null) : null;
  const studentId = payload.student_id || null;
  emit('navigate', { target, recordId, studentId });
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

  const aSeverity = severityRank[a.Severity] ?? 0;
  const bSeverity = severityRank[b.Severity] ?? 0;
  if (aSeverity !== bSeverity) return bSeverity - aSeverity;

  const aOccurred = new Date(a.OccurredAt || a.created_at || 0).getTime();
  const bOccurred = new Date(b.OccurredAt || b.created_at || 0).getTime();
  return bOccurred - aOccurred;
};

const displayNotifications = computed(() => {
  return [...notifications.value].sort(byPriority);
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
  if (typeFilter.value) params.set('type', typeFilter.value);
  if (includeResolved.value) params.set('include_resolved', '1');
  return params.toString();
};

const loadNotifications = async (page = 1) => {
  if (!props.branchId) {
    notifications.value = [];
    unreadCount.value = 0;
    urgentUnreadCount.value = 0;
    emit('unread-change', { unread: 0, urgent: 0 });
    return;
  }

  loading.value = true;
  errorMessage.value = '';
  try {
    const token = getToken();
    const baseUrl = getBaseUrl();
    const res = await fetch(`${baseUrl}/v1/notifications?${buildQuery(page)}`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json?.message || '通知載入失敗');

    notifications.value = json.data || [];
    unreadCount.value = Number(json.unread_count || 0);
    urgentUnreadCount.value = Number(json.urgent_unread_count || 0);
    currentPage.value = Number(json.current_page || 1);
    lastPage.value = Number(json.last_page || 1);
    emit('unread-change', { unread: unreadCount.value, urgent: urgentUnreadCount.value });
  } catch (err) {
    errorMessage.value = err.message || '通知載入失敗';
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
  if (!form.payment_date || !form.amount || form.amount <= 0) {
    tuitionModal.value.error = '請填寫繳費日期與金額';
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
        amount: form.amount,
        note: form.note?.trim() || '',
      }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json?.message || '更新繳費狀態失敗');

    tuitionModal.value.visible = false;
    await syncNotifications(false);
  } catch (err) {
    tuitionModal.value.error = err.message || '更新繳費狀態失敗';
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

watch([() => props.branchId, readFilter, typeFilter, includeResolved], () => {
  loadNotifications(1);
});

watch(soundEnabled, (value) => {
  localStorage.setItem('notifications_sound_enabled', value ? '1' : '0');
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
  await syncNotifications(false);
  refreshTimer = window.setInterval(() => {
    loadNotifications(currentPage.value);
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

/* ── Modal ── */
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
}

.modal-box {
  background: #fff;
  border-radius: 14px;
  padding: 24px 28px;
  max-width: 380px;
  width: 90%;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.18);
}

.payment-modal {
  max-width: 460px;
}

.modal-box h3 {
  margin: 0 0 8px;
  font-size: 17px;
}

.modal-desc {
  color: var(--text-light);
  font-size: 13px;
  margin: 0 0 12px;
}

.modal-item-name {
  background: #f5f5f5;
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
  background: #fff;
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
  background: #fff;
}

.payment-method-option input {
  display: none;
}

.payment-method-option.active {
  border-color: var(--primary);
  background: var(--primary-bg);
  color: var(--primary);
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

/* ── 分類 Tab ── */
.type-tabs {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  border-bottom: 1px solid var(--border);
  padding-bottom: 10px;
}

.type-tab {
  padding: 5px 14px;
  border: 1px solid var(--border);
  border-radius: 999px;
  background: #f5f7fa;
  color: var(--text-light);
  cursor: pointer;
  font-size: 13px;
  display: flex;
  align-items: center;
  gap: 5px;
  transition: all 0.15s;
}

.type-tab:hover {
  background: #e8edf5;
}

.type-tab.active {
  background: var(--primary);
  color: #fff;
  border-color: var(--primary);
}

.tab-badge {
  background: #ff5252;
  color: #fff;
  border-radius: 999px;
  font-size: 10px;
  padding: 1px 6px;
  font-weight: 700;
  min-width: 18px;
  text-align: center;
}

.type-tab.active .tab-badge {
  background: rgba(255, 255, 255, 0.35);
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
  color: #c62828;
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
  border: 1px solid #ef9a9a;
  border-radius: 10px;
  background: #fff3f3;
  padding: 10px;
  margin-bottom: 10px;
  animation: urgentPulse 1.8s ease-in-out infinite;
}

.urgent-panel h4 {
  color: #b71c1c;
  margin: 0 0 8px;
  font-size: 13px;
}

.urgent-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  padding: 8px 0;
  border-top: 1px dashed #ffcdd2;
}

.urgent-row:first-of-type {
  border-top: 0;
}

.urgent-title {
  font-size: 13px;
  color: #c62828;
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

@keyframes urgentPulse {
  0% {
    box-shadow: 0 0 0 0 rgba(244, 67, 54, 0.25);
  }
  70% {
    box-shadow: 0 0 0 10px rgba(244, 67, 54, 0);
  }
  100% {
    box-shadow: 0 0 0 0 rgba(244, 67, 54, 0);
  }
}

.empty {
  text-align: center;
  color: var(--text-light);
  padding: 18px 0;
}

.notification-item {
  border: 1px solid var(--border);
  border-left: 4px solid transparent;
  border-radius: 10px;
  padding: 12px;
  margin-bottom: 10px;
  background: #fff;
  transition: background 0.15s;
}

.notification-item.unread {
  border-color: #90caf9;
  border-left-color: #1976d2;
  background: #f3f8ff;
}

.notification-item.severity-high-item {
  border-left-color: #d32f2f;
  background: #fff8f8;
}

.notification-item.resolved {
  opacity: 0.65;
  background: #fafafa;
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
  background: #eceff1;
  color: #455a64;
}

.type-tuition {
  background: #fff3e0;
  color: #ef6c00;
}

.type-learning_review {
  background: #e8eaf6;
  color: #3f51b5;
}

.type-pending_swipe {
  background: #ffebee;
  color: #d32f2f;
}

.type-low_sessions {
  background: #f3e5f5;
  color: #6a1b9a;
}

.type-schedule_change,
.type-substitute_confirm {
  background: #e0f2f1;
  color: #00695c;
}

.severity-high {
  background: #ffebee;
  color: #c62828;
}

.severity-medium {
  background: #fff8e1;
  color: #ef6c00;
}

.severity-low,
.severity-info {
  background: #e3f2fd;
  color: #1565c0;
}

.resolved-tag {
  background: #e8f5e9;
  color: #2e7d32;
}

.unread-dot {
  width: 8px;
  height: 8px;
  background: #1976d2;
  border-radius: 50%;
  display: inline-block;
  flex-shrink: 0;
}

.main-title {
  font-size: 15px;
  font-weight: 700;
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
}
</style>
