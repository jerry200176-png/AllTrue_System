import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const source = readFileSync(resolve(__dirname, '../../pages/AdmissionInquiriesPage.vue'), 'utf8');

describe('admission inquiry UI contract', () => {
  it('keeps the public flow standalone and progressive', () => {
    expect(source).toContain("standalone ? 'admission-page-public' : 'admission-page-staff'");
    expect(source).toContain('問班進度');
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
  });
});
