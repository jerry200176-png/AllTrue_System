import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/TeacherHomePage.vue'), 'utf8');

describe('TeacherHome cleanup regression', () => {
  it('removes the non-rendered legacy task surface', () => {
    expect(source).not.toContain('th-legacy-workflow');
  });
});
