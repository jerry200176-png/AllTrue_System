<script setup>
// One figure in a StatStrip: small label over a big tabular value, optional unit and one sub line.
// Phone: two per row; give a wide figure `tw:max-md:col-span-2`, hide a minor one with `tw:max-md:hidden`.
import { cn } from '@/lib/utils';
import { toneText } from './tones.js';

const props = defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], required: true },
  unit: { type: String, default: '' },
  sub: { type: String, default: '' },
  tone: { type: String, default: 'neutral' },
  class: { type: [Boolean, null, String, Object, Array], required: false, skipCheck: true },
});
</script>

<template>
  <div data-slot="stat-item" :class="cn('tw:flex tw:min-w-0 tw:flex-col tw:gap-0.5 tw:bg-card tw:px-4 tw:py-3 tw:md:px-5 tw:md:py-4', props.class)">
    <dt class="tw:text-tpl-caption tw:font-semibold tw:text-tpl-secondary">{{ props.label }}</dt>
    <dd class="tw:m-0 tw:text-tpl-unit tw:font-bold tw:tracking-tight tw:whitespace-nowrap tw:tabular-nums tw:md:text-tpl-page" :class="toneText[props.tone] || 'tw:text-foreground'">
      {{ props.value }}<span v-if="props.unit" class="tw:text-tpl-body tw:font-semibold tw:tracking-normal">{{ ` ${props.unit}` }}</span>
    </dd>
    <dd v-if="props.sub || $slots.sub" class="tw:m-0 tw:text-tpl-caption tw:text-tpl-secondary"><slot name="sub">{{ props.sub }}</slot></dd>
  </div>
</template>
