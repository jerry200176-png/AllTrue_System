/**
 * TrueFit same-session learning loop helpers.
 * prep → observe → diagnose → remediate → mastery → (workspace / next prep)
 */

export const TRUEFIT_LOOP_STAGES = Object.freeze([
  'prep',
  'observe',
  'diagnose',
  'remediate',
  'mastery',
]);

export const LOOP_STAGE_LABELS = Object.freeze({
  prep: '備課',
  observe: '觀察',
  diagnose: '診斷',
  remediate: '補救',
  mastery: '精熟',
});

/** @typedef {'empty' | 'saved'} StagePresence */

const NEXT_STAGE = Object.freeze({
  prep: 'observe',
  observe: 'diagnose',
  diagnose: 'remediate',
  remediate: 'mastery',
  mastery: null,
});

const NEXT_CTA = Object.freeze({
  prep: '進入課堂觀察',
  observe: '進入錯誤診斷',
  diagnose: '進入補救計畫',
  remediate: '進入精熟檢核',
  mastery: '返回今日課程',
});

export function nextTrueFitStage(view) {
  return NEXT_STAGE[view] ?? null;
}

export function nextTrueFitStageCta(view) {
  return NEXT_CTA[view] || '下一步';
}

/**
 * Derive empty|saved from a single stage GET payload (`{ data: row|null }`).
 * @returns {StagePresence}
 */
export function stagePresenceFromPayload(payload) {
  const data = payload?.data;
  if (!data || typeof data !== 'object') return 'empty';
  if (data.id == null || data.id === '') return 'empty';
  return 'saved';
}

/** @returns {Record<string, StagePresence>} */
export function emptySessionStagePresence() {
  return Object.fromEntries(TRUEFIT_LOOP_STAGES.map((s) => [s, 'empty']));
}

/**
 * Build session progress from per-stage GET payloads (Option A fan-out).
 * @param {Record<string, object|null|undefined>} payloadsByStage
 * @returns {Record<string, StagePresence>}
 */
export function deriveSessionStagePresence(payloadsByStage = {}) {
  const out = emptySessionStagePresence();
  for (const stage of TRUEFIT_LOOP_STAGES) {
    out[stage] = stagePresenceFromPayload(payloadsByStage[stage]);
  }
  return out;
}

/**
 * Compact non-card progress text for workspace list rows.
 * @param {Record<string, StagePresence>|null|undefined} presence
 */
export function formatSessionProgressStrip(presence) {
  return TRUEFIT_LOOP_STAGES.map((stage) => {
    const label = LOOP_STAGE_LABELS[stage] || stage;
    const state = presence?.[stage] === 'saved' ? '已存' : '尚未';
    return `${label}·${state}`;
  }).join(' · ');
}

/**
 * Continuum next-CTA enablement — fail closed on empty / unsaved edges.
 * Prep requires a Teacher Brief; later stages require a saved current record.
 */
export function canContinueFromStage({
  stage = null,
  savedRecordId = null,
  hasBrief = false,
} = {}) {
  if (stage === 'prep') return Boolean(hasBrief);
  if (!stage || !TRUEFIT_LOOP_STAGES.includes(stage)) return false;
  return Boolean(savedRecordId);
}

/**
 * Seed only when current stage is unsaved and seed helper returned a payload.
 * Never overwrites an already-saved record.
 */
export function shouldApplyContinuumSeed({ savedRecordId = null, seed = null } = {}) {
  if (savedRecordId) return false;
  return seed != null && typeof seed === 'object';
}

/**
 * Seed diagnosis form fields from a saved observation when the diagnosis is empty.
 * @returns {object|null}
 */
export function seedDiagnosisFromObservation(observation) {
  if (!observation || typeof observation !== 'object') return null;
  const hyp = Array.isArray(observation.misconception_hypotheses)
    ? observation.misconception_hypotheses[0]
    : null;
  const struggle = Array.isArray(observation.struggle_signals)
    ? observation.struggle_signals[0]
    : null;
  if (!hyp && !struggle) return null;

  const label = String(hyp?.label || struggle?.label || '').trim();
  const statement = String(hyp?.what_student_seemed_to_believe || struggle?.signal || '').trim();
  const why = String(hyp?.what_to_check_next || struggle?.signal || '').trim();
  const linked = String(struggle?.linked_objective || '').trim();
  const check = String(hyp?.what_to_check_next || '').trim();

  if (!label && !statement) return null;

  return {
    primary_misconception: {
      label: label || '未命名迷思',
      statement: statement || label || '待教師確認',
      why_it_fits_observation: why || statement || '來自課堂觀察',
      linked_brief_objective: linked,
    },
    supporting_signals: Array.isArray(observation.student_moves)
      ? observation.student_moves.filter(Boolean).slice(0, 3)
      : [],
    recommended_checks: check ? [check] : [],
  };
}

/**
 * Seed remediation form fields from a saved diagnosis.
 * @returns {object|null}
 */
export function seedRemediationFromDiagnosis(diagnosis) {
  if (!diagnosis || typeof diagnosis !== 'object') return null;
  const primary = diagnosis.primary_misconception || {};
  const label = String(primary.label || '').trim();
  if (!label) return null;

  const checks = Array.isArray(diagnosis.recommended_checks)
    ? diagnosis.recommended_checks.filter(Boolean)
    : [];

  return {
    target_misconception_label: label,
    practice_moves: checks.length ? checks : [`針對「${label}」設計對照練習`],
    material_anchors: primary.linked_brief_objective
      ? [String(primary.linked_brief_objective)]
      : [`對應 Brief：${label}`],
    success_criteria: [
      `能正確說明／示範「${label}」的正確做法`,
    ],
  };
}

/**
 * Seed mastery form fields from a saved remediation.
 * @returns {object|null}
 */
export function seedMasteryFromRemediation(remediation) {
  if (!remediation || typeof remediation !== 'object') return null;
  const label = String(remediation.target_misconception_label || '').trim();
  if (!label) return null;

  const criteria = Array.isArray(remediation.success_criteria)
    ? remediation.success_criteria.filter(Boolean)
    : [];

  return {
    target_misconception_label: label,
    retrieval_prompt: criteria[0]
      || `延遲提取：請學生用自己的話解釋「${label}」並完成一道對照題`,
  };
}
