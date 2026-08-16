import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const teachers = readFileSync(resolve(__dirname, '../../pages/TeachersList.vue'), 'utf8');
const branch = readFileSync(resolve(__dirname, '../../pages/BranchManagementPage.vue'), 'utf8');
const ledger = readFileSync(resolve(__dirname, '../AccountingLedgerModal.vue'), 'utf8');

describe('ui human copy sweep — director surfaces', () => {
  it('teachers bulk import uses Chinese field guidance', () => {
    expect(teachers).toContain('帳號、姓名、電話、主分校、可授科目');
    expect(teachers).not.toContain('建議欄位：account,name,phone,branch_id');
    expect(teachers).toContain('缺少帳號');
  });

  it('branch management labels token as 授權碼', () => {
    expect(branch).toContain('授權碼');
    expect(branch).not.toContain('>Token<');
  });

  it('ledger uses humanizeDocumentRef and humanizeApiErrorMessage', () => {
    expect(ledger).toContain('humanizeDocumentRef');
    expect(ledger).toContain('humanizeApiErrorMessage');
  });
});
