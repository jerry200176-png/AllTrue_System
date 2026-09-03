import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import ReceiptModal from '../ReceiptModal.vue';
import {
  adaptPaymentReportReceipt,
  buildReceiptCopyText,
  paymentReportReceiptUrl,
  parsePositiveReportId,
} from '../../lib/paymentReportReceipt.js';
import { buildReceiptSvg, receiptImageBlob } from '../../lib/receiptImage.js';

const mockToken = 'test-token';

const VALID_PNG_BYTES = Uint8Array.from([
  0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a,
  0x00, 0x00, 0x00, 0x0d, 0x49, 0x48, 0x44, 0x52,
  0x00, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x01,
  0x08, 0x06, 0x00, 0x00, 0x00, 0x1f, 0x15, 0xc4,
  0x89, 0x00, 0x00, 0x00, 0x0d, 0x49, 0x44, 0x41,
  0x54, 0x78, 0x9c, 0x63, 0x60, 0x00, 0x00, 0x00,
  0x02, 0x00, 0x01, 0xe2, 0x21, 0xbc, 0x33, 0x00,
  0x00, 0x00, 0x00, 0x49, 0x45, 0x4e, 0x44, 0xae,
  0x42, 0x60, 0x82,
]);

const SAMPLE = {
  receipt_no: 'R-000123',
  student_name: '王小明',
  campus_name: '大安分校',
  subject: '英文',
  session_count: 8,
  period_start: '2026/07/01',
  period_end: '2026/12/31',
  attended_dates: ['2026/07/03'],
  session_dates: [{ date: '2026/07/03', expected: false }],
  payment_date: '2026/07/13',
  payment_method: 'cash',
  note: '8/23現金繳費收據號碼:016272',
  amount: 12000,
  confirmed_at: '2026/07/13',
  confirmed_by: '主任A',
  schedule_mode: 'count',
  is_backfilled: false,
};

function ok(json) {
  return { ok: true, status: 200, json: () => Promise.resolve(json) };
}
function fail(status, message) {
  return { ok: false, status, json: () => Promise.resolve(message ? { message } : {}) };
}
function pngBlob() {
  return new Blob([VALID_PNG_BYTES], { type: 'image/png' });
}
async function pngEvidence(blob) {
  const bytes = new Uint8Array(await blob.arrayBuffer());
  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  return {
    validSignature: bytes.slice(0, 8).every((byte, index) => byte === [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a][index]),
    width: view.getUint32(16),
    height: view.getUint32(20),
    bytes: bytes.length,
  };
}
async function tick() {
  await new Promise((r) => setTimeout(r, 30));
  await nextTick();
}

beforeEach(() => {
  localStorage.setItem('alltrue_session', JSON.stringify({ access_token: mockToken }));
  global.fetch = vi.fn();
});

describe('paymentReportReceipt helpers (#1197 closeout)', () => {
  it('parsePositiveReportId rejects null/empty/NaN/non-positive', () => {
    expect(parsePositiveReportId(null)).toBeNull();
    expect(parsePositiveReportId(undefined)).toBeNull();
    expect(parsePositiveReportId('')).toBeNull();
    expect(parsePositiveReportId('abc')).toBeNull();
    expect(parsePositiveReportId(NaN)).toBeNull();
    expect(parsePositiveReportId(0)).toBeNull();
    expect(parsePositiveReportId(-3)).toBeNull();
    expect(parsePositiveReportId(1.5)).toBeNull();
    expect(parsePositiveReportId(777)).toBe(777);
    expect(parsePositiveReportId('42')).toBe(42);
  });

  it('URL helper never builds /receipts or NaN path', () => {
    expect(paymentReportReceiptUrl(123)).toBe('/api/v1/payment-reports/123/receipt');
    expect(() => paymentReportReceiptUrl(null)).toThrow(/invalid_report_id/);
    expect(() => paymentReportReceiptUrl('x')).toThrow(/invalid_report_id/);
    expect(() => paymentReportReceiptUrl(NaN)).toThrow(/invalid_report_id/);
  });

  it('adapter maps real fields only', () => {
    const view = adaptPaymentReportReceipt(SAMPLE, 123);
    expect(view.receipt_number).toBe('R-000123');
    expect(view.content_snapshot.student_name).toBe('王小明');
    expect(view.content_snapshot.campus_name).toBe('大安分校');
    expect(view.content_snapshot.total_amount).toBe(12000);
    expect(view.content_snapshot.license_number).toBeUndefined();
    expect(view.content_snapshot.items[0].description).toBe('英文 · 8 堂 · 堂數制');
  });

  it('uses monthly settlement count and prints each billed lesson detail', () => {
    const view = adaptPaymentReportReceipt({
      ...SAMPLE,
      schedule_mode: 'date',
      period_sessions: 4,
      session_dates: [{
        date: '2026/07/03', start_time: '18:00', end_time: '20:00',
        subject: '英文', lesson: 1, expected: false,
      }],
    }, 123);
    expect(view.content_snapshot.items[0].description).toContain('4 堂');
    const text = buildReceiptCopyText(view.content_snapshot, view.receipt_number);
    expect(text).toContain('第1堂 2026/07/03 18:00-20:00 · 英文');
    expect(text).not.toContain('預計');
  });

  it('builds copy text from the fields visible on the receipt', () => {
    const view = adaptPaymentReportReceipt(SAMPLE, 123);
    const text = buildReceiptCopyText(view.content_snapshot, view.receipt_number);
    expect(text).toContain('電子收據');
    expect(text).toContain('收據號碼：R-000123');
    expect(text).toContain('學生姓名：王小明');
    expect(text).toContain('合計：NT$ 12,000');
    expect(text).toContain('上課日期：2026/07/03');
    expect(text).not.toContain('（預計）');
    expect(text).toContain('收款方式：現金');
    expect(text).toContain('備註：8/23現金繳費收據號碼:016272');
  });

  it('renders all eight expected sessions as 預計 in every receipt output', async () => {
    const eightExpectedSessions = Array.from({ length: 8 }, (_, index) => ({
      date: `2026/09/${String(index + 3).padStart(2, '0')}`,
      expected: true,
    }));
    const api = {
      ...SAMPLE,
      receipt_no: 'R-001622',
      attended_dates: [],
      session_dates: eightExpectedSessions,
    };
    global.fetch.mockResolvedValueOnce(ok(api));

    const wrapper = mount(ReceiptModal, { props: { show: true, reportId: 1622 } });
    await tick();

    const renderedText = wrapper.find('.receipt-doc-session-list').text();
    expect(renderedText.match(/（預計）/g)).toHaveLength(8);
    expect(renderedText).not.toContain('尚未上');

    const view = adaptPaymentReportReceipt(api, 1622);
    const copiedText = buildReceiptCopyText(view.content_snapshot, view.receipt_number);
    expect(copiedText.match(/（預計）/g)).toHaveLength(8);
    expect(copiedText).not.toContain('尚未上');

    const imageSvg = buildReceiptSvg(view.content_snapshot, view.receipt_number);
    expect(imageSvg.match(/（預計）/g)).toHaveLength(8);
    expect(imageSvg).not.toContain('尚未上');
  });

  it('receipt line names trial and tutoring', () => {
    const trial = adaptPaymentReportReceipt({ ...SAMPLE, class_type: 'trial', session_count: 1, amount: 0 }, 1);
    expect(trial.content_snapshot.items[0].description).toContain('試聽');
    const tutor = adaptPaymentReportReceipt({ ...SAMPLE, class_type: 'tutoring', amount: 0 }, 2);
    expect(tutor.content_snapshot.items[0].description).toContain('輔導');
  });
});

describe('ReceiptModal payment-reports contract', () => {
  it('renders nothing when show is false', () => {
    mount(ReceiptModal, { props: { show: false, reportId: 1 } });
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('loads only GET /api/v1/payment-reports/{id}/receipt', async () => {
    global.fetch.mockResolvedValueOnce(ok(SAMPLE));
    mount(ReceiptModal, { props: { show: true, reportId: 456 } });
    await tick();
    expect(global.fetch).toHaveBeenCalledTimes(1);
    expect(global.fetch.mock.calls[0][0]).toBe('/api/v1/payment-reports/456/receipt');
  });

  it('invalid reportId fail-fast: no fetch, no NaN URL', async () => {
    for (const bad of [null, undefined, 0, -1, NaN]) {
      global.fetch.mockClear();
      const wrapper = mount(ReceiptModal, { props: { show: true, reportId: bad } });
      await tick();
      expect(global.fetch).not.toHaveBeenCalled();
      expect(wrapper.text()).toContain('缺少核帳紀錄編號');
      expect(wrapper.text()).not.toContain('NaN');
      wrapper.unmount();
    }
  });

  it('success rendering shows key fields and 電子收據 title', async () => {
    global.fetch.mockResolvedValueOnce(ok(SAMPLE));
    const wrapper = mount(ReceiptModal, { props: { show: true, reportId: 123 } });
    await tick();
    const text = wrapper.text();
    expect(wrapper.find('.receipt-doc-title').text()).toBe('電子收據');
    expect(text).toContain('R-000123');
    expect(text).toContain('王小明');
    expect(text).toContain('大安分校');
    expect(text).toContain('NT$ 12,000');
    expect(text).toContain('2026/07/13');
    expect(text).toContain('現金');
  });

  it('copies the receipt text without another API request', async () => {
    global.fetch.mockResolvedValueOnce(ok(SAMPLE));
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
    const wrapper = mount(ReceiptModal, { props: { show: true, reportId: 123 } });
    await tick();

    await wrapper.find('[data-testid="copy-receipt-text"]').trigger('click');
    await tick();
    expect(writeText).toHaveBeenCalledTimes(1);
    expect(writeText.mock.calls[0][0]).toContain('收據號碼：R-000123');
    expect(wrapper.text()).toContain('已複製');
    expect(global.fetch).toHaveBeenCalledTimes(1);
  });

  it('copies a rendered PNG image for LINE or other image-capable chat apps', async () => {
    global.fetch.mockResolvedValueOnce(ok(SAMPLE));
    const write = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { write } });
    Object.defineProperty(window, 'ClipboardItem', {
      configurable: true,
      value: class ClipboardItemMock {
        constructor(items) { this.items = items; }
      },
    });
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:receipt');
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
      fillRect: vi.fn(),
      drawImage: vi.fn(),
      fillStyle: '#fff',
    });
    vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation((callback) => {
      callback(pngBlob());
    });
    const imageOnLoad = vi.fn();
    Object.defineProperty(window, 'Image', {
      configurable: true,
      value: class ImageMock {
        set src(_value) {
          imageOnLoad();
          this.onload?.();
        }
      },
    });

    const wrapper = mount(ReceiptModal, { props: { show: true, reportId: 123 } });
    await tick();
    await wrapper.find('[data-testid="copy-receipt-image"]').trigger('click');
    await tick();

    expect(write).toHaveBeenCalledTimes(1);
    const imageBlob = write.mock.calls[0][0][0].items['image/png'];
    const evidence = await pngEvidence(imageBlob);
    expect(imageBlob.type).toBe('image/png');
    expect(evidence).toMatchObject({ validSignature: true, width: 1, height: 1 });
    expect(evidence.bytes).toBeGreaterThan(0);
    expect(wrapper.text()).toContain('已複製圖片');
  });

  it('uses the same canonical PNG generator for a valid download with Traditional Chinese content', async () => {
    global.fetch.mockResolvedValueOnce(ok(SAMPLE));
    const generatedBlobs = [];
    vi.spyOn(URL, 'createObjectURL').mockImplementation((blob) => {
      generatedBlobs.push(blob);
      return `blob:receipt-${generatedBlobs.length}`;
    });
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
      fillRect: vi.fn(),
      drawImage: vi.fn(),
      fillStyle: '#fff',
    });
    vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation((callback) => callback(pngBlob()));
    Object.defineProperty(window, 'Image', {
      configurable: true,
      value: class ImageMock {
        set src(_value) { this.onload?.(); }
      },
    });
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});

    const wrapper = mount(ReceiptModal, { props: { show: true, reportId: 123 } });
    await tick();
    await wrapper.find('[data-testid="download-receipt-image"]').trigger('click');
    await tick();

    const downloadedPng = generatedBlobs.at(-1);
    const evidence = await pngEvidence(downloadedPng);
    expect(click).toHaveBeenCalled();
    expect(downloadedPng.type).toBe('image/png');
    expect(evidence).toMatchObject({ validSignature: true, width: 1, height: 1 });
    expect(wrapper.text()).toContain('電子收據');
    expect(wrapper.text()).toContain('王小明');
  });

  it('reports image generation failure without silently copying text', async () => {
    global.fetch.mockResolvedValueOnce(ok(SAMPLE));
    const write = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { write } });
    Object.defineProperty(window, 'ClipboardItem', { configurable: true, value: class ClipboardItemMock {} });
    Object.defineProperty(window, 'Image', {
      configurable: true,
      value: class ImageMock {
        set src(_value) { this.onerror?.(new Error('decode failed')); }
      },
    });
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:receipt');
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});

    const wrapper = mount(ReceiptModal, { props: { show: true, reportId: 123 } });
    await tick();
    await wrapper.find('[data-testid="copy-receipt-image"]').trigger('click');
    await tick();

    expect(write).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('複製圖片失敗');
    expect(wrapper.text()).not.toContain('已複製文字');
  });

  it('records the generator stages without exposing receipt contents', async () => {
    global.fetch.mockResolvedValueOnce(ok(SAMPLE));
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:receipt');
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {});
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
      fillRect: vi.fn(),
      drawImage: vi.fn(),
      fillStyle: '#fff',
    });
    vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation((callback) => callback(pngBlob()));
    Object.defineProperty(window, 'Image', {
      configurable: true,
      value: class ImageMock {
        set src(_value) { this.onload?.(); }
      },
    });

    const wrapper = mount(ReceiptModal, { props: { show: true, reportId: 123 } });
    await tick();
    const stages = {};
    const blob = await receiptImageBlob({
      source: wrapper.find('.receipt-document').element,
      snapshot: adaptPaymentReportReceipt(SAMPLE, 123).content_snapshot,
      receiptNumber: 'R-000123',
      onStage: (name, value) => { stages[name] = value; },
    });

    expect(blob.type).toBe('image/png');
    expect((await pngEvidence(blob)).validSignature).toBe(true);
    expect(stages).toMatchObject({
      receiptPrintRefAvailable: true,
      cloneSuccessful: true,
      svgStringGenerated: true,
      svgBlobGenerated: { type: 'image/svg+xml;charset=utf-8' },
      objectUrlGenerated: true,
      imageOutcome: 'onload',
      canvasContextAvailable: true,
      drawImage: 'SUCCESS',
      canvasToBlob: 'BLOB',
      pngBlob: { type: 'image/png' },
    });
  });

  it('classifies 403/404/422 without generic 請求失敗', async () => {
    global.fetch.mockResolvedValueOnce(fail(404));
    let w = mount(ReceiptModal, { props: { show: true, reportId: 9 } });
    await tick();
    expect(w.text()).toContain('找不到這筆核帳紀錄');
    expect(w.text()).not.toContain('請求失敗（404）');
    w.unmount();

    global.fetch.mockResolvedValueOnce(fail(403, 'Forbidden'));
    w = mount(ReceiptModal, { props: { show: true, reportId: 1 } });
    await tick();
    expect(w.text()).toContain('Forbidden');
    w.unmount();

    global.fetch.mockResolvedValueOnce(fail(422, '尚未核帳確認，無法產生收據'));
    w = mount(ReceiptModal, { props: { show: true, reportId: 1 } });
    await tick();
    expect(w.text()).toContain('尚未核帳確認');
  });

  it('uses reportId even if extraneous receiptId/paymentId props present', async () => {
    global.fetch.mockResolvedValueOnce(ok(SAMPLE));
    mount(ReceiptModal, { props: { show: true, reportId: 777, receiptId: 888, paymentId: 999 } });
    await tick();
    expect(global.fetch.mock.calls[0][0]).toBe('/api/v1/payment-reports/777/receipt');
  });

  it('switching reportId clears previous receipt before loading next', async () => {
    const second = { ...SAMPLE, receipt_no: 'R-000999', student_name: '另一位' };
    global.fetch
      .mockResolvedValueOnce(ok(SAMPLE))
      .mockResolvedValueOnce(ok(second));

    const wrapper = mount(ReceiptModal, { props: { show: true, reportId: 123 } });
    await tick();
    expect(wrapper.text()).toContain('王小明');
    expect(wrapper.text()).toContain('R-000123');

    await wrapper.setProps({ reportId: 999 });
    await tick();
    expect(wrapper.text()).toContain('另一位');
    expect(wrapper.text()).toContain('R-000999');
    expect(wrapper.text()).not.toContain('王小明');
    expect(global.fetch.mock.calls.map((c) => c[0])).toEqual([
      '/api/v1/payment-reports/123/receipt',
      '/api/v1/payment-reports/999/receipt',
    ]);
  });
});
