import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, '../..');
const read = (rel) => readFileSync(resolve(root, rel), 'utf8');

describe('authoritative mutation ownership (slice 1: billing nav)', () => {
  it('exposes tuition-collect deep-link helpers', () => {
    const routes = read('lib/authoritativeMutationRoutes.js');
    expect(routes).toContain('buildTuitionCollectNav');
    expect(routes).toContain("target: 'tuition-collect'");
  });

  it('removes notification tuition-paid mutation and deep-links to tuition-collect', () => {
    const notifications = read('pages/NotificationsCenter.vue');
    expect(notifications).not.toContain('confirmTuitionPaid');
    expect(notifications).not.toContain('openTuitionModal');
    expect(notifications).toContain('canGoToTuitionBilling');
    expect(notifications).toContain('前往帳務中心');
  });

  it('wires contextual navigate handlers for students and calendar', () => {
    const app = read('App.vue');
    expect(app).toContain("@navigate=\"onNavigateFromNotifications\"");
    expect(app).toContain('courseMgmtFocusStudentId');
    expect(app).toContain('bindingMgmtFocusStudentName');
  });
});
