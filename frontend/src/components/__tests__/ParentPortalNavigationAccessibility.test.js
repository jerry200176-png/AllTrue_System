import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(here, '../../pages/ParentPortal.vue'), 'utf8');

describe('parent portal navigation accessibility contract', () => {
  it('associates login labels with their controls and exposes errors', () => {
    expect(source).toMatch(/<label for="parent-login-name">/);
    expect(source).toMatch(/<input id="parent-login-name"[^>]*v-model="loginForm\.Name"/);
    expect(source).toMatch(/<label for="parent-login-phone">/);
    expect(source).toMatch(/<input id="parent-login-phone"[^>]*v-model="loginForm\.Phone"/);
    expect(source).toMatch(/<p class="pp-error" v-if="loginError" role="alert">/);
  });

  it('exposes the primary tabs and their panels as a connected tablist', () => {
    expect(source).toMatch(/class="pp-tab-bar" role="tablist" aria-label="家長入口分頁"/);
    for (const tab of ['learning', 'schedule', 'billing']) {
      expect(source).toMatch(new RegExp(`id="parent-tab-${tab}"[^>]*type="button"[^>]*role="tab"`));
      expect(source).toMatch(new RegExp(`aria-controls="parent-panel-${tab}"`));
      expect(source).toMatch(new RegExp(`:tabindex="activeTab === '${tab}' \\? 0 : -1"`));
      expect(source).toMatch(new RegExp(`id="parent-panel-${tab}"[^>]*role="tabpanel"[^>]*aria-labelledby="parent-tab-${tab}"`));
    }
  });

  it('supports roving tab movement without changing the selected-data contract', () => {
    expect(source).toMatch(/@keydown="onParentTabKeydown\(\$event, 'learning'\)"/);
    expect(source).toMatch(/\['ArrowRight', 'ArrowLeft', 'Home', 'End'\]/);
    expect(source).toMatch(/document\.getElementById\(`parent-tab-\$\{nextTab\}`\)\?\.focus\(\)/);
    expect(source).toMatch(/<button type="button" class="pp-btn pp-btn-primary" @click="login"/);
  });
});
