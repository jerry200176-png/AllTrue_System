<template>
  <div class="tf-diag-page">
    <AtPageHeader
      title="錯誤診斷"
      description="依課堂觀察提出結構化診斷提案，並由教師確認（非外部 LLM）。"
      icon="psychology"
    >
      <template #actions>
        <AtButton variant="ghost" shape="rect" icon="arrow_back" @click="$emit('back')">返回今日課程</AtButton>
      </template>
    </AtPageHeader>

    <AtInlineAlert v-if="error" tone="danger" role="alert">{{ error }}</AtInlineAlert>
    <AtInlineAlert v-if="savedHint" tone="success" role="status">{{ savedHint }}</AtInlineAlert>

    <AtCard class="tf-diag-card">
      <div v-if="sessionLabel" class="tf-diag-context" role="status">
        <span class="tf-diag-context__label">已選課程</span>
        <strong>{{ sessionLabel }}</strong>
      </div>

      <AtEmpty
        v-if="!session"
        icon="event_busy"
        title="尚未選定課程"
        description="請從今日課程清單選擇一堂課再進入診斷。"
      />

      <form v-else class="tf-diag-form" @submit.prevent="saveDiagnosis">
        <fieldset class="tf-diag-fieldset">
          <legend>主要迷思</legend>
          <AtField label="標籤">
            <input v-model="primary.label" type="text" class="tf-diag-input" required />
          </AtField>
          <AtField label="迷思陳述">
            <input v-model="primary.statement" type="text" class="tf-diag-input" required />
          </AtField>
          <AtField label="為何符合觀察">
            <input v-model="primary.why_it_fits_observation" type="text" class="tf-diag-input" required />
          </AtField>
          <AtField label="對應 Brief 目標">
            <input v-model="primary.linked_brief_objective" type="text" class="tf-diag-input" />
          </AtField>
        </fieldset>

        <AtField label="支持訊號" hint="多項以換行分隔">
          <textarea v-model="supportingText" rows="2" class="tf-diag-input" />
        </AtField>
        <AtField label="已排除" hint="多項以換行分隔；可留空">
          <textarea v-model="ruledOutText" rows="2" class="tf-diag-input" />
        </AtField>
        <AtField label="建議下一步檢查" hint="至少一項；換行分隔">
          <textarea v-model="checksText" rows="2" class="tf-diag-input" required />
        </AtField>
        <AtField label="教師備註（短述）">
          <input v-model="teacherNotes" type="text" class="tf-diag-input" maxlength="240" />
        </AtField>
        <AtField label="信心">
          <AtSelect v-model="confidence" :options="confidenceOptions" />
        </AtField>
        <AtField label="教師決策">
          <AtSelect v-model="teacherDecision" :options="decisionOptions" />
        </AtField>

        <AtButton variant="primary" shape="rect" icon="save" type="submit" :loading="saving" :disabled="saving">
          儲存診斷
        </AtButton>
      </form>
    </AtCard>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AtPageHeader from '../components/design-system/AtPageHeader.vue';
import AtButton from '../components/design-system/AtButton.vue';
import AtCard from '../components/design-system/AtCard.vue';
import AtEmpty from '../components/design-system/AtEmpty.vue';
import AtField from '../components/design-system/AtField.vue';
import AtSelect from '../components/design-system/AtSelect.vue';
import AtInlineAlert from '../components/design-system/AtInlineAlert.vue';
import { fetchTrueFitDiagnosis, upsertTrueFitDiagnosis } from '../lib/truefitApi.js';

const props = defineProps({
  session: { type: Object, default: null },
  token: { type: String, required: true },
});
defineEmits(['back']);

const error = ref('');
const savedHint = ref('');
const saving = ref(false);
const supportingText = ref('');
const ruledOutText = ref('');
const checksText = ref('');
const teacherNotes = ref('');
const confidence = ref('medium');
const teacherDecision = ref('pending');
const primary = reactive({
  label: '',
  statement: '',
  why_it_fits_observation: '',
  linked_brief_objective: '',
});

const confidenceOptions = [
  { value: 'low', label: '低' },
  { value: 'medium', label: '中' },
  { value: 'high', label: '高' },
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
  return {
    studentClassId: s.student_class_id,
    sessionDate: s.session_date,
    startTime: String(s.start_time || '').slice(0, 5),
  };
}

function applyDiagnosis(d) {
  if (!d || typeof d !== 'object') return;
  const p = d.primary_misconception || {};
  primary.label = p.label || '';
  primary.statement = p.statement || '';
  primary.why_it_fits_observation = p.why_it_fits_observation || '';
  primary.linked_brief_objective = p.linked_brief_objective || '';
  supportingText.value = (d.supporting_signals || []).join('\n');
  ruledOutText.value = (d.ruled_out || []).join('\n');
  checksText.value = (d.recommended_checks || []).join('\n');
  teacherNotes.value = d.teacher_notes || '';
  confidence.value = d.confidence || 'medium';
  teacherDecision.value = d.teacher_decision || 'pending';
}

async function loadExisting() {
  if (!props.session) return;
  error.value = '';
  try {
    const payload = await fetchTrueFitDiagnosis({ token: props.token, ...sessionQuery() });
    applyDiagnosis(payload?.data?.diagnosis || null);
  } catch (e) {
    error.value = e?.message || '錯誤診斷載入失敗';
  }
}

async function saveDiagnosis() {
  if (!props.session) return;
  const checks = linesToList(checksText.value);
  if (!checks.length) {
    error.value = '請至少填寫一項建議下一步檢查';
    return;
  }
  saving.value = true;
  error.value = '';
  savedHint.value = '';
  try {
    const payload = await upsertTrueFitDiagnosis({
      token: props.token,
      ...sessionQuery(),
      diagnosis: {
        primary_misconception: { ...primary },
        supporting_signals: linesToList(supportingText.value),
        ruled_out: linesToList(ruledOutText.value),
        recommended_checks: checks,
        confidence: confidence.value,
        teacher_decision: teacherDecision.value,
        teacher_notes: teacherNotes.value || '',
        source_observation_id: null,
      },
    });
    applyDiagnosis(payload?.data?.diagnosis || null);
    savedHint.value = '診斷已儲存';
  } catch (e) {
    error.value = e?.message || '錯誤診斷儲存失敗';
  } finally {
    saving.value = false;
  }
}

onMounted(loadExisting);
watch(() => props.session, () => { savedHint.value = ''; loadExisting(); });
</script>

<style scoped>
.tf-diag-page { max-width: 720px; margin: 0 auto; }
.tf-diag-card { padding: var(--ds-space-4); display: grid; gap: var(--ds-space-4); }
.tf-diag-context { padding: var(--ds-space-3); border-radius: var(--ds-radius-md); background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline); }
.tf-diag-context__label { display: block; font-size: var(--ds-font-size-sm); color: var(--ds-text-tertiary); margin-bottom: var(--ds-space-1); }
.tf-diag-form { display: grid; gap: var(--ds-space-3); }
.tf-diag-fieldset { margin: 0; padding: var(--ds-space-3); border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-md); display: grid; gap: var(--ds-space-2); }
.tf-diag-fieldset legend { padding-inline: var(--ds-space-1); font-size: var(--ds-font-size-sm); color: var(--ds-text-secondary); }
.tf-diag-input { width: 100%; box-sizing: border-box; border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-md); background: var(--ds-surface-0); color: var(--ds-text-primary); padding: var(--ds-space-2) var(--ds-space-3); font: inherit; line-height: 1.45; }
</style>
