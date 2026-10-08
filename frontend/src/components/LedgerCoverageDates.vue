<template>
  <div class="coverage" :data-testid="`ledger-coverage-${coverageKey}`" aria-live="polite">
    <div class="coverage__head">
      <strong>涵蓋上課日</strong>
      <span v-if="entry?.state === 'ready'" class="coverage__count">共 {{ preview.total }} 堂</span>
    </div>
    <p v-if="!entry || entry.state === 'loading'" class="coverage__muted">上課日載入中…</p>
    <p v-else-if="entry.state === 'error'" class="coverage__muted">
      上課日暫時無法載入，金額不受影響。
      <button
        class="coverage__link"
        type="button"
        :data-testid="`ledger-coverage-retry-${coverageKey}`"
        @click="$emit('retry')"
      >重試</button>
    </p>
    <p v-else-if="!preview.total" class="coverage__muted">目前沒有可列出的上課日。</p>
    <template v-else>
      <ol class="coverage__list">
        <li
          v-for="s in preview.shown"
          :key="s.key"
          :class="['coverage__date', `coverage__date--${s.tone}`]"
          data-testid="ledger-coverage-date"
        >
          {{ s.label }}<em v-if="s.status_label">{{ s.status_label }}</em>
        </li>
      </ol>
      <button
        v-if="preview.hidden > 0 || entry.showAll"
        class="coverage__link"
        type="button"
        :data-testid="`ledger-coverage-more-${coverageKey}`"
        :aria-expanded="!!entry.showAll"
        @click="$emit('toggle-all')"
      >{{ entry.showAll ? '收合' : `尚有 ${preview.hidden} 堂` }}</button>
    </template>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { coveragePreview } from '../lib/ledgerCoverageDates.js';

// entry: { state: 'loading' | 'ready' | 'error', sessions, showAll }
const props = defineProps({ coverageKey: { type: String, required: true }, entry: { type: Object, default: null } });
defineEmits(['retry', 'toggle-all']);

const preview = computed(() => coveragePreview(props.entry?.sessions, !!props.entry?.showAll));
</script>

<style scoped>
.coverage{padding:8px 0 0}
.coverage__head{display:flex;align-items:baseline;gap:8px;font-size:13px}
.coverage__count{font-size:12px;color:var(--text-light,var(--ds-ink-mute));font-variant-numeric:tabular-nums}
.coverage__muted{margin:6px 0 0;font-size:12px;color:var(--text-light,var(--ds-ink-mute))}
.coverage__list{list-style:none;margin:6px 0 0;padding:0;display:flex;flex-wrap:wrap;gap:6px}
.coverage__date{display:inline-flex;align-items:center;gap:4px;border-radius:6px;padding:2px 8px;font-size:12px;font-variant-numeric:tabular-nums;background:var(--ds-canvas-soft);color:var(--ds-ink)}
.coverage__date em{font-style:normal;font-size:12px;font-weight:700;color:var(--ds-ink-mute)}
.coverage__date--done em{color:var(--ds-success)}
.coverage__date--warn em{color:var(--ds-warning)}
.coverage__date--leave em{color:var(--ds-ink-mute)}
.coverage__link{margin-top:6px;border:0;background:transparent;color:var(--ds-primary-text);font-size:12px;font-weight:600;cursor:pointer;padding:0}
</style>
