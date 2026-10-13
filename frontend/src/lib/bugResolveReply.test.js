import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import {
  RESOLVE_REPLY_OUTCOMES,
  buildResolveReplyTemplate,
  missingResolveReplyPhrases,
  resolveOutcomeLabels,
  shouldPrefillResolveReply,
} from './bugResolveReply.js';

const pageSource = readFileSync(resolve(__dirname, '../pages/BugReportsPage.vue'), 'utf8');
const serviceSource = readFileSync(
  resolve(__dirname, '../../../backend/app/Services/BugReportService.php'),
  'utf8',
);

function backendOutcomes() {
  const match = serviceSource.match(/RESOLVE_REPLY_OUTCOMES\s*=\s*\[([^\]]*)\]/);
  expect(match, 'backend must define RESOLVE_REPLY_OUTCOMES').toBeTruthy();
  return Object.fromEntries([...match[1].matchAll(/'(\w+)'\s*=>\s*'([^']+)'/g)].map((m) => [m[1], m[2]]));
}

// Deliberately different from the fallback labels: proves the template follows the payload.
const SERVER = Object.freeze({ confirm: '已驗收OK', reopen: '還是壞的' });

describe('resolve reply template (GitHub #3750)', () => {
  it('fallback button labels match the backend constant', () => {
    expect({ ...RESOLVE_REPLY_OUTCOMES }).toEqual(backendOutcomes());
  });

  it('builds the template from the server-sent outcomes, not the local fallback', () => {
    const template = buildResolveReplyTemplate(SERVER);
    expect(template).toContain(SERVER.confirm);
    expect(template).toContain(SERVER.reopen);
    expect(template).not.toContain(RESOLVE_REPLY_OUTCOMES.confirm);
    expect(missingResolveReplyPhrases(template, SERVER)).toEqual([]);
  });

  it('does not prefill when the backend did not send outcomes', () => {
    expect(buildResolveReplyTemplate(undefined)).toBe('');
    expect(shouldPrefillResolveReply('resolved', '', undefined)).toBe(false);
    expect(shouldPrefillResolveReply('resolved', '', { confirm: 'x' })).toBe(false);
  });

  it('reports which outcome a reply is missing', () => {
    expect(missingResolveReplyPhrases(`請按「${SERVER.confirm}」`, SERVER)).toEqual([SERVER.reopen]);
    expect(missingResolveReplyPhrases('', SERVER)).toEqual([SERVER.confirm, SERVER.reopen]);
  });

  it('pre-fills only for resolved and never over staff text', () => {
    expect(shouldPrefillResolveReply('resolved', '', SERVER)).toBe(true);
    expect(shouldPrefillResolveReply('resolved', '   ', SERVER)).toBe(true);
    expect(shouldPrefillResolveReply('resolved', '已寫好的回覆', SERVER)).toBe(false);
    expect(shouldPrefillResolveReply('in_progress', '', SERVER)).toBe(false);
    expect(shouldPrefillResolveReply('closed', '', SERVER)).toBe(false);
  });

  it('button labels follow the server outcomes and fall back otherwise', () => {
    expect(resolveOutcomeLabels(SERVER)).toBe(SERVER);
    expect(resolveOutcomeLabels(null)).toBe(RESOLVE_REPLY_OUTCOMES);
  });

  it('BugReportsPage wires the server outcomes and keeps the resolve form after a reply', () => {
    expect(pageSource).toMatch(
      /watch\(newStatus, \(next\) => \{\s+const serverOutcomes = detail\.value\?\.resolve_reply_outcomes;\s+if \(!shouldPrefillResolveReply\(next, newComment\.value, serverOutcomes\)\) return;\s+newComment\.value = buildResolveReplyTemplate\(serverOutcomes\);\s+commentIsInternal\.value = false;/,
    );
    expect(pageSource).toContain('resolveOutcomeLabels(detail.value?.resolve_reply_outcomes)');
    expect(pageSource).toContain('{{ outcomeLabels.reopen }}');
    expect(pageSource).toContain('{{ outcomeLabels.confirm }}');
    const addComment = pageSource.slice(
      pageSource.indexOf('async function doAddComment('),
      pageSource.indexOf('function isUpdatingCommentVisibility('),
    );
    expect(addComment).toContain('await selectBug(activeBug.value, null, { keepActionForm: true });');
  });
});
