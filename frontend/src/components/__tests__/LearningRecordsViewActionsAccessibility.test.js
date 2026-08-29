import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/LearningRecordsPage.vue'), 'utf8');

describe('LearningRecords view and action accessibility', () => {
  it('announces the active list/card view and content preview state', () => {
    expect(source).toContain("class=\"lr-view-toggle\" role=\"group\" aria-label=\"切換列表或卡片\"");
    expect(source).toContain(":aria-pressed=\"effectiveViewMode === 'table' ? 'true' : 'false'\"");
    expect(source).toContain(":aria-pressed=\"effectiveViewMode === 'card' ? 'true' : 'false'\"");
    expect(source).toContain(":aria-pressed=\"showContentPreview ? 'true' : 'false'\"");
  });

  it('gives every native LearningRecords button an explicit non-submit type', () => {
    const buttons = source.match(/<button\b[\s\S]*?<\/button>/g) || [];
    expect(buttons.length).toBeGreaterThan(0);
    expect(buttons.filter((button) => !/\btype=\"(?:button|submit)\"/.test(button))).toEqual([]);
  });
});
