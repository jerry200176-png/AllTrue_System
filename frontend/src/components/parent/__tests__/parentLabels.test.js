import { describe, expect, it } from 'vitest';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import ParentHome from '../ParentHome.vue';
import {
  LESSON_STATUS_LABELS, MONEY_STATUS_LABELS, COURSE_TYPE_LABELS, PARENT_BANNED_TERMS, PARENT_FALLBACK_LABEL,
  lessonStatusLabel, moneyStatusLabel, courseTypeLabel, outOfLessonsLabel, findBannedTerms,
} from '../../../lib/parentLabels';

// Every code ParentPortalController / the dashboard can return.
const SESSION_CODES = ['scheduled', 'rescheduled', 'leave_requested', 'leave', 'cancelled', 'completed'];
const ATTENDANCE_CODES = ['present', 'late', 'absent', 'leave', 'excused'];
const MONEY_CODES = ['paid', 'unpaid', 'partial', 'free', 'waived', 'review_required'];

describe('parentLabels', () => {
  it('maps every dashboard status code to a plain label', () => {
    for (const c of [...SESSION_CODES, ...ATTENDANCE_CODES]) {
      expect(lessonStatusLabel(c), c).not.toBe(PARENT_FALLBACK_LABEL);
    }
    for (const c of MONEY_CODES) expect(moneyStatusLabel(c), c).not.toBe(PARENT_FALLBACK_LABEL);
    expect(lessonStatusLabel('present')).toBe('到');
    expect(lessonStatusLabel('excused')).toBe('請假');
    expect(lessonStatusLabel('absent')).toBe('缺課');
    expect(lessonStatusLabel('scheduled')).toBe('已排課');
    expect(lessonStatusLabel('cancelled')).toBe('已取消');
    expect(moneyStatusLabel('unpaid')).toBe('未繳');
    expect(moneyStatusLabel('paid')).toBe('已繳');
    expect(moneyStatusLabel('waived')).toBe('不用繳');
    expect(courseTypeLabel('monthly')).toBe('每月方案');
    expect(outOfLessonsLabel()).toBe('已上完');
  });

  it('never returns a raw or unknown code', () => {
    for (const fn of [lessonStatusLabel, moneyStatusLabel, courseTypeLabel]) {
      for (const bad of ['weird_code', '', null, undefined, 'SCHEDULED_X']) {
        expect(fn(bad)).toBe(PARENT_FALLBACK_LABEL);
      }
    }
    expect(lessonStatusLabel(' Present ')).toBe('到');
  });

  it('banned internal terms never appear in any label', () => {
    const all = [...Object.values(LESSON_STATUS_LABELS), ...Object.values(MONEY_STATUS_LABELS), ...Object.values(COURSE_TYPE_LABELS), outOfLessonsLabel(), PARENT_FALLBACK_LABEL].join('|');
    expect(findBannedTerms(all)).toEqual([]);
    expect(PARENT_BANNED_TERMS).toEqual(expect.arrayContaining(['月結', '共用方案', '已核帳', '堂次', '堂數用完']));
    expect(findBannedTerms('本月月結')).toEqual(['月結']);
  });

  it('banned terms never appear in the rendered 首頁 or in parent template wording', async () => {
    const full = {
      nextLesson: { session: {}, dateLabel: '11/8', weekday: '日', time: '15:00', subject: '英文', teacher: '', daysLeft: 2, canLeave: true },
      due: { amount: 7200, dueDateLabel: '11/10' },
      feedback: { isNew: true, dateLabel: '11/1', subject: '數學', text: '進步很多' },
    };
    for (const home of [full, { nextLesson: null, due: null, feedback: null }]) {
      const html = await renderToString(createSSRApp(ParentHome, { home }));
      expect(findBannedTerms(html)).toEqual([]);
    }
    const page = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../../pages/ParentPortal.vue'), 'utf8');
    const template = page.slice(0, page.indexOf('<script setup>'));
    expect(findBannedTerms(template.replace(/<!--[\s\S]*?-->/g, ''))).toEqual([]);
  });
});
