<template>
  <div class="searchable-select" :class="{ open: isOpen, disabled: disabled }" ref="container">
    <div class="ss-input-wrap" @click="toggleOpen">
      <input
        ref="inputEl"
        type="text"
        class="ss-input"
        :placeholder="selectedLabel || placeholder"
        :value="searchQuery"
        :disabled="disabled"
        @input="onInput"
        @focus="onFocus"
        @keydown.down.prevent="moveHighlight(1)"
        @keydown.up.prevent="moveHighlight(-1)"
        @keydown.enter.prevent="selectHighlighted"
        @keydown.escape="close"
      />
      <span class="ss-arrow">&#9662;</span>
    </div>
    <div v-if="modelValue && !isOpen && !disabled" class="ss-selected-display">
      {{ selectedLabel }}
    </div>
    <div v-if="isOpen" class="ss-dropdown">
      <div v-if="filteredOptions.length === 0" class="ss-no-results">
        查無結果
      </div>
      <div
        v-for="(opt, idx) in filteredOptions"
        :key="opt.value"
        class="ss-option"
        :class="{ highlighted: idx === highlightIndex, selected: opt.value === modelValue }"
        @mousedown.prevent="selectOption(opt)"
        @mouseenter="highlightIndex = idx"
      >
        {{ opt.label }}
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';

const props = defineProps({
  options: { type: Array, default: () => [] },        // [{value, label}]
  modelValue: { type: [String, Number, null], default: null },
  placeholder: { type: String, default: '請選擇...' },
  disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const container = ref(null);
const inputEl = ref(null);
const isOpen = ref(false);
const searchQuery = ref('');
const highlightIndex = ref(0);

const selectedLabel = computed(() => {
  if (!props.modelValue && props.modelValue !== 0) return '';
  const found = props.options.find(o => String(o.value) === String(props.modelValue));
  return found ? found.label : '';
});

const filteredOptions = computed(() => {
  if (!searchQuery.value) return props.options;
  const q = searchQuery.value.toLowerCase();
  return props.options.filter(o => o.label.toLowerCase().includes(q));
});

function toggleOpen() {
  if (props.disabled) return;
  if (isOpen.value) {
    close();
  } else {
    open();
  }
}

function open() {
  isOpen.value = true;
  searchQuery.value = '';
  highlightIndex.value = 0;
  nextTick(() => inputEl.value?.focus());
}

function close() {
  isOpen.value = false;
  searchQuery.value = '';
}

function onFocus() {
  if (!isOpen.value) open();
}

function onInput(e) {
  searchQuery.value = e.target.value;
  highlightIndex.value = 0;
  if (!isOpen.value) isOpen.value = true;
}

function moveHighlight(dir) {
  const len = filteredOptions.value.length;
  if (!len) return;
  highlightIndex.value = (highlightIndex.value + dir + len) % len;
}

function selectHighlighted() {
  if (filteredOptions.value.length > 0) {
    selectOption(filteredOptions.value[highlightIndex.value]);
  }
}

function selectOption(opt) {
  emit('update:modelValue', opt.value);
  close();
}

function handleClickOutside(e) {
  if (container.value && !container.value.contains(e.target)) {
    close();
  }
}

onMounted(() => document.addEventListener('mousedown', handleClickOutside));
onBeforeUnmount(() => document.removeEventListener('mousedown', handleClickOutside));
</script>

<style scoped>
.searchable-select {
  position: relative;
  width: 100%;
}

.ss-input-wrap {
  position: relative;
  cursor: pointer;
}

.ss-input {
  width: 100%;
  padding: 10px 32px 10px 12px;
  border: 1px solid var(--border, var(--ds-canvas-soft));
  border-radius: 8px;
  background: var(--ds-canvas);
  font-size: 14px;
  color: var(--text, var(--ds-ink));
  font-family: inherit;
  transition: all 0.2s ease;
  cursor: pointer;
}

.ss-input:focus {
  outline: none;
  border-color: var(--accent, var(--ds-warning));
  box-shadow: 0 0 0 3px rgba(255, 167, 38, 0.15);
  background: var(--ds-canvas);
  cursor: text;
}

.ss-input:disabled {
  background: var(--ds-canvas-soft);
  color: var(--ds-hairline);
  cursor: not-allowed;
}

.ss-arrow {
  position: absolute;
  right: 12px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 12px;
  color: var(--text-light, var(--ds-ink-mute));
  pointer-events: none;
  transition: transform 0.2s;
}

.searchable-select.open .ss-arrow {
  transform: translateY(-50%) rotate(180deg);
}

.searchable-select.disabled .ss-arrow {
  color: var(--ds-hairline);
}

.ss-selected-display {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  padding: 10px 32px 10px 12px;
  font-size: 14px;
  color: var(--text, var(--ds-ink));
  line-height: 1.4;
  pointer-events: none;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.ss-dropdown {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  background: var(--ds-canvas);
  border: 1px solid var(--border, var(--ds-canvas-soft));
  border-radius: 8px;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
  max-height: 220px;
  overflow-y: auto;
  z-index: 300;
  animation: ssSlideDown 0.15s ease;
}

@keyframes ssSlideDown {
  from { opacity: 0; transform: translateY(-4px); }
  to   { opacity: 1; transform: translateY(0); }
}

.ss-option {
  padding: 9px 12px;
  font-size: 13.5px;
  color: var(--text, var(--ds-ink));
  cursor: pointer;
  transition: background 0.1s;
}

.ss-option.highlighted {
  background: var(--primary-bg, var(--ds-warning-wash));
}

.ss-option.selected {
  color: var(--primary, var(--ds-primary));
  font-weight: 600;
}

.ss-no-results {
  padding: 12px;
  text-align: center;
  color: var(--text-light, var(--ds-ink-mute));
  font-size: 13px;
  font-style: italic;
}
</style>
