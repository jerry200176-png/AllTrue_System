<template>
  <div class="bugs-page">
    <div class="page-header" data-guide="bugs-header">
      <h2><span class="material-symbols-outlined header-icon">bug_report</span> {{ pageTitle }}</h2>
      <div class="page-desc">{{ pageDesc }}</div>
    </div>

    <div v-if="branchId == null" class="card empty-card">
      請先選擇分校後再查看 Bug 回報。
    </div>

    <template v-else>
      <!-- Filters -->
      <div class="card controls-card" data-guide="bugs-filters">
        <div class="controls-grid">
          <label>
            狀態
            <select v-model="filterStatus" @change="loadBugs">
              <option value="">全部</option>
              <option value="new">新提交</option>
              <option value="triaged">已分類</option>
              <option value="in_progress">處理中</option>
              <option value="resolved">已解決</option>
              <option value="closed">已關閉</option>
            </select>
          </label>
          <label>
            嚴重度
            <select v-model="filterSeverity" @change="loadBugs">
              <option value="">全部</option>
              <option value="critical">嚴重</option>
              <option value="high">高</option>
              <option value="medium">中</option>
              <option value="low">低</option>
            </select>
          </label>
        </div>
      </div>

      <!-- Bug list -->
      <div class="card" data-guide="bugs-list">
        <div v-if="loading" class="loading-box">載入中...</div>
        <div v-else-if="bugs.length === 0" class="empty-box">
          <span class="material-symbols-outlined empty-icon">check_circle</span>
          <p>目前沒有 Bug 回報</p>
        </div>
        <div v-else class="bug-list">
          <div
            v-for="bug in bugs"
            :key="bug.id"
            class="bug-item"
            :class="{ active: activeBug?.id === bug.id }"
            @click="selectBug(bug)"
          >
            <span class="severity-dot" :class="bug.severity"></span>
            <div class="bug-item-info">
              <div class="bug-title">{{ bug.title }}</div>
              <div class="bug-meta">
                <span class="status-tag" :class="bug.status">{{ statusLabel(bug.status) }}</span>
                <span v-if="bug.attachments_count > 0" class="bug-attach-hint" title="含截圖">
                  <span class="material-symbols-outlined">attach_file</span>
                  {{ bug.attachments_count }}
                </span>
                <span class="bug-date">{{ formatDate(bug.created_at) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Bug detail -->
      <div v-if="activeBug" class="card detail-card">
        <div class="detail-header">
          <h3>{{ detail?.title || activeBug.title }}</h3>
          <button class="btn-close-detail" @click="activeBug = null">
            <span class="material-symbols-outlined">close</span>
          </button>
        </div>

        <div v-if="loadingDetail" class="loading-box">載入詳情...</div>
        <template v-else-if="detail">
          <div class="detail-grid">
            <div><strong>狀態：</strong><span class="status-tag" :class="detail.status">{{ statusLabel(detail.status) }}</span></div>
            <div><strong>嚴重度：</strong><span class="severity-tag" :class="detail.severity">{{ severityLabel(detail.severity) }}</span></div>
            <div><strong>回報者：</strong>{{ detail.reporter_name }}</div>
            <div v-if="detail.page_key"><strong>頁面：</strong>{{ detail.page_key }}</div>
            <div><strong>時間：</strong>{{ formatDate(detail.created_at) }}</div>
          </div>

          <div class="detail-description">
            <strong>問題描述</strong>
            <p>{{ detail.description }}</p>
          </div>

          <div v-if="detail.attachments?.length" class="detail-attachments">
            <strong>截圖／附件（{{ detail.attachments.length }}）</strong>
            <div class="attachment-grid">
              <a
                v-for="a in detail.attachments"
                :key="a.id"
                :href="a.url"
                target="_blank"
                rel="noopener noreferrer"
                class="attachment-thumb-link"
              >
                <img :src="a.url" :alt="a.original_name || 'attachment'" class="attachment-thumb" loading="lazy" />
              </a>
            </div>
          </div>

          <!-- Super Admin actions -->
          <div v-if="isSuperAdmin" class="admin-actions">
            <div class="action-row">
              <label>更新狀態</label>
              <select v-model="newStatus">
                <option value="">-- 選擇 --</option>
                <option v-for="s in allowedTransitions" :key="s" :value="s">{{ statusLabel(s) }}</option>
              </select>
              <input v-model="statusNote" placeholder="備註（選填）" class="action-input" />
              <button class="btn-sm btn-primary" :disabled="!newStatus" @click="doUpdateStatus">更新</button>
            </div>
          </div>

          <!-- Status logs -->
          <div v-if="detail.status_logs?.length" class="status-log-section">
            <strong>狀態歷程</strong>
            <div v-for="(log, i) in detail.status_logs" :key="i" class="status-log-item">
              <span class="status-tag sm" :class="log.to_status">{{ statusLabel(log.to_status) }}</span>
              <span class="log-meta">{{ log.changed_by_name }} · {{ formatDate(log.created_at) }}</span>
              <span v-if="log.note" class="log-note">{{ log.note }}</span>
            </div>
          </div>

          <!-- Comments -->
          <div class="comments-section">
            <strong>留言（{{ detail.comments?.length || 0 }}）</strong>
            <div v-for="c in detail.comments" :key="c.id" class="comment-item" :class="{ internal: c.is_internal_note }">
              <div class="comment-header">
                <span class="comment-author">{{ c.author_name }}</span>
                <span class="comment-time">{{ formatDate(c.created_at) }}</span>
                <span v-if="c.is_internal_note" class="internal-tag">內部</span>
              </div>
              <div class="comment-body">{{ c.body }}</div>
            </div>

            <div class="comment-form">
              <textarea v-model="newComment" placeholder="輸入留言..." rows="2" class="form-textarea"></textarea>
              <div class="comment-form-actions">
                <label v-if="isSuperAdmin" class="checkbox-label">
                  <input type="checkbox" v-model="commentIsInternal" /> 內部備註
                </label>
                <button class="btn-sm btn-primary" :disabled="!newComment.trim()" @click="doAddComment">送出</button>
              </div>
            </div>
          </div>
        </template>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import {
  fetchBugReports, fetchBugDetail, addBugComment,
  updateBugStatus,
} from '../lib/bugReportsApi';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  userRole: { type: String, default: '' },
});

const bugs = ref([]);
const activeBug = ref(null);
const detail = ref(null);
const loading = ref(false);
const loadingDetail = ref(false);
const filterStatus = ref('');
const filterSeverity = ref('');

const newStatus = ref('');
const statusNote = ref('');
const newComment = ref('');
const commentIsInternal = ref(false);

const isSuperAdmin = computed(() => props.userRole === 'super_admin');
const pageTitle = computed(() => (isSuperAdmin.value ? 'Bug 回報（處理中心）' : '我的 Bug 回報'));
const pageDesc = computed(() => (
  isSuperAdmin.value
    ? '檢視各校區回報並更新狀態（僅超級管理員可處理）'
    : '僅顯示你本人提交的問題與處理進度'
));

const TRANSITIONS = {
  new: ['triaged', 'in_progress', 'closed'],
  triaged: ['in_progress', 'closed'],
  in_progress: ['resolved', 'closed'],
  resolved: ['in_progress', 'closed'],
  closed: ['in_progress'],
};

const allowedTransitions = computed(() => {
  if (!detail.value) return [];
  return TRANSITIONS[detail.value.status] || [];
});

onMounted(() => {
  loadBugs();
});

watch(() => props.branchId, () => {
  activeBug.value = null;
  detail.value = null;
  loadBugs();
});

async function loadBugs() {
  if (!props.branchId) return;
  loading.value = true;
  try {
    const filters = {};
    if (filterStatus.value) filters.status = filterStatus.value;
    if (filterSeverity.value) filters.severity = filterSeverity.value;
    const data = await fetchBugReports(props.branchId, filters);
    bugs.value = data.data || [];
  } catch (e) {
    console.error('[Bugs] loadBugs:', e);
  } finally {
    loading.value = false;
  }
}

async function selectBug(bug) {
  activeBug.value = bug;
  loadingDetail.value = true;
  newStatus.value = '';
  statusNote.value = '';
  newComment.value = '';
  try {
    detail.value = await fetchBugDetail(bug.id);
  } catch (e) {
    console.error('[Bugs] fetchDetail:', e);
  } finally {
    loadingDetail.value = false;
    window.dispatchEvent(new CustomEvent('alltrue-refresh-badges'));
  }
}

async function doUpdateStatus() {
  if (!newStatus.value || !activeBug.value) return;
  try {
    await updateBugStatus(activeBug.value.id, newStatus.value, statusNote.value || null);
    await selectBug(activeBug.value);
    loadBugs();
  } catch (e) {
    alert('更新失敗：' + e.message);
  }
}

async function doAddComment() {
  if (!newComment.value.trim() || !activeBug.value) return;
  try {
    await addBugComment(activeBug.value.id, newComment.value.trim(), commentIsInternal.value);
    newComment.value = '';
    commentIsInternal.value = false;
    await selectBug(activeBug.value);
  } catch (e) {
    alert('留言失敗：' + e.message);
  }
}

function statusLabel(s) {
  return { new: '新提交', triaged: '已分類', in_progress: '處理中', resolved: '已解決', closed: '已關閉' }[s] || s;
}

function severityLabel(s) {
  return { low: '低', medium: '中', high: '高', critical: '嚴重' }[s] || s;
}

function formatDate(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return d.toLocaleDateString('zh-TW', { month: 'numeric', day: 'numeric' }) +
    ' ' + d.toLocaleTimeString('zh-TW', { hour: '2-digit', minute: '2-digit' });
}
</script>

<style scoped>
.bugs-page { padding-bottom: 24px; }
.page-header { padding: 16px 24px 8px; }
.page-header h2 { display: flex; align-items: center; gap: 8px; margin: 0; }
.header-icon { font-size: 28px; color: var(--primary); }
.page-desc { color: var(--text-light); font-size: 14px; margin-top: 2px; }

.card { background: var(--card-bg); border-radius: var(--radius); box-shadow: var(--shadow); margin: 0 16px 16px; padding: 16px; }
.empty-card { text-align: center; padding: 32px; }
.controls-card .controls-grid { display: flex; gap: 16px; flex-wrap: wrap; }
.controls-card label { font-size: 13px; font-weight: 500; display: flex; flex-direction: column; gap: 4px; }
.controls-card select { padding: 6px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; }

.bug-list { display: flex; flex-direction: column; }
.bug-item {
  display: flex; align-items: flex-start; gap: 10px; padding: 12px;
  border-bottom: 1px solid var(--border); cursor: pointer; transition: background 0.15s;
}
.bug-item:last-child { border-bottom: none; }
.bug-item:hover { background: var(--primary-bg); }
.bug-item.active { background: var(--primary-bg); }

.severity-dot { width: 10px; height: 10px; border-radius: 50%; margin-top: 5px; flex-shrink: 0; }
.severity-dot.critical { background: var(--danger); }
.severity-dot.high { background: var(--warning); }
.severity-dot.medium { background: #2196F3; }
.severity-dot.low { background: #9E9E9E; }

.bug-title { font-weight: 600; font-size: 14px; }
.bug-meta { display: flex; align-items: center; gap: 8px; margin-top: 4px; }
.bug-date { font-size: 12px; color: var(--text-light); }
.bug-attach-hint {
  display: inline-flex; align-items: center; gap: 2px;
  font-size: 12px; color: var(--text-light);
}
.bug-attach-hint .material-symbols-outlined { font-size: 16px; }

.status-tag {
  display: inline-block; padding: 2px 8px; border-radius: 12px;
  font-size: 11px; font-weight: 600;
}
.status-tag.new { background: #E3F2FD; color: #1565C0; }
.status-tag.triaged { background: #FFF3E0; color: #E65100; }
.status-tag.in_progress { background: #F3E5F5; color: #7B1FA2; }
.status-tag.resolved { background: var(--success-bg); color: var(--success); }
.status-tag.closed { background: #ECEFF1; color: #546E7A; }
.status-tag.sm { font-size: 10px; padding: 1px 6px; }

.severity-tag { font-size: 13px; font-weight: 600; }
.severity-tag.critical { color: var(--danger); }
.severity-tag.high { color: var(--warning); }
.severity-tag.medium { color: #2196F3; }
.severity-tag.low { color: #9E9E9E; }

.loading-box { text-align: center; padding: 24px; color: var(--text-light); }
.empty-box { text-align: center; padding: 32px; color: var(--text-light); }
.empty-icon { font-size: 48px; opacity: 0.3; }

.detail-card { position: relative; }
.detail-header { display: flex; justify-content: space-between; align-items: flex-start; }
.detail-header h3 { margin: 0; font-size: 18px; }
.btn-close-detail { background: none; border: none; cursor: pointer; color: var(--text-light); }

.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin: 16px 0; font-size: 14px; }
.detail-description { margin: 12px 0; }
.detail-description strong { display: block; margin-bottom: 4px; }
.detail-description p { background: #f8f8f8; padding: 12px; border-radius: 8px; font-size: 14px; line-height: 1.6; white-space: pre-wrap; }

.detail-attachments { margin: 16px 0; }
.detail-attachments strong { display: block; margin-bottom: 8px; }
.attachment-grid {
  display: flex; flex-wrap: wrap; gap: 10px;
}
.attachment-thumb-link {
  display: block; width: 120px; height: 120px; border-radius: 8px; overflow: hidden;
  border: 1px solid var(--border); background: #f0f0f0;
}
.attachment-thumb {
  width: 100%; height: 100%; object-fit: cover;
}

.admin-actions { margin: 16px 0; padding: 12px; background: #FFFDE7; border-radius: 8px; }
.action-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
.action-row:last-child { margin-bottom: 0; }
.action-row label { font-size: 13px; font-weight: 600; min-width: 60px; }
.action-row select, .action-input { padding: 6px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; }
.action-input { flex: 1; min-width: 120px; }

.btn-sm { padding: 6px 14px; border: none; border-radius: 6px; font-size: 13px; cursor: pointer; }
.btn-sm.btn-primary { background: var(--primary); color: #fff; }
.btn-sm.btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }

.status-log-section { margin: 16px 0; }
.status-log-section strong { display: block; margin-bottom: 8px; }
.status-log-item { display: flex; align-items: center; gap: 8px; padding: 4px 0; font-size: 13px; }
.log-meta { color: var(--text-light); font-size: 12px; }
.log-note { color: var(--text); font-style: italic; }

.comments-section { margin-top: 16px; }
.comments-section strong { display: block; margin-bottom: 8px; }
.comment-item { padding: 10px 12px; background: #f8f8f8; border-radius: 8px; margin-bottom: 8px; }
.comment-item.internal { background: #FFFDE7; border-left: 3px solid var(--warning); }
.comment-header { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
.comment-author { font-weight: 600; font-size: 13px; }
.comment-time { font-size: 12px; color: var(--text-light); }
.internal-tag { font-size: 10px; background: var(--warning); color: #fff; padding: 1px 6px; border-radius: 8px; }
.comment-body { font-size: 14px; line-height: 1.5; white-space: pre-wrap; }

.comment-form { margin-top: 12px; }
.form-textarea {
  width: 100%; padding: 10px; border: 1px solid var(--border);
  border-radius: 8px; font-size: 14px; font-family: inherit; resize: vertical;
}
.comment-form-actions { display: flex; justify-content: space-between; align-items: center; margin-top: 8px; }
.checkbox-label { font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 4px; }
</style>
