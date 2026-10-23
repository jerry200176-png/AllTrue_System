import { expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { incomingClassConflict } from '../../lib/slotOccupancy.js';

// Execute the real edit handler: testing only the capacity helper missed this form boundary.
const source = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../pages/SmartCalendar.vue'), 'utf8');
const handler = source.slice(source.indexOf('const onCourseClick ='), source.indexOf('const loadCourseEvalRecords ='));
it.each(['one_on_three', null])('retains the stored trial target %s when opening a calendar edit', (target) => {
  const base = { id: 10, student_id: 20, teacher_id: 30, class_type: 'trial', trial_target_class_type: target };
  const modalForm = { value: null };
  const ref = () => ({ value: null });
  const dependencies = {
    courses: { value: [base] }, modalForm, editingCourseId: ref(), editingActionDate: ref(),
    editingException: ref(), sessionRecovery: ref(), moveContract: ref(), conflictWarning: ref(), showModal: ref(),
    findSessionRowForCell: () => null, makeSessionRecovery: () => ({}), makeMoveContract: () => ({}),
    normalizeTimeTo30: value => value, computeEndTime: () => '18:00',
    syncRatePer2hFromModel: vi.fn(), loadSessionRecovery: vi.fn(), loadCourseEvalRecords: vi.fn(),
  };
  const open = new Function(...Object.keys(dependencies), `${handler}; return onCourseClick;`)(...Object.values(dependencies));
  open({ ...base, is_exception: true, student_course_id: 10, trial_target_class_type: null }, '2026-10-22');
  expect(modalForm.value.trial_target_class_type).toBe(target);
  const result = incomingClassConflict({
    overlappingCourses: [{ id: 11, student_id: 21, class_type: 'trial' }],
    incomingType: modalForm.value.class_type, incomingTrialTarget: modalForm.value.trial_target_class_type,
  });
  expect(result.blocked).toBe(target !== 'one_on_three');
});
