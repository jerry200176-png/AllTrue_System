<script setup>
// 試聽暫留 (in-app #575): reserve a teacher's slot for a trial before the student's details are known.
// The server runs the same clash check as a real trial and refuses with a plain message; nothing is saved then.
import { computed, ref, watch } from 'vue';
import { FormDialog } from '@/components/app-overlay';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { createTrialHold } from '../../api.js';

const props = defineProps({
  open: { type: Boolean, default: false },
  token: { type: String, default: '' },
  campusId: { type: [Number, String], default: null },
  teachers: { type: Array, default: () => [] }, // [{ id, username|name }]
  prefill: { type: Object, default: () => ({}) }, // { teacher_id, hold_date, start_time }
});
const emit = defineEmits(['update:open', 'created']);

const blank = () => ({ teacher_id: '', hold_date: '', start_time: '16:00', end_time: '17:00', note: '' });
const form = ref(blank());
const saving = ref(false);
const error = ref('');

const plusHour = (hhmm) => {
  const [h, m] = String(hhmm || '').split(':').map(Number);
  return `${String(Math.min((h || 0) + 1, 23)).padStart(2, '0')}:${String(m || 0).padStart(2, '0')}`;
};

watch(() => props.open, (open) => {
  if (!open) return;
  const start = props.prefill.start_time || '16:00';
  form.value = { ...blank(), ...props.prefill, teacher_id: props.prefill.teacher_id ? String(props.prefill.teacher_id) : '', start_time: start, end_time: plusHour(start) };
  error.value = '';
});

const dirty = computed(() => Boolean(form.value.note));

async function submit() {
  if (!form.value.teacher_id || !form.value.hold_date) { error.value = '請選老師和日期。'; return; }
  if (form.value.end_time <= form.value.start_time) { error.value = '結束時間要晚於開始時間。'; return; }
  saving.value = true; error.value = '';
  try {
    await createTrialHold(props.token, {
      campus_id: Number(props.campusId), teacher_id: Number(form.value.teacher_id), hold_date: form.value.hold_date,
      start_time: form.value.start_time, end_time: form.value.end_time, note: form.value.note.trim() || null,
    });
    emit('created');
    emit('update:open', false);
  } catch (e) {
    error.value = e?.message || '沒有暫留成功，請再試一次。';
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <FormDialog
    :open="open"
    title="試聽暫留"
    description="先幫老師留住這個時段，還不用填學生資料。之後在「招生詢問」安排試聽時選這筆暫留就好。"
    size="sm"
    submit-label="暫留"
    :loading="saving"
    :dirty="dirty"
    @update:open="emit('update:open', $event)"
    @submit="submit"
  >
    <div class="tw:flex tw:flex-col tw:gap-3" data-testid="trial-hold-form">
      <div class="tw:flex tw:flex-col tw:gap-1">
        <Label for="hold-teacher">老師</Label>
        <select id="hold-teacher" v-model="form.teacher_id" required class="tw:h-11 tw:rounded-md tw:border tw:border-input tw:bg-background tw:px-3">
          <option value="" disabled>選擇老師</option>
          <option v-for="t in teachers" :key="t.id" :value="String(t.id)">{{ t.username || t.name || t.Name }}</option>
        </select>
      </div>
      <div class="tw:flex tw:flex-col tw:gap-1">
        <Label for="hold-date">日期</Label>
        <Input id="hold-date" v-model="form.hold_date" type="date" required class="tw:h-11" />
      </div>
      <div class="tw:grid tw:grid-cols-2 tw:gap-3">
        <div class="tw:flex tw:flex-col tw:gap-1">
          <Label for="hold-start">開始</Label>
          <Input id="hold-start" v-model="form.start_time" type="time" min="08:00" max="22:59" required class="tw:h-11" />
        </div>
        <div class="tw:flex tw:flex-col tw:gap-1">
          <Label for="hold-end">結束</Label>
          <Input id="hold-end" v-model="form.end_time" type="time" required class="tw:h-11" />
        </div>
      </div>
      <div class="tw:flex tw:flex-col tw:gap-1">
        <Label for="hold-note">備註（選填，只有主任看得到）</Label>
        <Input id="hold-note" v-model="form.note" maxlength="100" placeholder="例如：電話詢問，國二數學" class="tw:h-11" />
      </div>
      <p v-if="error" class="tw:text-sm tw:text-destructive" role="alert" data-testid="trial-hold-error">{{ error }}</p>
    </div>
  </FormDialog>
</template>
