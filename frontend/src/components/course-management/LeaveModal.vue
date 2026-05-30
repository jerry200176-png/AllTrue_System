<template>
  <div v-if="show" class="modal-overlay" @click.self="$emit('close')">
    <div class="modal course-modal" style="max-width: 420px;">
      <h3 class="modal-title">請假登記</h3>
      <p class="modal-desc">請假不扣堂數、不需填寫評量表</p>
      <div v-if="sessionOptions.length === 0" class="form-group">
        <p class="hint">此課程無可請假堂次（請確認開課日與排課設定）。</p>
      </div>
      <template v-else>
        <div class="form-group">
          <label>選擇要請假的堂次</label>
          <select v-model="form.schedule_date">
            <option value="">請選擇</option>
            <option v-for="opt in sessionOptions" :key="opt.date" :value="opt.date">
              第{{ opt.index }}堂 {{ opt.date }}{{ opt.isRetro ? ' ⚠️ 已上課' : '' }}
            </option>
          </select>
        </div>
        <div v-if="isRetroLeave" class="retro-leave-warning">
          <strong>補請假：</strong>此堂已點名/上課，確認後將沖回堂數並作廢該堂出缺勤與評量記錄。
          <div class="form-group" style="margin-top: 8px;">
            <label>補請假原因（選填）</label>
            <input v-model="form.reason" type="text" placeholder="例：家長臨時通知" style="width: 100%;" />
          </div>
        </div>
        <template v-if="form.schedule_date">
          <div class="form-group">
            <label>學生</label>
            <p style="font-weight: 600;">{{ form.student_name }}</p>
          </div>
          <div class="form-group">
            <label>科目</label>
            <p>{{ subjectLabel }}</p>
          </div>
          <div class="form-group">
            <label>原時段</label>
            <p>{{ dayLabelText }} {{ form.start_time }}~{{ form.end_time }}</p>
          </div>
          <div class="impact-preview" :class="{ 'impact-preview--danger': isRetroLeave }" role="note" aria-label="請假影響預覽">
            <div class="impact-preview__head">
              <span class="impact-preview__icon" aria-hidden="true">{{ isRetroLeave ? '!' : 'i' }}</span>
              <div>
                <strong>{{ impactPreview?.title || '送出前影響預覽' }}</strong>
                <p>{{ impactPreview?.summary || '系統會先處理請假並同步順延後續課程。' }}</p>
              </div>
            </div>
            <ul class="impact-preview__list">
              <li v-for="item in impactItems" :key="item">{{ item }}</li>
            </ul>
            <label class="impact-confirm">
              <input v-model="impactAccepted" type="checkbox" />
              <span>我已確認上述影響，確定要送出這次{{ isRetroLeave ? '補請假' : '請假' }}</span>
            </label>
          </div>
        </template>
        <div class="actions">
          <button class="ghost" @click="$emit('close')">取消</button>
          <button class="primary" @click="$emit('submit')" :disabled="!canSubmit">
            {{ isRetroLeave ? '確認補請假（沖回堂數）' : '確認請假' }}
          </button>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { getSubjectLabel } from '../../lib/constants';

const props = defineProps({
  show: Boolean,
  form: Object,
  sessionOptions: Array,
  isRetroLeave: Boolean,
  dayLabel: Function,
  impactPreview: Object,
});
defineEmits(['close', 'submit']);
const impactAccepted = ref(false);
const subjectLabel = computed(() => getSubjectLabel(props.form?.subject));
const dayLabelText = computed(() => props.dayLabel?.(props.form?.day_of_week) || '');
const impactItems = computed(() => {
  const items = props.impactPreview?.items;
  if (Array.isArray(items) && items.length) return items;
  return props.isRetroLeave
    ? ['將沖回該堂扣堂紀錄', '該堂出缺勤與評量紀錄會作廢', '後續課程會重新順延']
    : ['本堂不扣堂數', '後續課程會自動順延', '該堂不需要填寫評量表'];
});
const canSubmit = computed(() => Boolean(props.form?.schedule_date) && impactAccepted.value);
watch(
  () => [props.show, props.form?.schedule_date, props.isRetroLeave],
  () => { impactAccepted.value = false; },
);
</script>

<style scoped>
.course-modal { width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1.15rem; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.modal-desc { color: var(--text-light); font-size: 13px; margin-bottom: 20px; line-height: 1.6; }
.retro-leave-warning {
  margin: 8px 0; padding: 10px 14px; border-radius: 8px;
  background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; font-size: 0.92em;
}
.impact-preview {
  margin: 12px 0 4px;
  padding: 12px 14px;
  border-radius: 12px;
  border: 1px solid var(--ds-hairline, #e3e8ee);
  background: var(--ds-canvas-soft, #f6f9fc);
  color: var(--ds-ink, #1a1a1a);
}
.impact-preview--danger {
  border-color: #fdba74;
  background: #fff7ed;
  color: #9a3412;
}
.impact-preview__head {
  display: flex;
  gap: 10px;
  align-items: flex-start;
}
.impact-preview__head strong {
  display: block;
  font-size: 14px;
  margin-bottom: 2px;
}
.impact-preview__head p {
  margin: 0;
  font-size: 12px;
  line-height: 1.5;
}
.impact-preview__icon {
  width: 22px;
  height: 22px;
  border-radius: 999px;
  display: inline-grid;
  place-items: center;
  flex: 0 0 auto;
  color: #fff;
  background: #2563eb;
  font-weight: 800;
  font-size: 13px;
}
.impact-preview--danger .impact-preview__icon {
  background: #ea580c;
}
.impact-preview__list {
  margin: 10px 0;
  padding-left: 20px;
  font-size: 13px;
  line-height: 1.6;
}
.impact-confirm {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  font-size: 13px;
  font-weight: 600;
}
.impact-confirm input {
  margin-top: 2px;
}
</style>
