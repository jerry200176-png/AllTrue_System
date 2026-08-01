import { describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { useCourseSessionsDisplay } from '../useCourseSessionsDisplay.js';

/**
 * Regression for 2026-07-29: useCourseSessionsDisplay() referenced an
 * undeclared SESSION_NOT_OCCUPYING_QUOTA in its return object, throwing
 * ReferenceError on every CourseManagement.vue mount (blank page, #1409
 * follow-up). No prior test actually called the real composable — the
 * "occurrence" test file only mirrors the filtering logic — so CI never
 * executed this function body.
 */
describe('useCourseSessionsDisplay', () => {
  it('constructs without throwing and exposes the expected API', () => {
    let display;
    expect(() => {
      display = useCourseSessionsDisplay({
        sessionsByCourse: ref({}),
        completedSessionDatesByCourse: ref({}),
        fetchClassSessionsFn: vi.fn(),
        supabase: { auth: { getSession: vi.fn() } },
        branchId: ref(1),
      });
    }).not.toThrow();

    expect(typeof display.sessionUnits).toBe('function');
    expect(typeof display.primarySessionUnits).toBe('function');
    expect(display.primarySessionUnits({ id: 1 })).toEqual([]);
  });
});
