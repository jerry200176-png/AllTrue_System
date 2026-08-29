import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/ClassroomManagement.vue'), 'utf8');

describe('ClassroomManagement form accessibility', () => {
  it('associates each labelled form field with a stable control id', () => {
    expect(source).toContain('for="classroom-name"');
    expect(source).toContain('id="classroom-name"');
    expect(source).toContain('for="classroom-capacity"');
    expect(source).toContain('id="classroom-capacity"');
    expect(source).toContain('for="classroom-memo"');
    expect(source).toContain('id="classroom-memo"');
  });

  it('keeps both active-state controls uniquely identifiable', () => {
    expect(source).toContain('id="classroom-active-edit"');
    expect(source).toContain('id="classroom-active-create"');
  });

  it('declares row actions as non-submit buttons', () => {
    expect(source).toContain('<button type="button" class="small" @click="openEdit(r)">');
    expect(source).toContain('<button type="button" class="small" @click="toggleActive(r)">');
    expect(source).toContain('<button type="button" class="small ghost" @click="confirmDelete(r)">');
  });
});
