<template>
  <div class="tf-obs-page">
    <AtPageHeader
      title="課堂觀察"
      description="以結構化欄位記錄課堂觀察（教師輸入；非外部 LLM）。"
      icon="visibility"
    >
      <template #actions>
        <AtButton variant="ghost" shape="rect" icon="arrow_back" @click="$emit('back')">返回今日課程</AtButton>
      </template>
    </AtPageHeader>

    <AtInlineAlert v-if="error" tone="danger" role="alert">{{ error }}</AtInlineAlert>
    <AtInlineAlert v-if="savedHint" tone="success" role="status">{{ savedHint }}</AtInlineAlert>

    <AtCard class="tf-obs-card">
      <div v-if="sessionLabel" class="tf-obs-context" role="status">
        <span class="tf-obs-context__label">已選課程</span>
        <strong>{{ sessionLabel }}</strong>
      </div>

      <AtEmpty
        v-if="!session"
        icon="event_busy"
        title="尚未選定課程"
        description="請從今日課程清單選擇一堂課再進入觀察記錄。"
      />

      <form v-else class="tf-obs-form" @submit.prevent="saveObservation">
        <AtField label="觸及的學習目標" hint="多項以換行分隔">
          <textarea v-model="objectivesTouchedText" rows="2" class="tf-obs-input" />
        </AtField>
        <AtField label="學生表現／動作" hint="多項以換行分隔">
          <textarea v-model="studentMovesText" rows="2" class="tf-obs-input" />
        </AtField>
        <AtField label="優勢訊號" hint="多項以換行分隔；可留空">
          <textarea v-model="strengthSignalsText" rows="2" class="tf-obs-input" />
        </AtField>

        <fieldset class="tf-obs-fieldset">
          <legend>掙扎訊號（可留空）</legend>
          <AtField label="標籤">
            <input v-model="struggle.label" type="text" class="tf-obs-input" />
          </AtField>
          <AtField label="觀察到的訊號">
            <input v-model="struggle.signal" type="text" class="tf-obs-input" />
          </AtField>
          <AtField label="對應目標">
            <input v-model="struggle.linked_objective" type="text" class="tf-obs-input" />
          </AtField>
        </fieldset>

        <fieldset class="tf-obs-fieldset">
          <legend>迷思假設（可留空）</legend>
          <AtField label="標籤">
            <input v-model="misconception.label" type="text" class="tf-obs-input" />
          </AtField>
          <AtField label="學生似乎相信">
            <input v-model="misconception.what_student_seemed_to_believe" type="text" class="tf-obs-input" />
          </AtField>
          <AtField label="下一步要確認">
            <input v-model="misconception.what_to_check_next" type="text" class="tf-obs-input" />
          </AtField>
        </fieldset>

        <AtField label="證據備註（短述）">
          <input v-model="evidenceNotes" type="text" class="tf-obs-input" maxlength="240" />
        </AtField>

        <AtField label="信心">
          <AtSelect v-model="confidence" :options="confidenceOptions" />
        </AtField>

        <AtButton
          variant="primary"
          shape="rect"
          icon="save"
          type="submit"
          :loading="saving"
          :disabled="saving || !session"
        >
          儲存觀察
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
import { fetchTrueFitObservation, upsertTrueFitObservation } from '../lib/truefitApi.js';

const props = defineProps({
  session: { type: Object, default: null },
  token: { type: String, required: true },
});

defineEmits(['back']);

const error = ref('');
const savedHint = ref('');
const saving = ref(false);
const loading = ref(false);

const objectivesTouchedText = ref('');
const studentMovesText = ref('');
const strengthSignalsText = ref('');
const evidenceNotes = ref('');
const confidence = ref('medium');
const struggle = reactive({ label: '', signal: '', linked_objective: '' });
const misconception = reactive({
  label: '',
  what_student_seemed_to_believe: '',
  what_to_check_next: '',
});

const confidenceOptions = [
  { value: 'low', label: '低' },
  { value: 'medium', label: '中' },
  { value: 'high', label: '高' },
];

const sessionLabel = computed(() => {
  if (!props.session) return '';
  return [
    props.session.start_time,
    props.session.student_name,
    props.session.subject_name,
  ].filter(Boolean).join(' · ');
});

function linesToList(text) {
  return String(text || '')
    .split('\n')
    .map((s) => s.trim())
    .filter(Boolean);
}

function sessionQuery() {
  const s = props.session || {};
  if (s.class_session_id) {
    return { classSessionId: s.class_session_id };
  }
  return {
    studentClassId: s.student_class_id,
    sessionDate: s.session_date,
    startTime: String(s.start_time || '').slice(0, 5),
  };
}

function applyObservation(obs) {
  if (!obs || typeof obs !== 'object') return;
  objectivesTouchedText.value = (obs.objectives_touched || []).join('\n');
  studentMovesText.value = (obs.student_moves || []).join('\n');
  strengthSignalsText.value = (obs.strength_signals || []).join('\n');
  evidenceNotes.value = obs.evidence_notes || '';
  confidence.value = obs.confidence || 'medium';
  const s0 = (obs.struggle_signals || [])[0] || {};
  struggle.label = s0.label || '';
  struggle.signal = s0.signal || '';
  struggle.linked_objective = s0.linked_objective || '';
  const m0 = (obs.misconception_hypotheses || [])[0] || {};
  misconception.label = m0.label || '';
  misconception.what_student_seemed_to_believe = m0.what_student_seemed_to_believe || '';
  misconception.what_to_check_next = m0.what_to_check_next || '';
}

function buildObservationPayload() {
  const struggleSignals = (struggle.label || struggle.signal)
    ? [{
      label: struggle.label || '未命名',
      signal: struggle.signal || '',
      linked_objective: struggle.linked_objective || '',
    }]
    : [];
  const misconceptions = (misconception.label || misconception.what_student_seemed_to_believe)
    ? [{
      label: misconception.label || '未命名',
      what_student_seemed_to_believe: misconception.what_student_seemed_to_believe || '',
      what_to_check_next: misconception.what_to_check_next || '',
    }]
    : [];

  return {
    objectives_touched: linesToList(objectivesTouchedText.value),
    student_moves: linesToList(studentMovesText.value),
    struggle_signals: struggleSignals,
    strength_signals: linesToList(strengthSignalsText.value),
    misconception_hypotheses: misconceptions,
    evidence_notes: evidenceNotes.value || '',
    confidence: confidence.value || 'medium',
  };
}

async function loadExisting() {
  if (!props.session) return;
  loading.value = true;
  error.value = '';
  try {
    const payload = await fetchTrueFitObservation({
      token: props.token,
      ...sessionQuery(),
    });
    applyObservation(payload?.data?.observation || null);
  } catch (e) {
    error.value = e?.message || '課堂觀察載入失敗';
  } finally {
    loading.value = false;
  }
}

async function saveObservation() {
  if (!props.session) return;
  saving.value = true;
  error.value = '';
  savedHint.value = '';
  try {
    const payload = await upsertTrueFitObservation({
      token: props.token,
      ...sessionQuery(),
      observation: buildObservationPayload(),
    });
    applyObservation(payload?.data?.observation || null);
    savedHint.value = '觀察已儲存';
  } catch (e) {
    error.value = e?.message || '課堂觀察儲存失敗';
  } finally {
    saving.value = false;
  }
}

onMounted(loadExisting);
watch(() => props.session, () => {
  savedHint.value = '';
  loadExisting();
});
</script>

<style scoped>
.tf-obs-page {
  max-width: 720px;
  margin: 0 auto;
}

.tf-obs-card {
  padding: var(--ds-space-4);
  display: grid;
  gap: var(--ds-space-4);
}

.tf-obs-context {
  padding: var(--ds-space-3);
  border-radius: var(--ds-radius-md);
  background: var(--ds-canvas-soft);
  border: 1px solid var(--ds-hairline);
}

.tf-obs-context__label {
  display: block;
  font-size: var(--ds-font-size-sm);
  color: var(--ds-text-tertiary);
  margin-bottom: var(--ds-space-1);
}

.tf-obs-form {
  display: grid;
  gap: var(--ds-space-3);
}

.tf-obs-fieldset {
  margin: 0;
  padding: var(--ds-space-3);
  border: 1px solid var(--ds-hairline);
  border-radius: var(--ds-radius-md);
  display: grid;
  gap: var(--ds-space-2);
}

.tf-obs-fieldset legend {
  padding-inline: var(--ds-space-1);
  font-size: var(--ds-font-size-sm);
  color: var(--ds-text-secondary);
}

.tf-obs-input {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid var(--ds-hairline);
  border-radius: var(--ds-radius-md);
  background: var(--ds-surface-0);
  color: var(--ds-text-primary);
  padding: var(--ds-space-2) var(--ds-space-3);
  font: inherit;
  line-height: 1.45;
}
</style>
