import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import AgreedFeeCorrectionModal from '../AgreedFeeCorrectionModal.vue';
import ContractAdjustmentChoiceModal from '../course-management/ContractAdjustmentChoiceModal.vue';

const plan = {
  old_charge: 8000,
  original_invoice_total: 7600,
  new_charge: 500,
  charge_delta: -7500,
  obligation_delta: -7100,
  new_outstanding: 500,
  unchanged: {
    Rate: 1000,
    SessionCount: 8,
    RemainingSessions: 5,
    UsedSessions: 3,
    StartDate: '2026-10-01',
    EndDate: '2026-12-31',
  },
  message: '只更正約定費用，原帳單作廢並建立新未繳帳單，不收款。',
  preview_token: 'signed-state',
};

const ok = (json) => ({ ok: true, status: 200, json: async () => json });
const fail = (status, message, extra = {}) => ({
  ok: false,
  status,
  json: async () => ({ message, ...extra }),
});

afterEach(() => {
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
  localStorage.clear();
});

function mountFee(props = {}) {
  return mount(AgreedFeeCorrectionModal, {
    props: { courseId: 7, invoiceId: 8, ...props },
    global: { stubs: { Teleport: true } },
  });
}

async function fillAndPreview(wrapper, charge = '500', reason = '約定費用誤植') {
  await wrapper.get('[data-testid="fee-charge"]').setValue(charge);
  await wrapper.get('[data-testid="fee-reason"]').setValue(reason);
  await wrapper.get('form').trigger('submit');
  await flushPromises();
}

describe('ContractAdjustmentChoiceModal agreed-fee entry (default-off)', () => {
  it('hides agreed-fee entry when server capability is off', () => {
    const wrapper = mount(ContractAdjustmentChoiceModal, {
      props: {
        show: true,
        studentName: '測試學生',
        subject: 'math',
        paymentStatus: 'unpaid',
        agreedFeeAvailable: false,
      },
    });
    expect(wrapper.find('[data-testid="contract-adjust-agreed-fee"]').exists()).toBe(false);
    expect(wrapper.text()).not.toContain('只更正約定費用');
  });

  it('shows agreed-fee entry only when server capability is on and routes without inventing amounts', async () => {
    const wrapper = mount(ContractAdjustmentChoiceModal, {
      props: {
        show: true,
        studentName: '測試學生',
        subject: 'math',
        paymentStatus: 'unpaid',
        agreedFeeAvailable: true,
      },
    });
    const entry = wrapper.get('[data-testid="contract-adjust-agreed-fee"]');
    expect(entry.text()).toContain('只更正約定費用');
    expect(entry.text()).toContain('不新增收款');
    await entry.trigger('click');
    expect(wrapper.emitted('choose')).toEqual([['agreed_fee']]);
  });
});

describe('AgreedFeeCorrectionModal API contract', () => {
  it('starts with empty charge (never prefills a guessed amount) and reason', () => {
    const wrapper = mountFee({ invoiceId: null });
    expect(wrapper.get('[data-testid="fee-charge"]').element.value).toBe('');
    expect(wrapper.get('[data-testid="fee-reason"]').element.value).toBe('');
    wrapper.unmount();
  });

  it('preview POSTs preview only (no apply write) and shows stored Charge separately from bill', async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'fixture' }));
    const calls = [];
    vi.stubGlobal('fetch', vi.fn(async (url, options) => {
      calls.push({ url, body: JSON.parse(options.body) });
      if (String(url).endsWith('/preview')) return ok(plan);
      throw new Error('apply must not run during preview');
    }));
    const wrapper = mountFee();
    await fillAndPreview(wrapper);
    expect(calls).toHaveLength(1);
    expect(calls[0].url).toMatch(/\/agreed-fee-correction\/preview$/);
    expect(calls[0].body).toMatchObject({
      scope: 'billed',
      original_invoice_id: 8,
      new_charge: 500,
      reason: '約定費用誤植',
    });
    expect(calls[0].body.idempotency_key).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,
    );
    expect(wrapper.text()).toContain('NT$8,000');
    expect(wrapper.text()).toContain('NT$7,600');
    expect(wrapper.text()).toContain('堂數 8');
    expect(wrapper.text()).toContain('新增收款 0');
    expect(wrapper.emitted('changed')).toBeUndefined();
    expect(wrapper.emitted('close')).toBeUndefined();
    wrapper.unmount();
  });

  it('unbilled scope omits invoice id and still does not prefill charge', async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'fixture' }));
    const calls = [];
    vi.stubGlobal('fetch', vi.fn(async (url, options) => {
      calls.push({ url, body: JSON.parse(options.body) });
      return ok({ ...plan, original_invoice_total: null, obligation_delta: null, new_outstanding: null });
    }));
    const wrapper = mountFee({ invoiceId: null });
    expect(wrapper.get('[data-testid="fee-charge"]').element.value).toBe('');
    await fillAndPreview(wrapper);
    expect(calls[0].body.scope).toBe('unbilled');
    expect(calls[0].body.original_invoice_id).toBeNull();
    wrapper.unmount();
  });

  it('apply requires preview_token and retries the exact payload after a lost response', async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'fixture' }));
    const calls = [];
    let applies = 0;
    vi.stubGlobal('fetch', vi.fn(async (url, options) => {
      const body = JSON.parse(options.body);
      calls.push({ url, body });
      if (String(url).endsWith('/preview')) return ok(plan);
      if (++applies === 1) throw new Error('Lost response');
      return ok({ correction_id: 1 });
    }));
    const wrapper = mountFee();
    await fillAndPreview(wrapper);
    await wrapper.get('[data-testid="fee-apply"]').trigger('click');
    await flushPromises();
    await wrapper.get('[data-testid="fee-apply"]').trigger('click');
    await flushPromises();
    expect(calls[1].url).toMatch(/\/agreed-fee-correction\/apply$/);
    expect(calls[1].body).toEqual({ ...calls[0].body, preview_token: 'signed-state' });
    expect(calls[2].body).toEqual(calls[1].body);
    expect(wrapper.emitted('changed')).toHaveLength(1);
    expect(wrapper.emitted('close')).toHaveLength(1);
    wrapper.unmount();
  });

  it.each([
    [403, '沒有權限更正此課程費用。'],
    [503, '更正功能尚未啟用或資料表尚未準備完成。'],
    [409, '狀態已變更，請重新預覽。', { code: 'state_changed' }],
    [422, '此帳單已有收款，不可只更正約定費用。', { code: 'paid' }],
    [422, '原帳單已作廢，請重新選擇。', { code: 'void' }],
    [422, '此課程有多筆未結清帳單，請先整理後再更正。', { code: 'multiple' }],
    [422, '預覽已過期，請重新預覽。', { code: 'stale' }],
  ])('HTTP %s (%s) never reports success', async (status, message, extra = {}) => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'fixture' }));
    vi.stubGlobal('fetch', vi.fn(async (url) => {
      if (String(url).endsWith('/preview')) return ok(plan);
      return fail(status, message, extra);
    }));
    const wrapper = mountFee();
    await fillAndPreview(wrapper);
    await wrapper.get('[data-testid="fee-apply"]').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain(message);
    expect(wrapper.emitted('changed')).toBeUndefined();
    expect(wrapper.emitted('close')).toBeUndefined();
    wrapper.unmount();
  });

  it('preview failure never opens apply and never emits success', async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'fixture' }));
    vi.stubGlobal('fetch', vi.fn(async () => fail(422, '合約費用來源不明，請先確認後再更正。', { code: 'unknown_charge' })));
    const wrapper = mountFee({ invoiceId: null });
    await fillAndPreview(wrapper);
    expect(wrapper.find('[data-testid="fee-apply"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="fee-plan"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('合約費用來源不明');
    expect(wrapper.emitted('changed')).toBeUndefined();
    wrapper.unmount();
  });

  it('does not call payment or receipt endpoints', async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'fixture' }));
    const urls = [];
    vi.stubGlobal('fetch', vi.fn(async (url, options) => {
      urls.push(String(url));
      if (String(url).endsWith('/preview')) return ok(plan);
      return ok({ correction_id: 1 });
    }));
    const wrapper = mountFee();
    await fillAndPreview(wrapper);
    await wrapper.get('[data-testid="fee-apply"]').trigger('click');
    await flushPromises();
    expect(urls.every((u) => u.includes('/agreed-fee-correction/'))).toBe(true);
    expect(urls.some((u) => /payment|receipt|report/i.test(u))).toBe(false);
    wrapper.unmount();
  });

  it('P2: deferred preview — edit during in-flight must not let stale 500 apply/success', async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'fixture' }));
    let resolvePreview;
    const deferred = new Promise((resolve) => { resolvePreview = resolve; });
    const calls = [];
    vi.stubGlobal('fetch', vi.fn(async (url, options) => {
      const body = JSON.parse(options.body);
      calls.push({ url: String(url), body });
      if (String(url).endsWith('/preview')) {
        await deferred;
        // Server always returns the signed plan for the request body that was sent (500/old).
        return ok({
          ...plan,
          new_charge: body.new_charge,
          preview_token: `signed-${body.new_charge}-${body.reason}`,
        });
      }
      return ok({ correction_id: 99 });
    }));
    const wrapper = mountFee();
    await wrapper.get('[data-testid="fee-charge"]').setValue('500');
    await wrapper.get('[data-testid="fee-reason"]').setValue('約定費用誤植');
    await wrapper.get('form').trigger('submit');
    // Preview still in flight — inputs must be locked.
    const chargeInput = wrapper.get('[data-testid="fee-charge"]');
    const reasonInput = wrapper.get('[data-testid="fee-reason"]');
    expect(chargeInput.element.disabled).toBe(true);
    expect(reasonInput.element.disabled).toBe(true);

    // Simulate intent drift during the outstanding 500 request (race / forced edit bypassing disabled).
    chargeInput.element.disabled = false;
    reasonInput.element.disabled = false;
    await chargeInput.setValue('600');
    await reasonInput.setValue('改成新原因說明');
    resolvePreview();
    await flushPromises();

    // Stale response for 500/old must not become an apply surface or emit success.
    expect(wrapper.find('[data-testid="fee-plan"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="fee-apply"]').exists()).toBe(false);
    expect(wrapper.emitted('changed')).toBeUndefined();
    expect(calls.some((c) => c.url.endsWith('/apply'))).toBe(false);

    // Re-preview with the edited values is required.
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    const previews = calls.filter((c) => c.url.endsWith('/preview'));
    expect(previews).toHaveLength(2);
    expect(previews[0].body).toMatchObject({ new_charge: 500, reason: '約定費用誤植' });
    expect(previews[1].body).toMatchObject({ new_charge: 600, reason: '改成新原因說明' });
    expect(wrapper.find('[data-testid="fee-apply"]').exists()).toBe(true);

    await wrapper.get('[data-testid="fee-apply"]').trigger('click');
    await flushPromises();
    const applyCall = calls.find((c) => c.url.endsWith('/apply'));
    expect(applyCall.body).toMatchObject({
      new_charge: 600,
      reason: '改成新原因說明',
      preview_token: 'signed-600-改成新原因說明',
    });
    expect(wrapper.emitted('changed')).toHaveLength(1);
    wrapper.unmount();
  });

  it('P2: deferred apply race — after preview, back+edit then late actions still require fresh preview', async () => {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: 'fixture' }));
    let resolveSecondPreview;
    const secondDeferred = new Promise((resolve) => { resolveSecondPreview = resolve; });
    let previewCount = 0;
    const calls = [];
    vi.stubGlobal('fetch', vi.fn(async (url, options) => {
      const body = JSON.parse(options.body);
      calls.push({ url: String(url), body });
      if (String(url).endsWith('/preview')) {
        previewCount += 1;
        if (previewCount === 1) {
          return ok({ ...plan, new_charge: 500, preview_token: 'signed-500-old' });
        }
        await secondDeferred;
        return ok({
          ...plan,
          new_charge: body.new_charge,
          preview_token: `signed-${body.new_charge}-new`,
        });
      }
      return ok({ correction_id: 1 });
    }));
    const wrapper = mountFee();
    await fillAndPreview(wrapper, '500', '約定費用誤植');
    expect(wrapper.find('[data-testid="fee-plan"]').exists()).toBe(true);

    await wrapper.get('[data-testid="fee-back"]').trigger('click');
    await wrapper.get('[data-testid="fee-charge"]').setValue('600');
    await wrapper.get('[data-testid="fee-reason"]').setValue('改成新原因說明');
    await wrapper.get('form').trigger('submit');
    // Second preview in flight — must not still expose the old 500 apply surface.
    expect(wrapper.find('[data-testid="fee-apply"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="fee-plan"]').exists()).toBe(false);

    // Drift again before the second response lands (force-enable to simulate race).
    const chargeInput = wrapper.get('[data-testid="fee-charge"]');
    const reasonInput = wrapper.get('[data-testid="fee-reason"]');
    expect(chargeInput.element.disabled).toBe(true);
    chargeInput.element.disabled = false;
    reasonInput.element.disabled = false;
    await chargeInput.setValue('700');
    await reasonInput.setValue('又改一次原因文字');
    resolveSecondPreview();
    await flushPromises();

    expect(wrapper.find('[data-testid="fee-apply"]').exists()).toBe(false);
    expect(wrapper.emitted('changed')).toBeUndefined();
    expect(calls.some((c) => c.url.endsWith('/apply'))).toBe(false);

    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(calls.filter((c) => c.url.endsWith('/preview')).at(-1).body).toMatchObject({
      new_charge: 700,
      reason: '又改一次原因文字',
    });
    await wrapper.get('[data-testid="fee-apply"]').trigger('click');
    await flushPromises();
    expect(calls.at(-1).body).toMatchObject({
      new_charge: 700,
      reason: '又改一次原因文字',
      preview_token: 'signed-700-new',
    });
    expect(wrapper.emitted('changed')).toHaveLength(1);
    wrapper.unmount();
  });
});
