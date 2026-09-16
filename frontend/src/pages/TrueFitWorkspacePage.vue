<template>
  <div class="tf-workspace-page">
    <AtPageHeader
      title="今日課程"
      description="依時間排序的今日堂次；點選「準備課程」進入備課占位頁。"
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
import { fetchTrueFitTodaySessions } from '../lib/truefitApi.js';

const props = defineProps({
  token: { type: String, required: true },
  branchId: { type: [Number, String, null], default: null },
});

defineEmits(['prepare', 'observe', 'diagnose', 'remediate']);

const loading = ref(false);
const error = ref('');
const sessions = ref([]);
const metaDate = ref('');

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
  } catch (e) {
    sessions.value = [];
    error.value = e?.message || '載入失敗';
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
