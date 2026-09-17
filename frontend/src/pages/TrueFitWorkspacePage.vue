<template>
  <div class="tf-workspace-page">
    <AtPageHeader
      title="今日課程"
      description="同一堂次依序：備課 → 觀察 → 診斷 → 補救 → 精熟；也可從清單直接進入任一步。"
      icon="today"
    >
      <template #actions>
        <AtButton variant="ghost" shape="rect" icon="refresh" :loading="loading" @click="loadSessions">重新整理</AtButton>
      </template>
      <template #meta>
        <AtBadge tone="info">{{ metaDateLabel }}</AtBadge>
      </template>
    </AtPageHeader>

    <AtInlineAlert v-if="error" tone="danger" role="alert">{{ error }}</AtInlineAlert>
    <AtInlineAlert
      v-else-if="progressState === 'error' && progressError"
      tone="warning"
      role="status"
      data-testid="truefit-progress-error"
    >
      {{ progressError }}（已儲存階段不會被標成未存）
    </AtInlineAlert>

    <div v-if="loading && !sessions.length" class="tf-loading">
      <AtSkeleton v-for="n in 3" :key="n" :rows="1" height="88px" />
    </div>

    <AtEmpty
      v-else-if="!loading && !sessions.length && !error"
      icon="event_available"
      title="今天沒有待備課的堂次"
      description="若您預期應有課程，請確認課表或稍後重新整理。"
    />

    <ul v-else class="tf-session-list" aria-label="今日課程清單">
      <li v-for="session in sessions" :key="sessionListKey(session)" class="tf-session-card">
        <div class="tf-session-card__main">
          <p class="tf-session-card__time">
            <span class="material-symbols-outlined" aria-hidden="true">schedule</span>
            {{ formatTimeRange(session) }}
          </p>
          <h3 class="tf-session-card__student">{{ session.student_name || '學生' }}</h3>
          <p class="tf-session-card__subject">{{ session.subject_name || '科目待確認' }}</p>
          <p v-if="session.campus_name" class="tf-session-card__campus">{{ session.campus_name }}</p>
          <div
            class="tf-progress"
            data-testid="truefit-session-progress"
            :aria-busy="progressState === 'loading' ? 'true' : 'false'"
          >
            <p v-if="progressState === 'loading'" class="tf-progress__status">
              進度載入中…
            </p>
            <p
              v-else-if="progressState === 'error' && !progressFor(session)"
              class="tf-progress__status tf-progress__status--muted"
            >
              進度暫不可用
            </p>
            <ol
              v-else-if="progressFor(session)"
              class="tf-progress__strip"
              aria-label="堂次學習階段進度"
            >
              <li
                v-for="stage in PROGRESS_STAGES"
                :key="stage.key"
                class="tf-progress__stage"
                :data-stage="stage.key"
                :data-saved="progressFor(session)[stage.key] ? '1' : '0'"
              >
                <span class="tf-progress__dot" aria-hidden="true" />
                <span class="tf-progress__label">{{ stage.label }}</span>
                <span class="tf-progress__state">{{ progressFor(session)[stage.key] ? '已存' : '未存' }}</span>
              </li>
            </ol>
          </div>
        </div>
        <div class="tf-session-card__actions">
          <AtButton
            variant="primary"
            shape="rect"
            icon="menu_book"
            class="tf-session-card__cta"
            @click="$emit('prepare', session)"
          >
            準備課程
          </AtButton>
          <AtButton
            variant="ghost"
            shape="rect"
            icon="visibility"
            class="tf-session-card__cta"
            @click="$emit('observe', session)"
          >
            課堂觀察
          </AtButton>
          <AtButton
            variant="ghost"
            shape="rect"
            icon="psychology"
            class="tf-session-card__cta"
            @click="$emit('diagnose', session)"
          >
            錯誤診斷
          </AtButton>
          <AtButton
            variant="ghost"
            shape="rect"
            icon="healing"
            class="tf-session-card__cta"
            @click="$emit('remediate', session)"
          >
            補救計畫
          </AtButton>
          <AtButton
            variant="ghost"
            shape="rect"
            icon="verified"
            class="tf-session-card__cta"
            @click="$emit('mastery', session)"
          >
            精熟檢核
          </AtButton>
        </div>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';
import AtButton from '../components/design-system/AtButton.vue';
import AtBadge from '../components/design-system/AtBadge.vue';
import AtInlineAlert from '../components/design-system/AtInlineAlert.vue';
import AtEmpty from '../components/design-system/AtEmpty.vue';
import AtSkeleton from '../components/design-system/AtSkeleton.vue';
import {
  fetchTrueFitTodaySessions,
  fetchTrueFitSessionProgress,
  trueFitSessionProgressRef,
  trueFitProgressLookupKey,
} from '../lib/truefitApi.js';

const PROGRESS_STAGES = [
  { key: 'prep', label: '備課' },
  { key: 'observation', label: '觀察' },
  { key: 'diagnosis', label: '診斷' },
  { key: 'remediation', label: '補救' },
  { key: 'mastery', label: '精熟' },
];

const EMPTY_PROGRESS = Object.freeze({
  prep: false,
  observation: false,
  diagnosis: false,
  remediation: false,
  mastery: false,
});

const props = defineProps({
  token: { type: String, required: true },
  branchId: { type: [Number, String, null], default: null },
});

defineEmits(['prepare', 'observe', 'diagnose', 'remediate', 'mastery']);

const loading = ref(false);
const error = ref('');
const sessions = ref([]);
const metaDate = ref('');
/** @type {import('vue').Ref<'idle'|'loading'|'ready'|'error'>} */
const progressState = ref('idle');
const progressError = ref('');
/** @type {import('vue').Ref<Record<string, typeof EMPTY_PROGRESS>>} */
const progressByKey = ref({});

const metaDateLabel = computed(() => (
  metaDate.value ? `日期：${metaDate.value}` : '今日'
));

function formatTimeRange(session) {
  const start = String(session?.start_time || '').slice(0, 5);
  const end = String(session?.end_time || '').slice(0, 5);
  if (start && end) return `${start} – ${end}`;
  return start || '時間待確認';
}

function sessionListKey(session) {
  if (session?.class_session_id) return `s-${session.class_session_id}`;
  const classId = session?.student_class_id || 0;
  const start = String(session?.start_time || '').slice(0, 5);
  return `p-${classId}-${start}`;
}

function progressFor(session) {
  const key = trueFitProgressLookupKey(session);
  if (!key) return null;
  if (progressState.value === 'loading') return null;
  if (Object.prototype.hasOwnProperty.call(progressByKey.value, key)) {
    return progressByKey.value[key];
  }
  // Successful aggregate response: accessible session with no row ⇒ all empty.
  if (progressState.value === 'ready') {
    return EMPTY_PROGRESS;
  }
  // Error / idle without a prior row: do not masquerade as empty.
  return null;
}

async function loadProgress(list) {
  const refs = (Array.isArray(list) ? list : [])
    .map((row) => trueFitSessionProgressRef(row))
    .filter(Boolean)
    .slice(0, 40);

  if (!refs.length) {
    progressByKey.value = {};
    progressState.value = 'ready';
    progressError.value = '';
    return;
  }

  progressState.value = 'loading';
  progressError.value = '';
  try {
    const payload = await fetchTrueFitSessionProgress({
      token: props.token,
      sessions: refs,
    });
    const next = {};
    for (const row of (payload?.data || [])) {
      const key = trueFitProgressLookupKey(row?.session_ref);
      if (!key || !row?.progress || typeof row.progress !== 'object') continue;
      next[key] = {
        prep: !!row.progress.prep,
        observation: !!row.progress.observation,
        diagnosis: !!row.progress.diagnosis,
        remediation: !!row.progress.remediation,
        mastery: !!row.progress.mastery,
      };
    }
    // Only replace on success — failures keep prior map.
    progressByKey.value = next;
    progressState.value = 'ready';
  } catch (e) {
    progressState.value = 'error';
    progressError.value = e?.message || '堂次進度載入失敗';
  }
}

async function loadSessions() {
  loading.value = true;
  error.value = '';
  try {
    const payload = await fetchTrueFitTodaySessions({
      token: props.token,
      branchId: props.branchId,
    });
    sessions.value = Array.isArray(payload?.data) ? payload.data : [];
    metaDate.value = payload?.meta?.date || '';
    await loadProgress(sessions.value);
  } catch (e) {
    sessions.value = [];
    error.value = e?.message || '載入失敗';
    progressState.value = 'idle';
  } finally {
    loading.value = false;
  }
}

onMounted(loadSessions);
watch(() => [props.token, props.branchId], loadSessions);
</script>

<style scoped>
.tf-workspace-page {
  max-width: 720px;
  margin: 0 auto;
}

.tf-loading {
  display: grid;
  gap: var(--ds-space-3);
}

.tf-session-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: var(--ds-space-3);
}

.tf-session-card {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--ds-space-3);
  padding: var(--ds-space-4);
  border: 1px solid var(--ds-hairline);
  border-radius: var(--ds-radius-md);
  background: var(--ds-surface-0);
  box-shadow: var(--ds-shadow-sm);
}

.tf-session-card__main {
  min-width: 0;
  flex: 1 1 12rem;
}

.tf-session-card__time {
  display: flex;
  align-items: center;
  gap: var(--ds-space-1);
  margin: 0 0 var(--ds-space-2);
  font-size: var(--ds-font-size-sm);
  color: var(--ds-text-secondary);
  font-weight: var(--ds-font-weight-semibold);
}

.tf-session-card__time .material-symbols-outlined {
  font-size: 18px;
}

.tf-session-card__student {
  margin: 0;
  font-size: var(--ds-font-size-lg);
  color: var(--ds-text-primary);
}

.tf-session-card__subject {
  margin: var(--ds-space-1) 0 0;
  color: var(--ds-text-secondary);
}

.tf-session-card__campus {
  margin: var(--ds-space-1) 0 0;
  font-size: var(--ds-font-size-sm);
  color: var(--ds-text-tertiary);
}

.tf-progress {
  margin-top: var(--ds-space-3);
}

.tf-progress__status {
  margin: 0;
  font-size: var(--ds-font-size-xs, 0.75rem);
  color: var(--ds-text-secondary);
}

.tf-progress__status--muted {
  color: var(--ds-text-tertiary);
}

.tf-progress__strip {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: var(--ds-space-2);
}

.tf-progress__stage {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  font-size: var(--ds-font-size-xs, 0.75rem);
  color: var(--ds-text-tertiary);
  line-height: 1.2;
}

.tf-progress__dot {
  width: 0.4rem;
  height: 0.4rem;
  border-radius: 50%;
  background: var(--ds-text-tertiary);
  opacity: 0.45;
}

.tf-progress__stage[data-saved='1'] {
  color: var(--ds-text-secondary);
  font-weight: var(--ds-font-weight-semibold, 600);
}

.tf-progress__stage[data-saved='1'] .tf-progress__dot {
  background: var(--ds-color-success, #2f6f4e);
  opacity: 1;
}

.tf-progress__state {
  opacity: 0.85;
}

.tf-session-card__actions {
  display: flex;
  flex-direction: column;
  gap: var(--ds-space-2);
  align-self: center;
  flex-shrink: 0;
}

.tf-session-card__cta {
  flex-shrink: 0;
}

@media (max-width: 560px) {
  .tf-session-card {
    flex-direction: column;
    align-items: stretch;
  }

  .tf-session-card__actions {
    width: 100%;
  }

  .tf-session-card__cta {
    width: 100%;
  }
}
</style>
