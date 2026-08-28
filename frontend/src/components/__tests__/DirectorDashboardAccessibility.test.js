import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/DirectorDashboard.vue'), 'utf8');

describe('DirectorDashboard view switcher accessibility', () => {
  it('connects each view tab to its controlled panel', () => {
    expect(source).toContain('id="director-workbench-tab-focus"');
    expect(source).toContain('aria-controls="director-workbench-panel-focus"');
    expect(source).toContain('id="director-workbench-tab-full"');
    expect(source).toContain('aria-controls="director-workbench-panel-full"');
  });

  it('exposes both dashboard views as labelled tab panels', () => {
    expect(source).toContain('id="director-workbench-panel-focus"');
    expect(source).toContain('aria-labelledby="director-workbench-tab-focus"');
    expect(source).toContain('id="director-workbench-panel-full"');
    expect(source).toContain('aria-labelledby="director-workbench-tab-full"');
    expect(source).toContain('role="tabpanel"');
    expect(source).toContain('tabindex="0"');
  });
});
