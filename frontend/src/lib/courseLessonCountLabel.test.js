import { describe, expect, it } from 'vitest';
import { courseLessonCountLabel } from './courseLessonCountLabel.js';

describe('courseLessonCountLabel', () => {
  it('count mode shows what is left, even at zero; unknown stays a dash', () => {
    expect(courseLessonCountLabel({ isSession: true, remaining: 5, completed: 3 })).toBe('剩 5 堂');
    expect(courseLessonCountLabel({ isSession: true, remaining: 0, completed: 8 })).toBe('剩 0 堂');
    expect(courseLessonCountLabel({ isSession: true, remaining: null, completed: 3 })).toBe('—');
  });
  it('monthly shows lessons taught, never a remaining figure', () => {
    expect(courseLessonCountLabel({ isSession: false, remaining: 99, completed: 7 })).toBe('已上 7 堂');
    expect(courseLessonCountLabel({ isSession: false, completed: undefined })).toBe('已上 0 堂');
  });
});
