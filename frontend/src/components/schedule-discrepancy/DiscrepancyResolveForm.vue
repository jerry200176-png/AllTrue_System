<script setup>
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

// 課表回報管理 (#4989): the 處理說明 form, shared by the desktop detail row and the mobile card.
// Markup only: the draft, templates, busy flag and handlers stay in ScheduleDiscrepancyPage.
defineProps({
  rowId: { type: [Number, String], required: true },
  draft: { type: String, default: '' },
  templates: { type: Array, default: () => [] },
  canResolve: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  submitting: { type: Boolean, default: false },
  error: { type: String, default: '' },
  compact: { type: Boolean, default: false },
  idPrefix: { type: String, default: 'sdp' },
});
defineEmits(['update:draft', 'template', 'resolve']);

const btn = 'tw:h-12 tw:px-4 tw:text-[length:var(--tpl-text-body)] tw:font-semibold';
</script>

<template>
  <div class="dsc-resolve-form tw:flex tw:flex-col tw:gap-3">
    <label class="dsc-detail-label tw:text-tpl-caption tw:font-semibold tw:text-tpl-secondary" :for="`${idPrefix}-note-${rowId}`">
      處理說明 <span class="dsc-required tw:text-destructive" aria-hidden="true">*</span>
      <span v-if="!compact" class="dsc-note-hint tw:font-normal">（至少 10 字）</span>
    </label>
    <Textarea
      :id="`${idPrefix}-note-${rowId}`"
      class="dsc-textarea tw:min-h-24 tw:text-[length:var(--tpl-text-body)] tw:md:text-[length:var(--tpl-text-body)]"
      rows="3"
      maxlength="500"
      :model-value="draft"
      :placeholder="compact ? '至少 10 字' : '請簡述系統已補登 / 已修正哪些資料…'"
      @update:model-value="$emit('update:draft', $event)"
    />
    <div class="dsc-template-row tw:flex tw:flex-wrap tw:gap-2">
      <Button
        v-for="tpl in templates"
        :key="`${rowId}-${tpl.key}`"
        type="button"
        variant="outline"
        :class="btn"
        @click="$emit('template', tpl.key)"
      >
        {{ tpl.label }}
      </Button>
    </div>
    <div class="dsc-resolve-actions tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3">
      <span
        class="dsc-counter tw:text-tpl-caption tw:tabular-nums"
        :class="(draft || '').trim().length >= 10 ? 'dsc-counter-ok tw:text-[color:var(--ds-success-text)]' : 'tw:text-tpl-secondary'"
      >
        {{ (draft || '').length }} / 500
      </span>
      <Button type="button" :class="btn" :disabled="!canResolve || busy" @click="$emit('resolve')">
        {{ submitting ? (compact ? '…' : '送出中…') : '標記已修正' }}
      </Button>
    </div>
    <p v-if="error" class="dsc-error tw:text-tpl-body tw:text-[color:var(--ds-danger-text)]" role="alert">{{ error }}</p>
  </div>
</template>
