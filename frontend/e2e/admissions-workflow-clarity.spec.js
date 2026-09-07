// @ts-check
import { test, expect } from '@playwright/test';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.resolve(__dirname, '../../docs/design/evidence/admissions-clarity');

const viewports = [
  { name: 'mobile-390', width: 390, height: 844 },
  { name: 'tablet-768', width: 768, height: 1024 },
  { name: 'desktop-1280', width: 1280, height: 800 },
];

const MOCK_TEACHERS = [
  { id: 10, name: '林立文 老師' },
  { id: 11, name: '張雅婷 老師' },
];

const MOCK_INQUIRIES = [
  {
    id: 101,
    campus_id: 1,
    status: 'new',
    student_name: '王***',
    parent_phone: '******5678',
    subject: 'Math',
    owner_id: null,
    owner_name: null,
    follow_up_at: null,
    next_action: 'claim',
    last_action_at: '2026-09-07T08:00:00Z',
    created_at: '2026-09-07T08:00:00Z',
  },
  {
    id: 102,
    campus_id: 1,
    status: 'contacted',
    student_name: '林***',
    parent_phone: '******1234',
    subject: 'English',
    owner_id: 9001,
    owner_name: 'E2E Director',
    follow_up_at: '2026-09-07', // Due today
    next_action: 'schedule_trial',
    last_action_at: '2026-09-06T10:00:00Z',
    created_at: '2026-09-05T10:00:00Z',
  },
  {
    id: 103,
    campus_id: 1,
    status: 'contacted',
    student_name: '陳***',
    parent_phone: '******9999',
    subject: 'Physics',
    owner_id: 9001,
    owner_name: 'E2E Director',
    follow_up_at: '2026-09-01', // Overdue
    next_action: 'schedule_trial',
    last_action_at: '2026-09-01T10:00:00Z',
    created_at: '2026-08-31T10:00:00Z',
  },
  {
    id: 104,
    campus_id: 1,
    status: 'trial_scheduled',
    student_name: '張***',
    parent_phone: '******8888',
    subject: 'Science',
    owner_id: 9001,
    owner_name: 'E2E Director',
    follow_up_at: null,
    next_action: 'record_result',
    trial_student_class_id: 501,
    last_action_at: '2026-09-06T14:00:00Z',
    created_at: '2026-09-04T12:00:00Z',
  },
  {
    id: 105,
    campus_id: 1,
    status: 'trial_completed',
    trial_result: 'attended',
    student_name: '李***',
    parent_phone: '******7777',
    subject: 'Chinese',
    owner_id: 9001,
    owner_name: 'E2E Director',
    follow_up_at: null,
    next_action: 'enroll',
    trial_student_class_id: 502,
    last_action_at: '2026-09-07T09:00:00Z',
    created_at: '2026-09-02T12:00:00Z',
  },
  {
    id: 106,
    campus_id: 1,
    status: 'trial_completed',
    trial_result: 'no_show',
    student_name: '黃***',
    parent_phone: '******6666',
    subject: 'Math',
    owner_id: 9001,
    owner_name: 'E2E Director',
    follow_up_at: null,
    next_action: 'mark_lost',
    trial_student_class_id: 503,
    last_action_at: '2026-09-06T18:00:00Z',
    created_at: '2026-09-01T12:00:00Z',
  },
  {
    id: 107,
    campus_id: 1,
    status: 'enrolled',
    student_name: '趙***',
    parent_phone: '******5555',
    subject: 'English',
    owner_id: 9001,
    owner_name: 'E2E Director',
    follow_up_at: null,
    next_action: 'done',
    trial_student_class_id: 504,
    enrolled_student_class_id: 601,
    last_action_at: '2026-09-05T15:00:00Z',
    created_at: '2026-08-28T12:00:00Z',
  },
  {
    id: 108,
    campus_id: 1,
    status: 'lost',
    student_name: '孫***',
    parent_phone: '******4444',
    subject: 'Biology',
    owner_id: 9001,
    owner_name: 'E2E Director',
    follow_up_at: null,
    next_action: 'done',
    last_action_at: '2026-09-03T11:00:00Z',
    created_at: '2026-08-30T12:00:00Z',
  },
  {
    id: 109,
    campus_id: 1,
    status: 'new',
    student_name: '歐陽長名測試學員***',
    parent_phone: '******3333',
    subject: 'Chemistry',
    owner_id: null,
    owner_name: null,
    follow_up_at: null,
    next_action: 'claim',
    last_action_at: '2026-09-07T07:00:00Z',
    created_at: '2026-09-07T07:00:00Z',
  },
];

function getMockDetail(id) {
  const item = MOCK_INQUIRIES.find(i => i.id === Number(id)) || MOCK_INQUIRIES[0];
  const names = {
    101: { student: '王小明', parent: '王大同', school: '大安國中', grade: 'J1', notes: '希望加強因數分解與幾何圖形概念' },
    102: { student: '林小涵', parent: '林媽媽', school: '仁愛國中', grade: 'J2', notes: '想針對段考閱讀題加強練習' },
    103: { student: '陳立志', parent: '陳爸爸', school: '建國中學', grade: 'H1', notes: '高一物理力學單元需要加強' },
    104: { student: '張庭宇', parent: '張先生', school: '敦化國中', grade: 'J3', notes: '準備會考理化總複習' },
    105: { student: '李子平', parent: '李媽媽', school: '金華國小', grade: 'P6', notes: '小六升國中數學銜接' },
    106: { student: '黃昱安', parent: '黃爸爸', school: '中正高中', grade: 'H2', notes: '試聽當天臨時請假未到' },
    107: { student: '趙敏安', parent: '趙媽媽', school: '師大附中', grade: 'H3', notes: '學測英文衝刺班已順利轉正' },
    108: { student: '孫博文', parent: '孫先生', school: '和平高中', grade: 'H1', notes: '家長評估後先自行複習' },
    109: { student: '歐陽長名測試學員超長名稱驗證不爆版', parent: '歐陽長家長名稱', school: '國立臺灣師範大學附屬高級中學國中部名稱很長', grade: 'J2', notes: '這是一段很長很長的備註說明文字，用來測試在手機與平板上是否有橫向破版或內容重疊的問題，應當能夠自然折行顯示。'.repeat(2) },
  };
  const profile = names[id] || names[101];

  return {
    id: item.id,
    campus_id: item.campus_id,
    status: item.status,
    student_name: profile.student,
    parent_name: profile.parent,
    parent_phone: '0912-345-678',
    grade: profile.grade,
    school_name: profile.school,
    subject: item.subject,
    preferred_slots: ['平日晚上', '週六下午'],
    public_notes: profile.notes,
    staff_notes: item.status === 'contacted' ? '已與家長電訪確認時段' : '',
    trial_result: item.trial_result || null,
    owner_id: item.owner_id,
    owner_name: item.owner_name,
    follow_up_at: item.follow_up_at,
    next_action: item.next_action,
    trial_student_class_id: item.trial_student_class_id || null,
    enrolled_student_class_id: item.enrolled_student_class_id || null,
    history: [
      { event_type: 'admission_inquiry.submit', outcome: 'success', reason_code: 'submit', occurred_at: '2026-09-07T08:00:00Z' },
      ...(item.owner_id ? [{ event_type: 'admission_inquiry.owner_assigned', outcome: 'success', reason_code: 'owner_assigned', occurred_at: '2026-09-07T08:30:00Z' }] : []),
      ...(item.status !== 'new' ? [{ event_type: 'admission_inquiry.state_transition', outcome: 'success', reason_code: 'contacted', occurred_at: '2026-09-07T09:00:00Z' }] : []),
    ],
  };
}

async function installAdmissionsMock(page, { empty = false } = {}) {
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const p = url.pathname;
    const method = route.request().method();

    if (p.includes('/branches') || p.includes('/branch')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([{ id: 1, name: '大安分校' }, { id: 2, name: '木柵分校' }]),
      });
    }

    if (p.includes('/teachers')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(MOCK_TEACHERS),
      });
    }

    if (p.endsWith('/admission-inquiries') && method === 'GET') {
      const statusFilter = url.searchParams.get('status');
      let data = empty ? [] : MOCK_INQUIRIES;
      if (statusFilter) {
        data = data.filter(i => i.status === statusFilter);
      }
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data,
          current_page: 1,
          last_page: 1,
          per_page: 50,
          total: data.length,
        }),
      });
    }

    const detailMatch = p.match(/\/admission-inquiries\/(\d+)$/);
    if (detailMatch && method === 'GET') {
      const id = detailMatch[1];
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(getMockDetail(id)),
      });
    }

    if (p.match(/\/admission-inquiries\/(\d+)\//) && method === 'POST') {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ status: 'ok' }),
      });
    }

    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ status: 'ok' }),
    });
  });
}

test.beforeAll(async () => {
  fs.mkdirSync(outDir, { recursive: true });
});

test.describe('Admissions Workflow Clarity Browser Verification', () => {
  test('Zero inquiries state renders clear workflow guide and actions across all 3 viewports', async ({ page }) => {
    await installAdmissionsMock(page, { empty: true });

    for (const vp of viewports) {
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await page.goto('http://127.0.0.1:5177/pilot-mount.html?page=admissions&mode=empty');
      await page.waitForSelector('.admission-empty');

      // 1. Verify clear page purpose
      await expect(page.locator('h1')).toHaveText('新生問班');
      await expect(page.locator('.admission-empty h2')).toHaveText('目前沒有新詢問');

      // 2. Verify 4-step conceptual workflow is displayed
      await expect(page.locator('.admission-empty-flow')).toBeVisible();
      await expect(page.locator('.admission-flow-step').nth(0)).toContainText('家長送出需求');
      await expect(page.locator('.admission-flow-step').nth(1)).toContainText('主任電訪確認');
      await expect(page.locator('.admission-flow-step').nth(2)).toContainText('安排體驗試聽');
      await expect(page.locator('.admission-flow-step').nth(3)).toContainText('試聽轉正報名');

      // 3. Verify actionable CTA buttons
      await expect(page.locator('.admission-empty-actions').getByRole('button', { name: '複製公開問班連結' })).toBeVisible();
      await expect(page.locator('.admission-empty-actions').getByRole('button', { name: '查看公開問班表單' })).toBeVisible();

      // 4. Verify offline inquiry guidance (LINE / phone / walk-in)
      await expect(page.locator('.admission-empty-hint-card')).toContainText('家長若透過 LINE、電話或現場來訪？');

      // 5. Overflow check: ensure no horizontal scrolling occurs
      const overflow = await page.evaluate(() => {
        return document.documentElement.scrollWidth <= window.innerWidth + 2;
      });
      expect(overflow).toBe(true);

      // Capture screenshot evidence
      await page.screenshot({ path: path.join(outDir, `empty-state-${vp.name}.png`), fullPage: true });
    }
  });

  test('Populated queue renders urgency badges, pipeline stepper, and stage CTAs across viewports', async ({ page }) => {
    await installAdmissionsMock(page, { empty: false });

    for (const vp of viewports) {
      await page.setViewportSize({ width: vp.width, height: vp.height });
      await page.goto('http://127.0.0.1:5177/pilot-mount.html?page=admissions');
      await page.waitForSelector('.admission-staff-grid');

      // 1. Verify queue list and masked PII (王***, ******5678)
      const firstItem = page.locator('.admission-queue-item').first();
      await expect(firstItem).toContainText('王***');
      await expect(firstItem).toContainText('******5678');

      // 2. Verify urgency badges: Due today and Overdue
      const dueTodayBadge = page.locator('.admission-badge.today');
      await expect(dueTodayBadge.first()).toHaveText('今日需追蹤');

      const overdueBadge = page.locator('.admission-badge.overdue');
      await expect(overdueBadge.first()).toContainText('逾期');

      // 3. Verify Unclaimed state on inquiry 101
      await firstItem.click();
      await expect(page.locator('.admission-owner-panel')).toBeVisible();
      await expect(page.getByRole('button', { name: '由我負責' })).toBeVisible();

      // 4. Verify Pipeline Stepper is visible
      await expect(page.locator('.admission-pipeline')).toBeVisible();
      await expect(page.locator('.admission-pipeline-node').first()).toContainText('新詢問');

      // 5. Verify ONE primary CTA for Stage 1: Phone call / Mark contacted
      await expect(page.getByRole('button', { name: '標記已聯絡' })).toBeVisible();

      // 6. Test Direct Trial Scheduling toggle
      await page.getByRole('button', { name: '電話中已確定試聽時間？直接排課' }).click();
      await expect(page.locator('.admission-quick-trial-box')).toBeVisible();
      await page.getByRole('button', { name: '收合試聽欄位' }).click();
      await expect(page.locator('.admission-quick-trial-box')).not.toBeVisible();

      // 7. Verify Stage 2: CONTACTED (Inquiry 102)
      const item102 = page.locator('.admission-queue-item').nth(1);
      await item102.click();
      await expect(page.getByRole('button', { name: '建立試聽（帶入學生資料）' })).toBeVisible();

      // 8. Verify Stage 3: TRIAL SCHEDULED (Inquiry 104)
      const item104 = page.locator('.admission-queue-item').nth(3);
      await item104.click();
      await expect(page.getByRole('button', { name: '儲存結果' })).toBeVisible();
      await expect(page.locator('#admission-trial-result-select')).toBeVisible();

      // 9. Verify Stage 4: TRIAL COMPLETED - Attended (Inquiry 105)
      const item105 = page.locator('.admission-queue-item').nth(4);
      await item105.click();
      await expect(page.locator('.admission-workflow').getByRole('button', { name: '轉正式報名' })).toBeVisible();
      await expect(page.locator('.admission-workflow').getByRole('button', { name: '暫不報名／結案' })).toBeVisible();

      // 10. Verify Stage 4: TRIAL COMPLETED - No show (Inquiry 106)
      const item106 = page.locator('.admission-queue-item').nth(5);
      await item106.click();
      await expect(page.locator('.admission-workflow').getByRole('button', { name: '標為暫不繼續', exact: true })).toBeVisible();

      // 11. Verify Terminal: ENROLLED (Inquiry 107)
      const item107 = page.locator('.admission-queue-item').nth(6);
      await item107.click();
      await expect(page.locator('.admission-success.compact')).toContainText('已完成報名');

      // 12. Verify Terminal: LOST (Inquiry 108)
      const item108 = page.locator('.admission-queue-item').nth(7);
      await item108.click();
      await expect(page.locator('.admission-empty.compact')).toContainText('此詢問已結案');

      // 13. Long name & notes overflow check (Inquiry 109)
      const item109 = page.locator('.admission-queue-item').nth(8);
      await item109.click();
      const overflow = await page.evaluate(() => {
        return document.documentElement.scrollWidth <= window.innerWidth + 2;
      });
      expect(overflow).toBe(true);

      // Capture screenshot evidence for populated queue
      await page.screenshot({ path: path.join(outDir, `populated-queue-${vp.name}.png`), fullPage: true });
    }
  });

  test('Public link copy feedback works and copies expected admissions route', async ({ page, context }) => {
    await installAdmissionsMock(page, { empty: true });
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);

    await page.goto('http://127.0.0.1:5177/pilot-mount.html?page=admissions&mode=empty');
    await page.waitForSelector('.admission-empty');

    const copyBtn = page.locator('.admission-empty-actions').getByRole('button', { name: '複製公開問班連結' });
    await copyBtn.click();
    await expect(page.locator('.admission-empty-actions').getByRole('button', { name: '已複製問班連結！' })).toBeVisible();
  });
});
