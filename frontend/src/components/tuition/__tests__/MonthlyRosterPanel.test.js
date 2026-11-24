import { mount, flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import MonthlyRosterPanel from '../MonthlyRosterPanel.vue';

const mockFetch = vi.fn();
vi.mock('../../../lib/authedFetch', () => ({
  getAccessToken: async () => 'tok',
  authedFetch: (...a) => mockFetch(...a),
}));

const course = (id, over = {}) => ({
  course_id: id, subject: '數學', teacher_name: '張翔', class_type: 'one_on_one', schedule_mode: 'count', rate: 1500, stopped: false,
  lessons: [{ date: '2026-11-03', start: '10:00', kind: 'held', status: 'attended' }, { date: '2026-11-24', start: '10:00', kind: 'upcoming', status: 'scheduled' }],
  held: 1, money: { status: 'paid', outstanding: 0, current_period: null, month_total: null, month_invoiced: false },
  paid_this_month: [], pending_reports: 0, ...over,
});
const roster = {
  data: [
    { student_id: 1, student_name: '王小明', school: '崇光', outstanding: 4500, courses: [
      course(11, { money: { status: 'partial', outstanding: 4500, current_period: null, month_total: null, month_invoiced: false },
        paid_this_month: [{ date: '2026-11-05', amount: 1500, method: 'cash' }] }),
      course(12, { subject: '英文', pending_reports: 1 }),
    ] },
    { student_id: 2, student_name: '李小華', school: '五峰', outstanding: 0, courses: [
      course(21, { schedule_mode: 'date', money: { status: 'paid', outstanding: 0, current_period: '2026-11', month_total: 6000, month_invoiced: false } }),
    ] },
  ],
  summary: { period: '2026-11', students: 2, courses: 3, outstanding: 4500, paid_this_month: 1500, pending_reports: 1 },
};
const res = (body, ok = true, status = 200) => ({ ok, status, json: async () => body });
let wrapper;
const mountIt = (branchId = 9) => mount(MonthlyRosterPanel, { props: { branchId } });
const urls = () => mockFetch.mock.calls.map(([u]) => u);

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date(2026, 10, 17, 9));
  mockFetch.mockReset();
  mockFetch.mockImplementation(async () => res(roster));
});
afterEach(() => { wrapper?.unmount(); vi.useRealTimers(); });

it('asks for a campus first and loads nothing without one', async () => {
  wrapper = mountIt(null);
  await flushPromises();
  expect(wrapper.text()).toContain('請先選分校');
  expect(mockFetch).not.toHaveBeenCalled();
});

it('shows each student once with every course of the month, its lessons and what it owes', async () => {
  wrapper = mountIt();
  await flushPromises();
  expect(urls()[0]).toBe('/api/v1/accounting/monthly-roster?year=2026&month=11&branch_id=9');
  expect(wrapper.find('[data-testid="roster-month"]').text()).toBe('2026 年 11 月');
  const first = wrapper.find('[data-testid="roster-student-1"]');
  expect(first.findAll('tr')).toHaveLength(2);
  expect(first.text().match(/王小明/g)).toHaveLength(1);
  const math = wrapper.find('[data-testid="roster-course-11"]');
  expect(math.findAll('li').map((l) => l.text())).toEqual(['11/3 已上', '11/24 還沒上']);
  expect(math.text()).toContain('未繳 NT$4,500');
  expect(math.text()).toContain('11/5 現金 NT$1,500');
  expect(wrapper.find('[data-testid="roster-course-12"]').text()).toContain('待確認 1 筆');
  expect(wrapper.find('[data-testid="roster-course-21"]').text()).toContain('本月預估 NT$6,000');
  expect(wrapper.find('[data-testid="roster-course-21"]').text()).toContain('已收齊');
});

it('opens the student ledger on the course, straight at record or confirm', async () => {
  wrapper = mountIt();
  await flushPromises();
  await wrapper.find('[data-testid="roster-record-11"]').trigger('click');
  await wrapper.find('[data-testid="roster-confirm-12"]').trigger('click');
  await wrapper.find('[data-testid="roster-student-2"] button').trigger('click');
  expect(wrapper.emitted('open')).toEqual([
    [{ student_id: 1, first_class_id: 11 }, { kind: 'record', classId: 11 }],
    [{ student_id: 1, first_class_id: 12 }, { kind: 'confirm', classId: 12 }],
    [{ student_id: 2, first_class_id: 21 }, { classId: 21 }],
  ]);
});

it('filters to who owes or has a receipt to confirm, and finds a student by name or course id', async () => {
  wrapper = mountIt();
  await flushPromises();
  expect(wrapper.find('[data-testid="roster-filter-owing"]').text()).toContain('1');
  await wrapper.find('[data-testid="roster-filter-owing"]').trigger('click');
  expect(wrapper.find('[data-testid="roster-student-2"]').exists()).toBe(false);
  await wrapper.find('[data-testid="roster-filter-all"]').trigger('click');
  await wrapper.find('input[type="search"]').setValue('21');
  expect(wrapper.find('[data-testid="roster-student-1"]').exists()).toBe(false);
  expect(wrapper.find('[data-testid="roster-student-2"]').exists()).toBe(true);
});

it('moves across the year boundary and drops a slow reply for the month it left', async () => {
  let release;
  mockFetch.mockImplementationOnce(() => new Promise((r) => { release = () => r(res(roster)); }));
  mockFetch.mockImplementation(async () => res({ data: [], summary: { students: 0, outstanding: 0, paid_this_month: 0, pending_reports: 0 } }));
  wrapper = mountIt();
  await wrapper.find('button[aria-label="下一月"]').trigger('click');
  await wrapper.find('button[aria-label="下一月"]').trigger('click');
  await flushPromises();
  release();
  await flushPromises();
  expect(urls().at(-1)).toBe('/api/v1/accounting/monthly-roster?year=2027&month=1&branch_id=9');
  expect(wrapper.text()).toContain('這個月沒有課，也沒有要收的錢');
});

it('exports the rows on screen in the old sheet order, with formulas neutralised', async () => {
  const blobs = [];
  vi.stubGlobal('Blob', class { constructor(parts) { this.parts = parts; blobs.push(this); } });
  const urlFns = { create: URL.createObjectURL, revoke: URL.revokeObjectURL };
  URL.createObjectURL = () => 'blob:x';
  URL.revokeObjectURL = () => {};
  const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
  const evil = { ...roster, data: [{ ...roster.data[0], student_name: '=HYPERLINK("x")' }] };
  mockFetch.mockImplementation(async () => res(evil));
  wrapper = mountIt();
  await flushPromises();

  await wrapper.get('[data-testid="roster-export"]').trigger('click');

  expect(click).toHaveBeenCalledTimes(1);
  const lines = blobs[0].parts.join('').replace(/^\ufeff/, '').split('\n');
  expect(lines[0]).toBe('"科目","老師","學校","學生","繳費日期","付費方式","單價","已上堂數","本月預估","這個月收到","未繳","收款狀態","這個月的課","月學收"');
  expect(lines[1]).toBe(`"數學","張翔","崇光","'=HYPERLINK(""x"")","2026-11-05","現金","NT$1,500",1,"",1500,4500,"未繳 NT$4,500","11/3、11/24（還沒上）",""`);
  expect(lines).toHaveLength(3);
  click.mockRestore();
  vi.unstubAllGlobals();
  Object.assign(URL, { createObjectURL: urlFns.create, revokeObjectURL: urlFns.revoke });
});

it('shows 學收合計 and each course\'s 學收, says which have no price, and exports the column', async () => {
  const blobs = [];
  vi.stubGlobal('Blob', class { constructor(parts) { this.parts = parts; blobs.push(this); } });
  const urlFns = { create: URL.createObjectURL, revoke: URL.revokeObjectURL };
  URL.createObjectURL = () => 'blob:x';
  URL.revokeObjectURL = () => {};
  const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
  const priced = { data: [{ student_id: 4, student_name: '林小美', school: '光華', outstanding: 0, courses: [
    course(41, { tuition: 1500, tuition_lessons: 1 }),
    course(42, { subject: '英文', rate: null, tuition: null, tuition_lessons: 1 }),
  ] }], summary: { students: 1, outstanding: 0, paid_this_month: 0, pending_reports: 0, tuition_total: 1500, tuition_unpriced: 1 } };
  mockFetch.mockResolvedValue(res(priced));
  wrapper = mountIt();
  await flushPromises();
  const stat = wrapper.findAll('[data-slot="stat-item"]').find((n) => n.text().includes('學收合計'));
  expect(stat.text()).toContain('NT$1,500');
  expect(stat.text()).toContain('1 門課沒有單價，沒算進來');
  expect(wrapper.get('[data-testid="roster-course-41"]').text()).toContain('已上 1 堂・學收 1 堂 NT$1,500');
  expect(wrapper.get('[data-testid="roster-course-42"]').text()).toContain('學收 —');

  await wrapper.get('[data-testid="roster-export"]').trigger('click');
  const lines = blobs[0].parts.join('').replace(/^\ufeff/, '').split('\n');
  // 月學收 is the last column, after the old sheet's
  expect(lines[0].endsWith('"這個月的課","月學收"')).toBe(true);
  expect(lines[1].endsWith(',1500')).toBe(true);
  expect(lines[2].endsWith(',""')).toBe(true);
  click.mockRestore();
  vi.unstubAllGlobals();
  Object.assign(URL, { createObjectURL: urlFns.create, revokeObjectURL: urlFns.revoke });
});

it('shows and exports billed balances by month separately from unbilled contracts', async () => {
  const blobs = [];
  vi.stubGlobal('Blob', class { constructor(parts) { blobs.push(parts.join('')); } });
  const urlFns = { create: URL.createObjectURL, revoke: URL.revokeObjectURL };
  URL.createObjectURL = () => 'blob:x';
  URL.revokeObjectURL = () => {};
  const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
  const scoped = structuredClone(roster);
  const balances = { selected_billed: 0, other_billed: 3000, undated_billed: 500, unbilled: 1000 };
  scoped.summary.outstanding_by_scope = balances;
  scoped.data[0].courses[0].money.outstanding_by_scope = balances;
  mockFetch.mockImplementation(async () => res(scoped));
  try {
    wrapper = mountIt();
    await flushPromises();
    expect(wrapper.get('[data-slot="stat-strip"]').text()).toMatch(/本月帳單未收\s*NT\$0/);
    expect(wrapper.text()).toContain('其他月份帳單未收 NT$3,000・月份待核對的帳單未收 NT$500・未開單合約餘額 NT$1,000');
    const row = wrapper.get('[data-testid="roster-course-11"]');
    expect(row.text()).toContain('各期尚有餘額');
    expect(row.get('[data-testid="roster-record-11"]').text()).toBe('登記收款');
    await wrapper.get('[data-testid="roster-export"]').trigger('click');
    const [headers, firstRow] = blobs[0].split('\n');
    expect(headers).toContain('各期帳款餘額（含未開單）');
    expect(headers).toMatch(/"本月帳單未收","其他月份帳單未收","月份待核對的帳單未收","未開單合約餘額"$/);
    expect(firstRow).toMatch(/,0,3000,500,1000$/);
  } finally {
    click.mockRestore();
    vi.unstubAllGlobals();
    Object.assign(URL, { createObjectURL: urlFns.create, revokeObjectURL: urlFns.revoke });
  }
});

it('a balance under review still says 要核對, and the phone line shows the price', async () => {
  const review = { data: [{ student_id: 3, student_name: '陳小安', school: '光復', outstanding: 3000, courses: [
    course(31, { rate: 800, rate_unit: 'hour', money: { status: 'review_required', outstanding: 3000, current_period: null, month_total: null, month_invoiced: false } }),
  ] }], summary: { students: 1, outstanding: 3000, paid_this_month: 0, pending_reports: 0 } };
  mockFetch.mockResolvedValue(res(review));
  wrapper = mountIt();
  await flushPromises();
  const row = wrapper.find('[data-testid="roster-course-31"]');
  expect(row.text()).toContain('要核對（未繳 NT$3,000）');
  expect(row.text()).toContain('11/3、11/24（還沒上）・已上 1 堂・單價 NT$800／小時'); // phone line keeps each date's state
});

it('switching month clears the old rows and disables export until the new month loads', async () => {
  let release;
  mockFetch.mockResolvedValueOnce(res(roster));
  mockFetch.mockImplementationOnce(() => new Promise((r) => { release = () => r(res(roster)); }));
  wrapper = mountIt();
  await flushPromises();
  expect(wrapper.find('[data-testid="roster-student-1"]').exists()).toBe(true);
  await wrapper.find('button[aria-label="下一月"]').trigger('click');
  await flushPromises();
  expect(wrapper.find('[data-testid="roster-student-1"]').exists()).toBe(false);
  expect(wrapper.find('[data-testid="roster-export"]').attributes('disabled')).toBeDefined();
  release();
  await flushPromises();
  expect(wrapper.find('[data-testid="roster-student-1"]').exists()).toBe(true);
});

it('a paid mark with no bill says 已收（舊資料）, and the drawer can step to the next student on screen', async () => {
  const legacy = JSON.parse(JSON.stringify(roster));
  legacy.data[1].courses[0].money = { status: 'paid', outstanding: 0, current_period: null, month_total: null, month_invoiced: false, legacy_unverified: true };
  mockFetch.mockResolvedValue(res(legacy));
  wrapper = mountIt();
  await flushPromises();
  expect(wrapper.find('[data-testid="roster-course-21"]').text()).toContain('已收（舊資料）');
  expect(wrapper.vm.neighbor(1, 1)).toEqual({ student_id: 2, first_class_id: 21 });
  expect(wrapper.vm.neighbor(2, 1)).toBeNull();
  expect(wrapper.find('[data-testid="roster-name-1"]').attributes('data-student')).toBe('1');
});

it('a waived contract says 已確認不收 and offers no collection', async () => {
  const waived = JSON.parse(JSON.stringify(roster));
  waived.data[1].courses[0].money = { status: 'waived', outstanding: 0, current_period: null, month_total: null, month_invoiced: false };
  mockFetch.mockResolvedValue(res(waived));
  wrapper = mountIt();
  await flushPromises();
  expect(wrapper.find('[data-testid="roster-course-21"]').text()).toContain('已確認不收');
  expect(wrapper.find('[data-testid="roster-record-21"]').exists()).toBe(false);
});

it('a free course (tutoring, zero fee) says 不用收費, not 已收齊', async () => {
  const free = JSON.parse(JSON.stringify(roster));
  free.data[1].courses[0].money = { status: 'free', outstanding: 0, current_period: null, month_total: null, month_invoiced: false };
  mockFetch.mockResolvedValue(res(free));
  wrapper = mountIt();
  await flushPromises();
  expect(wrapper.find('[data-testid="roster-course-21"]').text()).toContain('不用收費');
});
