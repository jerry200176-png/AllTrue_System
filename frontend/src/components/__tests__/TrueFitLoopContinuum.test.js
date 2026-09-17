import { describe, it, expect } from 'vitest';
import {
  nextTrueFitStage,
  nextTrueFitStageCta,
  seedDiagnosisFromObservation,
  seedRemediationFromDiagnosis,
  seedMasteryFromRemediation,
  TRUEFIT_LOOP_STAGES,
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
});
