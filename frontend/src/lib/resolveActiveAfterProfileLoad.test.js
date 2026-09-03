import assert from 'node:assert/strict';
import { resolveActiveAfterProfileLoad } from './resolveActiveAfterProfileLoad.js';

assert.equal(
  resolveActiveAfterProfileLoad({
    role: 'teacher',
    mustChangePassword: true,
    currentActive: 'calendar',
  }),
  'profile',
  'password lock always wins',
);

assert.equal(
  resolveActiveAfterProfileLoad({
    role: 'teacher',
    currentActive: 'director',
  }),
  'teacher-home',
  'teacher session restore rewrites bootstrap director default',
);

assert.equal(
  resolveActiveAfterProfileLoad({
    role: 'teacher',
    currentActive: 'calendar',
  }),
  'calendar',
  'teacher profile refresh must not clobber 我的課表',
);

assert.equal(
  resolveActiveAfterProfileLoad({
    role: 'teacher',
    currentActive: 'attendance',
  }),
  'attendance',
  'teacher profile refresh must not clobber 出缺勤',
);

assert.equal(
  resolveActiveAfterProfileLoad({
    role: 'teacher',
    currentActive: 'teacher-home',
  }),
  'teacher-home',
  'staying on teacher-home is fine',
);

assert.equal(
  resolveActiveAfterProfileLoad({
    role: 'director',
    currentActive: 'course-mgmt',
  }),
  'course-mgmt',
  'director profile refresh must not clobber 課程查找',
);

assert.equal(
  resolveActiveAfterProfileLoad({
    role: 'super_admin',
    currentActive: 'teacher-home',
  }),
  'director',
  'director-family roles leave teacher-home mismatch',
);

assert.equal(
  resolveActiveAfterProfileLoad({
    role: 'director',
    currentActive: 'director',
  }),
  'director',
  'director home stays put',
);

console.log('resolveActiveAfterProfileLoad.test.js: ok');
