<template>
  <div
    v-if="show"
    class="modal-overlay enrollment-conflict-overlay"
    @click.self="emitCancel"
  >
    <div
      class="modal enrollment-conflict-modal"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="headingId"
    >
      <h3 :id="headingId" class="enrollment-conflict-heading">
        {{ isTrial ? '此學生已有相關課程 — 建立試聽？' : '此學生已有進行中的課程' }}
      </h3>

      <p class="enrollment-conflict-lead">
        <template v-if="isTrial">
          試聽可以與正式課日期重疊。若只是要加掛試聽，請選「建立試聽」。
          若同科目已有一筆試聽，請勿重複建立。
        </template>
        <template v-else>
          <span v-if="studentName"><strong>{{ studentName }}</strong> 目前</span>
          <span v-else>此學生目前</span>
          有以下進行中的課程。請選擇要怎麼處理——不要用含糊的「強制建立」。
        </template>
      </p>

      <div class="enrollment-conflict-table-wrap">
        <table class="enrollment-conflict-table">
          <thead>
            <tr>
              <th>科目</th>
              <th>類型</th>
              <th>剩餘堂數</th>
              <th v-if="!isTrial">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in conflicts" :key="c.existing_course_id || c.id">
              <td>{{ subjectLabel(c) }}</td>
              <td>{{ classTypeLabel(c.class_type) }}</td>
              <td
                :style="{
                  color: (c.remaining_sessions ?? 0) <= 2 ? '#c62828' : 'inherit',
                  fontWeight: 600,
                }"
              >
                {{ c.remaining_sessions ?? 0 }} 堂
              </td>
              <td v-if="!isTrial">
                <button
                  class="small btn-renew-warn"
                  type="button"
                  :disabled="submitting"
                  @click="emit('purchase', c)"
                >
                  去加購
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="!isTrial" class="enrollment-conflict-options">
        <p class="enrollment-conflict-options-title">請選擇下一步</p>
        <ul class="enrollment-conflict-option-list">
          <li><strong>加購既有課程</strong>：延續目前合約堂數（上方「去加購」）。</li>
          <li><strong>建立下一期續報</strong>：開新合約；課程延續關聯後續會自動建議串接。</li>
          <li><strong>建立獨立課程</strong>：與既有合約平行，需填寫原因（會留下操作紀錄）。</li>
          <li><strong>取消</strong>：不建立。</li>
        </ul>

        <label class="enrollment-conflict-reason-label" for="enrollment-conflict-reason">
          建立獨立課程時必填原因
        </label>
        <textarea
          id="enrollment-conflict-reason"
          v-model="independentReason"
          class="enrollment-conflict-reason"
          rows="2"
          maxlength="200"
          placeholder="例如：同科不同時段／不同老師／家長要求分開計費"
          :disabled="submitting"
        />
      </div>

      <div class="enrollment-conflict-actions">
        <button class="ghost" type="button" :disabled="submitting" @click="emitCancel">
          取消
        </button>
        <template v-if="isTrial">
          <button
            class="primary"
            type="button"
            :disabled="submitting"
            @click="emitDecision('create_trial')"
          >
            {{ submitting ? '建立中...' : '建立試聽' }}
          </button>
        </template>
        <template v-else>
          <button
            class="ghost"
            type="button"
            :disabled="submitting"
            @click="emitDecision('renewal_next_term')"
          >
            {{ submitting ? '建立中...' : '建立下一期續報' }}
          </button>
          <button
            class="ghost"
            type="button"
            :disabled="submitting || !independentReason.trim()"
            :title="!independentReason.trim() ? '請先填寫原因' : ''"
            @click="emitDecision('independent_parallel')"
          >
            {{ submitting ? '建立中...' : '建立獨立課程' }}
          </button>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
  show: { type: Boolean, default: false },
  conflicts: { type: Array, default: () => [] },
  studentName: { type: String, default: '' },
  classType: { type: String, default: '' },
  submitting: { type: Boolean, default: false },
  subjectLabelFn: { type: Function, default: null },
});

const emit = defineEmits(['cancel', 'purchase', 'decision']);

const headingId = 'enrollment-conflict-heading';
const independentReason = ref('');

const isTrial = computed(() => String(props.classType || '').toLowerCase() === 'trial');

watch(
  () => props.show,
  (open) => {
    if (open) independentReason.value = '';
  },
);

const CLASS_TYPE_LABEL = {
  one_on_one: '一對一',
  one_on_two: '一對二',
  one_on_three: '一對三',
  tutoring: '輔導',
  trial: '試聽',
};

function classTypeLabel(t) {
  return CLASS_TYPE_LABEL[t] || t || '—';
}

function subjectLabel(c) {
  const raw = c?.subject_name || c?.subject || '';
  if (typeof props.subjectLabelFn === 'function') {
    return props.subjectLabelFn(raw) || raw || '—';
  }
  return raw || '—';
}

function emitCancel() {
  if (props.submitting) return;
  emit('cancel');
}

function emitDecision(kind) {
  if (props.submitting) return;
  const existingIds = (props.conflicts || [])
    .map((c) => c.existing_course_id ?? c.id)
    .filter((id) => id != null);
  const payload = {
    kind,
    force_reason: kind,
    force_note: kind === 'independent_parallel' ? independentReason.value.trim() : '',
    existing_contract_ids: existingIds,
  };
  if (kind === 'independent_parallel' && !payload.force_note) {
    return;
  }
  emit('decision', payload);
}
</script>

<style scoped>
.enrollment-conflict-modal {
  width: min(520px, calc(100vw - 24px));
  max-width: 100%;
}
.enrollment-conflict-heading {
  color: #e65100;
  margin: 0 0 8px;
  font-size: 1.1rem;
}
.enrollment-conflict-lead {
  margin: 0 0 12px;
  line-height: 1.5;
  font-size: 14px;
}
.enrollment-conflict-table-wrap {
  max-height: 200px;
  overflow-y: auto;
  margin-bottom: 12px;
}
.enrollment-conflict-table {
  font-size: 13px;
  width: 100%;
  border-collapse: collapse;
}
.enrollment-conflict-table th,
.enrollment-conflict-table td {
  padding: 6px 8px;
  text-align: left;
  border-bottom: 1px solid #eee;
}
.enrollment-conflict-options {
  background: #fff8f0;
  border: 1px solid #ffe0b2;
  border-radius: 6px;
  padding: 10px 12px;
  margin-bottom: 12px;
}
.enrollment-conflict-options-title {
  margin: 0 0 6px;
  font-weight: 600;
  font-size: 13px;
}
.enrollment-conflict-option-list {
  margin: 0 0 10px;
  padding-left: 18px;
  font-size: 13px;
  line-height: 1.45;
}
.enrollment-conflict-reason-label {
  display: block;
  font-size: 12px;
  margin-bottom: 4px;
}
.enrollment-conflict-reason {
  width: 100%;
  box-sizing: border-box;
  padding: 8px;
  border: 1px solid #ddd;
  border-radius: 4px;
  resize: vertical;
  font: inherit;
}
.enrollment-conflict-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: flex-end;
}
@media (max-width: 390px) {
  .enrollment-conflict-actions {
    flex-direction: column-reverse;
    align-items: stretch;
  }
  .enrollment-conflict-actions button {
    width: 100%;
  }
}
</style>
