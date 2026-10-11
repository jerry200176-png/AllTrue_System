<template>
  <nav class="hub-tabs" :aria-label="`${title}分頁`" @keydown="onKey">
    <button
      v-for="tab in tabs"
      :key="tab.page"
      type="button"
      class="hub-tab"
      :class="{ active: tab.page === active }"
      :aria-current="tab.page === active ? 'page' : undefined"
      @click="$emit('select', tab.page)"
    >
      <span>{{ tab.label }}</span>
      <span v-if="badgeCount(tab) > 0" class="hub-tab-badge">{{ badgeCount(tab) > 99 ? '99+' : badgeCount(tab) }}</span>
    </button>
  </nav>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  tabs: { type: Array, required: true },
  active: { type: String, default: '' },
  badgeCount: { type: Function, default: () => 0 },
});
defineEmits(['select']);

// Arrow keys / Home / End move focus between tabs; Enter or Space activates (native button).
function onKey(event) {
  const keys = { ArrowRight: 1, ArrowLeft: -1, Home: 'first', End: 'last' };
  if (!(event.key in keys)) return;
  const buttons = [...event.currentTarget.querySelectorAll('.hub-tab')];
  const at = buttons.indexOf(document.activeElement);
  if (at < 0) return;
  const step = keys[event.key];
  const next = step === 'first' ? 0 : step === 'last' ? buttons.length - 1 : (at + step + buttons.length) % buttons.length;
  event.preventDefault();
  buttons[next].focus();
}
</script>

<style scoped>
.hub-tabs {
  display: flex; gap: 4px; margin: 0 0 14px; padding: 0 0 0;
  border-bottom: 1px solid var(--ds-hairline);
  overflow-x: auto; scrollbar-width: thin;
}
.hub-tab {
  display: inline-flex; align-items: center; gap: 6px; flex: 0 0 auto;
  min-height: 44px; padding: 8px 14px; border: 0; border-bottom: 3px solid transparent;
  background: transparent; color: var(--ds-ink-secondary); font-size: 14px; font-weight: 600; cursor: pointer;
}
.hub-tab:hover { color: var(--ds-ink); }
.hub-tab:focus-visible { outline: 2px solid var(--ds-primary); outline-offset: -2px; border-radius: 6px; }
.hub-tab.active { color: var(--ds-ink); border-bottom-color: var(--ds-primary); }
.hub-tab-badge {
  min-width: 18px; padding: 0 5px; border-radius: 999px; background: var(--ds-primary); color: #fff;
  font-size: 11px; line-height: 18px; text-align: center;
}
</style>
