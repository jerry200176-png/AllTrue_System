import { describe, expect, it } from 'vitest';
import {
  approvePack, confirmEvidence, createPaperFixture, draftPack, expireRaw,
  processingKey, renderApprovedPack, replacePage, runFixtureOcr,
  supplyFixtureAnswerKey, verifyPage,
} from '../../lib/truefitPaperFixture.js';

function verifiedFixture() {
  const s = createPaperFixture(); runFixtureOcr(s);
  s.pages.forEach((p) => verifyPage(s, p.pageId, p.ocrAnswer ?? p.question.answer ?? ''));
  supplyFixtureAnswerKey(s);
  s.pages.filter((p) => p.question.id === 8105).forEach((p) => verifyPage(s, p.pageId, '長 × 寬'));
  return s;
}

describe('TrueFit local paper fixture contract', () => {
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
