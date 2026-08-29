import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/AssessmentPage.vue'), 'utf8');

describe('Assessment action accessibility', () => {
  it('gives every native assessment button an explicit non-submit type', () => {
    const buttons = source.match(/<button\b[\s\S]*?<\/button>/g) || [];
    expect(buttons.length).toBeGreaterThan(0);
    expect(buttons.filter((button) => !/\btype=\"(?:button|submit)\"/.test(button))).toEqual([]);
  });

  it('keeps the action controls available without changing their handlers', () => {
    expect(source).toContain('@click="loadAll"');
    expect(source).toContain('@click="createAssessment"');
    expect(source).toContain('@click="saveResult"');
    expect(source).toContain('@click="updateRemediation(action, \'completed\')"');
  });

  it('labels both assessment modal surfaces as dialogs', () => {
    expect(source).toContain('aria-labelledby="assessment-create-title"');
    expect(source).toContain('id="assessment-create-title"');
    expect(source).toContain('aria-labelledby="assessment-result-title"');
    expect(source).toContain('id="assessment-result-title"');
    expect((source.match(/role="dialog"/g) || []).length).toBe(2);
    expect((source.match(/aria-modal="true"/g) || []).length).toBe(2);
  });
});
