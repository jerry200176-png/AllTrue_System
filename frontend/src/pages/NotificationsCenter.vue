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
      <div class="card controls-card" data-guide="notifications-controls">
        <div class="controls-grid">
          <label>
            狀態
            <select v-model="readFilter">
              <option value="unread">未讀</option>
              <option value="all">全部</option>
              <option value="read">已讀</option>
            </select>
          </label>

          <label>
            類型
            <select v-model="typeFilter">
              <option value="">全部類型</option>
              <option value="tuition">繳費通知</option>
              <option value="learning_review">待審評量</option>
              <option value="pending_swipe">未識別刷卡</option>
            </select>
          </label>

          <label class="checkbox-wrap">
            <input v-model="includeResolved" type="checkbox" />
            <span>包含已解除</span>
          </label>

          <label class="checkbox-wrap">
            <input v-model="soundEnabled" type="checkbox" />
            <span>急件聲音提醒</span>
          </label>

          <div class="stats-box">
            未讀：<strong>{{ unreadCount }}</strong>
            <span class="urgent-stat">急件：<strong>{{ urgentUnreadCount }}</strong></span>
          </div>
        </div>

        <div class="actions-row">
          <button class="small ghost" :disabled="syncing" @click="syncNotifications(true)">
            {{ syncing ? '同步中...' : '同步通知' }}
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
              <span class="urgent-title">{{ item.Title }}</span>
              <div class="urgent-actions">
                <button v-if="!item.read_at" class="small" @click="markRead(item.id)">標記已讀</button>
                <button
                  v-if="canMarkTuitionPaid(item)"
                  class="small primary"
                  :disabled="isMarkingTuitionPaid(item.id)"
                  @click="markTuitionPaid(item)"
                >
                  {{ isMarkingTuitionPaid(item.id) ? '處理中...' : '標記已繳費' }}
                </button>
                <button v-if="canCopyTuition(item)" class="small ghost" @click="copyTuitionMessage(item)">複製繳費通知</button>
                <button v-if="targetPage(item.Type)" class="small ghost" @click="goToTarget(item.Type, item)">前往處理</button>
              </div>
            </div>
          </div>

          <div v-for="item in mainNotifications" :key="item.id" class="notification-item" :class="{ unread: !item.read_at }">
            <div class="title-row">
              <span class="type-tag" :class="`type-${item.Type}`">{{ typeLabel(item.Type) }}</span>
              <span class="severity-tag" :class="`severity-${item.Severity}`">{{ severityLabel(item.Severity) }}</span>
              <span v-if="item.ResolvedAt" class="resolved-tag">已解除</span>
              <span v-if="!item.read_at" class="unread-dot">未讀</span>
            </div>

            <div class="main-title">{{ item.Title }}</div>
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
                @click="markTuitionPaid(item)"
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
const severityRank = { high: 3, medium: 2, low: 1, info: 0 };
const hasUrgentWatchInitialized = ref(false);
const lastUrgentDigest = ref('');
const markingTuitionPaidIds = ref(new Set());

const getToken = () => {
  const session = JSON.parse(localStorage.getItem('alltrue_session') || '{}');
  return session?.access_token || '';
};

const getBaseUrl = () => import.meta.env.VITE_API_BASE || '/api';

const typeLabel = (type) => {
  const map = {
    tuition: '繳費',
    learning_review: '評量',
    pending_swipe: '刷卡',
  };
  return map[type] || type || '其他';
};

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
  if (type === 'tuition') return 'course-mgmt';
  return null;
};

const goToTarget = (type, item) => {
  const target = targetPage(type);
  if (!target) return;
  const payload = item ? payloadOf(item) : {};
  const recordId = type === 'learning_review' ? (payload.record_id || null) : null;
  emit('navigate', { target, recordId });
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

const canCopyTuition = (item) => {
  if (!item || item.Type !== 'tuition') return false;
  const payload = payloadOf(item);
  return Number(payload?.student_id || 0) > 0;
};

const canMarkTuitionPaid = (item) => {
  if (!item || item.ResolvedAt) return false;
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

const markTuitionPaid = async (item) => {
  if (!item?.id || !props.branchId || isMarkingTuitionPaid(item.id)) return;
  const ok = confirm(`確定將「${item.Title || '此通知'}」標記為已繳費嗎？`);
  if (!ok) return;

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
      body: JSON.stringify({ branch_id: Number(props.branchId) }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json?.message || '更新繳費狀態失敗');

    await syncNotifications(false);
  } catch (err) {
    errorMessage.value = err.message || '更新繳費狀態失敗';
  } finally {
    setMarkingTuitionPaid(item.id, false);
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

.controls-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.controls-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(120px, 1fr));
  gap: 10px;
  align-items: end;
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
  border-radius: 10px;
  padding: 12px;
  margin-bottom: 10px;
  background: #fff;
}

.notification-item.unread {
  border-color: #ffcc80;
  background: #fffaf2;
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
  background: #263238;
  color: #fff;
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

@media (max-width: 900px) {
  .controls-grid {
    grid-template-columns: repeat(2, minmax(120px, 1fr));
  }
}

@media (max-width: 640px) {
  .controls-grid {
    grid-template-columns: 1fr;
  }

  .actions-row {
    justify-content: stretch;
  }

  .actions-row button {
    flex: 1;
  }
}
</style>
