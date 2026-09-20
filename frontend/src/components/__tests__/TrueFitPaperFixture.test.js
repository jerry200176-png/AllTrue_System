import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import TrueFitPaperFixturePage from '../../pages/TrueFitPaperFixturePage.vue';
import {
  approvePack, confirmEvidence, createPaperFixture, draftPack, expireRaw,
  processingKey, renderApprovedPack, replacePage, runFixtureOcr,
  supplyFixtureAnswerKey, updateDraftItem, verifyPage,
} from '../../lib/truefitPaperFixture.js';

function verifiedFixture() {
  const s = createPaperFixture(); runFixtureOcr(s);
  s.pages.forEach((p) => verifyPage(s, p.pageId, p.ocrAnswer ?? p.question.answer ?? ''));
  supplyFixtureAnswerKey(s);
  s.pages.filter((p) => p.question.id === 8105).forEach((p) => verifyPage(s, p.pageId, '長 × 寬'));
  return s;
}

describe('TrueFit local paper fixture contract', () => {
  it('keeps repeated unchanged confirmation idempotent and never leaves stale approval', async () => {
    const wrapper = mount(TrueFitPaperFixturePage);
    const click = async (label) => {
      const button = wrapper.findAll('button').find((candidate) => candidate.text() === label);
      expect(button, `missing button: ${label}`).toBeTruthy();
      await button.trigger('click');
    };

    await click('確認學生作答'); await click('產生講義草稿'); await click('明確核准');
    expect(wrapper.get('[aria-label="版本與證據"]').text()).toContain('pack-r14.1');
    expect(wrapper.get('[role="status"]').text()).toContain('input r14');
    await click('確認學生作答');
    expect(wrapper.get('[aria-label="版本與證據"]').text()).toContain('pack-r14.1');
    expect(wrapper.get('[role="status"]').text()).toContain('input r14');
  });

  it('clears the approved DOM preview after answer correction or page replacement', async () => {
    const wrapper = mount(TrueFitPaperFixturePage); const buttons = () => wrapper.findAll('button');
    const click = (label) => buttons().find((button) => button.text() === label).trigger('click');
    await click('確認學生作答'); await click('產生講義草稿'); await click('明確核准');
    const answer = wrapper.get('input[aria-label="syn-p1答案"]'); await answer.setValue('9'); await answer.trigger('change');
    expect(wrapper.find('[aria-label="列印預覽"]').exists()).toBe(false);
    expect(wrapper.get('[aria-label="版本與證據"]').text()).toContain('尚未核准');
    await click('確認學生作答'); await click('產生講義草稿'); await click('明確核准');
    await click('替換頁面'); expect(wrapper.find('[aria-label="列印預覽"]').exists()).toBe(false);
  });

  it('requires draft review, supports edit/return/approval, and separates print variants', async () => {
    const wrapper = mount(TrueFitPaperFixturePage);
    const click = (label) => wrapper.findAll('button').find((button) => button.text() === label).trigger('click');
    await click('確認學生作答'); await click('產生講義草稿');
    expect(wrapper.find('[aria-label="列印預覽"]').exists()).toBe(false);
    const explanation = wrapper.find('textarea'); await explanation.setValue('老師修改後的解說'); await explanation.trigger('change');
    expect(wrapper.get('[data-testid="pack-draft"]').text()).toContain('草稿修訂 2');
    await click('退回草稿'); expect(wrapper.find('[data-testid="pack-draft"]').exists()).toBe(false);
    await click('產生講義草稿'); await click('明確核准');
    expect(wrapper.get('[aria-label="列印預覽"]').text()).not.toContain('答案：');
    await click('教師版預覽'); expect(wrapper.get('[aria-label="列印預覽"]').text()).toContain('答案：');
    await click('學生版預覽'); expect(wrapper.get('[aria-label="列印預覽"]').text()).not.toContain('答案：');
  });

  it('keeps approved output through raw expiry and permits manual fallback after OCR failure', async () => {
    const wrapper = mount(TrueFitPaperFixturePage);
    const click = (label) => wrapper.findAll('button').find((button) => button.text() === label).trigger('click');
    await click('模擬 OCR 失敗'); await click('確認學生作答');
    expect(wrapper.get('[role="status"]').text()).toContain('VERIFIED');
    await click('產生講義草稿'); await click('明確核准'); await click('模擬 30 天到期');
    expect(wrapper.get('[role="status"]').text()).toContain('原始檔已到期');
    expect(wrapper.find('[aria-label="列印預覽"]').exists()).toBe(true);
  });

  it('contains 12 synthetic pages for exactly one synthetic student', () => {
    const s = createPaperFixture(); expect(s.pages).toHaveLength(12);
    expect(new Set(s.pages.map((p) => p.pageId)).size).toBe(12);
    expect(s.studentId).toBe('synthetic-student-a');
  });

  it('binds idempotency to job, attempt, student, input revision and processor', () => {
    const s = createPaperFixture(); const first = runFixtureOcr(s);
    expect(runFixtureOcr(s)).toBe(first);
    const oldKey = processingKey(s); replacePage(s, 'syn-p1');
    expect(processingKey(s)).not.toBe(oldKey); expect(runFixtureOcr(s)).not.toBe(first);
    const other = createPaperFixture(); other.studentId = 'synthetic-student-b';
    expect(processingKey(other)).not.toBe(oldKey);
  });

  it('can retry fixture OCR after failure without duplicating a successful run', () => {
    const s = createPaperFixture(); expect(runFixtureOcr(s, { fail: true }).status).toBe('OCR_FAILED');
    const success = runFixtureOcr(s); expect(success.status).toBe('TEACHER_REVIEW');
    expect(s.runs.size).toBe(1); expect(runFixtureOcr(s)).toBe(success);
    runFixtureOcr(s, { fail: true }); expect(runFixtureOcr(s)).toBe(success);
    expect(s.stage).toBe('TEACHER_REVIEW');
  });

  it('stops at NEEDS_ANSWER_KEY and never confirms before teacher review', () => {
    const s = createPaperFixture(); runFixtureOcr(s); expect(confirmEvidence(s)).toBeNull();
    s.pages.forEach((p) => verifyPage(s, p.pageId, p.ocrAnswer ?? ''));
    expect(confirmEvidence(s)).toBeNull(); expect(s.stage).toBe('NEEDS_ANSWER_KEY');
  });

  it('retries without a second confirmed record', () => {
    const s = verifiedFixture(); const first = confirmEvidence(s);
    expect(confirmEvidence(s)).toBe(first);
    expect(s.audit.filter((x) => x.includes('唯一確認紀錄'))).toHaveLength(1);
  });

  it('invalidates old processing after answer correction or page replacement', () => {
    const s = verifiedFixture(); confirmEvidence(s); draftPack(s); approvePack(s);
    const firstKey = processingKey(s);
    verifyPage(s, 'syn-p2', '1/2'); expect(processingKey(s)).not.toBe(firstKey);
    expect(s.confirmed).toBeNull(); expect(s.approved).toBeNull();
    const changedKey = processingKey(s); replacePage(s, 'syn-p2');
    expect(processingKey(s)).not.toBe(changedKey); expect(s.approved).toBeNull();
  });

  it('keeps confirmed evidence after raw expiry', () => {
    const s = verifiedFixture(); const confirmed = confirmEvidence(s); expireRaw(s);
    expect(s.rawAvailable).toBe(false); expect(s.pages.every((p) => p.ocrAnswer === null)).toBe(true);
    expect(s.confirmed).toEqual(confirmed);
  });

  it('rejects OCR after raw evidence expires', () => {
    const s = createPaperFixture(); expireRaw(s);
    expect(runFixtureOcr(s)).toBeNull(); expect(s.stage).toBe('SOURCE_EXPIRED');
    expect(s.runs.size).toBe(0);
  });

  it('reformats one approved revision without regenerating content', () => {
    const s = verifiedFixture(); confirmEvidence(s); draftPack(s); approvePack(s);
    const a = renderApprovedPack(s, 'a4-standard'); const b = renderApprovedPack(s, 'a4-compact');
    expect(b.approvedRevision).toBe(a.approvedRevision); expect(b.content).toEqual(a.content);
    expect(b.layout).not.toBe(a.layout);
  });

  it('assigns a new pack revision after corrected confirmed evidence', () => {
    const s = verifiedFixture(); confirmEvidence(s); draftPack(s); const first = approvePack(s).approvedRevision;
    verifyPage(s, 'syn-p2', '1/2'); confirmEvidence(s); draftPack(s); const second = approvePack(s).approvedRevision;
    expect(second).not.toBe(first);
  });

  it('changes draft revision and invalidates approval only for a substantive draft edit', () => {
    const s = verifiedFixture(); confirmEvidence(s); draftPack(s); const first = approvePack(s);
    const text = s.draft.items[0].explanation;
    updateDraftItem(s, s.draft.items[0].pageId, text); expect(s.approved).toBe(first);
    updateDraftItem(s, s.draft.items[0].pageId, `${text}（教師補充）`);
    expect(s.approved).toBeNull(); expect(s.draft.contentRevision).toBe(2);
  });

  it('uses page identity for repeated questions and keeps repeated approval idempotent', () => {
    const s = verifiedFixture(); verifyPage(s, 'syn-p6', 'wrong'); confirmEvidence(s); draftPack(s);
    const repeated = s.draft.items.filter((item) => item.questionId === 8101);
    expect(repeated).toHaveLength(2); expect(new Set(repeated.map((item) => item.pageId)).size).toBe(2);
    updateDraftItem(s, repeated[1].pageId, 'second page only');
    expect(repeated[0].explanation).not.toBe('second page only');
    const approved = approvePack(s); expect(approvePack(s)).toBe(approved);
    expect(s.audit.filter((line) => line.startsWith('核准 '))).toHaveLength(1);
  });

  it('supports manual continuation after fixture OCR failure', () => {
    const s = createPaperFixture(); runFixtureOcr(s, { fail: true }); expect(s.stage).toBe('OCR_FAILED');
    s.pages.forEach((p) => verifyPage(s, p.pageId, p.question.answer ?? ''));
    supplyFixtureAnswerKey(s);
    s.pages.filter((p) => p.question.id === 8105).forEach((p) => verifyPage(s, p.pageId, '長 × 寬'));
    expect(confirmEvidence(s)?.id).toBe('confirmed-01');
  });

  it('supports a manual pack after fixture AI failure', () => {
    const s = verifiedFixture(); confirmEvidence(s);
    expect(draftPack(s, { fail: true })).toBeNull(); expect(s.stage).toBe('AI_UNAVAILABLE');
    expect(draftPack(s, { manual: true })).not.toBeNull();
    expect(s.audit).toContain('人工診斷與講義草稿完成');
  });
});
