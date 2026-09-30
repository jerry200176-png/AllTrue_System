import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const source = readFileSync(resolve(__dirname, '../../pages/AttendancePage.vue'), 'utf8');

describe('teacher attendance calls stay on the current campus', () => {
  it('every teacher-attendance request sends campus_id', () => {
    const urls = source.match(/\/api\/v1\/teacher-attendance[^`'"]*/g) ?? [];
    expect(urls.length).toBeGreaterThan(0);
    for (const url of urls) expect(url).toContain('campus_id=');
  });
});
