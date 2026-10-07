<template>
  <div ref="root" class="am" :class="{ 'am--sheet': sheet }">
    <button
      ref="trigger"
      type="button"
      class="small ghost am__trigger"
      aria-haspopup="menu"
      :aria-expanded="open ? 'true' : 'false'"
      :aria-controls="open ? menuId : undefined"
      :aria-label="label"
      @click="toggle()"
      @keydown.down.prevent="openAt(0)"
      @keydown.up.prevent="openAt(-1)"
    >{{ triggerText }}</button>
    <div v-if="open && sheet" class="am__scrim" aria-hidden="true" @click="close(true)" />
    <div
      v-if="open"
      :id="menuId"
      ref="menu"
      class="am__menu"
      role="menu"
      :aria-label="label"
      @keydown="onKey"
    >
      <template v-for="(g, gi) in groups" :key="g.id">
        <hr v-if="gi > 0" class="am__sep" role="separator" />
        <p class="am__group" role="presentation">{{ g.label }}</p>
        <button
          v-for="item in g.items"
          :key="item.id"
          type="button"
          role="menuitem"
          tabindex="-1"
          class="am__item"
          :class="{ 'am__item--danger': item.danger }"
          :data-action="item.id"
          :aria-disabled="item.disabled ? 'true' : undefined"
          :title="item.title || undefined"
          @click="pick(item)"
        >{{ item.label }}{{ item.confirm ? '…' : '' }}</button>
      </template>
    </div>
  </div>
</template>

<script setup>
// Shared overflow menu (課程查找 redesign). WAI-ARIA menu button: Enter/Space/↓ open on the first item,
// ↑ on the last; ↑/↓/Home/End move, type-ahead jumps, Esc/Tab close; Esc returns focus to the trigger.
// ≤640px renders as a bottom sheet with 48px targets.
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
  groups: { type: Array, required: true },
  label: { type: String, default: '更多操作' },
  triggerText: { type: String, default: '⋯' },
});
const emit = defineEmits(['select']);

const menuId = `am-${Math.random().toString(36).slice(2, 9)}`;
const root = ref(null);
const trigger = ref(null);
const menu = ref(null);
const open = ref(false);
const sheet = ref(false);
let mq = null;
const syncSheet = () => { sheet.value = Boolean(mq?.matches); };

const items = () => [...(menu.value?.querySelectorAll('[role="menuitem"]') || [])];
const enabled = () => items().filter((el) => el.getAttribute('aria-disabled') !== 'true');

async function openAt(index) {
  open.value = true;
  await nextTick();
  const list = enabled();
  list.at(index < 0 ? -1 : index)?.focus();
}
function close(returnFocus = false) {
  if (!open.value) return;
  open.value = false;
  if (returnFocus) trigger.value?.focus();
}
function toggle() { open.value ? close(true) : openAt(0); }
function pick(item) {
  if (item.disabled) return;
  close(true);
  emit('select', item.id);
}
function move(step) {
  const list = enabled();
  const i = list.indexOf(document.activeElement);
  list[(i + step + list.length) % list.length]?.focus();
}
function onKey(e) {
  const list = enabled();
  if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
  else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
  else if (e.key === 'Home') { e.preventDefault(); list[0]?.focus(); }
  else if (e.key === 'End') { e.preventDefault(); list.at(-1)?.focus(); }
  else if (e.key === 'Escape') { e.preventDefault(); close(true); }
  else if (e.key === 'Tab') close(false);
  else if (e.key.length === 1 && /\S/.test(e.key)) {
    const i = list.indexOf(document.activeElement);
    const next = [...list.slice(i + 1), ...list.slice(0, i + 1)].find((el) => el.textContent.trim().startsWith(e.key));
    next?.focus();
  }
}
function onOutside(e) { if (open.value && !root.value?.contains(e.target)) close(false); }

onMounted(() => {
  mq = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia('(max-width: 640px)') : null;
  syncSheet();
  mq?.addEventListener?.('change', syncSheet);
  document.addEventListener('mousedown', onOutside);
});
onBeforeUnmount(() => {
  mq?.removeEventListener?.('change', syncSheet);
  document.removeEventListener('mousedown', onOutside);
});
defineExpose({ openAt, close });
</script>

<style scoped>
.am { position: relative; display: inline-block; }
.am__trigger { min-width: 40px; min-height: 40px; }
.am__trigger:focus-visible, .am__item:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.am__menu {
  position: absolute; right: 0; top: calc(100% + 4px); z-index: 40; min-width: 220px; max-height: 70vh; overflow-y: auto;
  padding: 6px; background: var(--ds-surface); color: var(--ds-text-primary);
  border: var(--ds-border-width) solid var(--ds-border); border-radius: var(--ds-radius-lg);
  box-shadow: 0 8px 24px rgb(0 0 0 / 14%);
}
.am__group { margin: 6px 10px 2px; font-size: 12px; color: var(--ds-text-secondary); }
.am__sep { border: 0; border-top: var(--ds-border-width) solid var(--ds-border); margin: 6px 0; }
.am__item {
  display: block; width: 100%; min-height: 40px; padding: 8px 10px; text-align: left;
  background: none; border: 0; border-radius: var(--ds-radius-md); color: inherit; font: inherit; cursor: pointer;
}
.am__item:hover, .am__item:focus-visible { background: var(--ds-surface-subtle); }
.am__item[aria-disabled='true'] { opacity: 0.5; cursor: not-allowed; }
.am__item--danger { color: var(--danger); }
.am--sheet .am__scrim { position: fixed; inset: 0; z-index: 39; background: rgb(0 0 0 / 35%); }
.am--sheet .am__menu {
  position: fixed; left: 0; right: 0; top: auto; bottom: 0; min-width: 0; max-height: 80vh;
  border-radius: var(--ds-radius-lg) var(--ds-radius-lg) 0 0; padding-bottom: calc(8px + env(safe-area-inset-bottom));
}
.am--sheet .am__item { min-height: 48px; }
@media (prefers-reduced-motion: no-preference) { .am--sheet .am__menu { animation: am-up 160ms ease-out; } }
@keyframes am-up { from { transform: translateY(16px); opacity: 0; } to { transform: none; opacity: 1; } }
</style>
