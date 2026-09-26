import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/BranchHealthBoard.vue'), 'utf8');

describe('BranchHealthBoard responsive accessibility', () => {
  it('uses shared primitives for page states and actions', () => {
    expect(source).toContain('<AtSkeleton v-if="loading"');
    expect(source).toContain('<AtInlineAlert v-else-if="error"');
    expect(source).toContain('<AtEmpty v-if="!rows.length"');
    expect((source.match(/<AtButton/g) || []).length).toBeGreaterThanOrEqual(3);
  });

  it('keeps the table labelled and adds a mobile branch list', () => {
    expect(source).toContain('<caption class="sr-only">各分校五個營運健康維度</caption>');
    expect(source).toContain('class="branch-health__mobile-list" aria-label="分校健康清單"');
    expect(source).toContain('class="branch-health__mobile-card"');
  });

  it('preserves read-only selection and does not add mutation controls', () => {
    expect(source).toContain('@click="select(row)"');
    expect(source).not.toContain('method="POST"');
    expect(source).not.toContain('method="PUT"');
    expect(source).not.toContain('method="DELETE"');
  });
});
