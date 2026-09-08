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

  it('keeps parent actions aligned with capability and recovery contracts', () => {
    expect(source).toContain("if (status === 'all_pending') return '查看待繳帳務';");
    expect(source).toContain(':disabled="!fbCanSubmit || !crossCampusActionsEnabled"');
    expect(source).toContain('const requestId = ++dashboardRequestSequence.value;');
    expect(source).toContain('if (isUnauthorized) {');
    expect(source).toContain('回覆已送出，但最新對話暫時載入失敗');
    expect(source).toContain('重試載入');
  });

  it('exposes the existing-data V1 home questions without adding a new data contract', () => {
    for (const label of ['最近學了什麼', '本週重點', '老師建議／處理', '回家要做什麼', '下一步／目前待辦']) {
      expect(source).toContain(label);
    }
    expect(source).toContain('buildParentHomeSummary');
  });
});
