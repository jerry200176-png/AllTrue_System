import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/TeachersList.vue'), 'utf8');

describe('TeachersList status workspace accessibility', () => {
  it('connects each status tab to its controlled panel', () => {
    expect(source).toContain('id="teachers-tab-active"');
    expect(source).toContain('aria-controls="teachers-panel-active"');
    expect(source).toContain('id="teachers-tab-pending"');
    expect(source).toContain('aria-controls="teachers-panel-pending"');
    expect(source).toContain('id="teachers-tab-suspended"');
    expect(source).toContain('aria-controls="teachers-panel-suspended"');
  });

  it('keeps the selected status content in a labelled tabpanel', () => {
    expect(source).toContain(':id="`teachers-panel-${tab}`"');
    expect(source).toContain(':aria-labelledby="`teachers-tab-${tab}`"');
    expect(source).toContain('role="tabpanel"');
    expect(source).toContain('tabindex="0"');
  });

  it('uses semantic status colors and readable RFID identifiers', () => {
    expect(source).toContain('class="badge badge--pending"');
    expect(source).toContain('class="badge badge--suspended"');
    expect(source).toContain('color: var(--ds-warning);');
    expect(source).toContain('color: var(--ds-ink-secondary);');
    expect(source).toContain('font-variant-numeric: tabular-nums;');
  });

  it('labels teacher management modal surfaces as dialogs', () => {
    expect(source).toContain('aria-labelledby="teacher-modal-title"');
    expect(source).toContain('id="teacher-modal-title"');
    expect(source).toContain('aria-labelledby="teachers-bulk-modal-title"');
    expect(source).toContain('id="teachers-bulk-modal-title"');
    expect((source.match(/role="dialog"/g) || []).length).toBe(2);
    expect((source.match(/aria-modal="true"/g) || []).length).toBe(2);
    expect(source).toContain('<button type="button" @click="closeModal">取消</button>');
    expect(source).toContain('<button type="button" class="primary" @click="submitForm">儲存</button>');
    expect(source).toContain('<button type="button" class="small" @click="copyBulkCredentials"');
    expect(source).toContain('<button type="button" class="small" @click="downloadBulkCredentialsCsv"');
    expect(source).toContain('<button type="button" class="small ghost" @click="refillBulkWithFailedRows"');
    expect(source).toContain('<button type="button" @click="closeBulkModal">關閉</button>');
    expect(source).toContain('<button type="button" class="primary" @click="submitBulkTeachers"');
  });

  it('associates teacher filters with their visible labels', () => {
    expect(source).toContain('<label for="teachers-search">搜尋（姓名／電話）</label>');
    expect(source).toContain('id="teachers-search"');
    expect(source).toContain('<label for="teachers-status-filter">狀態</label>');
    expect(source).toContain('id="teachers-status-filter"');
    expect(source).toContain('<label for="teachers-subject-filter">科目</label>');
    expect(source).toContain('id="teachers-subject-filter"');
  });
});
