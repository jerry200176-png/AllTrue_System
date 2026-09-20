// @ts-check
import { expect, test } from '@playwright/test';
import path from 'node:path';

const artifactDir = process.env.TRUEFIT_FIXTURE_ARTIFACT_DIR
  || path.resolve('../docs/truefit/evidence/fixture-print-acceptance');
const productionBoundary = process.env.TRUEFIT_PRODUCTION_BOUNDARY === '1';

async function openFixture(page) {
  await page.goto('/pilot-mount.html?page=truefit-fixture');
  await expect(page.getByRole('heading', { name: '紙本證據驗證台' })).toBeVisible();
  await expect(page.getByText('12 筆 page object；不是實體 OCR 影像測試集。')).toBeVisible();
  await expect(page.locator('tbody tr')).toHaveCount(2);
}

test('teacher operates review, return, explicit approval, invalidation, and A4 outputs', async ({ page }) => {
  test.skip(productionBoundary, 'development fixture operation test');
  await openFixture(page);
  await page.getByRole('button', { name: '確認學生作答' }).click();
  await expect(page.getByText('input r14')).toBeVisible();
  await page.getByRole('button', { name: '產生講義草稿' }).click();
  await expect(page.getByTestId('pack-draft')).toBeVisible();
  await expect(page.getByLabel('列印預覽')).toHaveCount(0);

  const explanation = page.getByRole('textbox', { name: /解說/ }).first();
  await explanation.fill('老師修訂：先確認除數，再逐步計算。');
  await explanation.blur();
  await expect(page.getByText('草稿修訂 2')).toBeVisible();
  await page.getByRole('button', { name: '退回草稿' }).click();
  await expect(page.getByTestId('pack-draft')).toHaveCount(0);
  await page.getByRole('button', { name: '產生講義草稿' }).click();
  await page.getByRole('button', { name: '明確核准' }).click();

  const preview = page.getByLabel('列印預覽');
  await expect(preview).toBeVisible();
  await expect(preview).toContainText('學生練習版 · pack-r14.1');
  await expect(preview).not.toContainText('答案：');
  await page.screenshot({ path: path.join(artifactDir, 'truefit-operation-approved.png'), fullPage: true });
  await page.emulateMedia({ media: 'print' });
  await expect(preview).not.toContainText('答案：');
  const studentBoxesFit = await preview.locator('.print-item').evaluateAll((items) => items.every((item) => item.scrollWidth <= item.clientWidth && item.scrollHeight <= item.clientHeight));
  expect(studentBoxesFit).toBe(true);
  await page.pdf({ path: path.join(artifactDir, 'truefit-student-pack-r14.1.pdf'), format: 'A4', preferCSSPageSize: true, printBackground: true });
  await page.emulateMedia({ media: 'screen' });

  await page.getByRole('button', { name: '教師版預覽' }).click();
  await expect(preview).toContainText('教師解答版 · pack-r14.1');
  await expect(preview).toContainText('答案：8');
  await expect(preview).toContainText('解說：');
  await page.screenshot({ path: path.join(artifactDir, 'truefit-teacher-preview.png'), fullPage: true });
  await page.emulateMedia({ media: 'print' });
  const teacherBoxesFit = await preview.locator('.print-item').evaluateAll((items) => items.every((item) => item.scrollWidth <= item.clientWidth && item.scrollHeight <= item.clientHeight));
  expect(teacherBoxesFit).toBe(true);
  await page.pdf({ path: path.join(artifactDir, 'truefit-teacher-pack-r14.1.pdf'), format: 'A4', preferCSSPageSize: true, printBackground: true });
  await page.emulateMedia({ media: 'screen' });

  await page.getByRole('button', { name: '同 revision 重新排版' }).click();
  await expect(page.getByText(/pack-r14\.1 · a4-compact · 教師版/)).toBeVisible();
  await expect(preview).toContainText('pack-r14.1');

  const firstAnswer = page.getByRole('textbox', { name: 'syn-p1答案' });
  await firstAnswer.fill('9');
  await firstAnswer.blur();
  await expect(preview).toHaveCount(0);
  await page.getByRole('button', { name: '確認學生作答' }).click();
  await page.getByRole('button', { name: '產生講義草稿' }).click();
  await page.getByRole('button', { name: '明確核准' }).click();
  await expect(page.getByLabel('列印預覽')).toBeVisible();
  await page.getByRole('button', { name: '替換頁面' }).first().click();
  await expect(page.getByLabel('列印預覽')).toHaveCount(0);
});

test('OCR failure keeps manual fallback and expiry preserves confirmed output', async ({ page }) => {
  test.skip(productionBoundary, 'development fixture operation test');
  await openFixture(page);
  await page.getByRole('button', { name: '模擬 OCR 失敗' }).click();
  await expect(page.getByText(/OCR_FAILED/)).toBeVisible();
  await page.getByRole('button', { name: '確認學生作答' }).click();
  await page.getByRole('button', { name: '產生講義草稿' }).click();
  await page.getByRole('button', { name: '明確核准' }).click();
  const revision = await page.getByLabel('列印預覽').getAttribute('data-print-variant');
  expect(revision).toBe('student');
  await page.getByRole('button', { name: '模擬 30 天到期' }).click();
  await expect(page.getByText(/原始檔已到期/)).toBeVisible();
  await expect(page.getByLabel('列印預覽')).toContainText('pack-r14.1');
});

test('integrated TrueFit shell is excluded from fixture printing', async ({ page }) => {
  test.skip(productionBoundary, 'development fixture operation test');
  await page.goto('/pilot-mount.html?page=truefit-app#/truefit/paper-fixture');
  await expect(page.getByText('紙本證據驗證台')).toBeVisible();
  await page.getByRole('button', { name: '確認學生作答' }).click();
  await page.getByRole('button', { name: '產生講義草稿' }).click();
  await page.getByRole('button', { name: '明確核准' }).click();
  await page.emulateMedia({ media: 'print' });
  await expect(page.locator('.truefit-shell__header')).toBeHidden();
  await expect(page.getByLabel('學生作答確認')).toBeHidden();
  await expect(page.getByLabel('列印預覽')).toBeVisible();
});

test('production build does not display the fixture entry', async ({ page }) => {
  test.skip(!productionBoundary, 'production boundary test');
  await page.goto('/pilot-mount.html?page=truefit-app#/truefit/paper-fixture');
  await expect(page.getByRole('heading', { name: 'TrueFit' })).toBeVisible();
  await expect(page.getByText('紙本證據驗證台')).toHaveCount(0);
  await expect(page.getByText('合成資料驗證')).toHaveCount(0);
});
