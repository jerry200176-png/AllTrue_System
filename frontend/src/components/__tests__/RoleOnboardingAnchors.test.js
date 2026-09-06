import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { getRoleOnboardingSteps } from '../../lib/roleOnboarding.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const pageSource = (name) => readFileSync(resolve(__dirname, `../../pages/${name}`), 'utf8');
const appSource = readFileSync(resolve(__dirname, '../../App.vue'), 'utf8');

const PAGE_FILES = {
  director: 'DirectorDashboard.vue',
  notifications: 'NotificationsCenter.vue',
  calendar: 'SmartCalendar.vue',
  learning: 'LearningRecordsPage.vue',
  'teacher-home': 'TeacherHomePage.vue',
  attendance: 'AttendancePage.vue',
};

describe('role onboarding spotlight anchors', () => {
  it('keeps every director and teacher step target mounted in the live page', () => {
    for (const role of ['director', 'teacher']) {
      for (const step of getRoleOnboardingSteps(role)) {
        const guide = step.target.match(/^\[data-guide="(.+)"\]$/)?.[1];
        expect(guide, `${role}/${step.id} target shape`).toBeTruthy();
        const file = PAGE_FILES[step.page];
        expect(file, `${role}/${step.id} page mapping`).toBeTruthy();
        expect(pageSource(file)).toContain(`data-guide="${guide}"`);
      }
    }
  });

  it('exposes the first-step anchors that V1/V1.1 tours highlight', () => {
    expect(pageSource('DirectorDashboard.vue')).toContain('data-guide="director-summary"');
    expect(pageSource('TeacherHomePage.vue')).toContain('data-guide="teacher-home-today"');
  });

  it('renders mission objectives, explicit completion copy, and the existing rank strip', () => {
    expect(appSource).toContain('onboardingPromptMission.eyebrow');
    expect(appSource).toContain('guide-tour-objective');
    expect(appSource).toContain('guide-tour-completion-prompt');
    expect(appSource).toContain('currentStep.value.completionPrompt');
    expect(appSource).toContain('我完成了，下一步');
    expect(appSource).toContain('<EngagementRankStrip');
    expect(appSource).toContain('onboardingPromptMission.rankNote');
    expect(pageSource('DirectorDashboard.vue')).toContain('<EngagementRankStrip');
    expect(pageSource('TeacherHomePage.vue')).toContain('<EngagementRankStrip');
  });

  it('unifies onboarding entry in account menu and eliminates duplicate authenticated guide FAB', () => {
    expect(appSource).toContain('重新觀看新手教學');
    expect(appSource).toContain('startRoleOnboarding({ force: true })');
    expect(appSource).toContain('restartRoleOnboarding');
    expect(appSource).toContain('從頭開始');
    // Main authenticated template must not have the floating ? FAB
    const authenticatedShell = appSource.split('<div v-else class="app-layout">')[1] || '';
    expect(authenticatedShell).not.toContain('class="global-guide-btn"');
  });
});
