// 意見與建議 anti-abuse: the 429 reads as one Chinese line in the form, and report text is never rendered as HTML.
import { describe, it, expect, vi, afterEach } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { submitBugReport, addBugComment, BUG_RATE_LIMIT_MESSAGE } from '../../lib/bugReportsApi.js';

afterEach(() => vi.unstubAllGlobals());

const tooMany = (body) => ({ ok: false, status: 429, json: async () => body });

describe('429 message', () => {
  it('submitBugReport rejects with the Chinese line even when the server sent the framework English one', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => tooMany({ message: 'Too Many Attempts.' })));
    await expect(submitBugReport({ branch_id: 1, title: 't', description: 'd' })).rejects.toThrow(BUG_RATE_LIMIT_MESSAGE);
  });
  it('comments and a body-less 429 get the same line', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 429, json: async () => { throw new Error('no body'); } })));
    await expect(addBugComment(1, 'x')).rejects.toThrow('回報有點多，請稍後再送；急事請直接找主任。');
  });
  it('other errors keep their own message', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 403, json: async () => ({ message: 'Forbidden' }) })));
    await expect(submitBugReport({ branch_id: 1, title: 't', description: 'd' })).rejects.toThrow('Forbidden');
  });
});

describe('report text is never HTML', () => {
  const walk = (dir) => readdirSync(dir).flatMap((f) => {
    const p = join(dir, f);
    return statSync(p).isDirectory() ? (f === '__tests__' || f === 'node_modules' ? [] : walk(p)) : [p];
  });
  const bugFiles = walk(join(process.cwd(), 'src')).filter((p) => /\.vue$/.test(p) && /bug|triage|notificationscenter/i.test(p));

  it('finds the staff list, detail and launcher files', () => {
    expect(bugFiles.some((p) => /BugReportsPage\.vue$/.test(p))).toBe(true);
    expect(bugFiles.some((p) => /BugReportLauncher\.vue$/.test(p))).toBe(true);
  });
  it.each(bugFiles.map((p) => [p.replace(/^.*\/src\//, 'src/'), p]))('%s has no v-html or innerHTML', (_name, path) => {
    const src = readFileSync(path, 'utf8');
    expect(src).not.toMatch(/v-html|\.innerHTML|insertAdjacentHTML/);
  });
});
