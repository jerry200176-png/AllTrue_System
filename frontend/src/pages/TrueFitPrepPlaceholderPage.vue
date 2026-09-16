<template>
  <div class="tf-prep-page">
    <AtPageHeader
      title="課程準備"
      description="Slice 0 占位：備課內容將在後續版本提供。"
      icon="menu_book"
    >
      <template #actions>
        <AtButton variant="ghost" shape="rect" icon="arrow_back" @click="$emit('back')">返回今日課程</AtButton>
      </template>
    </AtPageHeader>

    <AtCard class="tf-prep-card">
      <AtEmpty
        icon="construction"
        title="備課功能即將推出"
        :description="emptyDescription"
      />
      <div v-if="sessionLabel" class="tf-prep-context" role="status">
        <span class="tf-prep-context__label">已選課程</span>
        <strong>{{ sessionLabel }}</strong>
      </div>
    </AtCard>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';
import AtButton from '../components/design-system/AtButton.vue';
import AtCard from '../components/design-system/AtCard.vue';
import AtEmpty from '../components/design-system/AtEmpty.vue';

const props = defineProps({
  session: { type: Object, default: null },
});

defineEmits(['back']);

const sessionLabel = computed(() => {
  if (!props.session) return '';
  const parts = [
    props.session.start_time,
    props.session.student_name,
    props.session.subject_name,
  ].filter(Boolean);
  return parts.join(' · ');
});

const emptyDescription = computed(() => (
  sessionLabel.value
    ? '您已選定一堂課。AI 備課、學習目標與教材將在 Slice 1 之後提供。'
    : '請從今日課程清單選擇一堂課再進入準備流程。'
));
</script>

<style scoped>
.tf-prep-page {
  max-width: 720px;
  margin: 0 auto;
}

.tf-prep-card {
  padding: var(--ds-space-4);
}

.tf-prep-context {
  margin-top: var(--ds-space-4);
  padding: var(--ds-space-3);
  border-radius: var(--ds-radius-md);
  background: var(--ds-canvas-soft);
  border: 1px solid var(--ds-hairline);
}

.tf-prep-context__label {
  display: block;
  font-size: var(--ds-font-size-sm);
  color: var(--ds-text-tertiary);
  margin-bottom: var(--ds-space-1);
}
</style>
