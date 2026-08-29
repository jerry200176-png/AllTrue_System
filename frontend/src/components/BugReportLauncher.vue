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
    <AtDialog
      :open="showForm"
      title="回報系統問題"
      size="md"
      panel-class="bug-report-dialog"
      :close-on-backdrop="!submitting"
      close-label="關閉回報視窗"
      @close="!submitting && closeForm()"
    >
      <div class="bug-report-form" @paste="onPaste">

        <label for="bug-title">問題標題 <span class="optional">（選填，自動帶入頁面）</span></label>
        <input id="bug-title" v-model="title" class="form-input" placeholder="簡述問題（留空則自動填入）" maxlength="200" />

        <label for="bug-description">詳細描述 <span class="required">*</span></label>
        <textarea id="bug-description" v-model="description" class="form-textarea" placeholder="請描述：做了什麼、實際看到什麼、原本預期什麼？" rows="4" maxlength="5000"></textarea>
        <p class="description-hint">若問題只在特定資料出現，請在下方補充時間或資料編號；請勿填寫密碼。</p>

        <div class="triage-context" aria-label="協助定位問題的補充資訊">
          <label for="bug-occurrence-at">發生時間 <span class="optional">（選填）</span></label>
          <input id="bug-occurrence-at" v-model="occurrenceAt" class="form-input" type="datetime-local" />

          <label for="bug-related-reference">相關資料 <span class="optional">（選填）</span></label>
          <input
            id="bug-related-reference"
            v-model="relatedReference"
            class="form-input"
            maxlength="300"
            autocomplete="off"
            placeholder="例如：學生／課程／課堂／發票編號"
          />
        </div>

        <label>截圖（選填，最多 {{ maxFiles }} 張，每張 ≤5MB）</label>
        <input
          ref="fileInputRef"
          type="file"
          id="bug-file-input"
          class="file-input sr-only"
          accept="image/jpeg,image/png,image/gif,image/webp"
          multiple
          @change="onFilesPicked"
        />
        <div
          class="attachment-dropzone"
          :class="{ 'is-dragging': attachmentDragging }"
          role="button"
          tabindex="0"
          aria-controls="bug-file-input"
          @click="openFilePicker"
          @keydown.enter.prevent="openFilePicker"
          @keydown.space.prevent="openFilePicker"
          @dragenter.prevent="onDragEnter"
          @dragover.prevent="onDragOver"
          @dragleave.prevent="onDragLeave"
          @drop.prevent="onDrop"
        >
          <span class="material-symbols-outlined" aria-hidden="true">upload_file</span>
          <span><strong>{{ attachmentDragging ? '放開即可加入圖片' : '拖曳圖片到這裡' }}</strong></span>
          <span class="dropzone-or">或</span>
          <span class="dropzone-link">點此選取圖片</span>
          <small>也可以在這個視窗直接貼上截圖（Ctrl/Cmd + V）</small>
        </div>
        <div v-if="attachmentError" class="attachment-error" role="alert">{{ attachmentError }}</div>
        <div v-if="attachmentFiles.length" class="attachment-previews">
          <div v-for="(entry, i) in attachmentFiles" :key="entry.id" class="att-row">
            <img v-if="entry.previewUrl" :src="entry.previewUrl" :alt="entry.file.name || '已加入的圖片'" class="att-preview" />
            <span class="att-name">{{ entry.file.name }}</span>
            <button type="button" class="att-remove" :aria-label="`移除 ${entry.file.name || '圖片'}`" @click="removeAttachment(i)">移除</button>
          </div>
        </div>
        <div class="attachment-count" aria-live="polite">已加入 {{ attachmentFiles.length }} / {{ maxFiles }} 張</div>

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

        <div v-if="submitSuccess" class="success-msg">
          <span class="material-symbols-outlined">check_circle</span> 已提交，感謝回報！
        </div>
        <div v-if="submitError" class="error-msg">{{ submitError }}</div>
      </div>
      <template #actions>
        <button class="btn-cancel" :disabled="submitting" @click="closeForm">取消</button>
        <button class="btn-submit" :disabled="!canSubmit || submitting" @click="doSubmit">
          {{ submitting ? '提交中...' : '提交回報' }}
        </button>
      </template>
    </AtDialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import AtDialog from './design-system/AtDialog.vue';
import { submitBugReport } from '../lib/bugReportsApi';
import {
  extractImageFiles,
  extractTransferFiles,
  MAX_BUG_ATTACHMENTS,
  namePastedImage,
  validateBugAttachments,
} from '../lib/bugReportAttachments';

const props = defineProps({
  branchId: { type: [Number, String], default: null },
  currentPageKey: { type: String, default: '' },
});

const showForm = ref(false);
const title = ref('');
const description = ref('');
const occurrenceAt = ref('');
const relatedReference = ref('');
const severity = ref('medium');
const submitting = ref(false);
const submitSuccess = ref(false);
const submitError = ref('');
const attachmentError = ref('');
const attachmentFiles = ref([]);
const fileInputRef = ref(null);
const maxFiles = MAX_BUG_ATTACHMENTS;
const attachmentDragging = ref(false);
let attachmentDragDepth = 0;
let attachmentSequence = 0;

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
  openForm();
}

function onFilesPicked(e) {
  const input = e.target;
  const picked = input?.files ? Array.from(input.files) : [];
  input.value = '';
  addAttachments(picked);
}

function removeAttachment(index) {
  const entry = attachmentFiles.value[index];
  releasePreview(entry);
  attachmentFiles.value = attachmentFiles.value.filter((_, i) => i !== index);
}

function openForm() {
  showForm.value = true;
  submitError.value = '';
  attachmentError.value = '';
}

function openFilePicker() {
  fileInputRef.value?.click();
}

function addAttachments(files, source = 'file') {
  const normalized = source === 'paste'
    ? Array.from(files || []).map((file) => namePastedImage(file)).filter(Boolean)
    : Array.from(files || []);
  if (!normalized.length) return;

  const { accepted, errors } = validateBugAttachments(normalized, attachmentFiles.value.length);
  attachmentError.value = errors.join('；');
  if (!accepted.length) return;

  const entries = accepted.map((file) => ({
    id: `attachment-${Date.now()}-${attachmentSequence++}`,
    file,
    previewUrl: createPreviewUrl(file),
  }));
  attachmentFiles.value = [...attachmentFiles.value, ...entries];
}

function createPreviewUrl(file) {
  if (typeof URL === 'undefined' || typeof URL.createObjectURL !== 'function') return '';
  return URL.createObjectURL(file);
}

function releasePreview(entry) {
  if (!entry?.previewUrl || typeof URL === 'undefined' || typeof URL.revokeObjectURL !== 'function') return;
  URL.revokeObjectURL(entry.previewUrl);
}

function clearAttachments() {
  attachmentFiles.value.forEach(releasePreview);
  attachmentFiles.value = [];
}

function closeForm() {
  showForm.value = false;
  clearAttachments();
  title.value = '';
  description.value = '';
  occurrenceAt.value = '';
  relatedReference.value = '';
  severity.value = 'medium';
  submitError.value = '';
  attachmentError.value = '';
}

function onPaste(event) {
  const files = extractImageFiles(event.clipboardData);
  if (!files.length) return;
  event.preventDefault();
  addAttachments(files, 'paste');
}

function hasFilesInTransfer(dataTransfer) {
  return Array.from(dataTransfer?.types || []).includes('Files')
    || Array.from(dataTransfer?.items || []).some((item) => item?.kind === 'file');
}

function onDragEnter(event) {
  if (!hasFilesInTransfer(event.dataTransfer)) return;
  attachmentDragDepth += 1;
  attachmentDragging.value = true;
}

function onDragOver(event) {
  if (hasFilesInTransfer(event.dataTransfer)) {
    event.dataTransfer.dropEffect = 'copy';
  }
}

function onDragLeave(event) {
  if (!hasFilesInTransfer(event.dataTransfer)) return;
  attachmentDragDepth = Math.max(0, attachmentDragDepth - 1);
  if (attachmentDragDepth === 0) attachmentDragging.value = false;
}

function onDrop(event) {
  attachmentDragDepth = 0;
  attachmentDragging.value = false;
  addAttachments(extractTransferFiles(event.dataTransfer), 'drop');
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
  clearAttachments();
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
      timeZone: Intl.DateTimeFormat().resolvedOptions().timeZone || null,
      occurrenceAt: occurrenceAt.value || null,
      relatedReference: relatedReference.value.trim() || null,
    });

    await submitBugReport({
      branch_id: Number(props.branchId),
      title: title.value.trim() || `[${props.currentPageKey || '未知頁面'}] ${new Date().toLocaleString('zh-TW')}`,
      description: description.value.trim(),
      severity: severity.value,
      page_key: props.currentPageKey,
      url: window.location.href,
      client_info: clientInfo,
      files: attachmentFiles.value.map((entry) => entry.file),
    });

    submitSuccess.value = true;
    title.value = '';
    description.value = '';
    occurrenceAt.value = '';
    relatedReference.value = '';
    severity.value = 'medium';
    clearAttachments();
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
  background: var(--primary); color: var(--ds-canvas); border: none;
  box-shadow: 0 4px 12px rgba(0,0,0,0.2);
  cursor: grab; touch-action: none;
  display: flex; align-items: center; justify-content: center;
  will-change: transform;
  transition: left 0.35s cubic-bezier(0.22, 1, 0.36, 1), top 0.35s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.2s;
}
.fab:hover { transform: scale(1.08); box-shadow: 0 6px 20px rgba(0,0,0,0.25); }
.fab.dragging { cursor: grabbing; transform: none; transition: none; }
.fab .material-symbols-outlined { font-size: 26px; }

.bug-report-form { min-width: 0; }

label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; margin-top: 12px; }
.required { color: var(--danger); }
.optional { color: var(--ds-ink-mute); font-size: 12px; font-weight: normal; }
.form-input, .form-select, .form-textarea {
  width: 100%; padding: 8px 12px; border: 1px solid var(--border);
  border-radius: 8px; font-size: 14px; font-family: inherit;
}
.form-textarea { resize: vertical; }
.description-hint {
  margin: 5px 0 0; color: var(--ds-ink-mute); font-size: 12px; line-height: 1.5;
}
.triage-context {
  margin-top: 12px; padding: 2px 12px 10px; border: 1px solid var(--border);
  border-radius: 8px; background: var(--ds-canvas-soft);
}
.triage-context label:first-child { margin-top: 8px; }

.sr-only {
  position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
  overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
}
.attachment-dropzone {
  display: grid; justify-items: center; gap: 4px; padding: 16px 12px; margin-top: 6px;
  border: 1px dashed var(--ds-primary); border-radius: 8px;
  background: var(--ds-canvas-soft); color: var(--ds-ink-mute);
  font-size: 13px; cursor: pointer; text-align: center;
}
.attachment-dropzone:hover,
.attachment-dropzone:focus-visible,
.attachment-dropzone.is-dragging {
  border-color: var(--ds-primary-deep); background: var(--ds-primary-wash); outline: none;
}
.attachment-dropzone .material-symbols-outlined { font-size: 24px; color: var(--ds-primary); }
.dropzone-link { color: var(--ds-primary-deep); font-weight: 600; }
.dropzone-or { color: var(--ds-ink-mute); }
.attachment-dropzone small { color: var(--ds-ink-mute); font-size: 12px; }
.attachment-error { margin-top: 6px; color: var(--danger); font-size: 13px; }
.attachment-count {
  margin-top: 6px; color: var(--ds-ink-mute); font-size: 12px;
  text-align: right; font-variant-numeric: tabular-nums;
}
.attachment-previews { margin-top: 6px; font-size: 13px; }
.att-row {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  padding: 4px 0; border-bottom: 1px solid var(--border);
}
.att-preview {
  width: 36px; height: 36px; object-fit: cover; flex-shrink: 0;
  border-radius: 6px; border: 1px solid var(--border);
}
.att-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--text-light); }
.att-remove {
  flex-shrink: 0; padding: 2px 8px; font-size: 12px; border: 1px solid var(--border);
  border-radius: 6px; background: var(--card-bg); cursor: pointer;
}

.context-info {
  display: flex; align-items: center; gap: 6px; margin-top: 14px;
  padding: 8px 12px; background: var(--ds-canvas-soft); border-radius: 8px;
  font-size: 13px; color: var(--text-light);
}
.context-info .material-symbols-outlined { font-size: 18px; }

.btn-cancel {
  padding: 8px 20px; border: 1px solid var(--border); border-radius: 8px;
  background: var(--card-bg); font-size: 14px; cursor: pointer;
}
.btn-submit {
  padding: 8px 20px; border: none; border-radius: 8px;
  background: var(--primary); color: var(--ds-canvas); font-size: 14px; cursor: pointer;
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
