<template>
  <div class="score-cell">
    <p v-if="!editable" class="score-cell__ro">
      <strong>{{ isAbsent ? '缺考' : (score ?? '—') }}</strong>
      <small>已截止，請找主任更正</small>
    </p>
    <template v-else>
      <input
        class="score-cell__input exam-score-input"
        type="number"
        min="0"
        :max="max"
        step="0.5"
        :disabled="isAbsent || cleared"
        :value="cleared ? '' : (score ?? '')"
        :aria-label="`${label} 分數`"
        @change="emit('change', { score: $event.target.value === '' ? null : Number($event.target.value), is_absent: false })"
      />
      <label class="score-cell__absent">
        <input type="checkbox" :checked="isAbsent" :disabled="cleared" @change="emit('change', { score: null, is_absent: $event.target.checked })" />
        缺考
      </label>
      <button type="button" class="score-cell__btn" :disabled="cleared || (score == null && !isAbsent)" @click="emit('clear')">清除</button>
    </template>
    <button v-if="edited" type="button" class="score-cell__edited" @click="emit('history')">改過</button>
  </div>
</template>

<script setup>
defineProps({
  score: { type: Number, default: null },
  isAbsent: { type: Boolean, default: false },
  cleared: { type: Boolean, default: false },
  max: { type: Number, default: 100 },
  editable: { type: Boolean, default: true },
  edited: { type: Boolean, default: false },
  label: { type: String, default: '' },
});
const emit = defineEmits(['change', 'clear', 'history']);
</script>

<style scoped>
.score-cell { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; min-width: 9rem; }
.score-cell__input { width: 5rem; min-height: 44px; font-size: var(--ds-font-size-lg); padding: 6px 8px; }
.score-cell__absent { display: inline-flex; align-items: center; gap: 4px; margin: 0; min-height: 44px; font-size: var(--ds-font-size-lg); }
.score-cell__absent input { width: 20px; height: 20px; margin: 0; display: inline; }
.score-cell__btn, .score-cell__edited {
  min-height: 44px; min-width: 44px; padding: 0 12px; font-size: var(--ds-font-size-lg); border-radius: var(--ds-radius-pill); cursor: pointer;
  border: 1px solid var(--ds-ink); background: transparent; color: var(--ds-ink);
}
.score-cell__edited { border-color: var(--ds-brand-amber); color: var(--ds-ink); }
.score-cell__btn:disabled { opacity: 0.4; cursor: not-allowed; }
.score-cell__ro { margin: 0; font-size: var(--ds-font-size-lg); display: grid; }
.score-cell__ro small { font-size: var(--ds-font-size-lg); color: var(--text-light); }
</style>
