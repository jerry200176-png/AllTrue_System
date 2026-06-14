<template>
  <section class="stp card" :class="{ compact: compact }">
    <header class="stp-head">
      <div>
        <span class="stp-kicker">System Trust</span>
        <h3>系統穩定與更新狀態</h3>
      </div>
      <button v-if="!parentMode" class="ghost xs" type="button" @click="$emit('report-problem')">回報問題</button>
    </header>

    <div v-if="loading" class="stp-empty">載入中…</div>
    <div v-else-if="error" class="stp-empty stp-error">{{ error }}</div>
    <template v-else-if="summary">
      <div class="stp-snapshot">
        <div class="stp-chip">
          <span class="stp-chip-label">健康狀態</span>
          <strong :class="summary.reliability_snapshot?.health_status === 'ok' ? 'ok' : 'warn'">
            {{ summary.reliability_snapshot?.health_status === 'ok' ? '正常' : '注意' }}
          </strong>
        </div>
        <div class="stp-chip">
          <span class="stp-chip-label">高優先缺陷</span>
          <strong>{{ summary.reliability_snapshot?.high_priority_open_bugs ?? 0 }}</strong>
        </div>
        <div class="stp-chip">
          <span class="stp-chip-label">待審評量</span>
          <strong>{{ summary.reliability_snapshot?.pending_learning_reviews ?? 0 }}</strong>
        </div>
      </div>

      <div class="stp-block">
        <div class="stp-block-title">最近改善（30 天）</div>
        <ul class="stp-list">
          <li v-for="item in (summary.recent_improvements || []).slice(0, compact ? 2 : 4)" :key="`${item.date}-${item.title}`">
            <strong>{{ item.title }}</strong>
            <small>{{ item.date }}</small>
            <p>{{ item.summary }}</p>
          </li>
        </ul>
      </div>

      <div class="stp-block" v-if="(summary.known_issues || []).length > 0">
        <div class="stp-block-title">已知問題與暫行作法</div>
        <ul class="stp-list stp-list-issues">
          <li v-for="issue in (summary.known_issues || []).slice(0, compact ? 1 : 3)" :key="issue.id">
            <strong>{{ issue.title }}</strong>
            <small>{{ issue.status }} · {{ issue.updated_at }}</small>
            <p>{{ issue.workaround }}</p>
          </li>
        </ul>
      </div>
    </template>
    <div v-else class="stp-empty">目前暫無狀態資料</div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps({
  branchId: { type: [String, Number], default: null },
  token: { type: String, default: '' },
  parentMode: { type: Boolean, default: false },
  compact: { type: Boolean, default: false },
});

defineEmits(['report-problem']);

const loading = ref(false);
const error = ref('');
const summary = ref(null);

const endpoint = computed(() => {
  if (props.parentMode) {
    return '/api/v1/parent/system-trust-summary';
  }
  if (!props.branchId) return '';
  return `/api/v1/system/trust-summary?branch_id=${encodeURIComponent(String(props.branchId))}`;
});

async function loadSummary() {
  if (!endpoint.value) {
    summary.value = null;
    return;
  }
  loading.value = true;
  error.value = '';
  try {
    const headers = { Accept: 'application/json' };
    if (props.token) headers.Authorization = `Bearer ${props.token}`;
    const res = await fetch(endpoint.value, { headers });
    if (!res.ok) {
      error.value = `狀態載入失敗（${res.status}）`;
      summary.value = null;
      return;
    }
    const json = await res.json().catch(() => ({}));
    summary.value = json?.data || null;
  } catch (e) {
    error.value = e?.message || '狀態載入失敗';
    summary.value = null;
  } finally {
    loading.value = false;
  }
}

watch(() => [props.branchId, props.token, props.parentMode], loadSummary);
onMounted(loadSummary);
</script>

<style scoped>
.stp { border: 1px solid var(--border, var(--ds-canvas-soft)); }
.stp.compact { padding: 14px; }
.stp-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 10px; }
.stp-kicker { font-size: 11px; color: var(--ds-ink-mute); text-transform: uppercase; letter-spacing: 0.08em; }
.stp-head h3 { margin: 2px 0 0; font-size: 16px; color: var(--ds-ink); }
.stp-empty { font-size: 13px; color: var(--ds-ink-mute); padding: 8px 0; }
.stp-error { color: var(--ds-danger); }
.stp-snapshot { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-bottom: 10px; }
.stp-chip { border: 1px solid var(--ds-canvas-soft); border-radius: 10px; padding: 8px; background: var(--ds-canvas-soft); }
.stp-chip-label { display: block; font-size: 11px; color: var(--ds-ink-mute); margin-bottom: 4px; }
.stp-chip strong { font-size: 15px; color: var(--ds-ink); }
.stp-chip strong.ok { color: var(--ds-success); }
.stp-chip strong.warn { color: var(--ds-warning); }
.stp-block { margin-top: 8px; }
.stp-block-title { font-size: 12px; font-weight: 700; color: var(--ds-ink); margin-bottom: 6px; }
.stp-list { margin: 0; padding-left: 18px; }
.stp-list li { margin-bottom: 8px; }
.stp-list strong { display: block; font-size: 13px; color: var(--ds-ink); }
.stp-list small { display: block; font-size: 11px; color: var(--ds-ink-mute); margin: 2px 0; }
.stp-list p { margin: 0; font-size: 12px; color: var(--ds-ink); line-height: 1.45; }

@media (max-width: 720px) {
  .stp-snapshot { grid-template-columns: 1fr; }
}
</style>
