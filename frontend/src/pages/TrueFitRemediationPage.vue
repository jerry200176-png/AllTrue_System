<template>
  <div class="tf-rem-page">
    <AtPageHeader
      title="補救計畫"
      description="依已確認診斷制定結構化補救計畫（教師確認；非外部 LLM）。"
      icon="healing"
    >
      <template #actions>
        <AtButton variant="ghost" shape="rect" icon="arrow_back" @click="$emit('back')">返回今日課程</AtButton>
      </template>
    </AtPageHeader>
    <AtInlineAlert v-if="error" tone="danger" role="alert">{{ error }}</AtInlineAlert>
    <AtInlineAlert v-if="savedHint" tone="success" role="status">{{ savedHint }}</AtInlineAlert>
    <AtCard class="tf-rem-card">
      <div v-if="sessionLabel" class="tf-rem-context" role="status">
        <span class="tf-rem-context__label">已選課程</span>
        <strong>{{ sessionLabel }}</strong>
      </div>
      <AtEmpty v-if="!session" icon="event_busy" title="尚未選定課程" description="請從今日課程清單選擇一堂課。" />
      <form v-else class="tf-rem-form" @submit.prevent="savePlan">
        <AtField label="目標迷思標籤">
          <input v-model="targetLabel" type="text" class="tf-rem-input" required />
        </AtField>
        <AtField label="練習／教學動作" hint="換行分隔；至少一項">
          <textarea v-model="practiceText" rows="2" class="tf-rem-input" required />
        </AtField>
        <AtField label="教材錨點" hint="換行分隔；至少一項">
          <textarea v-model="anchorsText" rows="2" class="tf-rem-input" required />
        </AtField>
        <AtField label="成功標準" hint="換行分隔；至少一項">
          <textarea v-model="criteriaText" rows="2" class="tf-rem-input" required />
        </AtField>
        <AtField label="追蹤視窗">
          <AtSelect v-model="followUpWindow" :options="windowOptions" />
        </AtField>
        <AtField label="教師決策">
          <AtSelect v-model="teacherDecision" :options="decisionOptions" />
        </AtField>
        <AtField label="教師備註（短述）">
          <input v-model="teacherNotes" type="text" class="tf-rem-input" maxlength="240" />
        </AtField>
        <AtButton variant="primary" shape="rect" icon="save" type="submit" :loading="saving" :disabled="saving">
          儲存補救計畫
        </AtButton>
      </form>
    </AtCard>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';
import AtButton from '../components/design-system/AtButton.vue';
import AtCard from '../components/design-system/AtCard.vue';
import AtEmpty from '../components/design-system/AtEmpty.vue';
import AtField from '../components/design-system/AtField.vue';
import AtSelect from '../components/design-system/AtSelect.vue';
import AtInlineAlert from '../components/design-system/AtInlineAlert.vue';
import { fetchTrueFitRemediation, upsertTrueFitRemediation } from '../lib/truefitApi.js';

const props = defineProps({ session: { type: Object, default: null }, token: { type: String, required: true } });
defineEmits(['back']);

const error = ref('');
const savedHint = ref('');
const saving = ref(false);
const targetLabel = ref('');
const practiceText = ref('');
const anchorsText = ref('');
const criteriaText = ref('');
const followUpWindow = ref('next_session');
const teacherDecision = ref('pending');
const teacherNotes = ref('');

const windowOptions = [
  { value: 'same_session', label: '當堂' },
  { value: 'next_session', label: '下一堂' },
  { value: 'within_7_days', label: '七天內' },
];
const decisionOptions = [
  { value: 'pending', label: '待確認' },
  { value: 'accepted', label: '接受' },
  { value: 'edited', label: '已修改' },
  { value: 'rejected', label: '否決' },
];

const sessionLabel = computed(() => {
  if (!props.session) return '';
  return [props.session.start_time, props.session.student_name, props.session.subject_name].filter(Boolean).join(' · ');
});

function linesToList(text) {
  return String(text || '').split('\n').map((s) => s.trim()).filter(Boolean);
}
function sessionQuery() {
  const s = props.session || {};
  if (s.class_session_id) return { classSessionId: s.class_session_id };
  return { studentClassId: s.student_class_id, sessionDate: s.session_date, startTime: String(s.start_time || '').slice(0, 5) };
}
function applyPlan(p) {
  if (!p) return;
  targetLabel.value = p.target_misconception_label || '';
  practiceText.value = (p.practice_moves || []).join('\n');
  anchorsText.value = (p.material_anchors || []).join('\n');
  criteriaText.value = (p.success_criteria || []).join('\n');
  followUpWindow.value = p.follow_up_window || 'next_session';
  teacherDecision.value = p.teacher_decision || 'pending';
  teacherNotes.value = p.teacher_notes || '';
}
async function loadExisting() {
  if (!props.session) return;
  error.value = '';
  try {
    const payload = await fetchTrueFitRemediation({ token: props.token, ...sessionQuery() });
    applyPlan(payload?.data?.remediation || null);
  } catch (e) {
    error.value = e?.message || '補救計畫載入失敗';
  }
}
async function savePlan() {
  if (!props.session) return;
  const practice = linesToList(practiceText.value);
  const anchors = linesToList(anchorsText.value);
  const criteria = linesToList(criteriaText.value);
  if (!practice.length || !anchors.length || !criteria.length) {
    error.value = '練習動作、教材錨點、成功標準皆至少一項';
    return;
  }
  saving.value = true;
  error.value = '';
  savedHint.value = '';
  try {
    const payload = await upsertTrueFitRemediation({
      token: props.token,
      ...sessionQuery(),
      remediation: {
        target_misconception_label: targetLabel.value,
        practice_moves: practice,
        material_anchors: anchors,
        success_criteria: criteria,
        follow_up_window: followUpWindow.value,
        teacher_decision: teacherDecision.value,
        teacher_notes: teacherNotes.value || '',
        source_diagnosis_id: null,
      },
    });
    applyPlan(payload?.data?.remediation || null);
    savedHint.value = '補救計畫已儲存';
  } catch (e) {
    error.value = e?.message || '補救計畫儲存失敗';
  } finally {
    saving.value = false;
  }
}
onMounted(loadExisting);
watch(() => props.session, () => { savedHint.value = ''; loadExisting(); });
</script>

<style scoped>
.tf-rem-page { max-width: 720px; margin: 0 auto; }
.tf-rem-card { padding: var(--ds-space-4); display: grid; gap: var(--ds-space-4); }
.tf-rem-context { padding: var(--ds-space-3); border-radius: var(--ds-radius-md); background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline); }
.tf-rem-context__label { display: block; font-size: var(--ds-font-size-sm); color: var(--ds-text-tertiary); margin-bottom: var(--ds-space-1); }
.tf-rem-form { display: grid; gap: var(--ds-space-3); }
.tf-rem-input { width: 100%; box-sizing: border-box; border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-md); background: var(--ds-surface-0); color: var(--ds-text-primary); padding: var(--ds-space-2) var(--ds-space-3); font: inherit; line-height: 1.45; }
</style>
