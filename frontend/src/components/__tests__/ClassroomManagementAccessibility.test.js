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
    expect(source).toContain('@click="openEdit(r)"');
    expect(source).toContain('@click="toggleActive(r)"');
    expect(source).toContain('@click="confirmDelete(r)"');
    expect((source.match(/<AtButton/g) || []).length).toBeGreaterThan(8);
  });

  it('keeps loading failures actionable and separate from an empty branch', () => {
    expect(source).toContain('<AtSkeleton v-if="loading"');
    expect(source).toContain('class="classroom-error" tone="danger"');
    expect(source).toContain('教室清單暫時無法載入，請重試。');
    expect(source).toContain('@click="loadRooms">重試</AtButton>');
    expect(source).toContain('<AtEmpty v-else-if="!rooms.length"');
  });

  it('gives the room table and row actions contextual semantics', () => {
    expect(source).toContain('<caption class="sr-only">目前分校教室清單</caption>');
    expect(source).toContain('<th scope="col">教室名稱</th>');
    expect(source).toContain(':aria-label="`編輯教室：${r.name}`"');
    expect(source).toContain(':aria-label="`${r.is_active ? \'停用\' : \'啟用\'}教室：${r.name}`"');
    expect(source).toContain(':aria-label="`刪除教室：${r.name}`"');
  });
});
