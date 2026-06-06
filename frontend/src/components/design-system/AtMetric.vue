<script setup>
import { computed } from 'vue';

const props = defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], required: true },
  delta: { type: String, default: '' },
  // delta 語意：positive 綠 / negative 紅 / neutral 灰（不影響漲跌方向判斷，由呼叫端決定）
  deltaTone: {
    type: String,
    default: 'neutral',
    validator: (v) => ['positive', 'negative', 'neutral'].includes(v),
  },
  accent: { type: String, default: '' },
});

const accentStyle = computed(() =>
  props.accent ? { borderBottomColor: props.accent } : {}
);
</script>

<template>
  <div class="at-metric" :class="{ 'at-metric--accent': accent }" :style="accentStyle">
    <span class="at-metric__label">{{ label }}</span>
    <span class="at-metric__value">{{ value }}</span>
    <span v-if="delta" :class="['at-metric__delta', `at-metric__delta--${deltaTone}`]">{{ delta }}</span>
  </div>
</template>

<style scoped>
.at-metric {
  display: flex;
  flex-direction: column;
  gap: 4px;
  background: var(--ds-canvas);
  border: 1px solid var(--ds-hairline);
  border-radius: 12px;
  padding: 16px 18px;
}

.at-metric--accent {
  border-bottom-width: 3px;
  border-bottom-style: solid;
}

.at-metric__label {
  font-size: 12px;
  font-weight: 500;
  color: var(--ds-ink-mute);
  text-transform: uppercase;
  letter-spacing: 0.02em;
}

.at-metric__value {
  font-size: 28px;
  font-weight: 700;
  color: var(--ds-ink);
  font-variant-numeric: tabular-nums;
  letter-spacing: -0.01em;
  line-height: 1.1;
}

.at-metric__delta {
  font-size: 12.5px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.at-metric__delta--positive {
  color: var(--ds-success);
}
.at-metric__delta--negative {
  color: var(--ds-danger);
}
.at-metric__delta--neutral {
  color: var(--ds-ink-mute);
}
</style>
