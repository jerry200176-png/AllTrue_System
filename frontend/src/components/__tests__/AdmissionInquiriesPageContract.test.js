import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const source = readFileSync(resolve(__dirname, '../../pages/AdmissionInquiriesPage.vue'), 'utf8');

describe('admission inquiry UI contract', () => {
  it('keeps the public flow standalone and progressive', () => {
    expect(source).toContain("standalone ? 'admission-page-public' : 'admission-page-staff'");
    expect(source).toContain('<template v-if="!standalone">');
    expect(source).not.toContain('<template v-else>');
    expect(source).toContain('standalone && !clientEnabled');
    expect(source).toContain('問班進度');
    expect(source).toContain('@click="advancePublicStep"');
    expect(source).toContain("querySelector(':invalid')");
    expect(source).toContain('nextTick');
    expect(source).toContain('aria-current');
    expect(source).toContain("import { GRADES, SUBJECTS } from '../lib/constants';");
    expect(source).toContain('已收到問班需求');
    expect(source).toContain('role="alert"');
    expect(source).toContain('aria-live="assertive"');
  });

  it('keeps staff mutations explicit and mobile-safe', () => {
    for (const action of ['claim', 'contact', 'trial', 'trial-result', 'enroll', 'lost', 'follow-up']) {
      expect(source).toContain("'" + action + "'");
    }
    expect(source).toContain('min-height: 44px');
    expect(source).toContain('prefers-reduced-motion');
    expect(source).toContain('admission-skeleton');
    expect(source).toContain('statusFilter');
    expect(source).toContain('下一步：');
    expect(source).toContain('目前負責');
    expect(source).toContain('下次追蹤');
    expect(source).toContain('詢問歷程');
    expect(source).toContain(':aria-pressed="selectedId === item.id"');
  });

  it('provides a guided empty state with public form actions and offline guidance', () => {
    expect(source).toContain('目前沒有新詢問');
    expect(source).toContain('查看公開問班表單');
    expect(source).toContain('複製公開問班連結');
    expect(source).toContain('招生處理流程');
    expect(source).toContain('家長若透過 LINE、電話或現場來訪？');
  });

  it('provides visual pipeline stages and follow-up urgency handling', () => {
    expect(source).toContain('PIPELINE_STAGES');
    expect(source).toContain('getFollowUpMeta');
    expect(source).toContain('逾期');
    expect(source).toContain('今日需追蹤');
    expect(source).toContain('待認領');
    expect(source).toContain('showDirectTrial');
  });

  it('binds campus context to public admissions URL and preselects campus in public form', () => {
    expect(source).toContain('buildPublicAdmissionsUrl');
    expect(source).toContain('parsePublicAdmissionsContext');
    expect(source).toContain('matchPresetCampus');
    expect(source).toContain('applyPresetBranch');
    expect(source).toContain('presetBranchInfo');
    expect(source).toContain('admission-branch-preset-hint');
    expect(source).toContain('已為您預選');
  });
});
