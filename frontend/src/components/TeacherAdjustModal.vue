<template>
  <div v-if="visible" class="ta-modal-backdrop" @click.self="$emit('close')">
    <div class="ta-modal card" role="dialog" aria-modal="true" aria-labelledby="ta-modal-title">
      <div class="ta-modal-header">
        <h3 id="ta-modal-title">補卡</h3>
        <button class="ta-modal-close" @click="$emit('close')" aria-label="關閉">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>

      <div class="ta-modal-body">
        <div class="ta-field-group" v-if="record">
          <p class="ta-field-hint">
            老師：<strong>{{ record.teacher_name }}</strong>
            ／原始簽到：<strong>{{ record.sign_in_dt?.slice(11, 16) ?? '無' }}</strong>
            ／原始簽退：<strong>{{ record.sign_out_dt?.slice(11, 16) ?? '未簽退' }}</strong>
          </p>
        </div>

        <div class="ta-field-group">
          <label class="ta-label" for="ta-new-signin">新簽到時間 <span class="ta-required">*</span></label>
          <input id="ta-new-signin" type="datetime-local" v-model="form.new_signin_dt" class="ta-input" />
        </div>

        <div class="ta-field-group">
          <label class="ta-label" for="ta-new-signout">新簽退時間</label>
          <input id="ta-new-signout" type="datetime-local" v-model="form.new_signout_dt" class="ta-input" />
          <div v-if="errors.new_signout_dt" class="ta-error">{{ errors.new_signout_dt }}</div>
        </div>

        <div class="ta-field-group">
          <label class="ta-label" for="ta-reason">補卡原因 <span class="ta-required">*</span></label>
          <textarea
            id="ta-reason" v-model="form.adjust_reason"
            class="ta-input ta-textarea" rows="3"
            placeholder="請填寫補卡原因（必填）"
            maxlength="500"
          />
          <div v-if="errors.adjust_reason" class="ta-error">{{ errors.adjust_reason }}</div>
        </div>

        <div v-if="submitError" class="ta-msg error">{{ submitError }}</div>
      </div>

      <div class="ta-modal-footer">
        <button class="ghost" @click="$emit('close')" :disabled="submitting">取消</button>
        <button class="primary" @click="submit" :disabled="submitting">
          {{ submitting ? '送出中…' : '確認補卡' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import { supabase } from '../supabase';

const props = defineProps({
  visible: { type: Boolean, default: false },
  record:  { type: Object, default: null },
});

const emit = defineEmits(['close', 'submitted']);

const form = ref({ new_signin_dt: '', new_signout_dt: '', adjust_reason: '' });
const errors = ref({});
const submitting = ref(false);
const submitError = ref('');

watch(() => props.record, (rec) => {
  if (!rec) return;
  const toLocal = (dt) => dt ? dt.slice(0, 16).replace(' ', 'T') : '';
  form.value = {
    new_signin_dt:  toLocal(rec.sign_in_dt),
    new_signout_dt: toLocal(rec.sign_out_dt),
    adjust_reason:  '',
  };
  errors.value   = {};
  submitError.value = '';
});

function validate() {
  const e = {};
  if (!form.value.new_signin_dt) {
    e.new_signin_dt = '簽到時間必填';
  }
  if (form.value.new_signout_dt && form.value.new_signin_dt &&
      form.value.new_signout_dt <= form.value.new_signin_dt) {
    e.new_signout_dt = '簽退時間必須晚於簽到時間';
  }
  if (!form.value.adjust_reason?.trim()) {
    e.adjust_reason = '補卡原因必填';
  }
  errors.value = e;
  return Object.keys(e).length === 0;
}

async function submit() {
  if (!validate()) return;
  submitting.value  = true;
  submitError.value = '';
  try {
    const { data: { session } } = await supabase.auth.getSession();
    const token = session?.access_token;
    const res = await fetch(`/api/v1/teacher-attendance/${props.record.id}/adjust`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({
        new_signin_dt:  form.value.new_signin_dt.replace('T', ' '),
        new_signout_dt: form.value.new_signout_dt ? form.value.new_signout_dt.replace('T', ' ') : null,
        adjust_reason:  form.value.adjust_reason.trim(),
      }),
    });
    const json = await res.json();
    if (!res.ok) {
      submitError.value = json?.message ?? '補卡失敗，請稍後再試';
      return;
    }
    emit('submitted', json);
  } catch {
    submitError.value = '網路錯誤，請稍後再試';
  } finally {
    submitting.value = false;
  }
}
</script>

<style scoped>
.ta-modal-backdrop {
  position: fixed; inset: 0; background: rgba(0,0,0,.4);
  display: flex; align-items: center; justify-content: center;
  z-index: 1000; padding: 16px;
}
.ta-modal {
  width: 100%; max-width: 480px;
  background: var(--ds-canvas); border-radius: 14px; padding: 20px;
  display: flex; flex-direction: column; gap: 0;
}
.ta-modal-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 16px;
}
.ta-modal-header h3 { margin: 0; font-size: 17px; font-weight: 700; }
.ta-modal-close { background: none; border: none; cursor: pointer; padding: 4px; }
.ta-modal-body  { display: flex; flex-direction: column; gap: 14px; margin-bottom: 20px; }
.ta-field-group { display: flex; flex-direction: column; gap: 4px; }
.ta-label       { font-size: 13px; font-weight: 600; }
.ta-required    { color: var(--ds-danger); }
.ta-input {
  border: 1px solid var(--border); border-radius: 8px;
  padding: 8px 10px; font-size: 14px; width: 100%; box-sizing: border-box;
}
.ta-textarea    { resize: vertical; min-height: 72px; }
.ta-error       { color: var(--ds-danger); font-size: 12px; }
.ta-field-hint  { font-size: 13px; color: var(--text-secondary); margin: 0; }
.ta-msg.error   { background: var(--ds-danger-wash); color: var(--ds-danger); padding: 8px 12px; border-radius: 8px; font-size: 13px; }
.ta-modal-footer {
  display: flex; gap: 10px; justify-content: flex-end;
}
</style>
