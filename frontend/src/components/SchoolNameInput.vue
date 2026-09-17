<template>
  <div class="school-name-input" @keydown.escape.prevent="closeSuggestions">
    <input
      :id="inputId"
      v-model="text"
      type="text"
      autocomplete="off"
      :placeholder="placeholder"
      :aria-expanded="open ? 'true' : 'false'"
      aria-autocomplete="list"
      :aria-controls="listId"
      role="combobox"
      @input="onInput"
      @focus="onFocus"
      @keydown.down.prevent="move(1)"
      @keydown.up.prevent="move(-1)"
      @keydown.enter.prevent="selectHighlighted"
    />
    <ul
      v-if="open && (suggestions.length || showCustomHint)"
      :id="listId"
      class="school-name-suggestions"
      role="listbox"
    >
      <li
        v-for="(item, idx) in suggestions"
        :key="item.id"
        role="option"
        :aria-selected="idx === highlight ? 'true' : 'false'"
        :class="{ active: idx === highlight }"
        @mousedown.prevent="pick(item)"
      >
        <strong>{{ item.canonical_name }}</strong>
        <small>{{ locationOf(item) }}</small>
      </li>
      <li v-if="showCustomHint" class="school-name-custom-hint" role="presentation">
        也可直接輸入自訂校名（未在清單內）
      </li>
    </ul>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: '例：大安國中' },
  inputId: { type: String, default: 'student-school' },
  authToken: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const text = ref(props.modelValue || '');
const suggestions = ref([]);
const open = ref(false);
const highlight = ref(-1);
const listId = `${props.inputId}-list`;
let timer = null;
let seq = 0;

watch(
  () => props.modelValue,
  (v) => {
    if (v !== text.value) text.value = v || '';
  }
);

watch(text, (v) => {
  emit('update:modelValue', v);
});

const showCustomHint = computed(() => text.value.trim().length >= 1);

function locationOf(item) {
  const bits = [item.municipality, item.district].filter(Boolean);
  return bits.join(' ');
}

function closeSuggestions() {
  open.value = false;
  highlight.value = -1;
}

function onFocus() {
  if (text.value.trim().length >= 1) {
    open.value = true;
    queueSearch();
  }
}

function onInput() {
  open.value = true;
  queueSearch();
}

function queueSearch() {
  clearTimeout(timer);
  timer = setTimeout(runSearch, 180);
}

async function runSearch() {
  const q = text.value.trim();
  if (q.length < 1 || !props.authToken) {
    suggestions.value = [];
    return;
  }
  const my = ++seq;
  try {
    const res = await fetch(`/api/v1/schools?q=${encodeURIComponent(q)}&limit=12`, {
      headers: {
        Authorization: `Bearer ${props.authToken}`,
        Accept: 'application/json',
      },
    });
    if (!res.ok || my !== seq) return;
    const json = await res.json();
    if (my !== seq) return;
    suggestions.value = Array.isArray(json?.data) ? json.data : [];
    highlight.value = suggestions.value.length ? 0 : -1;
  } catch {
    if (my === seq) suggestions.value = [];
  }
}

function pick(item) {
  text.value = item.canonical_name || '';
  emit('update:modelValue', text.value);
  closeSuggestions();
}

function move(delta) {
  if (!suggestions.value.length) return;
  open.value = true;
  const n = suggestions.value.length;
  highlight.value = (highlight.value + delta + n) % n;
}

function selectHighlighted() {
  if (highlight.value >= 0 && suggestions.value[highlight.value]) {
    pick(suggestions.value[highlight.value]);
  } else {
    closeSuggestions();
  }
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<style scoped>
.school-name-input {
  position: relative;
}
.school-name-input input {
  width: 100%;
}
.school-name-suggestions {
  position: absolute;
  z-index: 20;
  left: 0;
  right: 0;
  top: calc(100% + 4px);
  margin: 0;
  padding: 4px 0;
  list-style: none;
  background: var(--ds-surface);
  border: 1px solid var(--ds-border);
  border-radius: 8px;
  max-height: 240px;
  overflow: auto;
  box-shadow: var(--ds-shadow-md, 0 8px 24px color-mix(in srgb, var(--ds-ink) 12%, transparent));
}
.school-name-suggestions li {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 8px 12px;
  cursor: pointer;
}
.school-name-suggestions li.active,
.school-name-suggestions li:hover {
  background: var(--ds-surface-subtle);
}
.school-name-suggestions strong {
  font-size: 0.92rem;
}
.school-name-suggestions small {
  color: var(--ds-text-tertiary);
  font-size: 0.78rem;
}
.school-name-custom-hint {
  cursor: default;
  color: var(--ds-text-tertiary);
  font-size: 0.78rem;
}
</style>
