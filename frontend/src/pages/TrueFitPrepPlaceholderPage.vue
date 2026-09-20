<template>
  <div class="tf-prep-page">
    <AtPageHeader
      title="AI 備課簡報"
      description="選擇教材單元後，由系統產生結構化 Teacher Brief（fixture，非外部 LLM）。"
      icon="menu_book"
    >
      <template #actions>
        <AtButton variant="ghost" shape="rect" icon="arrow_back" @click="$emit('back')">返回今日課程</AtButton>
      </template>
    </AtPageHeader>

    <AtInlineAlert v-if="error" tone="danger" role="alert">{{ error }}</AtInlineAlert>

    <AtCard class="tf-prep-card">
      <div v-if="sessionLabel" class="tf-prep-context" role="status">
        <span class="tf-prep-context__label">已選課程</span>
        <strong>{{ sessionLabel }}</strong>
      </div>

      <AtEmpty
        v-if="!session"
        icon="event_busy"
        title="尚未選定課程"
        description="請從今日課程清單選擇一堂課再進入準備流程。"
      />

      <template v-else>
        <div class="tf-prep-form">
          <AtField label="教材／單元" hint="合成教材目錄（v0.1），不含真實版權教材內容。">
            <AtSelect
              v-model="selectedUnitKey"
              :options="unitOptions"
              placeholder="請選擇教材單元"
              :disabled="loadingUnits || generating"
            />
          </AtField>
          <AtButton
            variant="primary"
            shape="rect"
            icon="auto_awesome"
            :loading="generating"
            :disabled="!selectedUnitKey || generating"
            @click="generateBrief"
          >
            產生 Teacher Brief
          </AtButton>
        </div>

        <div v-if="loadingPrep && !brief" class="tf-prep-loading">
          <AtSkeleton :rows="4" height="120px" />
        </div>

        <section v-if="brief" class="tf-brief" aria-label="Teacher Brief">
          <header class="tf-brief__header">
            <h2 class="tf-brief__title">{{ brief.material_title || 'Teacher Brief' }}</h2>
            <AtBadge tone="info">{{ prepMetaLabel }}</AtBadge>
          </header>

          <div class="tf-brief__block" v-for="block in briefBlocks" :key="block.key">
            <h3>{{ block.label }}</h3>
            <ul v-if="block.kind === 'list'">
              <li v-for="(item, idx) in block.items" :key="idx">{{ item }}</li>
            </ul>
            <p v-else-if="block.kind === 'text'">{{ block.text }}</p>
            <ul v-else-if="block.kind === 'misconceptions'">
              <li v-for="(item, idx) in block.items" :key="idx">
                <strong>{{ item.label }}</strong> — {{ item.signal }}；修正：{{ item.repair_move }}
              </li>
            </ul>
            <ol v-else-if="block.kind === 'hints'">
              <li v-for="(item, idx) in block.items" :key="idx">
                L{{ item.level }}：{{ item.hint }}
              </li>
            </ol>
            <div v-else-if="block.kind === 'exit'">
              <p>{{ block.plan.prompt }}</p>
              <ul>
                <li v-for="(c, idx) in (block.plan.success_criteria || [])" :key="idx">{{ c }}</li>
              </ul>
              <p class="tf-brief__muted">未達標：{{ block.plan.follow_up_if_miss }}</p>
            </div>
          </div>

          <div
            v-if="canContinueFromStage({ stage: 'prep', hasBrief: Boolean(brief) })"
            class="tf-brief__next"
          >
            <AtButton
              variant="primary"
              shape="rect"
              icon="visibility"
              data-testid="truefit-next-observe"
              @click="$emit('continue', session)"
            >
              進入課堂觀察
            </AtButton>
          </div>
        </section>
      </template>
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
import AtBadge from '../components/design-system/AtBadge.vue';
import AtSkeleton from '../components/design-system/AtSkeleton.vue';
import {
  fetchTrueFitMaterialUnits,
  fetchTrueFitLessonPrep,
  generateTrueFitLessonPrep,
} from '../lib/truefitApi.js';
import { canContinueFromStage } from '../lib/truefitLoop.js';

const props = defineProps({
  session: { type: Object, default: null },
  token: { type: String, required: true },
});

defineEmits(['back', 'continue']);

const error = ref('');
const loadingUnits = ref(false);
const loadingPrep = ref(false);
const generating = ref(false);
const units = ref([]);
const selectedUnitKey = ref('');
const prep = ref(null);

const brief = computed(() => prep.value?.brief || null);

const sessionLabel = computed(() => {
  if (!props.session) return '';
  return [
    props.session.start_time,
    props.session.student_name,
    props.session.subject_name,
  ].filter(Boolean).join(' · ');
});

const unitOptions = computed(() => units.value.map((u) => ({
  value: u.key,
  label: `${u.subject_hint} · ${u.unit_label} · ${u.title}`,
})));

const prepMetaLabel = computed(() => {
  const provider = prep.value?.brief_provider || 'fixture';
  return `provider: ${provider}`;
});

const briefBlocks = computed(() => {
  const b = brief.value;
  if (!b) return [];
  return [
    { key: 'objectives', label: '學習目標', kind: 'list', items: b.learning_objectives || [] },
    { key: 'prior', label: '先備知識', kind: 'text', text: b.prior_knowledge || '' },
    { key: 'hook', label: '導入 Hook', kind: 'text', text: b.hook || '' },
    { key: 'analogy', label: '類比／表徵', kind: 'text', text: b.analogy_or_representation || '' },
    { key: 'predict', label: '預測問題', kind: 'list', items: b.prediction_questions || [] },
    { key: 'misc', label: '預期迷思', kind: 'misconceptions', items: b.expected_misconceptions || [] },
    { key: 'hints', label: '提示階梯', kind: 'hints', items: b.hint_ladders || [] },
    { key: 'moves', label: '教材對應教學動作', kind: 'list', items: b.teaching_moves_tied_to_material || [] },
    { key: 'exit', label: 'Exit ticket', kind: 'exit', plan: b.exit_ticket_plan || {} },
  ];
});

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

async function loadUnits() {
  loadingUnits.value = true;
  error.value = '';
  try {
    const payload = await fetchTrueFitMaterialUnits({
      token: props.token,
      subjectHint: props.session?.subject_name || '',
    });
    units.value = Array.isArray(payload?.data) ? payload.data : [];
  } catch (e) {
    units.value = [];
    error.value = e?.message || '教材單元載入失敗';
  } finally {
    loadingUnits.value = false;
  }
}

async function loadExistingPrep() {
  if (!props.session) {
    prep.value = null;
    return;
  }
  loadingPrep.value = true;
  error.value = '';
  try {
    const payload = await fetchTrueFitLessonPrep({
      token: props.token,
      ...sessionQuery(),
    });
    prep.value = payload?.data || null;
    if (prep.value?.material_unit_key) {
      selectedUnitKey.value = prep.value.material_unit_key;
    }
  } catch (e) {
    prep.value = null;
    error.value = e?.message || '備課資料載入失敗';
  } finally {
    loadingPrep.value = false;
  }
}

async function generateBrief() {
  if (!props.session || !selectedUnitKey.value) return;
  generating.value = true;
  error.value = '';
  try {
    const payload = await generateTrueFitLessonPrep({
      token: props.token,
      materialUnitKey: selectedUnitKey.value,
      subjectName: props.session.subject_name || '',
      ...sessionQuery(),
    });
    prep.value = payload?.data || null;
  } catch (e) {
    error.value = e?.message || 'Teacher Brief 產生失敗';
  } finally {
    generating.value = false;
  }
}

onMounted(async () => {
  await loadUnits();
  await loadExistingPrep();
});

watch(() => props.session, async () => {
  selectedUnitKey.value = '';
  prep.value = null;
  await loadUnits();
  await loadExistingPrep();
});
</script>

<style scoped>
.tf-prep-page {
  max-width: 720px;
  margin: 0 auto;
}

.tf-prep-card {
  padding: var(--ds-space-4);
  display: grid;
  gap: var(--ds-space-4);
}

.tf-prep-context {
  padding: var(--ds-space-3);
  border-radius: var(--ds-radius-md);
  background: var(--ds-canvas-soft);
  border: 1px solid var(--ds-hairline);
}

.tf-prep-context__label {
  display: block;
  font-size: var(--ds-font-size-sm);
  color: var(--ds-text-tertiary);
  margin-bottom: var(--ds-space-1);
}

.tf-prep-form {
  display: grid;
  gap: var(--ds-space-3);
}

.tf-brief {
  display: grid;
  gap: var(--ds-space-4);
  border-top: 1px solid var(--ds-hairline);
  padding-top: var(--ds-space-4);
}

.tf-brief__header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--ds-space-2);
}

.tf-brief__title {
  margin: 0;
  font-size: var(--ds-font-size-lg);
}

.tf-brief__block h3 {
  margin: 0 0 var(--ds-space-2);
  font-size: var(--ds-font-size-sm);
  color: var(--ds-text-secondary);
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.tf-brief__block p,
.tf-brief__block li {
  margin: 0;
  color: var(--ds-text-primary);
  line-height: 1.55;
}

.tf-brief__block ul,
.tf-brief__block ol {
  margin: 0;
  padding-left: 1.2rem;
  display: grid;
  gap: var(--ds-space-2);
}

.tf-brief__muted {
  margin-top: var(--ds-space-2) !important;
  color: var(--ds-text-tertiary) !important;
  font-size: var(--ds-font-size-sm);
}

.tf-brief__next {
  display: flex;
  justify-content: flex-end;
  padding-top: var(--ds-space-2);
  border-top: 1px solid var(--ds-hairline);
}
</style>
