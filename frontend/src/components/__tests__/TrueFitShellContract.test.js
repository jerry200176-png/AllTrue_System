import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  parseTrueFitRoute,
  buildTrueFitPrepUrl,
  buildTrueFitObserveUrl,
  buildTrueFitRemediateUrl,
  buildTrueFitMasteryUrl,
  seedSessionFromPrepRoute,
  matchTodaySession,
} from '../../lib/truefitRoute.js';
import { isTrueFitHost } from '../../lib/truefitHost.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const appSource = readFileSync(resolve(__dirname, '../../App.vue'), 'utf8');
const workspaceSource = readFileSync(resolve(__dirname, '../../pages/TrueFitWorkspacePage.vue'), 'utf8');
const prepSource = readFileSync(resolve(__dirname, '../../pages/TrueFitPrepPlaceholderPage.vue'), 'utf8');
const navSource = readFileSync(resolve(__dirname, '../../lib/navigationRegistry.js'), 'utf8');
const trueFitAppSource = readFileSync(resolve(__dirname, '../../pages/TrueFitApp.vue'), 'utf8');
const paperFixtureSource = readFileSync(resolve(__dirname, '../../pages/TrueFitPaperFixturePage.vue'), 'utf8');

describe('TrueFit Slice 0 shell contract', () => {
  it('detects truefit subdomain host without path changes', () => {
    expect(isTrueFitHost('truefit.daan.lifenet.com.tw')).toBe(true);
    expect(isTrueFitHost('daan.lifenet.com.tw')).toBe(false);
  });

  it('parses workspace and prep hash routes', () => {
    expect(parseTrueFitRoute({ hash: '#/truefit', search: '', hostname: 'localhost' })).toEqual({ view: 'workspace' });
    expect(parseTrueFitRoute({ hash: '#/truefit/paper-fixture', search: '', hostname: 'localhost' })).toEqual({ view: 'paper-fixture' });
    expect(parseTrueFitRoute({ hash: '#/truefit/prep/42', search: '', hostname: 'localhost' })).toEqual({
      view: 'prep',
      classSessionId: 42,
    });
    expect(parseTrueFitRoute({ hash: '', search: '?truefit=1', hostname: 'localhost' })).toEqual({ view: 'workspace' });
  });

  it('keeps the paper fixture local and clears stale approved previews', () => {
    expect(trueFitAppSource).toContain('const fixtureDemoEnabled = import.meta.env.DEV');
    expect(workspaceSource).toContain('const fixtureDemoEnabled = import.meta.env.DEV');
    expect(paperFixtureSource).toContain('preview.value = null');
    expect(paperFixtureSource).toContain('if (!state.approved || !preview.value) return');
    expect(paperFixtureSource).toContain(':disabled="!state.confirmed"');
    expect(paperFixtureSource).not.toContain('if (!state.confirmed) manual()');
    expect(paperFixtureSource).toContain('p.verifiedAnswer ?? p.ocrAnswer');
    expect(paperFixtureSource).toContain('state.rawAvailable && p.question.id === 8105');
  });

  it('parses and builds prep deep links with session_date', () => {
    expect(parseTrueFitRoute({
      hash: '#/truefit/prep/c55-1630?d=2026-09-16',
      search: '',
      hostname: 'localhost',
    })).toEqual({
      view: 'prep',
      studentClassId: 55,
      projectedStartHm: '1630',
      sessionDate: '2026-09-16',
    });
    expect(parseTrueFitRoute({
      hash: '#/truefit/prep/42?d=2026-09-16',
      search: '',
      hostname: 'localhost',
    })).toEqual({
      view: 'prep',
      classSessionId: 42,
      sessionDate: '2026-09-16',
    });
    expect(buildTrueFitPrepUrl({
      class_session_id: 901,
      session_date: '2026-09-16',
    })).toBe('#/truefit/prep/901?d=2026-09-16');
    expect(buildTrueFitPrepUrl({
      student_class_id: 55,
      start_time: '16:30',
      session_date: '2026-09-16',
    })).toBe('#/truefit/prep/c55-1630?d=2026-09-16');
  });

  it('seeds and matches prep sessions without UTC today invention', () => {
    const seeded = seedSessionFromPrepRoute({
      view: 'prep',
      studentClassId: 55,
      projectedStartHm: '1630',
      sessionDate: '2026-09-16',
    });
    expect(seeded).toEqual({
      class_session_id: null,
      student_class_id: 55,
      start_time: '16:30',
      session_date: '2026-09-16',
    });
    expect(matchTodaySession(seeded, [
      {
        class_session_id: null,
        student_class_id: 55,
        start_time: '16:30',
        session_date: '2026-09-16',
        student_name: 'Ada',
        subject_name: 'Math',
      },
    ])).toMatchObject({ student_name: 'Ada', subject_name: 'Math' });
  });

  it('builds prep deep links for session cards', () => {
    expect(buildTrueFitPrepUrl(901)).toBe('#/truefit/prep/901');
  });

  it('registers teacher nav entry behind truefitEnabled option', () => {
    expect(navSource).toContain("page: 'truefit'");
    expect(navSource).toContain('truefitEnabled');
  });

  it('renders standalone TrueFit shell separate from admin layout', () => {
    expect(appSource).toContain('standalone-truefit-shell');
    expect(appSource).toContain('isStandaloneTrueFit');
    expect(appSource).toContain("page === 'truefit'");
  });

  it('shows required session card fields and CTA copy', () => {
    expect(workspaceSource).toContain('準備課程');
    expect(workspaceSource).toContain('課堂觀察');
    expect(workspaceSource).toContain('student_name');
    expect(workspaceSource).toContain('subject_name');
    expect(workspaceSource).toContain('campus_name');
    expect(workspaceSource).toContain('start_time');
  });

  it('prep page generates structured Teacher Brief instead of textarea notebook', () => {
    expect(prepSource).toContain('產生 Teacher Brief');
    expect(prepSource).toContain('learning_objectives');
    expect(prepSource).toContain('expected_misconceptions');
    expect(prepSource).toContain('hint_ladders');
    expect(prepSource).not.toContain('<textarea');
  });

  it('TrueFitApp hydrates prep deep links via today-sessions enrichment', () => {
    expect(trueFitAppSource).toContain('seedSessionFromPrepRoute');
    expect(trueFitAppSource).toContain('matchTodaySession');
    expect(trueFitAppSource).toContain('fetchTrueFitTodaySessions');
    expect(trueFitAppSource).not.toContain('toISOString().slice(0, 10)');
  });

  it('parses observe deep links and wires observation page', () => {
    expect(parseTrueFitRoute({
      hash: '#/truefit/observe/42?d=2026-09-16',
      search: '',
      hostname: 'localhost',
    })).toEqual({
      view: 'observe',
      classSessionId: 42,
      sessionDate: '2026-09-16',
    });
    expect(buildTrueFitObserveUrl({
      student_class_id: 55,
      start_time: '16:30',
      session_date: '2026-09-16',
    })).toBe('#/truefit/observe/c55-1630?d=2026-09-16');
    expect(trueFitAppSource).toContain('TrueFitObservationPage');
    expect(trueFitAppSource).toContain('goObserve');
    const obsSource = readFileSync(resolve(__dirname, '../../pages/TrueFitObservationPage.vue'), 'utf8');
    expect(obsSource).toContain('儲存觀察');
    expect(obsSource).toContain('struggle_signals');
    expect(obsSource).toContain('misconception_hypotheses');
    expect(obsSource).toContain('upsertTrueFitObservation');
  });

  it('parses remediate deep links and wires remediation page', () => {
    expect(parseTrueFitRoute({
      hash: '#/truefit/remediate/42?d=2026-09-16',
      search: '',
      hostname: 'localhost',
    })).toEqual({
      view: 'remediate',
      classSessionId: 42,
      sessionDate: '2026-09-16',
    });
    expect(buildTrueFitRemediateUrl({
      class_session_id: 42,
      session_date: '2026-09-16',
    })).toBe('#/truefit/remediate/42?d=2026-09-16');
    expect(trueFitAppSource).toContain('TrueFitRemediationPage');
    expect(workspaceSource).toContain('補救計畫');
  });

  it('parses mastery deep links and wires mastery page', () => {
    expect(parseTrueFitRoute({
      hash: '#/truefit/mastery/42?d=2026-09-16',
      search: '',
      hostname: 'localhost',
    })).toEqual({
      view: 'mastery',
      classSessionId: 42,
      sessionDate: '2026-09-16',
    });
    expect(buildTrueFitMasteryUrl({
      class_session_id: 42,
      session_date: '2026-09-16',
    })).toBe('#/truefit/mastery/42?d=2026-09-16');
    expect(trueFitAppSource).toContain('TrueFitMasteryPage');
    expect(trueFitAppSource).toContain('goMastery');
    expect(workspaceSource).toContain('精熟檢核');
    const masSource = readFileSync(resolve(__dirname, '../../pages/TrueFitMasteryPage.vue'), 'utf8');
    expect(masSource).toContain('儲存精熟證據');
    expect(masSource).toContain('retrieval_prompt');
    expect(masSource).toContain('upsertTrueFitMastery');
  });

  it('parses diagnose deep links and wires diagnosis page', () => {
    expect(parseTrueFitRoute({
      hash: '#/truefit/diagnose/42?d=2026-09-16',
      search: '',
      hostname: 'localhost',
    })).toEqual({
      view: 'diagnose',
      classSessionId: 42,
      sessionDate: '2026-09-16',
    });
    expect(trueFitAppSource).toContain('TrueFitDiagnosisPage');
    expect(trueFitAppSource).toContain('goDiagnose');
    expect(workspaceSource).toContain('錯誤診斷');
    const diagSource = readFileSync(resolve(__dirname, '../../pages/TrueFitDiagnosisPage.vue'), 'utf8');
    expect(diagSource).toContain('儲存診斷');
    expect(diagSource).toContain('primary_misconception');
    expect(diagSource).toContain('teacher_decision');
    expect(diagSource).toContain('upsertTrueFitDiagnosis');
  });

  it('wires same-session continuum next CTAs and source_* linking', () => {
    expect(trueFitAppSource).toContain('@continue="goObserve"');
    expect(trueFitAppSource).toContain('@continue="goDiagnose"');
    expect(trueFitAppSource).toContain('@continue="goRemediate"');
    expect(trueFitAppSource).toContain('@continue="goMastery"');
    expect(prepSource).toContain('進入課堂觀察');
    expect(prepSource).toContain("emit('continue'");
    const obsSource = readFileSync(resolve(__dirname, '../../pages/TrueFitObservationPage.vue'), 'utf8');
    const diagSource = readFileSync(resolve(__dirname, '../../pages/TrueFitDiagnosisPage.vue'), 'utf8');
    const remSource = readFileSync(resolve(__dirname, '../../pages/TrueFitRemediationPage.vue'), 'utf8');
    const masSource = readFileSync(resolve(__dirname, '../../pages/TrueFitMasteryPage.vue'), 'utf8');
    expect(obsSource).toContain('進入錯誤診斷');
    expect(diagSource).toContain('進入補救計畫');
    expect(diagSource).toContain('source_observation_id: sourceObservationId.value');
    expect(diagSource).toContain('seedDiagnosisFromObservation');
    expect(remSource).toContain('進入精熟檢核');
    expect(remSource).toContain('source_diagnosis_id: sourceDiagnosisId.value');
    expect(remSource).toContain('seedRemediationFromDiagnosis');
    expect(masSource).toContain('返回今日課程');
    expect(masSource).toContain('source_remediation_id: sourceRemediationId.value');
    expect(masSource).toContain('seedMasteryFromRemediation');
    expect(masSource).toContain('shouldApplyContinuumSeed');
    expect(masSource).toContain('canContinueFromStage');
    expect(workspaceSource).toContain('備課 → 觀察 → 診斷 → 補救 → 精熟');
    expect(workspaceSource).toContain('truefit-session-progress');
    expect(workspaceSource).toContain('deriveSessionStagePresence');
    expect(workspaceSource).toContain('loadProgressFanout');
    expect(prepSource).toContain('canContinueFromStage');
  });
});
