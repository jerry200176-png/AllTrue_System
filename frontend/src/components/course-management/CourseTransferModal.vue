<template>
  <div v-if="show" class="modal-overlay" @click.self="!busy && $emit('close')">
    <div class="modal course-modal" role="dialog" aria-modal="true" aria-labelledby="course-transfer-title">
      <h3 id="course-transfer-title" class="modal-title">轉課（改科目／老師／時段）</h3>
      <p class="modal-desc">轉課日之前的堂次留在舊合約；轉課日起的堂次（含紀錄）與剩餘堂數、已繳金額轉到新合約。</p>
      <p v-if="prefill" class="prefill-note" data-testid="transfer-prefill-note">已帶入你在課程設定改的內容，轉課日預設為下一堂未點名的課。請確認後先「試算」。</p>
      <p v-if="prefill?.not_carried?.length" class="prefill-note" data-testid="transfer-not-carried">轉課不會套用你改的{{ prefill.not_carried.join('、') }}：新合約沿用剩餘堂數；如需加堂，請在轉課後另外為新課程加堂。</p>

      <label class="form-label" for="ct-subject">新科目</label>
      <select id="ct-subject" v-model="form.subject" class="form-input" :disabled="busy">
        <option value="">（不變）</option>
        <option v-for="s in subjects" :key="s.value" :value="s.value">{{ s.label }}</option>
      </select>

      <label class="form-label" for="ct-teacher">新老師</label>
      <select id="ct-teacher" v-model="form.teacher_id" class="form-input" :disabled="busy">
        <option value="">（不變）</option>
        <option v-for="t in teachers" :key="t.id" :value="t.id">{{ t.username }}</option>
      </select>

      <label class="form-label" for="ct-start">轉課日（此日起轉到新合約）</label>
      <input id="ct-start" v-model="form.start_date" type="date" class="form-input" :disabled="busy" />

      <div v-if="multiSlots" class="prefill-note" data-testid="transfer-multi-slots">
        新每週時段（共 {{ multiSlots.length }} 個，沿用課程設定）：{{ multiSlots.map((x) => `週${DAYS[x.weekday - 1]} ${x.time}（${x.duration_minutes} 分）`).join('、') }}
      </div>
      <div v-else class="slot-row">
        <div>
          <label class="form-label" for="ct-weekday">新上課星期（不填＝沿用）</label>
          <select id="ct-weekday" v-model="form.weekday" class="form-input" :disabled="busy">
            <option value="">（沿用）</option>
            <option v-for="(d, i) in DAYS" :key="d" :value="i + 1">週{{ d }}</option>
          </select>
        </div>
        <div>
          <label class="form-label" for="ct-time">開始時間</label>
          <input id="ct-time" v-model="form.time" type="time" step="1800" class="form-input" :disabled="busy || !form.weekday" />
        </div>
        <div>
          <label class="form-label" for="ct-duration">時數（分鐘）</label>
          <input id="ct-duration" v-model.number="form.duration_minutes" type="number" min="30" max="480" step="30" class="form-input" :disabled="busy || !form.weekday" />
        </div>
      </div>

      <label class="form-label" for="ct-reason">原因（必填）</label>
      <textarea id="ct-reason" v-model.trim="form.reason" class="form-input" rows="2" maxlength="255" :disabled="busy" />

      <div v-if="preview" class="preview" role="status" data-testid="transfer-preview">
        <div>舊合約：{{ preview.source_correction.session_count }} 堂　${{ preview.source_correction.charge }}</div>
        <div>新合約：{{ preview.new_course.session_count }} 堂　${{ preview.new_course.charge }}（轉入 {{ preview.moved_session_count }} 堂既有堂次）</div>
        <div v-if="preview.paid_transfer"><strong>轉入金額：${{ preview.paid_transfer.transfer_amount }}</strong>（原收款與收據不變，帳上以轉出／轉入紀錄連動）</div>
      </div>
      <p v-if="error" class="error" role="alert">{{ error }}</p>

      <div class="actions">
        <button class="ghost" :disabled="busy" @click="$emit('close')">取消</button>
        <button class="ghost" :disabled="busy || !canSubmit" @click="run(true)">試算</button>
        <button class="primary" :disabled="busy || !canSubmit || !preview || !form.reason" @click="run(false)">確認轉課</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { supabase } from '../../supabase';

const props = defineProps({
  show: Boolean,
  courseId: { type: [Number, String], default: null },
  subjects: { type: Array, default: () => [] },
  teachers: { type: Array, default: () => [] },
  // in-app #409: values carried over from a blocked 課程設定 save.
  prefill: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done']);
const DAYS = ['一', '二', '三', '四', '五', '六', '日'];
const form = reactive({ subject: '', teacher_id: '', start_date: '', weekday: '', time: '16:00', duration_minutes: 120, reason: '' });
const preview = ref(null);
const error = ref('');
const busy = ref(false);
// A multi-slot edit is carried whole; 轉課 replaces the weekly schedule with this list.
const multiSlots = computed(() => (props.prefill?.slots?.length > 1 ? props.prefill.slots : null));
const canSubmit = computed(() => !!form.start_date && (form.subject || form.teacher_id || form.weekday || multiSlots.value));

watch(() => props.show, (v) => {
  if (v) {
    const { subject = '', teacher_id = '', start_date = '', weekday = '', time = '16:00', duration_minutes = 120 } = props.prefill || {};
    Object.assign(form, { subject, teacher_id, start_date, weekday, time, duration_minutes, reason: '' });
    preview.value = null;
    error.value = '';
  }
});
// Any input change invalidates a previous preview.
watch(() => [form.subject, form.teacher_id, form.start_date, form.weekday, form.time, form.duration_minutes], () => { preview.value = null; });

const body = () => ({
  start_date: form.start_date,
  reason: form.reason || '轉課',
  ...(form.subject ? { subject: form.subject } : {}),
  ...(form.teacher_id ? { teacher_id: Number(form.teacher_id) } : {}),
  ...(multiSlots.value
    ? { slots: multiSlots.value.map((x) => ({ weekday: Number(x.weekday), time: x.time, duration_minutes: Number(x.duration_minutes) })) }
    : form.weekday ? { slots: [{ weekday: Number(form.weekday), time: form.time, duration_minutes: Number(form.duration_minutes) }] } : {}),
});

async function run(previewOnly) {
  busy.value = true;
  error.value = '';
  try {
    const { data: { session } } = await supabase.auth.getSession();
    const res = await fetch(`/api/v1/student-classes/${props.courseId}/split-contract${previewOnly ? '/preview' : ''}`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${session?.access_token}` },
      body: JSON.stringify(body()),
    });
    const payload = await res.json().catch(() => ({}));
    if (!res.ok) {
      error.value = payload?.message || '轉課失敗，請檢查欄位。';
      return;
    }
    if (previewOnly) preview.value = payload;
    else emit('done', payload);
  } catch {
    error.value = '連線失敗，請稍後再試。';
  } finally {
    busy.value = false;
  }
}
</script>

<style scoped>
.course-modal { width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; }
.modal-title { font-size: 1.2rem; font-weight: 800; margin: 0 0 4px; }
.modal-desc { color: var(--text-light); font-size: 13px; margin: 0 0 12px; }
.prefill-note { margin: 0 0 8px; padding: 8px 10px; border-radius: var(--ds-radius-md); font-size: var(--ds-font-size-sm); background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline); }
.form-label { display: block; font-size: 13px; font-weight: 700; margin: 10px 0 6px; }
.form-input { width: 100%; padding: 8px 10px; border: 1px solid var(--ds-hairline); border-radius: 8px; font-size: 14px; }
.slot-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; }
.preview { margin-top: 12px; padding: 10px 12px; border-radius: 10px; background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline); font-size: 13px; line-height: 1.6; }
.error { margin: 10px 0; padding: 8px 10px; border-radius: 8px; font-size: 13px; background: var(--ds-danger-wash); color: var(--ds-danger); }
.actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
@media (max-width: 520px) { .slot-row { grid-template-columns: 1fr; } }
</style>
