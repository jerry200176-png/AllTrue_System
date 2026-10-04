import { describe, it, expect, beforeEach, vi } from 'vitest';
import {
  installUiRecorder, getRecentClicks, getRecentMessages, recordUserMessage, resetUiRecorderForTest, clearUiBreadcrumbs, fitClientInfo,
} from '../../lib/recentApiFailures';
import { useToast } from '../useToast';
import { parseBugReportClientInfo } from '../../lib/bugReportContext';

describe('F15 UI breadcrumbs', () => {
  window.alert = vi.fn();
  installUiRecorder();
  beforeEach(() => { resetUiRecorderForTest(); document.body.innerHTML = ''; });

  const click = (html) => {
    document.body.innerHTML = html;
    document.body.querySelector('[data-t]').dispatchEvent(new MouseEvent('click', { bubbles: true }));
  };

  it('records button labels, masks long digits, keeps the last 15', () => {
    click('<button>取消 <span data-t>這堂</span></button>');
    click('<a href="#" data-t aria-label="課程 12345 詳情">x</a>');
    expect(getRecentClicks().map((c) => c.label)).toEqual(['取消 這堂', '課程 # 詳情']);
    for (let i = 0; i < 20; i++) click(`<button data-t>b${i}</button>`);
    expect(getRecentClicks()).toHaveLength(15);
    expect(getRecentClicks().at(-1).label).toBe('b19');
  });

  it('ignores clicks inside the report dialog and on non-interactive elements', () => {
    click('<div class="bug-report-dialog"><button data-t>送出回報</button></div>');
    click('<div data-t>純文字</div>');
    expect(getRecentClicks()).toEqual([]);
  });

  it('records only error/warning toasts (last 5), never alerts or info/success', () => {
    useToast().error('登記繳費回報失敗', { title: '無法儲存' });
    useToast().success('臨時密碼：Ab3xY9kLm2Qz');
    window.alert('臨時密碼：Ab3xY9kLm2Qz');
    useToast().warning('月結課程不能登記繳費回報');
    expect(getRecentMessages().map((m) => [m.kind, m.text])).toEqual([
      ['error', '無法儲存 登記繳費回報失敗'],
      ['warning', '月結課程不能登記繳費回報'],
    ]);
    for (let i = 0; i < 9; i++) recordUserMessage('error', `m${i}`);
    expect(getRecentMessages()).toHaveLength(5);
  });

  it('clearUiBreadcrumbs forgets everything (logout / campus switch)', () => {
    click('<button data-t>王小明 詳情</button>');
    recordUserMessage('error', 'x');
    clearUiBreadcrumbs();
    expect(getRecentClicks()).toEqual([]);
    expect(getRecentMessages()).toEqual([]);
  });

  it('fits into client_info and shows on the admin page', () => {
    const info = { recentClicks: [{ label: '取消' }, { label: '確定' }], recentMessages: [{ kind: 'error', text: '已暫停課程不能取消' }], recentApiFailures: [] };
    const ctx = parseBugReportClientInfo(fitClientInfo(info));
    expect(ctx.recentClicks).toEqual(['取消', '確定']);
    expect(ctx.recentMessages).toEqual(['error：已暫停課程不能取消']);
    const big = { recentClicks: Array.from({ length: 15 }, () => ({ label: 'x'.repeat(30) })), recentMessages: [], recentApiFailures: [] };
    expect(fitClientInfo(big, 300).length).toBeLessThanOrEqual(300);
  });
});
