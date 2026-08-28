import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/StudentsList.vue'), 'utf8');

describe('StudentsList row disclosure accessibility', () => {
  it('makes the student row keyboard-operable with an explicit detail relationship', () => {
    expect(source).toContain('class="student-row"');
    expect(source).toContain('tabindex="0"');
    expect(source).toContain(':aria-expanded="expandedId === student.id"');
    expect(source).toContain(':aria-controls="`student-course-detail-${student.id}`"');
    expect(source).toContain('@keydown.enter.prevent="toggleExpand(student, $event)"');
    expect(source).toContain('@keydown.space.prevent="toggleExpand(student, $event)"');
    expect(source).toContain(':id="`student-course-detail-${student.id}`"');
  });

  it('keeps row-level keyboard handling away from selection and destructive actions', () => {
    expect(source).toContain('class="student-select-cell" @click.stop @keydown.stop');
    expect(source).toContain('@click.stop @keydown.stop class="action-cell"');
  });
});
