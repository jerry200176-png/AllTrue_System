<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal course-modal adjustment-choice-modal" role="dialog" aria-modal="true" aria-labelledby="contract-adjustment-title">
      <h3 id="contract-adjustment-title" class="modal-title">合約／堂次調整</h3>
      <p class="modal-desc">{{ studentName }}／{{ subjectLabel }}</p>
      <p class="choice-intro">先選你要處理的事情，系統會帶到正確流程。</p>

      <div class="choice-list">
        <button type="button" class="choice-card" @click="$emit('choose', 'billing')">
          <span class="choice-card__icon" aria-hidden="true">↺</span>
          <span class="choice-card__copy">
            <strong>未付款，堂數改少</strong>
            <small>例如原本 5 堂，最後只收 4 堂；已上課紀錄保留。</small>
          </span>
          <span class="choice-card__arrow" aria-hidden="true">›</span>
        </button>

        <button type="button" class="choice-card" @click="$emit('choose', 'transfer')">
          <span class="choice-card__icon" aria-hidden="true">↪</span>
          <span class="choice-card__copy">
            <strong>把已上課紀錄轉到另一份合約</strong>
            <small>搬移評量與點名紀錄；不改任何課程堂數或金額。</small>
          </span>
          <span class="choice-card__arrow" aria-hidden="true">›</span>
        </button>
      </div>

      <p class="choice-footnote">不確定時，先看兩個選項的說明；不要用「編輯」直接改已發生的扣堂資料。</p>

      <div class="actions">
        <button type="button" class="ghost" @click="$emit('close')">取消</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { getSubjectLabel } from '../../lib/constants';

const props = defineProps({
  show: Boolean,
  studentName: { type: String, default: '' },
  subject: { type: String, default: '' },
});

defineEmits(['close', 'choose']);

const subjectLabel = computed(() => getSubjectLabel(props.subject));
</script>

<style scoped>
.course-modal { width: 100%; max-width: 480px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1.2rem; font-weight: 800; color: var(--text); margin: 0 0 4px; }
.modal-desc { color: var(--text-light); font-size: 13px; margin: 0 0 12px; }
.choice-intro { margin: 0 0 14px; color: var(--ds-ink-mute); font-size: 13px; }
.choice-list { display: grid; gap: 10px; }
.choice-card {
  display: flex; align-items: center; gap: 10px; width: 100%; padding: 13px 12px;
  border: 1px solid var(--ds-hairline); border-radius: 12px; background: var(--ds-canvas-soft);
  color: var(--text); text-align: left; cursor: pointer;
}
.choice-card:hover, .choice-card:focus-visible { border-color: var(--ds-primary); background: var(--ds-canvas); outline: none; }
.choice-card__icon { flex: 0 0 28px; font-size: 22px; color: var(--ds-primary-deep); text-align: center; }
.choice-card__copy { display: grid; gap: 4px; flex: 1; }
.choice-card__copy strong { font-size: 14px; }
.choice-card__copy small { color: var(--ds-ink-mute); font-size: 12px; line-height: 1.45; }
.choice-card__arrow { color: var(--ds-ink-mute); font-size: 24px; line-height: 1; }
.choice-footnote { margin: 14px 0 0; color: var(--ds-ink-mute); font-size: 12px; line-height: 1.5; }
.actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
</style>
