import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/BindingManagementPage.vue'), 'utf8');

describe('BindingManagementPage responsive presentation contract', () => {
  it('keeps the dense desktop table while exposing the same rows as labelled mobile cards', () => {
    expect(source).toContain('class="bmp-table-wrap bmp-desktop-table"');
    expect(source).toContain('class="bmp-mobile-list" aria-label="LINE 綁定清單"');
    expect(source).toContain('class="bmp-mobile-card"');
    expect(source).toContain('@media (max-width: 640px)');
    expect(source).toContain('.bmp-desktop-table { display: none; }');
    expect(source).toContain('.bmp-mobile-list { display: block; }');
    expect(source).toContain('min-height: var(--ds-control-height-touch, 44px)');
  });

  it('keeps destructive unbinding on the existing confirmation and API path', () => {
    expect(source).toContain('@click="openUnbindDialog(row)"');
    expect(source).toContain('title="解除 LINE 綁定"');
    expect(source).toContain('@click="confirmUnbind"');
    expect(source).toContain('await unbindBinding(unbindTarget.value.id)');
    expect(source).toContain('fetchBindings({');
    expect(source).toContain('campusId: activeCampusId.value');
    expect(source).toContain('studentName: filters.value.studentName || undefined');
    expect(source).toContain('perPage,');
  });
});
