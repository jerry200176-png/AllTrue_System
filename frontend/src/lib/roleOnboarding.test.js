import assert from 'node:assert/strict';
import {
  ROLE_ONBOARDING_VERSION,
  getRoleOnboardingSteps,
  onboardingStartIndex,
  onboardingStorageKey,
  readOnboardingState,
  shouldAutoStartOnboarding,
  writeOnboardingState,
} from './roleOnboarding.js';

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
