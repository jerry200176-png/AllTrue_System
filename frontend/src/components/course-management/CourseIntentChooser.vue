<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal course-modal intent-chooser" role="dialog" aria-modal="true" aria-labelledby="course-intent-title" data-testid="course-intent-chooser">
      <h3 id="course-intent-title" class="modal-title">{{ COURSE_ENTRY_LABEL }}</h3>
      <p v-if="studentName" class="modal-desc">{{ studentName }}</p>

      <template v-if="!picking">
        <p class="choice-intro">先選要做哪一件事，系統會帶到對應的流程。</p>
        <div class="choice-list">
          <button
            v-for="intent in COURSE_INTENTS"
            :key="intent.id"
            type="button"
            class="choice-card"
            :data-testid="`course-intent-${intent.id}`"
            @click="onIntent(intent.id)"
          >
            <span class="material-symbols-outlined choice-card__icon" aria-hidden="true">{{ intent.icon }}</span>
            <span class="choice-card__copy">
              <strong>{{ intent.label }}</strong>
              <small>{{ intent.hint }}</small>
            </span>
            <span class="choice-card__arrow" aria-hidden="true">›</span>
          </button>
        </div>
      </template>

      <template v-else>
        <p class="choice-intro">{{ pickPrompt }}</p>
        <p v-if="!courses" class="choice-guide" data-testid="course-intent-guide">{{ guideText }}</p>
        <p v-else-if="!courses.length" class="choice-guide" data-testid="course-intent-empty">這位學生目前沒有進行中的課程，請改選「全新課程」。</p>
        <div v-else class="choice-list">
          <button
            v-for="c in courses"
            :key="c.id"
            type="button"
            class="choice-card"
            :data-testid="`course-intent-course-${c.id}`"
            @click="emitChoice(picking, c.course)"
          >
            <span class="choice-card__copy"><strong>{{ c.label }}</strong></span>
            <span class="choice-card__arrow" aria-hidden="true">›</span>
          </button>
        </div>
      </template>

      <div class="actions">
        <button v-if="picking" type="button" class="ghost" data-testid="course-intent-back" @click="picking = ''">上一步</button>
        <button type="button" class="ghost" @click="$emit('close')">取消</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { COURSE_ENTRY_LABEL, COURSE_INTENTS, RENEW_LESSONS_LABEL } from '../../lib/courseIntent.js';

const props = defineProps({
  show: Boolean,
  studentName: { type: String, default: '' },
  // [{ id, label, course }] for one student; null when the page has no student context (課程查找 header).
  courses: { type: Array, default: null },
});
const emit = defineEmits(['close', 'choose']);

const picking = ref('');
watch(() => props.show, () => { picking.value = ''; });

const pickPrompt = computed(() => (picking.value === 'renew'
  ? `要${RENEW_LESSONS_LABEL}哪一門課？`
  : '要把哪一門課的紀錄轉走？'));
const guideText = computed(() => (picking.value === 'renew'
  ? `在下方課程列表找到那門課，按「${RENEW_LESSONS_LABEL}」。`
  : '在下方課程列表找到那門課，按 ⋯ →「轉課」。'));

function emitChoice(intent, course = null) {
  emit('choose', { intent, course });
}

function onIntent(id) {
  // 全新課程 needs no course; the other two pick one (or show where the row button is).
  if (id === 'new') { emitChoice('new'); return; }
  if (props.courses && props.courses.length === 1) { emitChoice(id, props.courses[0].course); return; }
  picking.value = id;
}
</script>

<style scoped>
.course-modal { width: 100%; max-width: 480px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1.2rem; font-weight: 800; color: var(--text); margin: 0 0 4px; }
.modal-desc { color: var(--text-light); font-size: 13px; margin: 0 0 12px; }
.choice-intro { margin: 0 0 14px; color: var(--ds-ink-mute); font-size: 13px; }
.choice-guide { margin: 0; color: var(--text); font-size: var(--ds-font-size-sm); line-height: 1.5; }
.choice-list { display: grid; gap: 10px; }
.choice-card {
  display: flex; align-items: center; gap: 10px; width: 100%; padding: 13px 12px;
  border: 1px solid var(--ds-hairline); border-radius: 12px; background: var(--ds-canvas-soft);
  color: var(--text); text-align: left; cursor: pointer;
}
.choice-card:hover, .choice-card:focus-visible { border-color: var(--ds-primary); background: var(--ds-canvas); outline: none; }
.choice-card__icon { flex: 0 0 28px; font-size: 22px; color: var(--ds-primary-text); text-align: center; }
.choice-card__copy { display: grid; gap: 4px; flex: 1; }
.choice-card__copy strong { font-size: 14px; }
.choice-card__copy small { color: var(--ds-ink-mute); font-size: 12px; line-height: 1.45; }
.choice-card__arrow { color: var(--ds-ink-mute); font-size: 24px; line-height: 1; }
.actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
</style>
