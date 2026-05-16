<template>
  <div class="bug-launcher">
    <!-- Floating button -->
    <button
      class="fab"
      :class="{ dragging: fabDragging }"
      :style="fabStyle"
      @pointerdown="onFabPointerDown"
      @pointermove="onFabPointerMove"
      @pointerup="onFabPointerUp"
      @pointercancel="onFabPointerUp"
      @click="onFabClick"
      title="回報問題（可拖曳）"
    >
      <span class="material-symbols-outlined">bug_report</span>
    </button>

    <!-- Submit dialog -->
    <div v-if="showForm" class="modal-overlay">
      <div class="modal-card">
        <h3><span class="material-symbols-outlined">bug_report</span> 回報系統問題</h3>

        <label>問題標題 <span class="optional">（選填，自動帶入頁面）</span></label>
        <input v-model="title" class="form-input" placeholder="簡述問題（留空則自動填入）" maxlength="200" />

        <label>詳細描述 <span class="required">*</span></label>
        <textarea v-model="description" class="form-textarea" placeholder="發生什麼問題？在什麼情況下？" rows="4" maxlength="5000"></textarea>

        <label>截圖（選填，最多 {{ maxFiles }} 張，每張 ≤5MB）</label>
        <input
          ref="fileInputRef"
          type="file"
          class="file-input"
          accept="image/jpeg,image/png,image/gif,image/webp"
          multiple
          @change="onFilesPicked"
        />
        <div v-if="attachmentFiles.length" class="attachment-previews">
          <div v-for="(f, i) in attachmentFiles" :key="i" class="att-row">
            <span class="att-name">{{ f.name }}</span>
            <button type="button" class="att-remove" @click="removeAttachment(i)">移除</button>
          </div>
        </div>

        <label>嚴重程度</label>
        <select v-model="severity" class="form-select">
          <option value="low">低 — 不影響使用</option>
          <option value="medium">中 — 有些不方便</option>
          <option value="high">高 — 影響工作</option>
          <option value="critical">嚴重 — 完全無法使用</option>
        </select>

        <div class="context-info">
          <span class="material-symbols-outlined">info</span>
          將自動附帶當前頁面：<strong>{{ currentPageKey || '未知' }}</strong>
        </div>

        <div class="form-actions">
          <button class="btn-cancel" @click="showForm = false">取消</button>
          <button class="btn-submit" :disabled="!canSubmit || submitting" @click="doSubmit">
            {{ submitting ? '提交中...' : '提交回報' }}
          </button>
        </div>

        <div v-if="submitSuccess" class="success-msg">
          <span class="material-symbols-outlined">check_circle</span> 已提交，感謝回報！
        </div>
        <div v-if="submitError" class="error-msg">{{ submitError }}</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { submitBugReport, MAX_BUG_ATTACHMENTS } from '../lib/bugReportsApi';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  currentPageKey: { type: String, default: '' },
});

const showForm = ref(false);
const title = ref('');
const description = ref('');
const severity = ref('medium');
const submitting = ref(false);
const submitSuccess = ref(false);
const submitError = ref('');
const attachmentFiles = ref([]);
const fileInputRef = ref(null);
const maxFiles = MAX_BUG_ATTACHMENTS;
const maxBytesPerFile = 5 * 1024 * 1024;

const canSubmit = computed(() => description.value.trim() && props.branchId);

const FAB_SIZE = 52;
const FAB_MARGIN = 8;
const FAB_STORAGE_KEY = 'alltrue_bug_fab_pos';
const FAB_DRAG_THRESHOLD = 6;

const fabPos = ref({ x: 0, y: 0 });
const fabDragging = ref(false);
const fabSuppressClick = ref(false);
let fabPointerId = null;
let dragStartClient = { x: 0, y: 0 };
let dragStartPos = { x: 0, y: 0 };
let didDrag = false;

const fabStyle = computed(() => ({
  left: `${fabPos.value.x}px`,
  top: `${fabPos.value.y}px`,
  right: 'auto',
  bottom: 'auto',
}));

function getBottomSafeInset() {
  // Mobile has bottom tab bar
  return window.innerWidth <= 768 ? 100 : 12;
}

function clampFabPos(x, y) {
  const maxX = window.innerWidth - FAB_SIZE - FAB_MARGIN;
  const maxY = window.innerHeight - FAB_SIZE - getBottomSafeInset();
  return {
    x: Math.min(Math.max(FAB_MARGIN, x), Math.max(FAB_MARGIN, maxX)),
    y: Math.min(Math.max(FAB_MARGIN, y), Math.max(FAB_MARGIN, maxY)),
  };
}

function snapFabToNearestEdge(x, y) {
  const p = clampFabPos(x, y);
  const maxX = window.innerWidth - FAB_SIZE - FAB_MARGIN;
  const maxY = window.innerHeight - FAB_SIZE - getBottomSafeInset();
  const cx = p.x + FAB_SIZE / 2;
  const cy = p.y + FAB_SIZE / 2;
  const dLeft = cx;
  const dRight = window.innerWidth - cx;
  const dTop = cy;
  const dBottom = window.innerHeight - cy;

  const min = Math.min(dLeft, dRight, dTop, dBottom);
  if (min === dLeft) return { x: FAB_MARGIN, y: p.y };
  if (min === dRight) return { x: maxX, y: p.y };
  if (min === dTop) return { x: p.x, y: FAB_MARGIN };
  return { x: p.x, y: maxY };
}

function saveFabPos() {
  try {
    localStorage.setItem(FAB_STORAGE_KEY, JSON.stringify(fabPos.value));
  } catch { /* ignore */ }
}

function loadFabPos() {
  try {
    const raw = localStorage.getItem(FAB_STORAGE_KEY);
    if (raw) {
      const p = JSON.parse(raw);
      if (typeof p.x === 'number' && typeof p.y === 'number') {
        return snapFabToNearestEdge(p.x, p.y);
      }
    }
  } catch { /* ignore */ }

  // Default: bottom-right but leave room for guide "?" button
  const defaultX = window.innerWidth - FAB_MARGIN - FAB_SIZE - 62;
  const defaultY = window.innerHeight - FAB_SIZE - getBottomSafeInset();
  return clampFabPos(defaultX, defaultY);
}

function onFabPointerDown(e) {
  if (e.pointerType === 'mouse' && e.button !== 0) return;
  fabPointerId = e.pointerId;
  didDrag = false;
  dragStartClient = { x: e.clientX, y: e.clientY };
  dragStartPos = { ...fabPos.value };
  fabDragging.value = true;
  e.currentTarget.setPointerCapture?.(e.pointerId);
}

function onFabPointerMove(e) {
  if (fabPointerId !== e.pointerId) return;
  const dx = e.clientX - dragStartClient.x;
  const dy = e.clientY - dragStartClient.y;
  if (Math.abs(dx) > FAB_DRAG_THRESHOLD || Math.abs(dy) > FAB_DRAG_THRESHOLD) {
    didDrag = true;
  }
  fabPos.value = clampFabPos(dragStartPos.x + dx, dragStartPos.y + dy);
}

function onFabPointerUp(e) {
  if (fabPointerId !== e.pointerId) return;
  try {
    e.currentTarget.releasePointerCapture?.(e.pointerId);
  } catch { /* ignore */ }
  fabPointerId = null;
  fabDragging.value = false;

  if (didDrag) {
    fabSuppressClick.value = true;
    fabPos.value = snapFabToNearestEdge(fabPos.value.x, fabPos.value.y);
    saveFabPos();
    didDrag = false;
  }
}

function onFabClick(e) {
  if (fabSuppressClick.value) {
    fabSuppressClick.value = false;
    e.preventDefault();
    e.stopPropagation();
    return;
  }
  showForm.value = true;
}

function onFilesPicked(e) {
  const input = e.target;
  const picked = input?.files ? Array.from(input.files) : [];
  input.value = '';
  if (!picked.length) return;

  const next = [...attachmentFiles.value];
  for (const file of picked) {
    if (next.length >= maxFiles) break;
    if (!/^image\/(jpeg|png|gif|webp)$/i.test(file.type)) {
      submitError.value = '僅支援 JPEG、PNG、GIF、WebP 圖片';
      continue;
    }
    if (file.size > maxBytesPerFile) {
      submitError.value = `「${file.name}」超過 5MB，請壓縮或換一張`;
      continue;
    }
    submitError.value = '';
    next.push(file);
  }
  attachmentFiles.value = next;
}

function removeAttachment(index) {
  attachmentFiles.value = attachmentFiles.value.filter((_, i) => i !== index);
}

function onWindowResize() {
  fabPos.value = snapFabToNearestEdge(fabPos.value.x, fabPos.value.y);
}

onMounted(() => {
  fabPos.value = loadFabPos();
  window.addEventListener('resize', onWindowResize);
});

onBeforeUnmount(() => {
  window.removeEventListener('resize', onWindowResize);
});

async function doSubmit() {
  if (!canSubmit.value || submitting.value) return;
  submitting.value = true;
  submitSuccess.value = false;
  submitError.value = '';

  try {
    const clientInfo = JSON.stringify({
      userAgent: navigator.userAgent,
      screenSize: `${window.innerWidth}x${window.innerHeight}`,
      timestamp: new Date().toISOString(),
    });

    await submitBugReport({
      branch_id: Number(props.branchId),
      title: title.value.trim() || `[${props.currentPageKey || '未知頁面'}] ${new Date().toLocaleString('zh-TW')}`,
      description: description.value.trim(),
      severity: severity.value,
      page_key: props.currentPageKey,
      url: window.location.href,
      client_info: clientInfo,
      files: attachmentFiles.value,
    });

    submitSuccess.value = true;
    title.value = '';
    description.value = '';
    severity.value = 'medium';
    attachmentFiles.value = [];
    window.dispatchEvent(new CustomEvent('alltrue-refresh-badges'));
    setTimeout(() => { showForm.value = false; submitSuccess.value = false; }, 1500);
  } catch (e) {
    submitError.value = '提交失敗：' + e.message;
  } finally {
    submitting.value = false;
  }
}
</script>

<style scoped>
.fab {
  position: fixed; z-index: 900;
  width: 52px; height: 52px; border-radius: 50%;
  background: var(--primary); color: #fff; border: none;
  box-shadow: 0 4px 12px rgba(0,0,0,0.2);
  cursor: grab; touch-action: none;
  display: flex; align-items: center; justify-content: center;
  will-change: transform;
  transition: left 0.35s cubic-bezier(0.22, 1, 0.36, 1), top 0.35s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.2s;
}
.fab:hover { transform: scale(1.08); box-shadow: 0 6px 20px rgba(0,0,0,0.25); }
.fab.dragging { cursor: grabbing; transform: none; transition: none; }
.fab .material-symbols-outlined { font-size: 26px; }

.modal-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.4);
  display: flex; align-items: center; justify-content: center; z-index: 1000;
}
.modal-card {
  background: var(--card-bg); border-radius: var(--radius); padding: 24px;
  width: 480px; max-width: 90vw; max-height: 80vh; overflow-y: auto;
  box-shadow: var(--shadow-hover);
}
.modal-card h3 { display: flex; align-items: center; gap: 8px; margin: 0 0 16px; font-size: 18px; }

label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; margin-top: 12px; }
.required { color: var(--danger); }
.optional { color: #94a3b8; font-size: 12px; font-weight: normal; }
.form-input, .form-select, .form-textarea {
  width: 100%; padding: 8px 12px; border: 1px solid var(--border);
  border-radius: 8px; font-size: 14px; font-family: inherit;
}
.form-textarea { resize: vertical; }

.file-input {
  width: 100%; padding: 8px 0; font-size: 13px;
}
.attachment-previews { margin-top: 6px; font-size: 13px; }
.att-row {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  padding: 4px 0; border-bottom: 1px solid var(--border);
}
.att-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--text-light); }
.att-remove {
  flex-shrink: 0; padding: 2px 8px; font-size: 12px; border: 1px solid var(--border);
  border-radius: 6px; background: var(--card-bg); cursor: pointer;
}

.context-info {
  display: flex; align-items: center; gap: 6px; margin-top: 14px;
  padding: 8px 12px; background: #f0f4f8; border-radius: 8px;
  font-size: 13px; color: var(--text-light);
}
.context-info .material-symbols-outlined { font-size: 18px; }

.form-actions { display: flex; gap: 8px; margin-top: 20px; justify-content: flex-end; }
.btn-cancel {
  padding: 8px 20px; border: 1px solid var(--border); border-radius: 8px;
  background: var(--card-bg); font-size: 14px; cursor: pointer;
}
.btn-submit {
  padding: 8px 20px; border: none; border-radius: 8px;
  background: var(--primary); color: #fff; font-size: 14px; cursor: pointer;
}
.btn-submit:disabled { opacity: 0.4; cursor: not-allowed; }

.success-msg {
  display: flex; align-items: center; gap: 6px; margin-top: 12px;
  color: var(--success); font-size: 14px; font-weight: 600;
}
.error-msg { margin-top: 12px; color: var(--danger); font-size: 13px; }

@media (max-width: 768px) {
  .fab { width: 48px; height: 48px; }
}
</style>
