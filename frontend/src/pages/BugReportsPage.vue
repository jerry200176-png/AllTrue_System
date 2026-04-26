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

      <!-- Super Admin: 頁面層級 Tab（Bug 回報 / 家長回饋）-->
      <div v-if="isSuperAdmin" class="bugs-page-tabs">
        <button :class="['bugs-page-tab', { active: pageTab === 'bugs' }]" @click="pageTab = 'bugs'">
          <span class="material-symbols-outlined">bug_report</span>
          Bug 回報
        </button>
        <button :class="['bugs-page-tab', { active: pageTab === 'feedback' }]" @click="switchToFeedbackTab">
          <span class="material-symbols-outlined">chat</span>
          家長回饋
          <span v-if="pfUnreadCount > 0" class="bugs-page-tab-badge">{{ pfUnreadCount }}</span>
        </button>
      </div>

      <!-- ═══ 家長回饋 Tab（super_admin only）═══ -->
      <div v-if="isSuperAdmin && pageTab === 'feedback'" class="card pf-admin-card">
        <!-- 篩選 -->
        <div class="pf-filter-row">
          <select v-model="pfCategoryFilter" class="pf-select">
            <option value="">全部類型</option>
            <option value="teaching">老師教學</option>
            <option value="schedule">課程安排</option>
            <option value="system">系統功能</option>
            <option value="other">其他建議</option>
          </select>
          <span class="pf-total">共 {{ pfTotal }} 筆</span>
        </div>

        <!-- Loading -->
        <div v-if="pfLoading" class="pf-loading">
          <div class="pp-spinner-inline"></div> 載入中…
        </div>

        <!-- Empty -->
        <div v-else-if="!pfList.length" class="pf-empty">
          <span class="material-symbols-outlined">mark_chat_read</span>
          <p>目前無家長回饋</p>
        </div>

        <!-- 列表 -->
        <div v-else class="pf-list">
          <div v-for="fb in pfList" :key="fb.id"
            class="pf-item" :class="{ unread: !fb.is_read }">
            <div class="pf-item-head">
              <div class="pf-item-meta">
                <span class="pf-student">{{ fb.student_name }}</span>
                <span class="pf-category pf-cat-{{ fb.category }}">{{ pfCategoryLabel(fb.category) }}</span>
                <span v-if="!fb.is_read" class="pf-new-dot">NEW</span>
              </div>
              <div class="pf-item-time">{{ fb.created_at?.substring(0, 16).replace('T', ' ') }}</div>
            </div>
            <!-- 星評 -->
            <div v-if="fb.rating" class="pf-stars">
              <span v-for="n in 5" :key="n" class="material-symbols-outlined pf-star"
                :style="{ color: n <= fb.rating ? '#f59e0b' : '#e5e7eb' }">star</span>
            </div>
            <!-- 內容 -->
            <div class="pf-content">{{ fb.content }}</div>
            <!-- 動作 -->
            <div class="pf-actions">
              <button v-if="!fb.is_read" class="pf-btn-read" @click="markRead(fb)">
                <span class="material-symbols-outlined">done_all</span>
                標記已讀
              </button>
              <span v-else class="pf-read-label">
                <span class="material-symbols-outlined">check_circle</span>
                已處理
              </span>
            </div>
          </div>
        </div>

        <!-- 分頁 -->
        <div v-if="pfLastPage > 1" class="pf-pagination">
          <button :disabled="pfPage <= 1" @click="loadFeedback(pfPage - 1)" class="pf-page-btn">‹</button>
          <span>{{ pfPage }} / {{ pfLastPage }}</span>
          <button :disabled="pfPage >= pfLastPage" @click="loadFeedback(pfPage + 1)" class="pf-page-btn">›</button>
        </div>
      </div>

      <!-- Quick filter tabs（Bug 回報，pageTab === 'bugs' 或非 super_admin） -->
      <div v-if="!isSuperAdmin || pageTab === 'bugs'" class="card quick-filter-card" data-guide="bugs-quick-filter">
        <div class="quick-tabs">
          <!-- Super admin tabs: 待處理 first (their default action queue) -->
          <template v-if="isSuperAdmin">
            <button class="quick-tab" :class="{ active: quickFilter === 'pending' }" @click="setQuickFilter('pending')">
              <span class="material-symbols-outlined tab-icon">pending_actions</span>
              待處理
            </button>
            <button class="quick-tab" :class="{ active: quickFilter === 'all' }" @click="setQuickFilter('all')">
              <span class="material-symbols-outlined tab-icon">list</span>
              全部
            </button>
            <button class="quick-tab" :class="{ active: quickFilter === 'closed' }" @click="setQuickFilter('closed')">
              <span class="material-symbols-outlined tab-icon">check_circle</span>
              已關閉
            </button>
          </template>
          <!-- Reporter tabs: 全部 first (see all progress), then filtering by status -->
          <template v-else>
            <button class="quick-tab" :class="{ active: quickFilter === 'all' }" @click="setQuickFilter('all')">
              <span class="material-symbols-outlined tab-icon">list</span>
              全部
              <span v-if="unreadCount > 0" class="unread-badge">{{ unreadCount }}</span>
            </button>
            <button class="quick-tab" :class="{ active: quickFilter === 'pending' }" @click="setQuickFilter('pending')">
              <span class="material-symbols-outlined tab-icon">pending_actions</span>
              處理中
            </button>
            <button class="quick-tab" :class="{ active: quickFilter === 'resolved' }" @click="setQuickFilter('resolved')">
              <span class="material-symbols-outlined tab-icon">task_alt</span>
              已解決
            </button>
            <button class="quick-tab" :class="{ active: quickFilter === 'closed' }" @click="setQuickFilter('closed')">
              <span class="material-symbols-outlined tab-icon">check_circle</span>
              已關閉
            </button>
          </template>
        </div>
      </div>

      <!-- Filters -->
      <div class="card controls-card" data-guide="bugs-filters">
        <!-- Row 1: dropdowns + keyword search -->
        <div class="filter-row filter-row-main">
          <div class="filter-field" :class="{ 'is-active': filterStatus }">
            <span class="filter-icon material-symbols-outlined">flag</span>
            <select v-model="filterStatus" @change="onFilterChange" class="filter-select">
              <option value="">狀態</option>
              <option value="new">新提交</option>
              <option value="triaged">已分類</option>
              <option value="in_progress">處理中</option>
              <option value="resolved">已解決</option>
              <option value="closed">已關閉</option>
            </select>
          </div>

          <div class="filter-field" :class="{ 'is-active': filterSeverity }">
            <span class="filter-icon material-symbols-outlined">warning</span>
            <select v-model="filterSeverity" @change="onFilterChange" class="filter-select">
              <option value="">嚴重度</option>
              <option value="critical">嚴重</option>
              <option value="high">高</option>
              <option value="medium">中</option>
              <option value="low">低</option>
            </select>
          </div>

          <div class="filter-field" :class="{ 'is-active': filterSort }">
            <span class="filter-icon material-symbols-outlined">sort</span>
            <select v-model="filterSort" @change="onFilterChange" class="filter-select">
              <option value="">排序</option>
              <option value="created_at_desc">最新建立</option>
              <option value="created_at_asc">最舊建立</option>
              <option value="updated_at_desc">最近更新</option>
              <option value="severity_desc">嚴重度優先</option>
            </select>
          </div>

          <div class="filter-divider"></div>

          <div class="filter-field filter-field-grow" :class="{ 'is-active': filterKeyword }">
            <span class="filter-icon material-symbols-outlined">search</span>
            <input
              v-model="filterKeyword"
              @input="onKeywordInput"
              placeholder="搜尋標題、描述、頁面…"
              class="filter-input"
            />
            <button v-if="filterKeyword" class="filter-clear-x" @click="filterKeyword = ''; onFilterChange()">
              <span class="material-symbols-outlined">close</span>
            </button>
          </div>

          <div v-if="isSuperAdmin" class="filter-field" :class="{ 'is-active': filterReporter }">
            <span class="filter-icon material-symbols-outlined">person_search</span>
            <input
              v-model="filterReporter"
              @input="onKeywordInput"
              placeholder="回報者姓名"
              class="filter-input filter-input-narrow"
            />
            <button v-if="filterReporter" class="filter-clear-x" @click="filterReporter = ''; onFilterChange()">
              <span class="material-symbols-outlined">close</span>
            </button>
          </div>
        </div>

        <!-- Row 2: date range + per-page + clear -->
        <div class="filter-row filter-row-secondary">
          <div class="filter-date-group" :class="{ 'is-active': filterDateFrom || filterDateTo }">
            <span class="filter-icon material-symbols-outlined">calendar_month</span>
            <input type="date" v-model="filterDateFrom" @change="onFilterChange" class="filter-date" />
            <span class="date-sep">—</span>
            <input type="date" v-model="filterDateTo" @change="onFilterChange" class="filter-date" />
          </div>

          <div class="filter-field filter-field-perpage">
            <span class="filter-icon material-symbols-outlined">format_list_numbered</span>
            <select v-model.number="perPage" @change="onFilterChange" class="filter-select filter-select-sm">
              <option :value="20">20 筆</option>
              <option :value="50">50 筆</option>
              <option :value="100">100 筆</option>
            </select>
          </div>

          <transition name="fade">
            <button v-if="hasActiveFilters" class="btn-clear-all" @click="clearAllFilters">
              <span class="material-symbols-outlined">filter_alt_off</span>
              清除篩選
            </button>
          </transition>
        </div>
      </div>

      <!-- Bug list（只在 Bug Tab 顯示）-->
      <div v-if="!isSuperAdmin || pageTab === 'bugs'" class="card" data-guide="bugs-list" ref="listCardRef">
        <div v-if="loading" class="loading-box">載入中...</div>
        <div v-else-if="bugs.length === 0" class="empty-box">
          <span class="material-symbols-outlined empty-icon">check_circle</span>
          <p v-if="hasActiveFilters">沒有符合篩選條件的 Bug</p>
          <p v-else>目前沒有 Bug 回報</p>
          <button v-if="hasActiveFilters" class="btn-sm btn-ghost" @click="clearAllFilters">清除篩選</button>
        </div>
        <template v-else>
          <div class="list-summary">
            共 {{ total }} 筆，第 {{ currentPage }}/{{ lastPage }} 頁
          </div>
          <div class="bug-list">
            <div
              v-for="bug in bugs"
              :key="bug.id"
              class="bug-item"
              :class="{ active: activeBug?.id === bug.id, unread: isUnread(bug) }"
              @click="selectBug(bug)"
            >
              <span class="severity-dot" :class="bug.severity"></span>
              <div class="bug-item-info">
                <div class="bug-title">
                  {{ bug.title }}
                  <span v-if="isUnread(bug)" class="unread-dot" title="有新動態"></span>
                </div>
                <div class="bug-meta">
                  <span class="status-tag" :class="bug.status">{{ statusLabel(bug.status) }}</span>
                  <span v-if="isUnread(bug) && !isSuperAdmin" class="new-activity-tag">新動態</span>
                  <span v-if="bug.attachments_count > 0" class="bug-attach-hint" title="含截圖">
                    <span class="material-symbols-outlined">attach_file</span>
                    {{ bug.attachments_count }}
                  </span>
                  <span v-if="bug.comments_count > 0" class="bug-comment-hint" title="有留言">
                    <span class="material-symbols-outlined">chat_bubble</span>
                    {{ bug.comments_count }}
                  </span>
                  <span class="bug-date">{{ formatDate(bug.created_at) }}</span>
                  <span v-if="bug.updated_at && bug.updated_at !== bug.created_at" class="bug-updated" title="最後更新">
                    <span class="material-symbols-outlined">update</span>
                    {{ formatDate(bug.updated_at) }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <!-- Pagination -->
          <div v-if="lastPage > 1" class="pagination">
            <button class="page-btn" :disabled="currentPage <= 1" @click="goToPage(1)" title="第一頁">
              <span class="material-symbols-outlined">first_page</span>
            </button>
            <button class="page-btn" :disabled="currentPage <= 1" @click="goToPage(currentPage - 1)" title="上一頁">
              <span class="material-symbols-outlined">chevron_left</span>
            </button>
            <template v-for="p in visiblePages" :key="p">
              <span v-if="p === '...'" class="page-ellipsis">…</span>
              <button v-else class="page-btn" :class="{ active: p === currentPage }" @click="goToPage(p)">
                {{ p }}
              </button>
            </template>
            <button class="page-btn" :disabled="currentPage >= lastPage" @click="goToPage(currentPage + 1)" title="下一頁">
              <span class="material-symbols-outlined">chevron_right</span>
            </button>
            <button class="page-btn" :disabled="currentPage >= lastPage" @click="goToPage(lastPage)" title="最後一頁">
              <span class="material-symbols-outlined">last_page</span>
            </button>
          </div>
        </template>
      </div>

      <!-- Bug detail -->
      <div v-if="activeBug" class="card detail-card">
        <div class="detail-header">
          <h3>{{ detail?.title || activeBug.title }}</h3>
          <button class="btn-close-detail" @click="closeDetail">
            <span class="material-symbols-outlined">close</span>
          </button>
        </div>

        <div v-if="loadingDetail" class="loading-box">載入詳情...</div>
        <template v-else-if="detail">
          <!-- Resolved / closed banner — shown to all roles so reporter sees resolution clearly -->
          <div v-if="detail.status === 'resolved'" class="resolution-banner resolution-banner--resolved">
            <span class="material-symbols-outlined">task_alt</span>
            <div class="resolution-content">
              <strong>已解決</strong>
              <span v-if="resolutionNote" class="resolution-note">{{ resolutionNote }}</span>
              <span v-else class="resolution-note resolution-note--empty">管理員已標記為解決，如仍有問題請在留言中說明。</span>
            </div>
          </div>
          <div v-else-if="detail.status === 'closed'" class="resolution-banner resolution-banner--closed">
            <span class="material-symbols-outlined">lock</span>
            <div class="resolution-content">
              <strong>已關閉</strong>
              <span v-if="resolutionNote" class="resolution-note">{{ resolutionNote }}</span>
              <span v-else class="resolution-note resolution-note--empty">此問題已關閉，如需重新開啟請聯繫管理員。</span>
            </div>
          </div>

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
                <button
                  v-if="isSuperAdmin"
                  class="btn-sm btn-ghost"
                  :disabled="isUpdatingCommentVisibility(c.id)"
                  @click="toggleCommentVisibility(c)"
                >
                  {{ c.is_internal_note ? '給回報者看' : '設為內部' }}
                </button>
              </div>
              <div class="comment-body">{{ c.body }}</div>
            </div>

            <div class="comment-form">
              <textarea v-model="newComment" placeholder="輸入留言..." rows="2" class="form-textarea"></textarea>
              <div class="comment-form-actions">
                <label v-if="isSuperAdmin" class="checkbox-label">
                  <input type="checkbox" v-model="commentIsInternal" /> 內部備註（回報者不可見）
                </label>
                <button class="btn-sm btn-primary" :disabled="!newComment.trim()" @click="doAddComment">送出</button>
              </div>
            </div>
          </div>
        </template>
      </div>

      <!-- Back to top -->
      <transition name="fade">
        <button v-if="showBackToTop" class="back-to-top" @click="scrollToTop" title="回到頂部">
          <span class="material-symbols-outlined">arrow_upward</span>
        </button>
      </transition>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import {
  fetchBugReports, fetchBugDetail, addBugComment,
  updateBugStatus, updateBugCommentVisibility,
} from '../lib/bugReportsApi';
import { getParentFeedbackList, getParentFeedbackUnreadCount, markParentFeedbackRead } from '../api';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  userRole: { type: String, default: '' },
});

const bugs = ref([]);
const activeBug = ref(null);
const detail = ref(null);
const loading = ref(false);
const loadingDetail = ref(false);

// Reporters default to 'all' so they see progress on their submitted bugs.
// Super-admins default to 'pending' because they need an action queue.
const quickFilter = ref('pending'); // recalculated in onMounted after isSuperAdmin is known
const filterStatus = ref('');
const filterSeverity = ref('');
const filterReporter = ref('');
const filterKeyword = ref('');
const filterSort = ref('');
const filterDateFrom = ref('');
const filterDateTo = ref('');
const perPage = ref(20);
const currentPage = ref(1);
const lastPage = ref(1);
const total = ref(0);

const newStatus = ref('');
const statusNote = ref('');
const newComment = ref('');
const commentIsInternal = ref(false);
const updatingCommentVisibilityIds = ref(new Set());

// ── Unread tracking ──────────────────────────────────────────────────────
// localStorage key → { [bugId]: ISO timestamp of last view }
const READ_STORAGE_KEY = 'alltrue_bug_read_times';

function getReadTimes() {
  try {
    return JSON.parse(localStorage.getItem(READ_STORAGE_KEY) || '{}');
  } catch { return {}; }
}

function markBugRead(bugId) {
  try {
    const map = getReadTimes();
    map[bugId] = new Date().toISOString();
    localStorage.setItem(READ_STORAGE_KEY, JSON.stringify(map));
  } catch { /* ignore quota errors */ }
}

function isUnread(bug) {
  // For super_admin the unread concept is less relevant (they manage all bugs)
  if (isSuperAdmin.value) return false;
  // Bug has activity after submission (status change, comment, etc.)
  if (!bug.updated_at || bug.updated_at === bug.created_at) return false;
  const map = getReadTimes();
  const lastRead = map[bug.id];
  if (!lastRead) return true; // never viewed while there is activity
  return new Date(bug.updated_at) > new Date(lastRead);
}

const unreadCount = computed(() => bugs.value.filter(isUnread).length);

const showBackToTop = ref(false);
const listCardRef = ref(null);

let debounceTimer = null;

const isSuperAdmin = computed(() => props.userRole === 'super_admin');

// ─── 頁面層級 Tab（super_admin：Bug 回報 / 家長回饋）──────────────
const pageTab = ref('bugs');

// ─── 家長回饋列表狀態 ─────────────────────────────────────────────
const pfList = ref([]);
const pfLoading = ref(false);
const pfTotal = ref(0);
const pfPage = ref(1);
const pfLastPage = ref(1);
const pfUnreadCount = ref(0);
const pfCategoryFilter = ref('');

const pfCategoryLabel = (cat) => {
  const map = { teaching: '老師教學', schedule: '課程安排', system: '系統功能', other: '其他建議' };
  return map[cat] || cat;
};

async function loadFeedback(page = 1) {
  pfLoading.value = true;
  try {
    const token = localStorage.getItem('alltrue_session') || '';
    const res = await getParentFeedbackList(token, {
      campusId: props.branchId,
      category: pfCategoryFilter.value,
      page,
    });
    pfList.value = res.data || [];
    pfTotal.value = res.total || 0;
    pfPage.value = res.current_page || 1;
    pfLastPage.value = res.last_page || 1;
  } catch (e) {
    console.error('Load parent feedback failed:', e);
  } finally {
    pfLoading.value = false;
  }
}

async function loadPfUnreadCount() {
  if (!isSuperAdmin.value) return;
  const token = localStorage.getItem('alltrue_session') || '';
  pfUnreadCount.value = await getParentFeedbackUnreadCount(token, props.branchId);
}

async function switchToFeedbackTab() {
  pageTab.value = 'feedback';
  await loadFeedback(1);
  pfUnreadCount.value = 0;
}

async function markRead(fb) {
  try {
    const token = localStorage.getItem('alltrue_session') || '';
    await markParentFeedbackRead(token, fb.id);
    fb.is_read = true;
  } catch (e) {
    console.error('Mark read failed:', e);
  }
}

watch(pfCategoryFilter, () => loadFeedback(1));

// The most recent status_log note when a bug reaches resolved/closed — shown in banner
const resolutionNote = computed(() => {
  if (!detail.value?.status_logs?.length) return '';
  const terminal = ['resolved', 'closed'];
  const log = [...detail.value.status_logs]
    .reverse()
    .find(l => terminal.includes(l.to_status));
  return log?.note || '';
});

const pageTitle = computed(() => {
  if (isSuperAdmin.value) return 'Bug 回報（處理中心）';
  return '我的 Bug 回報';
});
const pageDesc = computed(() => {
  if (isSuperAdmin.value) {
    return '檢視各校區回報並更新狀態（僅超級管理員可處理）';
  }
  return '僅顯示你本人提交的問題與處理進度';
});

const hasActiveFilters = computed(() => {
  return filterStatus.value || filterSeverity.value || filterReporter.value
    || filterKeyword.value || filterSort.value || filterDateFrom.value || filterDateTo.value;
});

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

const visiblePages = computed(() => {
  const pages = [];
  const lp = lastPage.value;
  const cp = currentPage.value;
  if (lp <= 7) {
    for (let i = 1; i <= lp; i++) pages.push(i);
    return pages;
  }
  pages.push(1);
  if (cp > 3) pages.push('...');
  const start = Math.max(2, cp - 1);
  const end = Math.min(lp - 1, cp + 1);
  for (let i = start; i <= end; i++) pages.push(i);
  if (cp < lp - 2) pages.push('...');
  pages.push(lp);
  return pages;
});

function setQuickFilter(mode) {
  quickFilter.value = mode;
  filterStatus.value = '';
  currentPage.value = 1;
  loadBugs();
}

function onFilterChange() {
  quickFilter.value = '';
  currentPage.value = 1;
  loadBugs();
}

function onKeywordInput() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {
    quickFilter.value = '';
    currentPage.value = 1;
    loadBugs();
  }, 400);
}

function clearAllFilters() {
  filterStatus.value = '';
  filterSeverity.value = '';
  filterReporter.value = '';
  filterKeyword.value = '';
  filterSort.value = '';
  filterDateFrom.value = '';
  filterDateTo.value = '';
  quickFilter.value = isSuperAdmin.value ? 'pending' : 'all';
  currentPage.value = 1;
  loadBugs();
}

function goToPage(p) {
  if (p < 1 || p > lastPage.value || p === currentPage.value) return;
  currentPage.value = p;
  loadBugs();
  nextTick(() => {
    if (listCardRef.value) listCardRef.value.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
}

onMounted(() => {
  quickFilter.value = isSuperAdmin.value ? 'pending' : 'all';
  loadBugs();
  loadPfUnreadCount();
  window.addEventListener('scroll', handleScroll);
});

onBeforeUnmount(() => {
  window.removeEventListener('scroll', handleScroll);
  clearTimeout(debounceTimer);
});

watch(() => props.branchId, () => {
  activeBug.value = null;
  detail.value = null;
  currentPage.value = 1;
  loadBugs();
});

function buildStatusFilter() {
  if (filterStatus.value) return filterStatus.value;
  if (quickFilter.value === 'pending') return 'new,triaged,in_progress';
  if (quickFilter.value === 'resolved') return 'resolved,closed';
  if (quickFilter.value === 'closed') return 'closed';
  return ''; // 'all' → no filter
}

async function loadBugs() {
  if (!props.branchId) return;
  loading.value = true;
  try {
    const filters = {};
    const st = buildStatusFilter();
    if (st) filters.status = st;
    if (filterSeverity.value) filters.severity = filterSeverity.value;
    if (filterReporter.value) filters.reporter = filterReporter.value;
    if (filterKeyword.value) filters.keyword = filterKeyword.value;
    if (filterSort.value) filters.sort = filterSort.value;
    if (filterDateFrom.value) filters.date_from = filterDateFrom.value;
    if (filterDateTo.value) filters.date_to = filterDateTo.value;
    const data = await fetchBugReports(props.branchId, filters, perPage.value, currentPage.value);
    bugs.value = data.data || [];
    currentPage.value = data.current_page || 1;
    lastPage.value = data.last_page || 1;
    total.value = data.total || 0;
    window.dispatchEvent(new CustomEvent('alltrue-refresh-badges'));
  } catch (e) {
    console.error('[Bugs] loadBugs:', e);
  } finally {
    loading.value = false;
  }
}

function closeDetail() {
  activeBug.value = null;
  detail.value = null;
}

async function selectBug(bug) {
  activeBug.value = bug;
  loadingDetail.value = true;
  newStatus.value = '';
  statusNote.value = '';
  newComment.value = '';
  try {
    detail.value = await fetchBugDetail(bug.id);
    // Mark as read so unread dot clears after opening
    markBugRead(bug.id);
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

function isUpdatingCommentVisibility(commentId) {
  return updatingCommentVisibilityIds.value.has(commentId);
}

async function toggleCommentVisibility(comment) {
  if (!activeBug.value) return;
  const nextValue = !Boolean(comment?.is_internal_note);
  updatingCommentVisibilityIds.value.add(comment.id);
  try {
    await updateBugCommentVisibility(activeBug.value.id, comment.id, nextValue);
    await selectBug(activeBug.value);
  } catch (e) {
    alert('更新留言可見性失敗：' + e.message);
  } finally {
    updatingCommentVisibilityIds.value.delete(comment.id);
  }
}

function handleScroll() {
  showBackToTop.value = window.scrollY > 400;
}

function scrollToTop() {
  window.scrollTo({ top: 0, behavior: 'smooth' });
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

/* ═══ 頁面 Tab Bar（Bug 回報 / 家長回饋）═══ */
.bugs-page-tabs {
  display: flex; gap: 0; background: #fff; border-radius: 10px;
  box-shadow: 0 1px 4px rgba(0,0,0,.07); overflow: hidden; margin-bottom: 12px;
}
.bugs-page-tab {
  flex: 1; display: flex; align-items: center; justify-content: center;
  gap: 6px; padding: 11px 16px; background: none; border: none;
  cursor: pointer; font-size: 0.88em; color: #78909c;
  transition: color .2s, background .2s; position: relative; font-weight: 500;
}
.bugs-page-tab .material-symbols-outlined { font-size: 18px; }
.bugs-page-tab.active { color: #1565c0; background: #e8f2ff; font-weight: 700; }
.bugs-page-tab:not(.active):hover { background: #f5f8ff; }
.bugs-page-tab-badge {
  background: #e53935; color: #fff; border-radius: 10px;
  font-size: 0.72em; padding: 1px 6px; font-weight: 700; min-width: 18px; text-align: center;
}

/* ═══ 家長回饋 Admin 列表 ═══ */
.pf-admin-card { padding: 16px; }
.pf-filter-row { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
.pf-select {
  border: 1px solid #e0e0e0; border-radius: 8px; padding: 7px 12px;
  font-size: 0.88em; outline: none; background: #fafafa;
}
.pf-total { font-size: 0.82em; color: #90a4ae; margin-left: auto; }
.pf-loading { display: flex; align-items: center; gap: 8px; color: #78909c; padding: 20px 0; }
.pf-empty { text-align: center; padding: 32px 16px; color: #90a4ae; }
.pf-empty .material-symbols-outlined { font-size: 40px; display: block; margin: 0 auto 8px; }
.pf-list { display: flex; flex-direction: column; gap: 10px; }
.pf-item {
  border: 1px solid #e8e8e8; border-radius: 10px; padding: 14px; background: #fff;
  transition: box-shadow .15s;
}
.pf-item.unread { border-color: #90caf9; background: #f0f7ff; }
.pf-item-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; margin-bottom: 6px; }
.pf-item-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.pf-student { font-weight: 700; color: #263238; font-size: 0.9em; }
.pf-category {
  padding: 2px 10px; border-radius: 10px; font-size: 0.76em; font-weight: 600;
  background: #e3f2fd; color: #1565c0;
}
.pf-new-dot {
  background: #e53935; color: #fff; padding: 1px 7px; border-radius: 8px;
  font-size: 0.7em; font-weight: 700;
}
.pf-item-time { font-size: 0.75em; color: #90a4ae; white-space: nowrap; flex-shrink: 0; }
.pf-stars { display: flex; gap: 2px; margin-bottom: 6px; }
.pf-star { font-size: 16px; }
.pf-content { font-size: 0.9em; color: #37474f; line-height: 1.6; margin-bottom: 10px; }
.pf-actions { display: flex; align-items: center; }
.pf-btn-read {
  display: flex; align-items: center; gap: 5px;
  background: #e3f2fd; color: #1565c0; border: none; border-radius: 8px;
  padding: 6px 14px; font-size: 0.82em; cursor: pointer; font-weight: 600;
  transition: background .15s;
}
.pf-btn-read:hover { background: #bbdefb; }
.pf-btn-read .material-symbols-outlined { font-size: 16px; }
.pf-read-label { display: flex; align-items: center; gap: 5px; color: #66bb6a; font-size: 0.82em; font-weight: 600; }
.pf-read-label .material-symbols-outlined { font-size: 16px; }
.pf-pagination { display: flex; align-items: center; justify-content: center; gap: 12px; margin-top: 14px; }
.pf-page-btn {
  border: 1px solid #e0e0e0; background: #fff; border-radius: 6px;
  padding: 6px 14px; cursor: pointer; font-size: 1em;
}
.pf-page-btn:disabled { opacity: .4; cursor: not-allowed; }
.page-header { padding: 16px 24px 8px; }
.page-header h2 { display: flex; align-items: center; gap: 8px; margin: 0; }
.header-icon { font-size: 28px; color: var(--primary); }
.page-desc { color: var(--text-light); font-size: 14px; margin-top: 2px; }

.card { background: var(--card-bg); border-radius: var(--radius); box-shadow: var(--shadow); margin: 0 16px 16px; padding: 16px; }
.empty-card { text-align: center; padding: 32px; }

/* Quick filter tabs */
.quick-filter-card { padding: 8px 16px; }
.quick-tabs { display: flex; gap: 4px; flex-wrap: wrap; }
.quick-tab {
  display: flex; align-items: center; gap: 4px;
  padding: 8px 16px; border: none; border-radius: 8px;
  background: transparent; color: var(--text-light);
  font-size: 13px; font-weight: 500; cursor: pointer;
  transition: all 0.15s; position: relative;
}
.quick-tab:hover { background: var(--primary-bg); color: var(--text); }
.quick-tab.active { background: var(--primary); color: #fff; }
.tab-icon { font-size: 18px; }

/* Unread badge on "全部" tab */
.unread-badge {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 18px; height: 18px; padding: 0 5px;
  background: var(--danger, #e53935); color: #fff;
  border-radius: 9px; font-size: 11px; font-weight: 700; line-height: 1;
}

/* ── Filter bar ─────────────────────────────────────────────── */
.controls-card { padding: 12px 16px; }

.filter-row {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.filter-row-secondary {
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid var(--border);
}

/* Each pill-shaped field wrapper */
.filter-field {
  display: flex;
  align-items: center;
  gap: 4px;
  height: 36px;
  padding: 0 10px;
  background: var(--input-bg, #f8f8f8);
  border: 1.5px solid var(--border);
  border-radius: 20px;
  transition: border-color 0.15s, box-shadow 0.15s;
  min-width: 0;
}
.filter-field:focus-within {
  border-color: var(--primary);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--primary) 15%, transparent);
  background: var(--card-bg);
}
.filter-field.is-active {
  border-color: var(--primary);
  background: color-mix(in srgb, var(--primary) 8%, var(--card-bg));
}
.filter-field-grow { flex: 1; min-width: 180px; }
.filter-field-perpage { min-width: 0; }

.filter-icon {
  font-size: 16px;
  color: var(--text-light);
  flex-shrink: 0;
  pointer-events: none;
}
.filter-field.is-active .filter-icon,
.filter-field:focus-within .filter-icon {
  color: var(--primary);
}

.filter-select {
  border: none;
  background: transparent;
  font-size: 13px;
  color: var(--text);
  outline: none;
  cursor: pointer;
  padding: 0;
  min-width: 60px;
}
.filter-select-sm { min-width: 56px; }

.filter-input {
  border: none;
  background: transparent;
  font-size: 13px;
  color: var(--text);
  outline: none;
  flex: 1;
  min-width: 0;
}
.filter-input::placeholder { color: var(--text-light); }
.filter-input-narrow { width: 110px; flex: none; }

.filter-clear-x {
  display: flex;
  align-items: center;
  border: none;
  background: none;
  cursor: pointer;
  color: var(--text-light);
  padding: 0;
  flex-shrink: 0;
}
.filter-clear-x:hover { color: var(--danger, #e53935); }
.filter-clear-x .material-symbols-outlined { font-size: 16px; }

.filter-divider {
  width: 1px;
  height: 22px;
  background: var(--border);
  flex-shrink: 0;
  margin: 0 2px;
}

/* Date range group */
.filter-date-group {
  display: flex;
  align-items: center;
  gap: 4px;
  height: 36px;
  padding: 0 10px;
  background: var(--input-bg, #f8f8f8);
  border: 1.5px solid var(--border);
  border-radius: 20px;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.filter-date-group:focus-within {
  border-color: var(--primary);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--primary) 15%, transparent);
  background: var(--card-bg);
}
.filter-date-group.is-active {
  border-color: var(--primary);
  background: color-mix(in srgb, var(--primary) 8%, var(--card-bg));
}
.filter-date-group .filter-icon { color: var(--text-light); }
.filter-date-group.is-active .filter-icon { color: var(--primary); }

.filter-date {
  border: none;
  background: transparent;
  font-size: 13px;
  color: var(--text);
  outline: none;
  width: 118px;
  cursor: pointer;
}
.date-sep { font-size: 13px; color: var(--text-light); padding: 0 2px; }

/* Clear all button */
.btn-clear-all {
  display: flex;
  align-items: center;
  gap: 5px;
  height: 32px;
  padding: 0 12px;
  border: 1.5px solid var(--border);
  border-radius: 20px;
  background: transparent;
  color: var(--text-light);
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
  white-space: nowrap;
  margin-left: auto;
  transition: border-color 0.15s, color 0.15s, background 0.15s;
}
.btn-clear-all:hover {
  border-color: var(--danger, #e53935);
  color: var(--danger, #e53935);
  background: color-mix(in srgb, var(--danger, #e53935) 6%, transparent);
}
.btn-clear-all .material-symbols-outlined { font-size: 15px; }

/* List summary */
.list-summary { font-size: 12px; color: var(--text-light); margin-bottom: 8px; padding: 0 4px; }

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

.bug-item.unread { border-left: 3px solid var(--primary); padding-left: 9px; }

.bug-title { font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 6px; }

/* Unread dot — inline with title */
.unread-dot {
  display: inline-block; width: 8px; height: 8px; border-radius: 50%;
  background: var(--danger, #e53935); flex-shrink: 0;
}

/* "新動態" tag in meta row */
.new-activity-tag {
  display: inline-block; padding: 1px 7px; border-radius: 10px;
  font-size: 10px; font-weight: 700;
  background: color-mix(in srgb, var(--danger, #e53935) 12%, transparent);
  color: var(--danger, #e53935); white-space: nowrap;
}

.bug-meta { display: flex; align-items: center; gap: 8px; margin-top: 4px; flex-wrap: wrap; }
.bug-date { font-size: 12px; color: var(--text-light); }
.bug-updated { display: inline-flex; align-items: center; gap: 2px; font-size: 12px; color: var(--text-light); }
.bug-updated .material-symbols-outlined { font-size: 14px; }
.bug-attach-hint {
  display: inline-flex; align-items: center; gap: 2px;
  font-size: 12px; color: var(--text-light);
}
.bug-attach-hint .material-symbols-outlined { font-size: 16px; }
.bug-comment-hint {
  display: inline-flex; align-items: center; gap: 2px;
  font-size: 12px; color: var(--text-light);
}
.bug-comment-hint .material-symbols-outlined { font-size: 16px; }

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

/* Pagination */
.pagination {
  display: flex; align-items: center; justify-content: center;
  gap: 4px; margin-top: 16px; padding-top: 12px;
  border-top: 1px solid var(--border);
}
.page-btn {
  display: flex; align-items: center; justify-content: center;
  min-width: 36px; height: 36px; padding: 0 8px;
  border: 1px solid var(--border); border-radius: 8px;
  background: var(--card-bg); color: var(--text);
  font-size: 13px; cursor: pointer; transition: all 0.15s;
}
.page-btn:hover:not(:disabled) { background: var(--primary-bg); border-color: var(--primary); }
.page-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.page-btn:disabled { opacity: 0.3; cursor: not-allowed; }
.page-btn .material-symbols-outlined { font-size: 20px; }
.page-ellipsis { padding: 0 4px; color: var(--text-light); font-size: 14px; }

/* Back to top */
.back-to-top {
  position: fixed; bottom: 80px; right: 20px; z-index: 100;
  width: 44px; height: 44px; border-radius: 50%;
  background: var(--primary); color: #fff; border: none;
  box-shadow: 0 2px 12px rgba(0,0,0,.2); cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: all 0.2s;
}
.back-to-top:hover { transform: scale(1.1); }
.back-to-top .material-symbols-outlined { font-size: 24px; }
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

/* Detail */
.detail-card { position: relative; }
.detail-header { display: flex; justify-content: space-between; align-items: flex-start; }
.detail-header h3 { margin: 0; font-size: 18px; }
.btn-close-detail { background: none; border: none; cursor: pointer; color: var(--text-light); }

/* Resolution banner */
.resolution-banner {
  display: flex; align-items: flex-start; gap: 12px;
  padding: 14px 16px; border-radius: 10px; margin: 12px 0 4px;
  font-size: 14px;
}
.resolution-banner .material-symbols-outlined { font-size: 24px; flex-shrink: 0; margin-top: 1px; }
.resolution-banner--resolved {
  background: color-mix(in srgb, var(--success, #43a047) 10%, transparent);
  border: 1.5px solid color-mix(in srgb, var(--success, #43a047) 35%, transparent);
  color: var(--success, #2e7d32);
}
.resolution-banner--closed {
  background: #ECEFF1; border: 1.5px solid #CFD8DC; color: #546E7A;
}
.resolution-content { display: flex; flex-direction: column; gap: 4px; }
.resolution-content strong { font-size: 15px; }
.resolution-note { font-size: 13px; line-height: 1.5; }
.resolution-note--empty { opacity: 0.7; font-style: italic; }

.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin: 16px 0; font-size: 14px; }
.detail-description { margin: 12px 0; }
.detail-description strong { display: block; margin-bottom: 4px; }
.detail-description p { background: #f8f8f8; padding: 12px; border-radius: 8px; font-size: 14px; line-height: 1.6; white-space: pre-wrap; }

.detail-attachments { margin: 16px 0; }
.detail-attachments strong { display: block; margin-bottom: 8px; }
.attachment-grid { display: flex; flex-wrap: wrap; gap: 10px; }
.attachment-thumb-link {
  display: block; width: 120px; height: 120px; border-radius: 8px; overflow: hidden;
  border: 1px solid var(--border); background: #f0f0f0;
}
.attachment-thumb { width: 100%; height: 100%; object-fit: cover; }

.admin-actions { margin: 16px 0; padding: 12px; background: #FFFDE7; border-radius: 8px; }
.action-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
.action-row:last-child { margin-bottom: 0; }
.action-row label { font-size: 13px; font-weight: 600; min-width: 60px; }
.action-row select, .action-input { padding: 6px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; }
.action-input { flex: 1; min-width: 120px; }

.btn-sm { padding: 6px 14px; border: none; border-radius: 6px; font-size: 13px; cursor: pointer; }
.btn-sm.btn-primary { background: var(--primary); color: #fff; }
.btn-sm.btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-sm.btn-ghost { background: #fff; border: 1px solid var(--border); color: var(--text); padding: 4px 10px; }
.btn-sm.btn-ghost:disabled { opacity: 0.4; cursor: not-allowed; }

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
