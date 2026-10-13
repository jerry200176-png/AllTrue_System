import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import {
  RESOLVE_REPLY_OUTCOMES,
  RESOLVE_REPLY_REQUIRED_PHRASES,
  buildResolveReplyTemplate,
  missingResolveReplyPhrases,
  shouldPrefillResolveReply,
} from './bugResolveReply.js';

const pageSource = readFileSync(resolve(__dirname, '../pages/BugReportsPage.vue'), 'utf8');
const serviceSource = readFileSync(
  resolve(__dirname, '../../../backend/app/Services/BugReportService.php'),
  'utf8',
);

function backendRequiredPhrases() {
  const match = serviceSource.match(/RESOLVE_REPLY_REQUIRED_PHRASES\s*=\s*\[([^\]]*)\]/);
  expect(match, 'backend must define RESOLVE_REPLY_REQUIRED_PHRASES').toBeTruthy();
  return [...match[1].matchAll(/'([^']+)'/g)].map((m) => m[1]);
}

describe('resolve reply template (GitHub #3750)', () => {
  it('matches the phrases the backend enforces', () => {
    expect([...RESOLVE_REPLY_REQUIRED_PHRASES]).toEqual(backendRequiredPhrases());
  });

  it('pre-filled template names every required outcome', () => {
    const template = buildResolveReplyTemplate();
    for (const phrase of RESOLVE_REPLY_REQUIRED_PHRASES) {
      expect(template).toContain(phrase);
    }
    expect(missingResolveReplyPhrases(template)).toEqual([]);
  });

  it('reports which outcome a reply is missing', () => {
    expect(missingResolveReplyPhrases(`請按「${RESOLVE_REPLY_OUTCOMES.confirm}」`)).toEqual([
      RESOLVE_REPLY_OUTCOMES.reopen,
    ]);
    expect(missingResolveReplyPhrases('')).toEqual([...RESOLVE_REPLY_REQUIRED_PHRASES]);
  });

  it('pre-fills only for resolved and never over staff text', () => {
    expect(shouldPrefillResolveReply('resolved', '')).toBe(true);
    expect(shouldPrefillResolveReply('resolved', '   ')).toBe(true);
    expect(shouldPrefillResolveReply('resolved', '已寫好的回覆')).toBe(false);
    expect(shouldPrefillResolveReply('in_progress', '')).toBe(false);
    expect(shouldPrefillResolveReply('closed', '')).toBe(false);
  });

  it('BugReportsPage pre-fills the public reply when 已解決 is chosen', () => {
    expect(pageSource).toMatch(
      /watch\(newStatus, \(next\) => \{\s+if \(!shouldPrefillResolveReply\(next, newComment\.value\)\) return;\s+newComment\.value = buildResolveReplyTemplate\(\);\s+commentIsInternal\.value = false;/,
    );
    expect(pageSource).toContain('{{ RESOLVE_REPLY_OUTCOMES.reopen }}');
    expect(pageSource).toContain('{{ RESOLVE_REPLY_OUTCOMES.confirm }}');
  });
});
