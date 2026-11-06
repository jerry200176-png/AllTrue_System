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
    for (const tab of ['home', 'schedule', 'learning', 'billing']) {
      expect(source).toMatch(new RegExp(`id="parent-tab-${tab}"[^>]*type="button"[^>]*role="tab"`));
      expect(source).toMatch(new RegExp(`aria-controls="parent-panel-${tab}"`));
      expect(source).toMatch(new RegExp(`:tabindex="activeTab === '${tab}' \\? 0 : -1"`));
      expect(source).toMatch(new RegExp(`id="parent-panel-${tab}"[^>]*role="tabpanel"[^>]*aria-labelledby="parent-tab-${tab}"`));
    }
  });

  it('orders the bottom tabs 首頁 | 課表 | 學習 | 繳費 with touch targets of at least 44px', () => {
    const order = ['home', 'schedule', 'learning', 'billing'].map((t) => source.indexOf(`id="parent-tab-${t}"`));
    expect([...order].sort((a, b) => a - b)).toEqual(order);
    expect(order.every((i) => i > 0)).toBe(true);
    expect(source).toMatch(/\.pp-tab-bar \{\s*position: fixed;[^}]*bottom: 0/);
    expect(source).toMatch(/\.pp-tab \{[^}]*min-height: 56px;[^}]*min-width: 44px/);
    expect(source).toMatch(/\.pp-btn-logout \{[^}]*min-width: 44px; min-height: 44px/);
    expect(source).toContain("const parentTabKeys = ['home', 'schedule', 'learning', 'billing'];");
    expect(source).toContain("const activeTab = ref('home');");
  });

  it('supports roving tab movement without changing the selected-data contract', () => {
    expect(source).toMatch(/@keydown="onParentTabKeydown\(\$event, 'home'\)"/);
    expect(source).toMatch(/@keydown="onParentTabKeydown\(\$event, 'learning'\)"/);
    expect(source).toMatch(/\['ArrowRight', 'ArrowLeft', 'Home', 'End'\]/);
    expect(source).toMatch(/document\.getElementById\(`parent-tab-\$\{nextTab\}`\)\?\.focus\(\)/);
    expect(source).toMatch(/<button type="button" class="pp-btn pp-btn-primary" @click="login"/);
  });

  it('keeps parent actions aligned with capability and recovery contracts', () => {
    expect(source).toContain('const parentHome = computed(() => {');
    expect(source).toContain(':disabled="!fbCanSubmit || !crossCampusActionsEnabled"');
    expect(source).toContain(':disabled="!crossCampusActionsEnabled"');
    expect(source).toContain(':aria-label="`${n} 顆星`"');
    expect(source).toContain('const requestId = ++dashboardRequestSequence.value;');
    expect(source).toContain('if (isUnauthorized) {');
    expect(source).toContain('回覆已送出，但最新對話暫時載入失敗');
    expect(source).toContain('重試載入');
  });

  it('renders tutoring cards as free and never as unpaid', () => {
    expect(source).toContain("c.is_tutoring || c.payment_status === 'free'");
    expect(source).toContain('免費（不適用）');
    expect(source).toContain("const courseCardClass = (c) => {\n  if (c.is_tutoring || c.payment_status === 'free') return '';");
    expect(source).toContain("<span v-else class=\"pp-badge pp-badge-warning\">未繳費</span>");
  });

  it('首頁 shows exactly 3 cards from the existing dashboard and none of the retired first-load cards', () => {
    expect(source).toContain('<ParentHome :home="parentHome"');
    for (const gone of ['parent-progress-hub', 'parent-home-v1-title', 'parent-attention-card', 'pp-parent-update', '本次學習重點', '需要留意', '進度中心']) {
      expect(source).not.toContain(gone);
    }
    const home = readFileSync(resolve(here, '../parent/ParentHome.vue'), 'utf8');
    expect(home.match(/data-guide="parent-home-(next-lesson|money|feedback)"/g)).toHaveLength(3);
    expect(source).toContain('pickNextLesson');
    expect(source).toContain('pickAmountDue');
    expect(source).toContain('pickLatestFeedback');
  });

  it('makes learning records and their truthful empty state operable', () => {
    expect(source).toContain('class="pp-expand-icon"');
    expect(source).toContain(':aria-expanded="expandedRecords.has(record.id ?? record.ID) ? \'true\' : \'false\'"');
    expect(source).toContain('@click.stop="toggleRecord(record.id ?? record.ID)"');
    expect(source).toContain('data-guide="parent-learning-empty"');
    expect(source).toContain('v-if="!lrError && !allLearningRecords.length"');
    expect(source).toContain('老師完成複核後，這裡會顯示每堂課的進度、作業與建議。');
    expect(source).toContain("@click=\"gotoParentTarget('schedule', 'learning_empty')\"");
  });

  it('routes 首頁 buttons to the existing leave box, 繳費 and 學習', () => {
    expect(source).toContain('async function gotoParentTarget(target, source = \'hub_card\')');
    expect(source).toContain("@pay=\"gotoParentTarget('billing', 'home_pay')\"");
    expect(source).toContain("@learning=\"gotoParentTarget('learning', 'home_feedback')\"");
    expect(source).toContain('openLeaveBox(session);');
  });

  it('keeps the ⚙ settings entry, logout and plain-language labels', () => {
    expect(source).toContain('aria-label="設定"');
    expect(source).toMatch(/id="parent-panel-settings"[^>]*aria-label="設定"/);
    expect(source).toContain('@click="logout">登出</button>');
    expect(source).toContain('const statusLabel = lessonStatusLabel;');
  });
});
