import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  ROLE_ONBOARDING_VERSION,
  getRoleOnboardingSteps,
  onboardingStartIndex,
  onboardingStorageKey,
  readOnboardingState,
  shouldAutoStartOnboarding,
  writeOnboardingState,
} from './roleOnboarding.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const pageSource = (name) => readFileSync(resolve(__dirname, `../pages/${name}`), 'utf8');

const PAGE_FILES = {
  director: 'DirectorDashboard.vue',
  notifications: 'NotificationsCenter.vue',
  calendar: 'SmartCalendar.vue',
  learning: 'LearningRecordsPage.vue',
  'teacher-home': 'TeacherHomePage.vue',
  attendance: 'AttendancePage.vue',
};

function createStorage() {
  const values = new Map();
  return {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, String(value)),
  };
}

const storage = createStorage();
const identity = { role: 'teacher', userId: 'teacher-7', storage };

assert.equal(getRoleOnboardingSteps('teacher').length, 4);
assert.equal(getRoleOnboardingSteps('director').length, 4);
assert.equal(getRoleOnboardingSteps('super_admin')[0].page, 'director');
for (const role of ['teacher', 'director']) {
  for (const step of getRoleOnboardingSteps(role)) {
    assert.ok(step.id && step.title && step.description && step.icon);
    assert.match(step.target, /^\[data-guide=".+"\]$/);
    const guide = step.target.match(/^\[data-guide="(.+)"\]$/)?.[1];
    const file = PAGE_FILES[step.page];
    assert.ok(file, `missing page file mapping for ${step.page}`);
    assert.match(
      pageSource(file),
      new RegExp(`data-guide="${guide}"`),
      `${role} step ${step.id} target ${step.target} must exist in ${file}`,
    );
  }
}
assert.deepEqual(
  getRoleOnboardingSteps('teacher').map(({ page, target }) => [page, target]),
  [
    ['teacher-home', '[data-guide="teacher-home-today"]'],
    ['attendance', '[data-guide="attendance-header"]'],
    ['learning', '[data-guide="learning-header"]'],
    ['calendar', '[data-guide="calendar-header"]'],
  ],
);
assert.deepEqual(
  getRoleOnboardingSteps('director').map(({ page, target }) => [page, target]),
  [
    ['director', '[data-guide="director-summary"]'],
    ['notifications', '[data-guide="notifications-header"]'],
    ['calendar', '[data-guide="calendar-toolbar"]'],
    ['learning', '[data-guide="learning-header"]'],
  ],
);

assert.equal(onboardingStorageKey(identity), 'alltrue_role_onboarding:teacher:teacher-7');
assert.equal(readOnboardingState(identity), null);
assert.equal(shouldAutoStartOnboarding({ firstLogin: true }), true);
assert.equal(shouldAutoStartOnboarding({ firstLogin: false }), false);

assert.equal(writeOnboardingState({ ...identity, status: 'in_progress', stepIndex: 2 }), true);
const inProgress = readOnboardingState(identity);
assert.equal(inProgress.version, ROLE_ONBOARDING_VERSION);
assert.equal(inProgress.status, 'in_progress');
assert.equal(inProgress.stepIndex, 2);
assert.match(inProgress.updatedAt, /^20\d\d-/);
assert.equal(shouldAutoStartOnboarding({ state: inProgress, firstLogin: false }), true);
assert.equal(onboardingStartIndex(inProgress, 4), 2);
assert.equal(onboardingStartIndex({ ...inProgress, stepIndex: 99 }, 4), 3);

assert.equal(writeOnboardingState({ ...identity, status: 'completed', stepIndex: 3 }), true);
const completed = readOnboardingState(identity);
assert.equal(completed.status, 'completed');
assert.equal(shouldAutoStartOnboarding({ state: completed, firstLogin: true }), false);
assert.equal(shouldAutoStartOnboarding({ state: { ...completed, version: 'old' }, firstLogin: true }), false);

assert.equal(writeOnboardingState({ ...identity, status: 'skipped', stepIndex: 1 }), true);
assert.equal(readOnboardingState(identity).status, 'skipped');

assert.equal(writeOnboardingState({ ...identity, status: 'deferred' }), true);
assert.equal(readOnboardingState(identity).status, 'deferred');
assert.equal(shouldAutoStartOnboarding({ state: readOnboardingState(identity), firstLogin: true }), false);

console.log('roleOnboarding tests passed');
