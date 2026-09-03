<script setup>
import { computed } from 'vue';
import { getDailyWorkProgress } from '../lib/dailyWorkProgress.js';

const props = defineProps({
  completed: { type: Number, default: 0 },
  total: { type: Number, default: 0 },
  nextTask: { type: Object, default: null },
  loading: { type: Boolean, default: false },
});

defineEmits(['next']);

const progress = computed(() => getDailyWorkProgress(props.completed, props.total));
const progressStyle = computed(() => ({ width: `${progress.value.percent}%` }));
const titleId = 'today-progress-card-title';
</script>

<template>
  <section class="today-progress-card" :aria-labelledby="titleId" data-testid="today-progress-card">
    <header class="today-progress-card__header">
      <div>
        <p class="today-progress-card__eyebrow">今日節奏</p>
        <h2 :id="titleId">今日課務進度</h2>
      </div>
      <span v-if="!loading && progress.hasWork" class="today-progress-card__percent">{{ progress.percent }}%</span>
    </header>

    <div v-if="loading" class="today-progress-card__loading" role="status" aria-live="polite">
      <span class="today-progress-card__skeleton today-progress-card__skeleton--value" aria-hidden="true"></span>
      <span class="today-progress-card__skeleton" aria-hidden="true"></span>
      <span class="today-progress-card__skeleton today-progress-card__skeleton--copy" aria-hidden="true"></span>
      載入今日課務進度…
    </div>

    <template v-else>
      <div v-if="progress.hasWork" class="today-progress-card__summary" role="status" aria-live="polite">
        <strong>{{ progress.completed }} / {{ progress.total }}</strong>
        <span>{{ progress.isComplete ? '今日課務已完成' : `還有 ${progress.remaining} 堂待完成` }}</span>
      </div>
      <p v-else class="today-progress-card__empty" role="status">今天沒有已排定的課務。</p>

      <div
        v-if="progress.hasWork"
        class="today-progress-card__track"
        role="progressbar"
        :aria-valuenow="progress.completed"
        aria-valuemin="0"
        :aria-valuemax="progress.total"
        :aria-label="`今日課務進度：${progress.completed} / ${progress.total} 堂`"
      >
        <span class="today-progress-card__fill" :style="progressStyle"></span>
      </div>
      <p v-if="progress.hasWork" class="today-progress-card__assistive">
        課務進度 {{ progress.completed }} / {{ progress.total }} 堂
      </p>

      <div v-if="nextTask" class="today-progress-card__next">
        <div class="today-progress-card__next-copy">
          <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
          <span>
            <strong>接著處理</strong>
            <span>{{ nextTask.title }}</span>
          </span>
        </div>
        <button
          type="button"
          class="today-progress-card__next-action"
          :aria-label="`接著處理：${nextTask.actionLabel}，${nextTask.title}`"
          @click="$emit('next', nextTask)"
        >
          {{ nextTask.actionLabel }}
          <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
        </button>
      </div>
      <p v-else-if="progress.isComplete" class="today-progress-card__complete" role="status">
        <span class="material-symbols-outlined" aria-hidden="true">task_alt</span>
        今天的課務節奏已完成，做得很好。
      </p>
    </template>
  </section>
</template>

<style scoped>
.today-progress-card { grid-column: 1 / -1; padding: 18px 20px 20px; border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-lg); background: var(--ds-canvas); box-shadow: var(--ds-shadow-1); }
.today-progress-card__header, .today-progress-card__summary, .today-progress-card__next, .today-progress-card__next-copy { display: flex; align-items: center; }
.today-progress-card__header, .today-progress-card__summary, .today-progress-card__next { justify-content: space-between; gap: 12px; }
.today-progress-card__eyebrow { margin: 0 0 3px; color: var(--ds-cta); font-size: var(--ds-font-size-xs); font-weight: var(--ds-font-weight-bold); letter-spacing: .08em; text-transform: uppercase; }
.today-progress-card h2 { margin: 0; color: var(--ds-ink); font-size: var(--ds-font-size-xl); font-weight: var(--ds-font-weight-bold); }
.today-progress-card__percent, .today-progress-card__summary strong { color: var(--ds-ink); font-variant-numeric: tabular-nums; font-weight: var(--ds-font-weight-bold); }
.today-progress-card__percent { font-size: 24px; }
.today-progress-card__summary { margin-top: 18px; }
.today-progress-card__summary strong { font-size: 22px; }
.today-progress-card__summary span, .today-progress-card__empty, .today-progress-card__complete { color: var(--ds-ink-mute); font-size: var(--ds-font-size-sm); }
.today-progress-card__empty { margin: 18px 0 0; }
.today-progress-card__track { height: 9px; margin-top: 10px; overflow: hidden; border: 0; border-radius: var(--ds-radius-pill); background: var(--ds-surface-2); }
.today-progress-card__track:focus-visible { outline: 3px solid var(--ds-focus-ring); outline-offset: 3px; }
.today-progress-card__fill { display: block; height: 100%; border-radius: inherit; background: var(--ds-cta); transition: width var(--ds-motion-base) var(--ds-ease-standard); }
.today-progress-card__assistive { position: absolute; width: 1px; height: 1px; padding: 0; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
.today-progress-card__next { margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--ds-hairline); }
.today-progress-card__next-copy { min-width: 0; gap: 8px; }
.today-progress-card__next-copy > .material-symbols-outlined { color: var(--ds-cta); font-size: 19px; }
.today-progress-card__next-copy > span:last-child { display: grid; gap: 2px; min-width: 0; }
.today-progress-card__next-copy strong { color: var(--ds-ink-mute); font-size: var(--ds-font-size-xs); }
.today-progress-card__next-copy span:last-child span { overflow: hidden; color: var(--ds-ink); font-size: var(--ds-font-size-sm); font-weight: var(--ds-font-weight-semibold); text-overflow: ellipsis; white-space: nowrap; }
.today-progress-card__next-action { display: inline-flex; flex-shrink: 0; align-items: center; gap: 4px; min-height: var(--ds-control-height-md); padding: 6px 10px; border: 1px solid var(--ds-cta); border-radius: var(--ds-radius-pill); background: var(--ds-canvas); color: var(--ds-cta); cursor: pointer; font-size: var(--ds-font-size-sm); font-weight: var(--ds-font-weight-semibold); }
.today-progress-card__next-action:hover, .today-progress-card__next-action:focus-visible { background: var(--ds-primary-wash); }
.today-progress-card__next-action .material-symbols-outlined { font-size: 16px; }
.today-progress-card__complete { display: flex; align-items: center; gap: 6px; margin: 18px 0 0; }
.today-progress-card__complete .material-symbols-outlined { color: var(--ds-success); font-size: 18px; }
.today-progress-card__loading { display: grid; grid-template-columns: auto 1fr; gap: 8px 10px; align-items: center; margin-top: 18px; color: var(--ds-ink-mute); font-size: var(--ds-font-size-sm); }
.today-progress-card__skeleton { display: block; width: 100%; height: 9px; border-radius: var(--ds-radius-pill); background: var(--ds-surface-2); animation: today-progress-pulse 1.2s ease-in-out infinite; }
.today-progress-card__skeleton--value { width: 96px; height: 22px; }
.today-progress-card__skeleton--copy { grid-column: 1 / -1; width: 65%; }
@keyframes today-progress-pulse { 50% { opacity: .45; } }
@media (max-width: 560px) { .today-progress-card { padding: 16px; } .today-progress-card__next { align-items: flex-start; flex-direction: column; } .today-progress-card__next-action { width: 100%; justify-content: center; } }
@media (prefers-reduced-motion: reduce) { .today-progress-card__fill, .today-progress-card__skeleton { animation: none; transition: none; } }
</style>
