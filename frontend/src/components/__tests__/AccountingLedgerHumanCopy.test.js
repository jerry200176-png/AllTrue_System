import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ledger = readFileSync(resolve(__dirname, '../AccountingLedgerModal.vue'), 'utf8');
const tuition = readFileSync(resolve(__dirname, '../../pages/TuitionCollectionPage.vue'), 'utf8');
const course = readFileSync(resolve(__dirname, '../../pages/CourseManagement.vue'), 'utf8');

describe('billing human copy — no engineering jargon on director surfaces', () => {
  it('ledger modal avoids English model names and slash columns', () => {
    expect(ledger).not.toContain('AR Ledger');
    expect(ledger).not.toContain('Invoice / Receipt / Payment');
    expect(ledger).not.toContain('Invoice 帳單');
    expect(ledger).not.toContain('帳單 / 課程');
    expect(ledger).toContain('帳單（科目）');
    expect(ledger).not.toContain('對帳');
    expect(ledger).not.toContain('對齊帳單');
    expect(ledger).toContain('到今天未繳');
    expect(ledger).toContain('多收');
    expect(ledger).toContain('撤銷收款');
  });

  it('tuition collection avoids Invoice/Payment/Receipt and PNG slash CTA', () => {
    expect(tuition).not.toContain('Invoice/Payment/Receipt');
    expect(tuition).not.toContain('查看 / PNG');
    expect(tuition).toContain('查看收據');
    expect(tuition).toContain('多收');
    expect(tuition).toContain('多收');
  });

  it('course management stats and billing CTA use Chinese', () => {
    expect(course).not.toContain('Course Matrix');
    expect(course).not.toContain('>Subjects<');
    expect(course).not.toContain('帳單/對帳');
    expect(course).not.toContain('帳單 / 對帳');
    expect(course).not.toContain('帳單 / 期別');
    expect(course).toContain('課程總覽');
    expect(course).toContain('學生帳務');
  });
});

describe('ledger anomaly with nothing to undo (in-app #446)', () => {
  it('explains the drift and offers a working action, not a dead end', () => {
    expect(ledger).not.toContain('請找老闆確認');
    expect(ledger).toContain('data-testid="ledger-exception-drift"');
    expect(ledger).toContain('回報給系統管理員');
    expect(ledger).toContain("new CustomEvent('alltrue-open-bug-report'");
    expect(ledger).toContain('這筆多收沒有對應的收款，系統無法自動處理');
  });
  it('the report launcher listens for the pre-filled open request', () => {
    const launcher = readFileSync(resolve(__dirname, '../BugReportLauncher.vue'), 'utf8');
    expect(launcher).toContain("addEventListener('alltrue-open-bug-report', onOpenRequest)");
    expect(launcher).toContain("removeEventListener('alltrue-open-bug-report', onOpenRequest)");
  });
});
