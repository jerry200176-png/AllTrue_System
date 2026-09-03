<template>
  <section
    class="operations-quick-start"
    :class="{ 'operations-quick-start--compact': compact }"
    :aria-labelledby="headingId"
    data-testid="operations-quick-start"
  >
    <header class="operations-quick-start__header">
      <div>
        <p v-if="eyebrow" class="operations-quick-start__eyebrow">{{ eyebrow }}</p>
        <h2 :id="headingId">{{ heading }}</h2>
        <p v-if="description" class="operations-quick-start__description">{{ description }}</p>
      </div>
    </header>
    <div class="operations-quick-start__steps" role="list">
      <button
        v-for="(step, index) in steps"
        :key="step.id"
        type="button"
        class="operations-quick-start__step"
        :class="{ 'is-current': currentId && currentId === step.id }"
        :aria-current="currentId && currentId === step.id ? 'step' : undefined"
        :aria-label="`${step.title}：${step.description || step.action || ''}`"
        @click="$emit('select', step.id)"
      >
        <span class="operations-quick-start__number" aria-hidden="true">{{ String(index + 1).padStart(2, '0') }}</span>
        <span class="material-symbols-outlined operations-quick-start__icon" aria-hidden="true">{{ step.icon || 'arrow_forward' }}</span>
        <span class="operations-quick-start__copy">
          <strong>{{ step.title }}</strong>
          <span v-if="step.description">{{ step.description }}</span>
        </span>
        <span class="operations-quick-start__action">{{ step.action || '開始' }} <span aria-hidden="true">→</span></span>
      </button>
    </div>
  </section>
</template>

<script setup>
defineProps({
  steps: { type: Array, default: () => [] },
  heading: { type: String, default: '常用流程' },
  eyebrow: { type: String, default: '' },
  description: { type: String, default: '' },
  currentId: { type: String, default: '' },
  compact: { type: Boolean, default: false },
});

defineEmits(['select']);

const headingId = `operations-quick-start-${Math.random().toString(36).slice(2, 9)}`;
</script>

<style scoped>
.operations-quick-start {
  margin-top: 24px;
  padding: 18px 20px 20px;
  border: 1px solid var(--ds-hairline);
  border-radius: var(--ds-radius-lg, 12px);
  background: var(--ds-canvas);
  box-shadow: var(--ds-shadow-1);
}
.operations-quick-start--compact {
  margin-top: 18px;
  padding: 14px 16px 16px;
  background: var(--ds-canvas-soft);
  box-shadow: none;
}
.operations-quick-start__header { margin-bottom: 13px; }
.operations-quick-start__eyebrow {
  margin: 0 0 3px;
  color: var(--ds-cta);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: .08em;
  text-transform: uppercase;
}
.operations-quick-start h2 {
  margin: 0;
  color: var(--ds-ink);
  font-size: 16px;
  font-weight: 850;
}
.operations-quick-start__description {
  margin: 4px 0 0;
  color: var(--ds-ink-mute);
  font-size: 12px;
  line-height: 1.5;
}
.operations-quick-start__steps {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}
.operations-quick-start__step {
  display: grid;
  grid-template-columns: auto auto minmax(0, 1fr);
  grid-template-areas:
    'number icon copy'
    'number icon action';
  gap: 3px 9px;
  min-width: 0;
  min-height: 84px;
  padding: 13px 13px 12px;
  border: 1px solid var(--ds-hairline);
  border-radius: var(--ds-radius-md, 9px);
  background: var(--ds-canvas-soft);
  color: var(--ds-ink);
  text-align: left;
  cursor: pointer;
  transition: border-color 150ms ease, background-color 150ms ease, box-shadow 150ms ease;
}
.operations-quick-start__step:hover,
.operations-quick-start__step:focus-visible,
.operations-quick-start__step.is-current {
  border-color: var(--ds-cta);
  background: var(--ds-canvas);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--ds-cta) 14%, transparent);
}
.operations-quick-start__number {
  grid-area: number;
  align-self: start;
  color: var(--ds-ink-mute);
  font-size: 11px;
  font-variant-numeric: tabular-nums;
  font-weight: 800;
}
.operations-quick-start__icon {
  grid-area: icon;
  color: var(--ds-cta);
  font-size: 19px;
}
.operations-quick-start__copy {
  display: grid;
  min-width: 0;
  gap: 3px;
}
.operations-quick-start__copy strong {
  overflow: hidden;
  font-size: 13px;
  font-weight: 800;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.operations-quick-start__copy span {
  color: var(--ds-ink-secondary);
  font-size: 11px;
  line-height: 1.45;
}
.operations-quick-start__action {
  grid-area: action;
  color: var(--ds-cta);
  font-size: 11px;
  font-weight: 800;
}
@media (prefers-reduced-motion: reduce) {
  .operations-quick-start__step { transition: none; }
}
@media (max-width: 720px) {
  .operations-quick-start { margin-top: 16px; padding: 15px 13px; }
  .operations-quick-start__steps { grid-template-columns: 1fr; gap: 8px; }
  .operations-quick-start__step { grid-template-columns: auto auto minmax(0, 1fr) auto; grid-template-areas: 'number icon copy action'; align-items: center; padding: 11px 10px; }
  .operations-quick-start__copy span { display: none; }
  .operations-quick-start__action { white-space: nowrap; }
}
</style>
