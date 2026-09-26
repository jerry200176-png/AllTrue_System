<template>
  <div class="tf-mas-page">
    <AtPageHeader
      title="精熟檢核"
      description="延遲提取／精熟證據（教師登錄；非外部 LLM 自動評分）。"
      icon="verified"
    >
      <template #actions>
        <AtButton variant="ghost" shape="rect" icon="arrow_back" @click="$emit('back')">返回今日課程</AtButton>
      </template>
    </AtPageHeader>
    <AtInlineAlert v-if="error" tone="danger" role="alert">{{ error }}</AtInlineAlert>
    <AtInlineAlert v-if="savedHint" tone="success" role="status">{{ savedHint }}</AtInlineAlert>
    <AtCard class="tf-mas-card">
      <div v-if="sessionLabel" class="tf-mas-context" role="status">
        <span class="tf-mas-context__label">已選課程</span>
        <strong>{{ sessionLabel }}</strong>
      </div>
      <AtEmpty v-if="!session" icon="event_busy" title="尚未選定課程" description="請從今日課程清單選擇一堂課。" />
      <form v-else class="tf-mas-form" @submit.prevent="saveEvidence">
        <AtField label="目標迷思標籤">
          <input v-model="targetLabel" type="text" class="tf-mas-input" required />
        </AtField>
        <AtField label="提取提示／檢核題">
          <textarea v-model="retrievalPrompt" rows="2" class="tf-mas-input" required />
        </AtField>
        <AtField label="學生回應摘要" hint="最小化；勿貼上原始 PII">
          <textarea v-model="responseSummary" rows="2" class="tf-mas-input" />
        </AtField>
        <AtField label="精熟結果">
          <AtSelect v-model="outcome" :options="outcomeOptions" />
        </AtField>
        <AtField label="下次複習視窗">
          <AtSelect v-model="nextReviewWindow" :options="windowOptions" />
        </AtField>
        <AtField label="教師決策">
          <AtSelect v-model="teacherDecision" :options="decisionOptions" />
        </AtField>
        <AtField label="證據備註（短述）">
          <input v-model="evidenceNotes" type="text" class="tf-mas-input" maxlength="240" />
        </AtField>
        <div class="tf-mas-actions">
          <AtButton variant="primary" shape="rect" icon="save" type="submit" :loading="saving" :disabled="saving">
            儲存精熟證據
          </AtButton>
          <AtButton
            v-if="canContinueFromStage({ stage: 'mastery', savedRecordId })"
            variant="ghost"
            shape="rect"
            icon="home"
            type="button"
            data-testid="truefit-loop-back-workspace"
            @click="$emit('continue', session)"
          >
            返回今日課程
          </AtButton>
        </div>
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
import {
  fetchTrueFitMastery,
  fetchTrueFitRemediation,
  upsertTrueFitMastery,
} from '../lib/truefitApi.js';
import {
  canContinueFromStage,
  seedMasteryFromRemediation,
  shouldApplyContinuumSeed,
} from '../lib/truefitLoop.js';

const props = defineProps({ session: { type: Object, default: null }, token: { type: String, required: true } });
defineEmits(['back', 'continue']);

const error = ref('');
const savedHint = ref('');
const saving = ref(false);
const savedRecordId = ref(null);
const sourceRemediationId = ref(null);
const targetLabel = ref('');
const retrievalPrompt = ref('');
const responseSummary = ref('');
const outcome = ref('not_checked');
const nextReviewWindow = ref('none');
const teacherDecision = ref('pending');
const evidenceNotes = ref('');

const outcomeOptions = [
  { value: 'mastered', label: '已精熟' },
  { value: 'partial', label: '部分' },
  { value: 'not_yet', label: '尚未' },
  { value: 'not_checked', label: '未檢核' },
];
const windowOptions = [
  { value: 'none', label: '無需' },
  { value: 'within_7_days', label: '七天內' },
  { value: 'within_30_days', label: '三十天內' },
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

function sessionQuery() {
  const s = props.session || {};
  if (s.class_session_id) return { classSessionId: s.class_session_id };
  return {
    studentClassId: s.student_class_id,
    sessionDate: s.session_date,
    startTime: String(s.start_time || '').slice(0, 5),
  };
}

function applyEvidence(p) {
  if (!p) return;
  targetLabel.value = p.target_misconception_label || '';
  retrievalPrompt.value = p.retrieval_prompt || '';
  responseSummary.value = p.student_response_summary || '';
  outcome.value = p.outcome || 'not_checked';
  nextReviewWindow.value = p.next_review_window || 'none';
  teacherDecision.value = p.teacher_decision || 'pending';
  evidenceNotes.value = p.evidence_notes || '';
  if (p.source_remediation_id != null) {
    sourceRemediationId.value = Number(p.source_remediation_id) || null;
  }
}

function applySeedIfEmpty(seed) {
  if (!seed) return;
  if (!targetLabel.value) targetLabel.value = seed.target_misconception_label || '';
  if (!retrievalPrompt.value) retrievalPrompt.value = seed.retrieval_prompt || '';
}

async function loadExisting() {
  if (!props.session) return;
  error.value = '';
  try {
    const [masPayload, remPayload] = await Promise.all([
      fetchTrueFitMastery({ token: props.token, ...sessionQuery() }),
      fetchTrueFitRemediation({ token: props.token, ...sessionQuery() }).catch(() => null),
    ]);
    savedRecordId.value = masPayload?.data?.id || null;
    if (masPayload?.data?.source_remediation_id != null) {
      sourceRemediationId.value = Number(masPayload.data.source_remediation_id) || null;
    } else if (remPayload?.data?.id) {
      sourceRemediationId.value = Number(remPayload.data.id) || null;
    }
    applyEvidence(masPayload?.data?.mastery || null);
    const seed = seedMasteryFromRemediation(remPayload?.data?.remediation || null);
    if (shouldApplyContinuumSeed({ savedRecordId: savedRecordId.value, seed })) {
      applySeedIfEmpty(seed);
    }
  } catch (e) {
    error.value = e?.message || '精熟證據載入失敗';
  }
}

async function saveEvidence() {
  if (!props.session) return;
  if (!String(targetLabel.value || '').trim() || !String(retrievalPrompt.value || '').trim()) {
    error.value = '目標迷思標籤與提取提示為必填';
    return;
  }
  saving.value = true;
  error.value = '';
  savedHint.value = '';
  try {
    const payload = await upsertTrueFitMastery({
      token: props.token,
      ...sessionQuery(),
      mastery: {
        target_misconception_label: targetLabel.value,
        retrieval_prompt: retrievalPrompt.value,
        student_response_summary: responseSummary.value || '',
        outcome: outcome.value,
        evidence_notes: evidenceNotes.value || '',
        next_review_window: nextReviewWindow.value,
        teacher_decision: teacherDecision.value,
        source_remediation_id: sourceRemediationId.value,
      },
    });
    savedRecordId.value = payload?.data?.id || null;
    if (payload?.data?.source_remediation_id != null) {
      sourceRemediationId.value = Number(payload.data.source_remediation_id) || null;
    }
    applyEvidence(payload?.data?.mastery || null);
    savedHint.value = '精熟證據已儲存';
  } catch (e) {
    error.value = e?.message || '精熟證據儲存失敗';
  } finally {
    saving.value = false;
  }
}

onMounted(loadExisting);
watch(() => props.session, () => {
  savedHint.value = '';
  savedRecordId.value = null;
  sourceRemediationId.value = null;
  loadExisting();
});
</script>

<style scoped>
.tf-mas-page { max-width: 720px; margin: 0 auto; }
.tf-mas-card { padding: var(--ds-space-4); display: grid; gap: var(--ds-space-4); }
.tf-mas-context { padding: var(--ds-space-3); border-radius: var(--ds-radius-md); background: var(--ds-canvas-soft); border: 1px solid var(--ds-hairline); }
.tf-mas-context__label { display: block; font-size: var(--ds-font-size-sm); color: var(--ds-text-tertiary); margin-bottom: var(--ds-space-1); }
.tf-mas-form { display: grid; gap: var(--ds-space-3); }
.tf-mas-actions { display: flex; flex-wrap: wrap; gap: var(--ds-space-2); justify-content: flex-end; }
.tf-mas-input { width: 100%; box-sizing: border-box; border: 1px solid var(--ds-hairline); border-radius: var(--ds-radius-md); background: var(--ds-surface-0); color: var(--ds-text-primary); padding: var(--ds-space-2) var(--ds-space-3); font: inherit; line-height: 1.45; }
</style>
