// @ts-check
import { test, expect } from '@playwright/test';

const bank = {
  id: 3001,
  name: '國一英文文法與閱讀理解長篇題庫名稱驗證手機版清晰度',
  description: '保留題目來源、版本與授權資訊，供老師建立內容並由主任審核。這段說明用來驗證長中文不會推出畫面。',
  items_count: 2,
  status: 'draft',
};

const items = [
  {
    id: 3101,
    question_type: 'single_choice',
    prompt: '請選出最適合放入句子中的動詞，並閱讀完整題幹以確認手機版內容仍能自然換行。',
    knowledge_tag: '英文／過去式／閱讀理解',
    difficulty: 3,
    version_no: 1,
    status: 'draft',
    source_name: 'AllTrue 內部教材',
  },
  {
    id: 3102,
    question_type: 'multiple_choice',
    prompt: '這是一題已核准的長內容題目，用來驗證狀態與歷史操作在不同螢幕寬度下仍然可辨識。',
    knowledge_tag: '英文／文法',
    difficulty: 4,
    version_no: 2,
    status: 'approved',
    source_name: 'AllTrue 內部教材',
  },
];

async function installQuestionBankMocks(page, mode = 'long') {
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (request.method() === 'GET' && url.pathname === '/api/v1/question-banks') {
      if (mode === 'loading') return new Promise(() => {});
      if (mode === 'error') {
        return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '題庫資料暫時無法載入' }) });
      }
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: mode === 'empty' ? [] : [bank] }) });
    }
    if (request.method() === 'GET' && url.pathname === '/api/v1/question-banks/3001/items') {
      if (mode === 'loading') return new Promise(() => {});
      if (mode === 'error') {
        return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '題目資料暫時無法載入' }) });
      }
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: items }) });
    }
    if (request.method() === 'GET' && url.pathname === '/api/v1/question-bank-items/3102/versions') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ ...items[1], prompt: '歷史版本題幹' }] }) });
    }
    if (request.method() === 'POST' && url.pathname === '/api/v1/question-banks/3001/items/import') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ count: 1 }) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], ok: true }) });
  });
}

test.describe('Question Bank real Vue page', () => {
  const viewports = [
    { name: '390', width: 390, height: 844 },
    { name: '412', width: 412, height: 915 },
    { name: '768', width: 768, height: 1024 },
    { name: '1280', width: 1280, height: 900 },
    { name: '1440', width: 1440, height: 900 },
  ];

  for (const viewport of viewports) {
    test(`keeps question workflow readable with long content @${viewport.name}`, async ({ page }) => {
      const consoleErrors = [];
      const failedRequests = [];
      page.on('console', (message) => {
        if (message.type() === 'error') consoleErrors.push(message.text());
      });
      page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
      await installQuestionBankMocks(page, 'long');
      await page.setViewportSize(viewport);
      await page.goto('/pilot-mount.html?page=question-bank&mode=long');

      await expect(page.getByText('題庫管理')).toBeVisible();
      await expect(page.getByRole('button', { name: '新增題庫' })).toBeVisible();
      await expect(page.getByRole('button', { name: '匯入 CSV' })).toBeVisible();
      await expect(page.getByRole('button', { name: '歷史' }).last()).toBeVisible();
      await expect(page.getByText(items[0].prompt)).toBeVisible();

      const controls = page.locator('.question-bank-page button:visible, .question-bank-page summary:visible');
      const dimensions = await controls.evaluateAll((elements) => elements.map((element) => ({
        label: element.textContent?.trim().slice(0, 30),
        height: element.getBoundingClientRect().height,
        visible: Boolean(element.offsetWidth || element.offsetHeight || element.getClientRects().length),
      })));
      expect(dimensions.every(({ visible }) => visible)).toBe(true);
      expect(dimensions.every(({ height }) => height >= 44)).toBe(true);

      const importButton = page.getByRole('button', { name: '匯入 CSV' });
      await importButton.focus();
      await expect(importButton).toBeFocused();

      await page.getByRole('button', { name: '新增題庫' }).click();
      await expect(page.getByRole('heading', { name: '新增題庫' })).toBeVisible();
      await page.getByRole('button', { name: '取消' }).click();
      await page.locator('.qb-editor-disclosure > summary').click();
      await expect(page.getByText('題目內容與必要標籤先完成即可儲存')).toBeVisible();
      await page.getByRole('button', { name: '歷史' }).last().click();
      await expect(page.getByRole('dialog')).toBeVisible();
      await expect(page.getByRole('dialog')).toContainText('歷史版本題幹');
      await page.getByRole('dialog').getByRole('button', { name: '關閉' }).click();

      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
      expect(consoleErrors).toEqual([]);
      expect(failedRequests).toEqual([]);
      if (viewport.name === '390' || viewport.name === '1440') {
        await page.screenshot({ path: `/tmp/alltrue-question-bank-after-20260912/question-bank-${viewport.name}.png`, fullPage: true });
      }
    });
  }

  test('makes question-bank empty and loading states explicit', async ({ page }) => {
    await installQuestionBankMocks(page, 'empty');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/pilot-mount.html?page=question-bank&mode=empty');
    await expect(page.getByRole('status', { name: '' }).filter({ hasText: '尚未建立題庫。' })).toBeVisible();
    await expect(page.getByText('請先從左側選擇題庫。')).toBeVisible();

    await page.unroute('**/api/v1/**');
    await installQuestionBankMocks(page, 'loading');
    await page.reload();
    await expect(page.getByRole('status')).toContainText('載入中…');
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
  });

  test('opens CSV import from the keyboard and preserves the existing upload request', async ({ page }) => {
    await installQuestionBankMocks(page, 'long');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/pilot-mount.html?page=question-bank&mode=long');

    const importButton = page.getByRole('button', { name: '匯入 CSV' });
    await importButton.focus();
    const chooserPromise = page.waitForEvent('filechooser');
    await importButton.press('Enter');
    const chooser = await chooserPromise;
    await chooser.setFiles({
      name: 'questions.csv',
      mimeType: 'text/csv',
      buffer: Buffer.from('question_type,prompt,knowledge_tag,difficulty\nsingle_choice,Example,English,3\n'),
    });

    await expect(page.getByRole('status')).toContainText('已匯入 1 題，全部進入待審核。');
  });

  test('surfaces question-bank load errors with retry', async ({ page }) => {
    await installQuestionBankMocks(page, 'error');
    await page.setViewportSize({ width: 412, height: 915 });
    await page.goto('/pilot-mount.html?page=question-bank&mode=error');
    await expect(page.getByRole('alert')).toContainText('題庫資料暫時無法載入');
    await expect(page.getByRole('button', { name: '重試' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
  });
});
