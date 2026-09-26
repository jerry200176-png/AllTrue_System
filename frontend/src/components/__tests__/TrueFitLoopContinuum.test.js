import { describe, it, expect } from 'vitest';
import {
  nextTrueFitStage,
  nextTrueFitStageCta,
  seedDiagnosisFromObservation,
  seedRemediationFromDiagnosis,
  seedMasteryFromRemediation,
  TRUEFIT_LOOP_STAGES,
  deriveSessionStagePresence,
  emptySessionStagePresence,
  stagePresenceFromPayload,
  formatSessionProgressStrip,
  canContinueFromStage,
  shouldApplyContinuumSeed,
} from '../../lib/truefitLoop.js';

describe('truefitLoop continuum', () => {
  it('orders the same-session learning loop', () => {
    expect(TRUEFIT_LOOP_STAGES).toEqual([
      'prep',
      'observe',
      'diagnose',
      'remediate',
      'mastery',
    ]);
    expect(nextTrueFitStage('prep')).toBe('observe');
    expect(nextTrueFitStage('observe')).toBe('diagnose');
    expect(nextTrueFitStage('diagnose')).toBe('remediate');
    expect(nextTrueFitStage('remediate')).toBe('mastery');
    expect(nextTrueFitStage('mastery')).toBeNull();
    expect(nextTrueFitStageCta('prep')).toBe('進入課堂觀察');
    expect(nextTrueFitStageCta('mastery')).toBe('返回今日課程');
  });

  it('seeds diagnosis from observation hypotheses', () => {
    const seed = seedDiagnosisFromObservation({
      student_moves: ['把分母相加'],
      misconception_hypotheses: [{
        label: '分母相加',
        what_student_seemed_to_believe: '分數加減把分母相加',
        what_to_check_next: '給同分母對照題',
      }],
      struggle_signals: [],
    });
    expect(seed.primary_misconception.label).toBe('分母相加');
    expect(seed.recommended_checks).toContain('給同分母對照題');
    expect(seed.supporting_signals).toContain('把分母相加');
  });

  it('seeds remediation and mastery from prior stage payloads', () => {
    const rem = seedRemediationFromDiagnosis({
      primary_misconception: {
        label: '分母相加',
        statement: 'x',
        why_it_fits_observation: 'y',
        linked_brief_objective: '通分',
      },
      recommended_checks: ['對照題'],
    });
    expect(rem.target_misconception_label).toBe('分母相加');
    expect(rem.material_anchors).toContain('通分');

    const mas = seedMasteryFromRemediation({
      target_misconception_label: '分母相加',
      success_criteria: ['能正確通分'],
    });
    expect(mas.target_misconception_label).toBe('分母相加');
    expect(mas.retrieval_prompt).toBe('能正確通分');
  });

  it('returns null for null or partial prior payloads (fail closed)', () => {
    expect(seedDiagnosisFromObservation(null)).toBeNull();
    expect(seedDiagnosisFromObservation({})).toBeNull();
    expect(seedDiagnosisFromObservation({
      misconception_hypotheses: [{ label: '', what_student_seemed_to_believe: '' }],
      struggle_signals: [],
    })).toBeNull();
    expect(seedRemediationFromDiagnosis(null)).toBeNull();
    expect(seedRemediationFromDiagnosis({ primary_misconception: { label: '' } })).toBeNull();
    expect(seedMasteryFromRemediation(null)).toBeNull();
    expect(seedMasteryFromRemediation({ target_misconception_label: '  ' })).toBeNull();
  });

  it('derives session progress empty|saved from fan-out payloads', () => {
    expect(stagePresenceFromPayload({ data: null })).toBe('empty');
    expect(stagePresenceFromPayload({ data: { id: 12 } })).toBe('saved');
    expect(deriveSessionStagePresence({})).toEqual(emptySessionStagePresence());
    const presence = deriveSessionStagePresence({
      observe: { data: { id: 7, observation: {} } },
      diagnose: { data: null },
    });
    expect(presence.prep).toBe('empty');
    expect(presence.observe).toBe('saved');
    expect(presence.diagnose).toBe('empty');
    expect(formatSessionProgressStrip(presence)).toContain('觀察·已存');
    expect(formatSessionProgressStrip(presence)).toContain('診斷·尚未');
  });

  it('gates continuum CTAs and seed overwrite fail-closed', () => {
    expect(canContinueFromStage({ stage: 'prep', hasBrief: false })).toBe(false);
    expect(canContinueFromStage({ stage: 'prep', hasBrief: true })).toBe(true);
    expect(canContinueFromStage({ stage: 'observe', savedRecordId: null })).toBe(false);
    expect(canContinueFromStage({ stage: 'observe', savedRecordId: 9 })).toBe(true);
    expect(canContinueFromStage({ stage: 'mastery', savedRecordId: 3 })).toBe(true);
    expect(shouldApplyContinuumSeed({ savedRecordId: 1, seed: { a: 1 } })).toBe(false);
    expect(shouldApplyContinuumSeed({ savedRecordId: null, seed: null })).toBe(false);
    expect(shouldApplyContinuumSeed({ savedRecordId: null, seed: { a: 1 } })).toBe(true);
  });
});
