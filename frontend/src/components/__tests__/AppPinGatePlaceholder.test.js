import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const appPath = resolve(__dirname, '../../App.vue');
const source = readFileSync(appPath, 'utf8');

describe('PIN gate placeholder', () => {
  it('shows copy in main content while protected pages stay unmounted', () => {
    expect(source).toContain('class="pin-gate-placeholder"');
    expect(source).toContain('此頁需要 PIN 才能查看');
    expect(source).toContain('暫不啟用，直接進入');
    expect(source).toContain('TuitionCollectionPage v-if="!isPasswordChangeLocked && isDirector && active === \'tuition-collect\'"');
  });
});
