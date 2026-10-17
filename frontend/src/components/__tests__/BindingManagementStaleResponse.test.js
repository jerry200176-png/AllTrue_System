import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/BindingManagementPage.vue'), 'utf8');

describe('BindingManagementPage stale campus response guard (#4076)', () => {
  it('bumps a generation counter before each list and stats request', () => {
    expect(source).toMatch(/let\s+listRequestSeq\s*=\s*0/);
    expect(source).toMatch(/let\s+statsRequestSeq\s*=\s*0/);
    expect(source).toContain('const seq = ++listRequestSeq');
    expect(source).toContain('const seq = ++statsRequestSeq');
  });

  it('drops list and stats responses that finish after a newer campus refresh', () => {
    // After the await, ignore work that is no longer the latest request.
    expect(source).toMatch(/await fetchBindings\([\s\S]*?if \(seq !== listRequestSeq\) return/);
    expect(source).toMatch(/await fetchBindingStats\([\s\S]*?if \(seq !== statsRequestSeq\) return/);
    // loading / stats assignment must also respect the same generation.
    expect(source).toContain('if (seq === listRequestSeq) loading.value = false');
    expect(source).toMatch(/if \(seq !== statsRequestSeq\) return[\s\S]*stats\.value\s*=/);
  });
});
