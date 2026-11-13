import { test, expect } from '@playwright/test';
import { CLASSMATES, DAY_LABELS, TODAY, mockStudyWeekApi } from './fixtures/ui-foundation/study-week-data.js';

/**
 * Layout guard for pages migrated to the ui components (in-app #541 / #542 / #543, #4989).
 * Real browser, realistic CJK data, desktop + phone, every 字級 setting. A page is guarded by adding ONE entry to PAGES.
 *
 * For every page x viewport x font size it asserts:
 *   (a) a name never renders taller than 1.6 x its line height (no per-character wrapping);
 *   (b) the page body has no horizontal overflow;
 *   (c) sibling rows start at the same x (within 1px), whether or not a row shows a checkbox;
 *   (d) optional `touch` selectors: every visible match is at least 44 x 44 and the selectors match something.
 * It runs in the required ui-foundation job (see playwright.ui-foundation.config.js), not the visual-regression job.
 */

const TEXT_SIZES = ['standard', 'medium', 'large']; // useTextSize.js TEXT_SIZES (標準, 稍大, 大)
const VIEWPORTS = [{ name: 'desktop-1536', width: 1536, height: 784 }, { name: 'phone-375', width: 375, height: 800 }];
// Tablet widths, run for the 課程查找 entries only (the course rows change layout at 768 and 1024).
const TABLET_VIEWPORTS = [{ name: 'tablet-768', width: 768, height: 1024 }, { name: 'laptop-1024', width: 1024, height: 768 }, { name: 'laptop-1099', width: 1099, height: 768 }];
const json = (route, body) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });

const NAMES = ['林宥辰', '陳品妤', '王子謙', '張育豪', '李欣怡', '黃柏諺', '吳佳穎', '蔡承翰', '劉宇軒', '楊雅婷', '許家瑋', '鄭淑芬', '謝宗翰', '郭怡君', '洪瑞', '曾翊', '周小明', '蕭婉如', '賴冠廷', '歐陽子安'];
const SUBJECTS = ['國中數學', '高中英文', '國小自然', '高二物理（先修班）', '國中理化與生物綜合複習衝刺班', '一對一作文'];

const COURSE_NAMES = ['林宥辰', '歐陽子晴', '范姜宜蓁', '司馬文傑', '陳品妤', '曾翊'];
const COURSE_MGMT = {
  // 課程查找, slices 1-4 (issue 4989): header, toolbar, 課程分布 strip, the student group cards and the course rows inside them.
  // The left-edge group mixes the header title column, the toolbar card, the strip label and the group cards on purpose:
  // they are different elements, so a gutter that differs between header and list makes it fail (a list of identical cards cannot).
  key: 'course-mgmt',
  url: '/pilot-mount.html?page=course&mode=normal',
  names: COURSE_NAMES,
  allNames: true,
  rows: ['.course-workbench__intro, .course-header-card, .course-stats-disclosure__label, .student-group-card', '.contract-item'],
  touch: ['.student-group-toggle', '.focus-btn', '.student-group-view-tab', '[data-testid="course-row-primary"]', '.course-row .action-menu-trigger', '.paused-notice__btn', '.tag-usage-review--action'],
  // Slice 3: the five facts of a course row sit in one line under the column header at desktop (same left edges, same top),
  // and on a phone no fact or action sticks out of its row.
  extra: {
    run: () => {
      const visible = (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.width > 0 && r.height > 0 && cs.display !== 'none' && cs.visibility !== 'hidden'; };
      const round = (n) => Math.round(n * 10) / 10;
      const head = [...document.querySelectorAll('.contract-list-head')].find(visible);
      const headLefts = head ? [...head.children].map((c) => round(c.getBoundingClientRect().left)) : [];
      const rows = [...document.querySelectorAll('.course-row')].filter(visible).map((row) => {
        const box = row.getBoundingClientRect();
        const cells = [...row.children].map((c) => c.getBoundingClientRect());
        const value = row.querySelector('[data-col="remaining"] .remaining-value');
        const [cr, cg, cb] = (value ? getComputedStyle(value).color : 'rgb(0, 0, 0)').match(/\d+(\.\d+)?/g).map(Number);
        const badge = row.querySelector('.payment-status-badge');
        const [br, bg, bb] = (badge ? getComputedStyle(badge).color : 'rgb(0, 0, 0)').match(/\d+(\.\d+)?/g).map(Number);
        return {
          lefts: cells.map((c) => round(c.left)), tops: cells.map((c) => round(c.top)),
          overflow: cells.filter((c) => c.right > box.right + 1 || c.left < box.left - 1).length, count: cells.length,
          low: row.querySelector('[data-col="remaining"]')?.classList.contains('low') || false, paused: row.classList.contains('course-paused'),
          valueRed: cr > cg + 80 && cb > cg + 20, badgeText: badge?.textContent.trim() || '', badgeRed: br > bg + 80 && bb > bg + 20,
        };
      });
      const rgbOf = (el) => getComputedStyle(el).color.match(/\d+(\.\d+)?/g).map(Number);
      const usageRed = [...document.querySelectorAll('.course-row .tag-usage-review--action')].filter(visible).map((b) => { const [r, g, bl] = rgbOf(b); return r > g + 80 && bl > g + 20; });
      return { headLefts, rows, usageRed };
    },
    assert: (r, vp) => {
      expect(r.rows.length, 'course rows must be on screen').toBeGreaterThan(0);
      expect(r.usageRed.length, 'the fixture has a course with a usage review').toBeGreaterThan(0);
      expect(r.usageRed.every(Boolean), 'the 堂數待對帳 button of a course row is red').toBe(true);
      for (const row of r.rows) {
        expect(row.count, 'a row has five cells: subject, next, remaining, payment, actions').toBe(5);
        expect(row.overflow, 'a cell of a course row sticks out of its row').toBe(0);
        // a low 堂數 is red, but a paused course's low count is not; owed (未繳) is red, partial (繳了一部分) is not
        if (row.low) expect(row.valueRed, `a low 堂數 is red unless the course is paused (paused: ${row.paused})`).toBe(!row.paused);
        else expect(row.valueRed, 'a normal 堂數 stays ink').toBe(false);
        if (row.badgeText === '未繳') expect(row.badgeRed, '未繳 is red').toBe(true);
        if (row.badgeText.includes('一部分')) expect(row.badgeRed, '部分 is a warning tone, not red').toBe(false);
        if (vp.width >= 1024) {
          expect(r.headLefts.length, 'the column header is shown at desktop').toBe(5);
          row.lefts.slice(0, 4).forEach((x, i) => expect(Math.abs(x - r.headLefts[i]), `column ${i} of a row lines up with its header (${row.lefts.join(',')} vs ${r.headLefts.join(',')})`).toBeLessThanOrEqual(1));
          expect(Math.max(...row.tops) - Math.min(...row.tops), 'the five cells of a desktop row share one top').toBeLessThanOrEqual(1);
        } else {
          expect(r.headLefts.length, 'no column header on a phone').toBe(0);
        }
      }
    },
  },
  mock: async (page) => {
    const data = COURSE_NAMES.map((n, i) => ({ id: 100 + i, ...(i === 2 ? { usage_balance_status: 'review_required' } : {}), ...(i === 3 ? { PackageID: 5, PackageName: '雙科方案' } : {}), student_id: 9100 + i, student_name: n, teacher_name: ['王老師', '李大華老師', '陳小明老師'][i % 3], subject: 'math', subject_name: SUBJECTS[i % 6], first_class_date: '2026-09-01', status: i === 1 ? 'inactive' : 'active', ScheduleMode: 'count', payment_type: 'session', sessions_purchased: 8, sessions_used: 3, remaining_sessions: i === 1 || i === 2 ? 1 : 5, payment_status: ['unpaid', 'paid', 'partial'][i % 3], class_type: 'one_on_one' }));
    await page.route('**/api/v1/**', (r) => json(r, new URL(r.request().url()).pathname === '/api/v1/student-classes' ? { data, total: data.length, current_page: 1, last_page: 1 } : { data: [] }));
  },
};
// The opened help panel (#4989 slice 1 follow-up): it takes its own full row, starts on the title's left edge and does not push the page sideways.
const COURSE_MGMT_HELP_OPEN = {
  ...COURSE_MGMT,
  key: 'course-mgmt (help panel open)',
  allNames: false,
  rows: ['.course-workbench__intro, .course-workbench__help[open], .course-workbench__help[open] > summary > svg, .course-workbench__help[open] > p'],
  touch: ['.course-workbench__help[open] > summary'],
  setup: async (page) => { await page.locator('[data-testid="course-help"] > summary').click(); await expect(page.locator('.course-workbench__help[open]')).toBeVisible(); },
};

// 課程查找 slice 4 (issue 4989): the history cards. Five closed courses of one student, one per closed state, one with a usage review.
// Checks that every card keeps its facts inside the card, that each closed state carries its tone (settled = success, owed = danger,
// completed / package = neutral) and that only the 堂數待對帳 button is red.
const HISTORY_CARD_CASES = [
  { id: 200, reason: 'completed', over: {} },
  { id: 201, reason: 'settled', over: { class_type: 'one_on_two', usage_balance_status: 'review_required' } },
  { id: 202, reason: 'waived', over: { PackageID: 5, PackageName: '雙科方案', teacher_name: '李大華老師' } },
  { id: 203, reason: 'converted_trial', over: { class_type: 'trial', sessions_purchased: 1, sessions_used: 1 } },
  { id: 204, reason: 'contract_amended', over: { payment_status: 'unpaid', class_type: 'one_on_three' } },
];
const COURSE_MGMT_HISTORY = {
  ...COURSE_MGMT,
  key: 'course-mgmt (history cards open)',
  names: ['林宥辰'],
  allNames: false,
  rows: ['.history-course-card'],
  touch: ['.history-section__toggle', '.history-course-card .btn-toggle', '.history-course-card .action-menu-trigger', '.history-course-card .tag-usage-review--action'],
  setup: async (page) => {
    await page.getByText('林宥辰', { exact: true }).first().waitFor({ state: 'attached' });
    for (const toggle of await page.locator('.history-section__toggle').all()) await toggle.click();
    await expect(page.locator('.history-course-card')).toHaveCount(HISTORY_CARD_CASES.length);
  },
  extra: {
    run: () => {
      const visible = (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.width > 0 && r.height > 0 && cs.display !== 'none' && cs.visibility !== 'hidden'; };
      const rgb = (el) => getComputedStyle(el).color.match(/\d+(\.\d+)?/g).map(Number);
      const isRed = ([r, g, b]) => r > g + 80 && b > g + 20;
      const isGreen = ([r, g, b]) => g > r + 15 && g > b;
      return [...document.querySelectorAll('.history-course-card')].filter(visible).map((card) => {
        const box = card.getBoundingClientRect();
        const inside = [...card.querySelectorAll('*')].filter(visible).filter((el) => { const r = el.getBoundingClientRect(); return r.right > box.right + 1 || r.left < box.left - 1; }).length;
        const button = card.querySelector('.tag-usage-review--action');
        return {
          id: Number(card.getAttribute('data-contract-id')), outside: inside, oldTags: card.querySelectorAll('.tag, .status-tag').length,
          facts: card.querySelectorAll('.history-course-card__details dt').length,
          badges: [...card.querySelectorAll('[data-slot="status-badge"]')].map((b) => ({ text: b.textContent.trim(), tone: b.getAttribute('data-tone'), red: isRed(rgb(b)), green: isGreen(rgb(b)) })),
          button: button ? { red: isRed(rgb(button)), h: Math.round(button.getBoundingClientRect().height) } : null,
        };
      });
    },
    assert: (cards) => {
      expect(cards.length, 'every history card is on screen').toBe(HISTORY_CARD_CASES.length);
      const byId = Object.fromEntries(cards.map((c) => [c.id, c]));
      for (const c of cards) {
        expect(c.outside, `a part of history card ${c.id} sticks out of the card`).toBe(0);
        expect(c.oldTags, `history card ${c.id} still uses the old .tag / .status-tag`).toBe(0);
        expect(c.facts, `history card ${c.id} shows 開課, 老師, 費用 and 堂數`).toBeGreaterThanOrEqual(4);
        // red: a badge whose tone is danger (an owed 未收 course) and the 堂數待對帳 button; every other history badge is not red.
        // Keyed on the tone, not on the badge's words, so rewording a label cannot flip a colour check.
        for (const b of c.badges) expect(b.red, `badge 「${b.text}」 (tone ${b.tone}) on history card ${c.id} is red exactly when its tone is danger`).toBe(b.tone === 'danger');
      }
      const tone = (id, text) => byId[id].badges.find((b) => b.text.includes(text));
      expect(tone(201, '已結算')?.tone, 'a settled course is success').toBe('success');
      expect(tone(201, '已結算')?.green, 'success is green').toBe(true);
      expect(tone(202, '歷史')?.tone, 'a waived course is success').toBe('success');
      expect(tone(202, '方案')?.tone, 'the package badge is neutral').toBe('neutral');
      expect(tone(203, '已轉正式')?.tone, 'a converted trial is success').toBe('success');
      expect(tone(204, '未收')?.tone, 'ended with money owed (未收) is danger, like 未繳 on an active row').toBe('danger');
      expect(tone(200, '已完課')?.tone, 'a completed course is neutral').toBe('neutral');
      expect(tone(200, '已完課')?.green, 'neutral is not green').toBe(false);
      expect(byId[201].button?.red, 'the 堂數待對帳 button is red').toBe(true);
      expect(byId[201].button?.h, 'the 堂數待對帳 button is at least 44px tall').toBeGreaterThanOrEqual(44);
      expect(byId[200].button, 'only the course with a usage review has the button').toBeNull();
    },
  },
  mock: async (page) => {
    const base = (o) => ({ student_id: 9100, student_name: '林宥辰', teacher_name: '歐陽子晴老師', subject: 'math', ScheduleMode: 'count', payment_type: 'session', sessions_purchased: 8, class_type: 'one_on_one', ...o });
    const history = HISTORY_CARD_CASES.map(({ id, reason, over }) => base({ id, first_class_date: `2025-0${id - 199}-01`, status: 'inactive', history_reason: reason, closed_reason: reason, is_active_course: false, sessions_used: 8, remaining_sessions: 0, payment_status: 'paid', ...over }));
    const active = base({ id: 100, first_class_date: '2026-09-01', status: 'active', is_active_course: true, sessions_used: 3, remaining_sessions: 5, payment_status: 'paid' });
    const data = [active, ...history];
    await page.route('**/api/v1/**', (r) => json(r, new URL(r.request().url()).pathname === '/api/v1/student-classes' ? { data, total: data.length, current_page: 1, last_page: 1 } : { data: [] }));
  },
};

// 課程查找 slice 5 (issue 4989): the 帳務資料 tab. Money screen: every status keeps its tone (owed / anomaly = danger, partial / needs a look = warning,
// paid = success, nothing to pay = neutral), red appears only on debt, and every amount line is tabular and on one line.
const BILLING_CASES = [
  { id: 100, status: 'unpaid', invoice: { status: 'unpaid', total_amount: 4800 }, reports: [] },
  { id: 101, status: 'partial', invoice: { status: 'partial', total_amount: 6400 }, reports: [{ status: 'pending', payment_date: '2026-11-03', account_last5: '12345' }] },
  { id: 102, status: 'paid', invoice: { status: 'paid', total_amount: 3200 }, reports: [{ status: 'confirmed', payment_date: '2026-10-28' }] },
  { id: 103, status: 'pending_report', invoice: null, reports: [{ status: 'pending', payment_date: '2026-11-10' }], charge: 5600 },
  { id: 104, status: 'unpaid', invoice: null, reports: [], over: { class_type: 'tutoring', tutoring_billing_anomaly: true } },
];
const COURSE_MGMT_BILLING = {
  ...COURSE_MGMT,
  key: 'course-mgmt (billing table)',
  names: ['林宥辰'],
  allNames: false,
  // the column header and every row start at the table's left edge
  rows: ['.student-billing-table th:first-child, .student-billing-table td:first-child'],
  touch: ['.student-billing-table tbody button', '.student-billing-wrap [role="status"] button'],
  setup: async (page) => {
    await page.getByRole('tab', { name: '帳務資料', exact: true }).first().click();
    await expect(page.locator('.student-billing-table tbody tr')).toHaveCount(BILLING_CASES.length);
  },
  extra: {
    run: () => {
      const visible = (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.width > 0 && r.height > 0 && cs.display !== 'none' && cs.visibility !== 'hidden'; };
      const rgb = (el) => getComputedStyle(el).color.match(/\d+(\.\d+)?/g).map(Number);
      const isRed = ([r, g, b]) => r > g + 80 && b > g + 20;
      const isGreen = ([r, g, b]) => g > r + 15 && g > b;
      const wrap = document.querySelector('.student-billing-wrap');
      const table = document.querySelector('.student-billing-table');
      const lineH = (el) => parseFloat(getComputedStyle(el).lineHeight) || parseFloat(getComputedStyle(el).fontSize) * 1.4;
      const summary = [...wrap.querySelectorAll('[role="status"]')].find((el) => el.textContent.includes('學生歷史帳務'));
      const owed = summary ? [...summary.querySelectorAll('span')].filter((el) => el.textContent.includes('尚有未繳')).pop() : null; // the innermost span that holds the words
      return {
        wrapScroll: wrap.scrollWidth - wrap.clientWidth, wrapOverflowX: getComputedStyle(wrap).overflowX,
        oldClasses: wrap.querySelectorAll('.tag, .subject-tag, .course-table, .price-line, .hint').length,
        headers: [...table.querySelectorAll('thead th')].map((th) => ({ text: th.textContent.trim(), h: Math.round(th.getBoundingClientRect().height), upper: getComputedStyle(th).textTransform })),
        owedRed: owed ? isRed(rgb(owed)) : null, owedText: owed?.textContent.trim() || '',
        rows: [...table.querySelectorAll('tbody tr')].filter(visible).map((tr) => {
          const badge = tr.querySelector('[data-slot="status-badge"]');
          const box = tr.getBoundingClientRect();
          return {
            ref: tr.querySelector('[data-testid="billing-contract-period"]')?.textContent.trim() || '',
            badge: badge ? { text: badge.textContent.trim(), tone: badge.getAttribute('data-tone'), red: isRed(rgb(badge)), green: isGreen(rgb(badge)) } : null,
            amounts: [...tr.querySelectorAll('[data-testid="billing-amount"]')].map((a) => ({ text: a.textContent.trim(), tabular: getComputedStyle(a).fontVariantNumeric.includes('tabular-nums'), oneLine: a.getBoundingClientRect().height <= lineH(a) * 1.6, red: isRed(rgb(a)) })),
            buttons: [...tr.querySelectorAll('button')].filter(visible).map((b) => ({ h: Math.round(b.getBoundingClientRect().height), red: isRed(rgb(b)) })),
            outside: [...tr.querySelectorAll('td > *')].filter(visible).filter((el) => el.getBoundingClientRect().right > table.getBoundingClientRect().right + 1).length,
            height: Math.round(box.height),
          };
        }),
      };
    },
    assert: (r, vp) => {
      expect(r.rows.length, 'every billing row is on screen').toBe(BILLING_CASES.length);
      expect(r.oldClasses, 'the 帳務資料 table no longer uses the old .tag / .subject-tag / .course-table / .price-line / .hint classes').toBe(0);
      expect(r.wrapOverflowX, 'the billing panel scrolls inside itself, never the page').toBe('auto');
      expect(r.headers.map((h) => h.text), 'the six column headers keep their words').toEqual(['科目', '合約／期間', '繳費', '最近回報', '收據', '操作']);
      for (const h of r.headers) {
        expect(h.h, `header 「${h.text}」 is 44px tall`).toBeGreaterThanOrEqual(44);
        expect(h.upper, `header 「${h.text}」 is not upper-cased`).toBe('none');
      }
      // colour meaning: red only for debt (owed 未繳, 帳務資料需修正, 尚有未繳 in the summary), warning for a part payment or a report to check, green for paid
      const tones = Object.fromEntries(r.rows.map((row) => [row.badge?.text, row.badge]));
      expect(tones['未繳']?.tone, '未繳 is danger').toBe('danger');
      expect(tones['繳了一部分']?.tone, '部分 is warning').toBe('warning');
      expect(tones['已收']?.tone, '已收 is success').toBe('success');
      expect(tones['已收']?.green, '已收 is green').toBe(true);
      expect(tones['帳務資料需修正']?.tone, 'a tutoring anomaly is danger').toBe('danger');
      expect(Object.values(tones).some((b) => b && b.tone === 'warning' && !['繳了一部分'].includes(b.text)), '待確認入帳 is a warning tone').toBe(true);
      for (const row of r.rows) {
        expect(row.badge, 'every row has exactly one status badge').not.toBeNull();
        expect(row.badge.red, `badge 「${row.badge.text}」 (tone ${row.badge.tone}) is red exactly when its tone is danger`).toBe(row.badge.tone === 'danger');
        expect(row.outside, `a cell of row ${row.ref} sticks out of the table`).toBe(0);
        for (const b of row.buttons) { expect(b.h, 'row buttons are at least 44px').toBeGreaterThanOrEqual(44); expect(b.red, 'a row button is never red').toBe(false); }
        expect(row.amounts.length, `row ${row.ref} shows an amount line (應繳 / 待開單 / 估算)`).toBeGreaterThan(0);
        for (const a of row.amounts) {
          expect(a.tabular, `amount 「${a.text}」 uses tabular numbers`).toBe(true);
          expect(a.oneLine, `amount 「${a.text}」 stays on one line`).toBe(true);
          expect(a.red, `amount 「${a.text}」 is not red (it is a figure, not a debt state)`).toBe(false);
        }
      }
      expect(r.owedText, 'the summary names the owed total').toContain('尚有未繳');
      expect(r.owedRed, 'the owed total in the summary is red').toBe(true);
      if (vp.width >= 1024) expect(r.wrapScroll, 'no inner sideways scroll at desktop width').toBeLessThanOrEqual(1);
    },
  },
  mock: async (page) => {
    const base = (o) => ({ student_id: 9100, student_name: '林宥辰', teacher_name: '歐陽子晴老師', ScheduleMode: 'count', payment_type: 'session', sessions_purchased: 8, sessions_used: 3, remaining_sessions: 5, class_type: 'one_on_one', first_class_date: '2026-09-01', status: 'active', is_active_course: true, ...o });
    const subjects = ['math', 'english', 'physics', 'chemistry', 'science'];
    const data = BILLING_CASES.map((c, i) => base({ id: c.id, subject: subjects[i], payment_status: c.status, ...(c.charge ? { Charge: c.charge } : {}), ...(c.over || {}) }));
    const byId = Object.fromEntries(BILLING_CASES.map((c) => [c.id, c]));
    await page.route('**/api/v1/**', (r) => json(r, new URL(r.request().url()).pathname === '/api/v1/student-classes' ? { data, total: data.length, current_page: 1, last_page: 1 } : { data: [] }));
    await page.route('**/api/v1/student-classes/*/invoices', (r) => { const c = byId[r.request().url().match(/student-classes\/(\d+)/)[1]]; return json(r, { invoices: c?.invoice ? [{ id: c.id, ...c.invoice }] : [] }); });
    await page.route('**/api/v1/payment-reports**', (r) => { const c = byId[new URL(r.request().url()).searchParams.get('student_class_id')]; return json(r, { data: c?.reports || [] }); });
    await page.route('**/api/v1/accounting/ledger**', (r) => json(r, { summary: { outstanding_total: 11200 } }));
  },
};

// Dark mode, hovered row (Codex finding on issue 4989 slice 5): styles.css has a global `tr:hover td` background rule; the shared dataTable cell classes must
// override it, or light text lands on near-white. Asserts the text/background contrast of every cell of the hovered row is at least 4.5 (WCAG AA).
const COURSE_MGMT_BILLING_DARK = {
  ...COURSE_MGMT_BILLING,
  key: 'course-mgmt (billing table, dark, row hovered)',
  setup: async (page) => {
    await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
    await page.getByRole('tab', { name: '帳務資料', exact: true }).first().click();
    await expect(page.locator('.student-billing-table tbody tr')).toHaveCount(BILLING_CASES.length);
    await page.locator('.student-billing-table tbody tr').nth(1).hover();
  },
  extra: {
    run: () => {
      const probe = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
      const rgba = (css) => { probe.clearRect(0, 0, 1, 1); probe.fillStyle = '#000'; probe.fillStyle = css; probe.fillRect(0, 0, 1, 1); const [r, g, b, a] = probe.getImageData(0, 0, 1, 1).data; return [r, g, b, a / 255]; };
      const over = (top, under) => [0, 1, 2].map((i) => top[i] * top[3] + under[i] * (1 - top[3])).concat([1]);
      const lum = ([r, g, b]) => { const f = (c) => { const v = c / 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
      const backdrop = (el) => { const chain = []; for (let n = el; n; n = n.parentElement) chain.push(rgba(getComputedStyle(n).backgroundColor)); return chain.reduceRight((acc, c) => over(c, acc), [255, 255, 255, 1]); };
      const tr = [...document.querySelectorAll('.student-billing-table tbody tr')][1];
      return {
        dark: document.documentElement.getAttribute('data-theme') === 'dark', hovered: tr.matches(':hover'),
        cells: [...tr.querySelectorAll('td')].map((td) => {
          const bg = backdrop(td); const fg = over(rgba(getComputedStyle(td).color), bg);
          const [l1, l2] = [lum(fg), lum(bg)].sort((a, b) => b - a);
          return { text: td.textContent.trim().slice(0, 12), ratio: Math.round(((l1 + 0.05) / (l2 + 0.05)) * 100) / 100 };
        }),
      };
    },
    assert: (r) => {
      expect(r.dark, 'the page is in dark mode').toBe(true);
      expect(r.hovered, 'the second billing row is hovered').toBe(true);
      expect(r.cells.length).toBe(6);
      for (const c of r.cells) expect(c.ratio, `text 「${c.text}」 on the hovered dark row is at least 4.5:1 (got ${c.ratio})`).toBeGreaterThanOrEqual(4.5);
    },
  },
};

/** PAGES: { key, url, mock(page), names (exact texts that must stay on one line), rows (selector groups whose visible members must share a left edge), setup?(page) } */
const PAGES = [
  {
    key: 'tuition-report',
    url: '/pilot-mount.html?page=tuition-report',
    names: NAMES,
    rows: ['tbody tr td:first-child'],
    mock: async (page) => {
      const data = NAMES.map((n, i) => ({ student_class_id: i + 1, student_name: n, subject: SUBJECTS[i % 6], teacher_name: ['王老師', '李大華老師', '陳老師'][i % 3], class_type: 'one_on_one', monthly_sessions: 8, rate: 600, monthly_tuition: 4800, pay_status: ['paid', 'partial', 'unpaid', 'review'][i % 4], outstanding_amount: 1200, last_paid_at: '2026-11-03' }));
      await page.route('**/api/v1/finance/branch-monthly-tuition**', (r) => json(r, { data, summary: { total_students: 20, total_sessions: 160, total_tuition: 96000, total_due: 96000, total_collected: 50000, total_outstanding: 46000, unpaid_students: 9 }, meta: { current_page: 1, last_page: 1, per_page: 50, total: 20 } }));
    },
  },
  {
    // issue 5269 step 2: the teacher's week view (a tab of the student profile). Day labels are the "names" (never wrap);
    // the plan lines and the day grid share the card's left edge; every control a finger meets is at least 44px.
    key: 'student-study-week',
    url: '/study-week-pilot-mount.html',
    names: [...DAY_LABELS, '歐陽子安 學習檔案'],
    allNames: false,
    rows: ['[data-testid="study-progress"], [data-testid="week-plans"], [data-testid="week-grid"]', '[data-testid="week-plans"] > div'],
    touch: ['[data-testid^="add-"]', '[data-testid="week-prev"]', '[data-testid="week-next"]', '[data-testid="week-today"]', '[data-testid="copy-week"]', '[data-testid^="done-"] ~ *, label:has([data-testid^="done-"])', 'button[data-testid^="title-"]'],
    mock: async (page) => { await page.clock.setFixedTime(TODAY); await mockStudyWeekApi(page, 'full'); },
    setup: async (page) => {
      await page.getByRole('tab', { name: '讀書計畫' }).click();
      await expect(page.getByTestId('task-8')).toBeVisible();
      // Five tabs must not widen the profile dialog: the tab strip scrolls inside itself, nothing overflows the dialog or the page.
      const tabs = await page.evaluate(() => {
        const dialog = document.querySelector('[role="dialog"]').getBoundingClientRect();
        const list = document.querySelector('[data-testid="profile-tabs"]');
        const box = list.getBoundingClientRect();
        const first = list.querySelector('[role="tab"]').getBoundingClientRect();
        return { listRight: box.right, dialogRight: dialog.right, listScroll: getComputedStyle(list).overflowX, firstLeft: first.left - box.left, tabH: Math.min(...[...list.querySelectorAll('[role="tab"]')].map((t) => t.getBoundingClientRect().height)), dialogOverflow: document.querySelector('[role="dialog"]').scrollWidth - document.querySelector('[role="dialog"]').clientWidth };
      });
      expect(tabs.listRight, 'tab strip wider than the dialog').toBeLessThanOrEqual(tabs.dialogRight + 1);
      expect(tabs.dialogOverflow, 'the dialog scrolls sideways').toBeLessThanOrEqual(0);
      expect(tabs.listScroll).toBe('auto');
      expect(tabs.firstLeft, 'tabs start at the left edge of the strip').toBeLessThanOrEqual(8);
      expect(tabs.tabH, 'tab targets are at least 44px').toBeGreaterThanOrEqual(44);
      // Progress strip (step 3): three figures with the numbers of the fixture, each value on one line, nothing clipped, the strip
      // inside the dialog; the decorative bar's fill is the same share of its track as the number says; desktop = one row, phone = plan on top, two below.
      await expect(page.getByTestId('week-plans')).toBeVisible();
      await expect(page.getByTestId('progress-plan')).toContainText('33%');
      const strip = await page.evaluate(() => {
        const q = (k) => document.querySelector(`[data-testid="progress-${k}"]`);
        const box = (el) => el.getBoundingClientRect();
        const read = (k) => {
          const el = q(k); const dd = el.querySelector('dd'); const bar = document.querySelector(`[data-testid="progress-${k}-bar"]`);
          return { text: dd.textContent.replace(/\s+/g, ''), oneLine: box(dd).height < parseFloat(getComputedStyle(dd).lineHeight) * 1.6, clipped: el.scrollWidth > el.clientWidth + 1,
            fill: bar ? Math.round((box(bar).width / box(bar.parentElement).width) * 100) : null, painted: bar ? [getComputedStyle(bar).backgroundColor, getComputedStyle(bar.parentElement).backgroundColor].every((c) => c !== 'rgba(0, 0, 0, 0)') : null, top: Math.round(box(el).top), left: Math.round(box(el).left), width: Math.round(box(el).width) };
        };
        const strip = box(document.querySelector('[data-testid="study-progress"]'));
        return { plan: read('plan'), week: read('week'), attendance: read('attendance'), stripLeft: strip.left, stripRight: strip.right, dialogRight: box(document.querySelector('[role="dialog"]')).right, stripTop: strip.top, gridTop: box(document.querySelector('[data-testid="week-plans"]')).top };
      });
      expect(strip.plan.text, 'plan progress = the API percent_done').toBe('33%');
      expect(strip.week.text, 'week tasks = done / total of the shown week').toBe('2/8項');
      expect(strip.attendance.text, 'attendance = this month only (3 of 4)').toBe('75%');
      expect([strip.plan.fill, strip.week.fill, strip.attendance.fill], 'bar fill equals the number').toEqual([33, 25, 75]);
      for (const k of ['plan', 'week', 'attendance']) expect(strip[k].painted, `${k} bar and track have a colour (the component must be in the ui.css @source list)`).toBe(true);
      for (const k of ['plan', 'week', 'attendance']) { expect(strip[k].oneLine, `${k} value wraps`).toBe(true); expect(strip[k].clipped, `${k} figure is clipped`).toBe(false); }
      expect(strip.stripRight, 'the strip is wider than the dialog').toBeLessThanOrEqual(strip.dialogRight + 1);
      expect(strip.stripTop, 'the strip sits above the plan lines').toBeLessThan(strip.gridTop);
      const sameRow = new Set([strip.plan.top, strip.week.top, strip.attendance.top]).size === 1;
      expect(sameRow, 'three figures in one row on desktop, plan on its own row on a phone').toBe(page.viewportSize().width >= 1024);
      if (page.viewportSize().width < 768) {
        expect(strip.week.top, 'phone: the two small figures share a row').toBe(strip.attendance.top);
        expect(strip.plan.width, 'phone: plan figure spans the strip').toBeGreaterThan(strip.week.width + strip.attendance.width - 2);
      }
      // Phone: one vertical list of seven days; desktop: seven columns side by side.
      const columns = () => page.evaluate(() => new Set([...document.querySelectorAll('[data-testid^="day-"]')].map((d) => Math.round(d.getBoundingClientRect().top))).size === 1);
      await expect.poll(columns, { message: 'seven columns on a wide dialog, one stacked list on a phone' }).toBe(page.viewportSize().width >= 1280);
      // The first task of every column starts at the same height, so the 今天 mark must not push one column down.
      if (page.viewportSize().width >= 1280) {
        const starts = await page.evaluate(() => ['task-1', 'task-2', 'task-4', 'task-5', 'task-6', 'task-8'].map((id) => Math.round(document.querySelector(`[data-testid="${id}"]`).getBoundingClientRect().top)));
        expect(new Set(starts).size, `first tasks start at ${starts.join(', ')}`).toBe(1);
      }
    },
  },
  {
    // issue 5269 step 3: the 套用到同班其他學生 picker (opened from a task's edit dialog). Names never wrap, the
    // checkbox rows share a left edge, and every checkbox row and button is at least 44px.
    key: 'student-study-week-apply',
    url: '/study-week-pilot-mount.html',
    names: CLASSMATES.map((c) => c.name),
    allNames: true,
    rows: ['[data-testid="apply-dialog"] li label'],
    touch: ['[data-testid="apply-dialog"] li label', '[data-testid="apply-dialog"] label:has([data-testid="apply-all"])', '[data-testid="apply-go"]'],
    mock: async (page) => { await page.clock.setFixedTime(TODAY); await mockStudyWeekApi(page, 'full'); },
    setup: async (page) => {
      await page.getByRole('tab', { name: '讀書計畫' }).click();
      await expect(page.getByTestId('task-8')).toBeVisible();
      await page.getByTestId('title-8').click();
      await page.getByTestId('f-apply').click();
      await expect(page.getByTestId('mate-21')).toBeVisible();
      await page.getByTestId('mate-22').check();
      const box = await page.getByTestId('apply-dialog').boundingBox();
      expect(box.x + box.width, 'the picker is wider than the screen').toBeLessThanOrEqual(page.viewportSize().width + 1);
      expect(box.x, 'the picker starts off screen').toBeGreaterThanOrEqual(-1);
    },
  },
  ...[false, true].map((search) => ({
    key: search ? 'tuition-collect (search: students without a bill)' : 'tuition-collect (bill list, mixed selectable rows)',
    url: '/pilot-mount.html?page=tuition',
    names: search ? ['林小明', '林佳', '林宥辰'] : NAMES.filter((n) => n !== '林小明'),
    rows: ['.bl__row, .bl__plainrow'],
    mock: async (page) => {
      const kinds = ['pending_report', 'unpaid', 'partial', 'paid'];
      const rows = NAMES.map((n, i) => { const k = kinds[i % 4]; return { id: 100 + i, student_id: 9100 + i, student_name: n, subject: SUBJECTS[i % 6], schedule_mode: i % 2 ? 'date' : 'count', payment_status: k, charge: 4200, paid_amount: k === 'paid' || k === 'pending_report' ? 4200 : 0, outstanding: k === 'unpaid' ? 4200 : 0, payable_amount: 4200, payable_outstanding: k === 'unpaid' ? 4200 : 0, due_date: '2026-09-12', days_until_settlement: 2 - i, course_start_date: '2026-09-01', course_end_date: '2026-09-30', invoice_id: 500 + i, payable_status: 'invoiced', remaining_sessions: i % 5, sessions_purchased: 16, latest_payment_report_id: k === 'pending_report' ? 600 + i : null }; });
      await page.route('**/api/v1/alerts/tuition**', (r) => json(r, rows));
      await page.route('**/api/v1/accounting/**', (r) => json(r, { data: [], summary: {} }));
      await page.route('**/api/v1/students**', (r) => json(r, { data: [...rows.map((x) => ({ id: x.student_id, name: x.student_name, enable: 1 })), { id: 1, name: '林小明', enable: 1 }, { id: 2, name: '林佳', enable: 1 }] }));
      await page.route('**/api/v1/branches**', (r) => json(r, [{ id: 1, name: '內湖分校' }]));
      await page.route('**/api/v1/class-sessions**', (r) => json(r, { data: [] }));
    },
    setup: async (page) => {
      await expect(page.locator('[data-testid^="bill-row-"]').first()).toBeVisible();
      if (search) {
        await page.getByTestId('bill-search').fill('林');
        await expect(page.getByTestId('bill-student-1')).toBeVisible();
      }
    },
  })),
  COURSE_MGMT,
  COURSE_MGMT_HELP_OPEN,
  COURSE_MGMT_HISTORY,
  COURSE_MGMT_BILLING,
  COURSE_MGMT_BILLING_DARK,
  {
    key: 'teacher-eligibility',
    url: '/teacher-eligibility-pilot-mount.html',
    names: ['王老師', '李大華老師', '陳小明'],
    rows: ['.desktop-table tbody tr td:first-child', '.teacher-card'],
    mock: async (page) => {
      const t = (id, name) => ({ teacher_id: id, teacher_name: name, overall_status: 'qualifies', review_required: false, settlement: { regular_subject_count: 4, tutoring_trial_subject_count: 2, payroll_subject_count: 6, one_to_three_count: 3, subject_count_bonus: 1200, one_to_three_bonus: 800, multiplier_pct: 105, weighted_bonus_amount: 2100, total_payout: 36000, payout_is_draft: false, adjustments: [{ label: '行政加給', amount: 500 }], multiplier_parts: [{ key: 'base', label: '基本', pct: 100 }] }, components: { weekly_16_segments: { status: 'qualifies', metrics: { regular_segments: 18, trial_segments: 2, total_segments: 20, meets_16_segments: true, course_sessions: [] } }, subject_count_bonus: { status: 'qualifies', amount: 1200 } } });
      await page.route('**/api/v1/finance/teacher-eligibility**', (r) => (r.request().url().includes('/inputs')
        ? json(r, { events: [], achievements: [], deductions: [], admin_allowances: [], cash_adjustments: [] })
        : json(r, { teachers: [t(701, '王老師'), t(702, '李大華老師'), t(703, '陳小明')], period: { start: '2026-09-01', end: '2026-09-30' }, policy_version: '115.07', branch_subject_total: 12, lock: { status: 'draft' } })));
    },
  },
  {
    key: 'binding-conflicts',
    url: '/pilot-mount.html?page=binding-conflicts&role=super_admin',
    names: NAMES.slice(0, 8),
    rows: ['.bcrp-table tbody tr', '.bcrp-mobile-card'],
    mock: async (page) => {
      const side = (id, c, m, d) => ({ id, campus_id: c, campus_name: c === 1 ? '內湖分校' : '大安分校', line_user_id_masked: m, bound_at: `2026-10-0${d}T12:00:00+08:00` });
      const data = { conflicts: NAMES.slice(0, 8).map((n, i) => ({ id: i + 7, student_id: i + 7, student_name: n, campus_count: 2, bindings: [side(i * 2 + 11, 1, 'U1111111…aaaa', 1), side(i * 2 + 12, 2, 'U2222222…bbbb', 2)] })) };
      await page.route('**/api/v1/**', (r) => json(r, new URL(r.request().url()).pathname === '/api/v1/bindings/conflicts' ? data : { data: [] }));
    },
  },
  {
    key: 'binding-health',
    url: '/pilot-mount.html?page=binding-health&role=director',
    names: NAMES.slice(0, 12),
    rows: [],
    mock: async (page) => {
      const data = { overall: { bound_count: 82, student_total: 100 }, weekly_summary: { bound: 7, unbound: 2, net: 5 }, by_campus: [{ campus_id: 1, campus_name: '內湖分校', rate: 0.86, bound_count: 43, student_total: 50 }, { campus_id: 2, campus_name: '大安分校', rate: 0.78, bound_count: 39, student_total: 50 }], trend: { day: [60, 64, 63, 70, 75, 74, 82].map((b, i) => ({ date: `2026-09-0${i + 1}`, bound: b })), week: [{ week: '2026-W35', bound: 70 }, { week: '2026-W36', bound: 82 }] }, unbound_active: NAMES.slice(0, 12).map((n, i) => ({ student_id: i, student_name: n, campus_id: 1, campus_name: '內湖分校' })), anomalies: [{ id: 101, type: '重複綁定', severity: 'warning', campus_id: 2, campus_name: '大安分校', student_name: NAMES[0], occurred_at: '' }] };
      await page.route('**/api/v1/**', (r) => json(r, new URL(r.request().url()).pathname === '/api/v1/bindings/metrics' ? data : { data: [] }));
    },
  },
];

/** Runs in the browser: wrapped names, left edges per row group, page overflow. */
function measure({ names, rows, touch = [] }) {
  const visible = (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none'; };
  const wanted = new Set(names);
  const found = new Set();
  const wrapped = [];
  for (const el of document.querySelectorAll('body *')) {
    if (el.children.length) continue;
    const text = el.textContent.trim();
    if (!wanted.has(text) || !visible(el)) continue;
    found.add(text);
    const range = document.createRange();
    range.selectNodeContents(el);
    const box = range.getBoundingClientRect();
    const cs = getComputedStyle(el);
    const lineHeight = parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.4;
    if (box.height > lineHeight * 1.6) wrapped.push({ text, height: Math.round(box.height), lineHeight: Math.round(lineHeight) });
  }
  const rowEdges = rows.map((selector) => ({ selector, lefts: [...document.querySelectorAll(selector)].filter(visible).map((el) => Math.round(el.getBoundingClientRect().left * 10) / 10) }));
  const small = touch.flatMap((selector) => [...document.querySelectorAll(selector)].filter(visible).map((el) => { const r = el.getBoundingClientRect(); return { selector, w: Math.round(r.width), h: Math.round(r.height) }; }).filter((t) => t.h < 44 || t.w < 44));
  const touchCount = touch.reduce((n, selector) => n + [...document.querySelectorAll(selector)].filter(visible).length, 0);
  return { found: [...found], wrapped, rowEdges, small, touchCount, scrollWidth: document.documentElement.scrollWidth, innerWidth: window.innerWidth };
}

for (const entry of PAGES) {
  for (const size of TEXT_SIZES) {
    for (const vp of entry.key.startsWith('course-mgmt') ? [...VIEWPORTS, ...TABLET_VIEWPORTS] : VIEWPORTS) {
      test(`layout guard: ${entry.key} @${vp.name} text-size ${size}`, async ({ page }) => {
        await page.setViewportSize({ width: vp.width, height: vp.height });
        await page.addInitScript((s) => {
          try { localStorage.setItem('alltrue_text_size', s); } catch { /* storage blocked */ }
          document.documentElement.setAttribute('data-text-size', s);
        }, size);
        await entry.mock(page);
        await page.goto(entry.url);
        await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1', { timeout: 15_000 });
        await expect(page.locator('html')).toHaveAttribute('data-text-size', size);
        if (entry.setup) await entry.setup(page);
        await page.getByText(entry.names[0], { exact: true }).first().waitFor({ state: 'attached' });
        await page.waitForLoadState('networkidle');
        const m = await page.evaluate(measure, { names: entry.names, rows: entry.rows, touch: entry.touch || [] });

        expect(m.found.length, 'the fixture names must be on screen, otherwise this guard checks nothing').toBeGreaterThan(0);
        if (entry.allNames) expect([...m.found].sort(), 'every fixture name must be on screen').toEqual([...entry.names].sort());
        expect(m.wrapped, '(a) a name wraps inside its cell').toEqual([]);
        if (entry.touch) {
          expect(m.touchCount, 'the touch-target selectors must match something, otherwise (d) checks nothing').toBeGreaterThan(0);
          expect(m.small, '(d) a touch target is smaller than 44px').toEqual([]);
        }
        expect(m.scrollWidth, '(b) the page body scrolls sideways').toBeLessThanOrEqual(m.innerWidth + 1);
        if (entry.extra) entry.extra.assert(await page.evaluate(entry.extra.run), vp);
        for (const group of m.rowEdges) {
          if (group.lefts.length < 2) continue;
          expect(Math.max(...group.lefts) - Math.min(...group.lefts), `(c) rows of ${group.selector} start at different x: ${group.lefts.join(', ')}`).toBeLessThanOrEqual(1);
        }
      });
    }
  }
}
