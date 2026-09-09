import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/AssessmentPage.vue'), 'utf8');

describe('Assessment action accessibility', () => {
  it('gives every native assessment button an explicit non-submit type', () => {
    const buttons = source.match(/<button\b[\s\S]*?<\/button>/g) || [];
    expect(buttons.filter((button) => !/\btype=\"(?:button|submit)\"/.test(button))).toEqual([]);
  });

  it('keeps the action controls available without changing their handlers', () => {
    expect(source).toContain('@click="loadAll"');
    expect(source).toContain('@click="createAssessment"');
    expect(source).toContain('@click="saveResult"');
    expect(source).toContain('@click="updateRemediation(action, \'completed\')"');
  });

  it('uses the shared dialog primitive for both assessment surfaces', () => {
    expect((source.match(/<AtDialog/g) || []).length).toBe(2);
    expect(source).toContain('title-id="assessment-create-title"');
    expect(source).toContain('title-id="assessment-result-title"');
  });
});
