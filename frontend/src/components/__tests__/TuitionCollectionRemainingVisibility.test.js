import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/TuitionCollectionPage.vue'), 'utf8');

describe('tuition remaining lessons visibility (in-app #362)', () => {
  it('places the sortable remaining-lessons column beside the student column', () => {
    const studentHeader = source.indexOf("toggleSort('student_name')");
    const lessonsHeader = source.indexOf("toggleSort('remaining_sessions')");
    const subjectHeader = source.indexOf("toggleSort('subject')");

    expect(studentHeader).toBeGreaterThan(-1);
    expect(lessonsHeader).toBeGreaterThan(studentHeader);
    expect(subjectHeader).toBeGreaterThan(lessonsHeader);
  });

  it('shows the lessons cell directly below the student on mobile cards', () => {
    expect(source).toContain('td:nth-child(3) { grid-column: 1 / -1; grid-row: 2;');
    expect(source).toContain("td:nth-child(3)::before { content: '剩餘堂數'");
  });

  it('keeps the existing detail action and does not change billing calculations', () => {
    expect(source).toContain('@click="openSessionDetail(r)"');
    expect(source).toContain('const numericKeys = [\'charge\', \'outstanding\', \'remaining_sessions\']');
  });
});
