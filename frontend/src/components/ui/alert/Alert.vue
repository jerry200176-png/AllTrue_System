<script setup>
import { cn } from "@/lib/utils";

// shadcn-vue Alert shape (role=alert, title + description) with a `tone` and `title` shortcut.
// Info/success/warning use a soft wash and ink text (AA); red is only for `danger`.
const props = defineProps({
  tone: { type: String, default: "info", validator: (v) => ["info", "success", "warning", "danger"].includes(v) },
  title: { type: String, default: "" },
  class: { type: [Boolean, null, String, Object, Array], required: false, skipCheck: true },
});
const TONES = {
  info: "tw:border-border tw:bg-secondary",
  success: "tw:border-[var(--ds-success)] tw:bg-[var(--ds-success-wash)]",
  warning: "tw:border-[var(--ds-warning)] tw:bg-[var(--ds-warning-wash)]",
  danger: "tw:border-destructive tw:bg-destructive/10",
};
</script>

<template>
  <div
    :role="props.tone === 'warning' || props.tone === 'danger' ? 'alert' : 'status'"
    data-slot="alert"
    :class="cn('tw:relative tw:w-full tw:rounded-xl tw:border tw:px-4 tw:py-3 tw:text-foreground', TONES[props.tone], props.class)"
  >
    <p v-if="title" data-slot="alert-title" class="tw:font-semibold">{{ title }}</p>
    <div data-slot="alert-description" class="tw:text-sm"><slot /></div>
  </div>
</template>
