// @ts-check
import { test, expect } from '@playwright/test';

async function installDirectorAccountsMocks(page, mode = 'long') {
  await page.route('**/api/v1/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (request.method() === 'GET' && (url.pathname === '/api/v1/directors/pending' || url.pathname === '/api/v1/directors')) {
      if (mode === 'loading') return new Promise(() => {});
      if (mode === 'error') {
        return route.fulfill({
          status: 500,
          contentType: 'application/json',
          body: JSON.stringify({ message: '主任帳號資料暫時無法載入' }),
        });
      }
      const active = url.pathname === '/api/v1/directors';
      if (mode === 'empty') {
        return route.fulfill({ status: 200, contentType: 'application/json', body: '[]' });
      }
      const data = active
        ? [{
            id: 9101,
            name: '林主任超長姓名用來驗證帳號管理在手機上仍然可讀且操作不會被推出視窗',
            account: 'director-with-a-long-login@example.test',
            campus_names: ['台北總校', '台中分校'],
            campus_ids: [1, 2],
          }]
        : [{
            id: 9201,
            name: '待審主任甲',
            email: 'pending-director@example.test',
            campus_name: '台北總校',
          }];
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(data) });
    }
    if (request.method() === 'GET' && url.pathname === '/api/v1/campuses') {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([{ id: 1, name: '台北總校' }, { id: 2, name: '台中分校' }]),
      });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
  });
}

test.describe('Director Accounts real Vue page', () => {
  const viewports = [
    { name: '390', width: 390, height: 844 },
    { name: '412', width: 412, height: 915 },
    { name: '768', width: 768, height: 1024 },
    { name: '1280', width: 1280, height: 900 },
    { name: '1440', width: 1440, height: 900 },
  ];

  for (const viewport of viewports) {
    test(`keeps account actions reachable with long content @${viewport.name}`, async ({ page }) => {
      const consoleErrors = [];
      const failedRequests = [];
      page.on('console', (message) => {
        if (message.type() === 'error') consoleErrors.push(message.text());
      });
      page.on('requestfailed', (request) => failedRequests.push(`${request.method()} ${request.url()}`));
      await installDirectorAccountsMocks(page, 'long');
      await page.setViewportSize(viewport);
      await page.goto('/director-accounts-pilot-mount.html?mode=long');

      await expect(page.getByText('主任管理')).toBeVisible();
      await expect(page.getByRole('button', { name: '編輯分校' })).toBeVisible();
      await expect(page.getByRole('button', { name: '通過' })).toBeVisible();
      if (viewport.name === '390') {
        await page.screenshot({ path: '/tmp/pr2646-before-synthetic/vue-director-accounts-390.png', fullPage: true });
      }
      const buttons = page.locator('.director-accounts-page button');
      const dimensions = await buttons.evaluateAll((elements) => elements.map((element) => ({
        height: element.getBoundingClientRect().height,
        visible: Boolean(element.offsetWidth || element.offsetHeight || element.getClientRects().length),
      })));
      expect(dimensions.every(({ visible }) => visible)).toBe(true);
      expect(dimensions.every(({ height }) => height >= 44)).toBe(true);

      await page.getByRole('button', { name: '編輯分校' }).click();
      const dialog = page.getByRole('dialog');
      await expect(dialog).toBeVisible();
      await expect(dialog).toHaveAttribute('aria-modal', 'true');
      await expect(dialog).toHaveAttribute('aria-labelledby', 'campus-modal-title');
      await expect(dialog).toBeFocused();
      const dialogRect = await dialog.evaluate((element) => {
        const rect = element.getBoundingClientRect();
        return { left: rect.left, top: rect.top, right: rect.right, bottom: rect.bottom };
      });
      expect(dialogRect.left).toBeGreaterThanOrEqual(0);
      expect(dialogRect.top).toBeGreaterThanOrEqual(0);
      expect(dialogRect.right).toBeLessThanOrEqual(viewport.width);
      expect(dialogRect.bottom).toBeLessThanOrEqual(viewport.height);
      await expect(page.getByRole('button', { name: '取消' })).toBeVisible();
      await page.keyboard.press('Escape');
      await expect(dialog).toBeHidden();
      await expect(page.getByRole('button', { name: '編輯分校' })).toBeFocused();

      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
      expect(consoleErrors).toEqual([]);
      expect(failedRequests).toEqual([]);
      if (viewport.name === '390' || viewport.name === '1440') {
        await page.screenshot({ path: `/tmp/pr2646-render-synthetic/vue-director-accounts-long-${viewport.name}.png`, fullPage: true });
      }
    });
  }

  test('makes empty and loading states explicit', async ({ page }) => {
    await installDirectorAccountsMocks(page, 'empty');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/director-accounts-pilot-mount.html?mode=empty');
    await expect(page.getByText('目前沒有已審核的主任')).toBeVisible();
    await expect(page.getByText('目前沒有待審申請')).toBeVisible();

    await page.unroute('**/api/v1/**');
    await installDirectorAccountsMocks(page, 'loading');
    await page.reload();
    await expect(page.getByRole('status').first()).toContainText('載入中...');
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
  });

  test('surfaces account loading errors with a retry action', async ({ page }) => {
    await installDirectorAccountsMocks(page, 'error');
    await page.setViewportSize({ width: 412, height: 915 });
    await page.goto('/director-accounts-pilot-mount.html?mode=error');
    await expect(page.getByRole('alert').first()).toContainText('主任帳號資料暫時無法載入');
    await expect(page.getByRole('button', { name: '重新載入' }).first()).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(await page.evaluate(() => document.documentElement.clientWidth));
  });
});

// GitHub #2646: preserve target and single existing PUT while a synthetic response is held.
for (const width of [390,1440]) {
  test(`pending campus save retains original target and blocks dismissal at ${width}`,async ({page})=>{
    let release;const held=new Promise(r=>{release=r;});const writes=[];
    await page.route('**/api/v1/**',async route=>{
      const r=route.request();const path=new URL(r.url()).pathname;
      if(r.method()==='PUT'){writes.push({method:r.method(),path,body:r.postDataJSON()});await held;return route.fulfill({status:200,contentType:'application/json',body:'{}'});}
      const data=path==='/api/v1/directors'?[{id:101,name:'主任甲',account:'a@example.test',campus_ids:[1],campus_names:['合成校一']},{id:102,name:'主任乙',account:'b@example.test',campus_ids:[2],campus_names:['合成校二']}]:path==='/api/v1/campuses'?[{id:1,name:'合成校一'},{id:2,name:'合成校二'}]:[];
      return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(data)});
    });
    await page.setViewportSize({width,height:900});await page.goto('/director-accounts-pilot-mount.html');
    await page.getByRole('button',{name:'編輯分校'}).first().click();const dialog=page.getByRole('dialog');
    await dialog.getByRole('button',{name:'儲存'}).click();await expect.poll(()=>writes.length).toBe(1);
    await page.keyboard.press('Escape');await expect(dialog).toBeVisible();
    await dialog.getByRole('button',{name:'取消'}).dispatchEvent('click');await expect(dialog).toBeVisible();
    await page.locator('.at-dialog-overlay').dispatchEvent('click');await expect(dialog).toBeVisible();
    await page.getByRole('button',{name:'編輯分校'}).nth(1).dispatchEvent('click');await expect(dialog).toContainText('主任甲');
    await dialog.getByRole('button',{name:'儲存'}).dispatchEvent('click');expect(writes).toHaveLength(1);
    for(let i=0;i<10;i++){await page.keyboard.press('Tab');expect(await dialog.evaluate(el=>el.contains(document.activeElement))).toBe(true);}
    release();await expect(dialog).toBeHidden();await expect(page.getByRole('status')).toContainText('已更新「主任甲」');
    expect(writes).toEqual([{method:'PUT',path:'/api/v1/directors/101/campuses',body:{campus_ids:[1]}}]);
  });
}

for (const width of [390,1440]) {
  test(`cancel and focus remain inside the existing campus dialog at ${width}`,async ({page})=>{
    const writes=[];page.on('request',r=>{if(r.url().includes('/api/v1/')&&r.method()!=='GET')writes.push(r.method());});
    await installDirectorAccountsMocks(page);await page.setViewportSize({width,height:900});await page.goto('/director-accounts-pilot-mount.html');
    const opener=page.getByRole('button',{name:'編輯分校'});await opener.click();const dialog=page.getByRole('dialog');
    for(let i=0;i<10;i++){await page.keyboard.press('Tab');expect(await dialog.evaluate(el=>el.contains(document.activeElement))).toBe(true);}
    expect(await dialog.locator('button').evaluateAll(es=>es.every(e=>e.getBoundingClientRect().height>=44))).toBe(true);
    await dialog.getByRole('button',{name:'取消',exact:true}).click();await expect(dialog).toBeHidden();await expect(opener).toBeFocused();expect(writes).toEqual([]);
  });
  test(`failed campus save retains target and permits exact retry at ${width}`,async ({page})=>{
    await installDirectorAccountsMocks(page);const writes=[];
    await page.route('**/api/v1/directors/*/campuses',async route=>{const r=route.request();writes.push({method:r.method(),path:new URL(r.url()).pathname,body:r.postDataJSON()});return route.fulfill({status:writes.length===1?422:200,contentType:'application/json',body:JSON.stringify(writes.length===1?{message:'合成資料驗證失敗'}:{})});});
    await page.setViewportSize({width,height:900});await page.goto('/director-accounts-pilot-mount.html');await page.getByRole('button',{name:'編輯分校'}).click();const dialog=page.getByRole('dialog');
    await dialog.getByRole('button',{name:'儲存',exact:true}).click();await expect(dialog.getByRole('alert')).toContainText('合成資料驗證失敗');await expect(dialog).toContainText('林主任超長姓名');
    await expect(dialog.getByRole('checkbox').first()).toBeChecked();await expect(dialog.getByRole('checkbox').nth(1)).toBeChecked();
    await dialog.getByRole('button',{name:'儲存',exact:true}).click();await expect(dialog).toBeHidden();
    expect(writes).toEqual(Array(2).fill({method:'PUT',path:'/api/v1/directors/9101/campuses',body:{campus_ids:[1,2]}}));
  });
}
test('network error keeps campus target and shows recoverable message',async ({page})=>{
  await installDirectorAccountsMocks(page);await page.route('**/api/v1/directors/*/campuses',r=>r.abort('failed'));
  await page.goto('/director-accounts-pilot-mount.html');await page.getByRole('button',{name:'編輯分校'}).click();const dialog=page.getByRole('dialog');
  await dialog.getByRole('button',{name:'儲存',exact:true}).click();await expect(dialog.getByRole('alert')).toContainText('更新失敗，請稍後重試');await expect(dialog).toContainText('林主任超長姓名');await expect(dialog.getByRole('button',{name:'儲存',exact:true})).toBeEnabled();
});
