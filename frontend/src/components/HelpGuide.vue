<template>
  <div class="help-guide" v-if="items && items.length">
    <button
      class="help-toggle"
      :class="{ open: isOpen }"
      @click="isOpen = !isOpen"
    >
      <span class="help-icon">💡</span>
      <span class="help-title">{{ title || '使用說明' }}</span>
      <span class="help-arrow">▼</span>
    </button>
    <div class="help-content" v-if="isOpen">
      <ol v-if="ordered">
        <li v-for="(item, i) in items" :key="i" v-html="item"></li>
      </ol>
      <ul v-else>
        <li v-for="(item, i) in items" :key="i" v-html="item"></li>
      </ul>
      <div class="help-tip" v-if="tip">
        💡 <strong>小提示：</strong>{{ tip }}
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';

const props = defineProps({
  title: { type: String, default: '使用說明' },
  items: { type: Array, default: () => [] },
  tip: { type: String, default: '' },
  ordered: { type: Boolean, default: true },
  defaultOpen: { type: Boolean, default: false }
});

const isOpen = ref(props.defaultOpen);
</script>
