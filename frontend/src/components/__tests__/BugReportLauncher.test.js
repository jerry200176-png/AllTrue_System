import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import BugReportLauncher from '../BugReportLauncher.vue';
import {
  extractImageFiles,
  MAX_BUG_ATTACHMENT_BYTES,
  namePastedImage,
  validateBugAttachments,
} from '../../lib/bugReportAttachments';
import { submitBugReport } from '../../lib/bugReportsApi';

vi.mock('../../lib/bugReportsApi', () => ({
  submitBugReport: vi.fn(() => Promise.resolve({ id: 1 })),
}));

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../BugReportLauncher.vue'), 'utf8');

let previewCounter = 0;

function makeFile(name = 'capture.png', type = 'image/png', size = 10) {
  return new File([new Uint8Array(size)], name, { type });
}

function makeTransfer(files = [], items = null) {
  return {
    files,
    items: items || files.map((file) => ({
      kind: 'file',
      type: file.type,
      getAsFile: () => file,
    })),
    types: files.length ? ['Files'] : [],
  };
}

function makeEvent(type, dataTransfer) {
  const event = new Event(type, { bubbles: true, cancelable: true });
  Object.defineProperty(event, type === 'paste' ? 'clipboardData' : 'dataTransfer', {
    value: dataTransfer,
  });
  return event;
}

async function openLauncher() {
  const wrapper = mount(BugReportLauncher, {
    props: { branchId: 9, currentPageKey: 'attendance' },
  });
  await wrapper.find('.fab').trigger('click');
  return wrapper;
}

function bodyElement(selector) {
  const element = document.body.querySelector(selector);
  if (!element) throw new Error(`Expected ${selector} in teleported dialog`);
  return element;
}

function bodyCount(selector) {
  return document.body.querySelectorAll(selector).length;
}

describe('bug report attachments', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    previewCounter = 0;
    Object.defineProperty(URL, 'createObjectURL', {
      configurable: true,
      writable: true,
      value: vi.fn(() => `blob:bug-preview-${++previewCounter}`),
    });
    Object.defineProperty(URL, 'revokeObjectURL', {
      configurable: true,
      writable: true,
      value: vi.fn(),
    });
  });

  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('extracts image files, names pasted blobs, and validates limits', () => {
    const image = makeFile('screen.png');
    const text = { kind: 'string', type: 'text/plain', getAsFile: () => null };
    expect(extractImageFiles(makeTransfer([], [text, {
      kind: 'file', type: image.type, getAsFile: () => image,
    }]))).toEqual([image]);
    expect(extractImageFiles(makeTransfer([image]))).toEqual([image]);

    const pasted = namePastedImage(new File([new Uint8Array(3)], '', { type: 'image/png' }), 123);
    expect(pasted.name).toBe('pasted-image-123.png');

    const result = validateBugAttachments([
      image,
      makeFile('notes.txt', 'text/plain'),
      makeFile('large.png', 'image/png', MAX_BUG_ATTACHMENT_BYTES + 1),
    ]);
    expect(result.accepted).toEqual([image]);
    expect(result.errors.join('；')).toContain('支援的圖片格式');
    expect(result.errors.join('；')).toContain('超過 5MB');
  });

  it('accepts pasted images, previews them, and leaves text paste untouched', async () => {
    const wrapper = await openLauncher();
    const image = makeFile('screen.png');
    const imageEvent = makeEvent('paste', makeTransfer([image]));

    expect(bodyElement('.bug-report-form').dispatchEvent(imageEvent)).toBe(false);
    await nextTick();
    expect(bodyCount('.att-row')).toBe(1);
    expect(bodyElement('.att-preview').getAttribute('src')).toMatch(/^blob:/);
    expect(document.body.textContent).toContain('已加入 1 / 5 張');

    const textEvent = makeEvent('paste', makeTransfer([], [{
      kind: 'string', type: 'text/plain', getAsFile: () => null,
    }]));
    expect(bodyElement('.bug-report-form').dispatchEvent(textEvent)).toBe(true);
    expect(bodyCount('.att-row')).toBe(1);
    wrapper.unmount();
  });

  it('accepts dropped images and reports unsupported files', async () => {
    const wrapper = await openLauncher();
    const dropzone = bodyElement('.attachment-dropzone');
    const image = makeFile('dropped.webp', 'image/webp');

    await dropzone.dispatchEvent(makeEvent('dragenter', makeTransfer([image])));
    expect(dropzone.classList.contains('is-dragging')).toBe(true);
    await dropzone.dispatchEvent(makeEvent('drop', makeTransfer([image])));
    await nextTick();
    expect(dropzone.classList.contains('is-dragging')).toBe(false);
    expect(bodyCount('.att-row')).toBe(1);

    await dropzone.dispatchEvent(makeEvent('drop', makeTransfer([makeFile('notes.txt', 'text/plain')])));
    await nextTick();
    expect(bodyElement('.attachment-error').textContent).toContain('支援的圖片格式');
    wrapper.unmount();
  });

  it('submits the original files and revokes preview URLs on removal', async () => {
    const wrapper = await openLauncher();
    const image = makeFile('screen.jpg', 'image/jpeg');
    await bodyElement('.bug-report-form').dispatchEvent(makeEvent('paste', makeTransfer([image])));
    await nextTick();
    const textarea = bodyElement('textarea');
    textarea.value = '畫面無法載入';
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    await nextTick();
    bodyElement('.btn-submit').click();
    await flushPromises();
    await nextTick();

    expect(submitBugReport).toHaveBeenCalledWith(expect.objectContaining({ files: [image] }));
    expect(document.body.textContent).toContain('編號 #1');
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:bug-preview-1');
    wrapper.unmount();
  });

  it('includes optional occurrence context without changing the user description', async () => {
    const wrapper = await openLauncher();
    const textarea = bodyElement('#bug-report-description');
    textarea.value = '帳務列表顯示錯誤';
    textarea.dispatchEvent(new Event('input', { bubbles: true }));

    const occurrenceAt = bodyElement('#bug-occurrence-at');
    occurrenceAt.value = '2026-08-29T14:30';
    occurrenceAt.dispatchEvent(new Event('input', { bubbles: true }));
    const relatedReference = bodyElement('#bug-related-reference');
    relatedReference.value = '學生 271／課堂 32570';
    relatedReference.dispatchEvent(new Event('input', { bubbles: true }));
    await nextTick();

    bodyElement('.btn-submit').click();
    await nextTick();

    const payload = submitBugReport.mock.calls.at(-1)[0];
    expect(payload.description).toBe('帳務列表顯示錯誤');
    expect(JSON.parse(payload.client_info)).toMatchObject({
      occurrenceAt: '2026-08-29T14:30',
      relatedReference: '學生 271／課堂 32570',
    });
    wrapper.unmount();
  });
});

describe('bug report composer accessibility', () => {
  it('associates visible field labels with stable controls', () => {
    expect(source).toContain('for="bug-report-title"');
    expect(source).toContain('id="bug-report-title"');
    expect(source).toContain('for="bug-report-description"');
    expect(source).toContain('id="bug-report-description"');
    expect(source).toContain('for="bug-file-input"');
    expect(source).toContain('for="bug-report-severity"');
    expect(source).toContain('id="bug-report-severity"');
  });

  it('gives the attachment trigger and submit feedback explicit semantics', () => {
    expect(source).toContain('aria-label="回報系統問題"');
    expect(source).toContain('aria-label="新增截圖"');
    expect(source).toContain('class="success-msg" role="status" aria-live="polite"');
    expect(source).toContain('class="error-msg" role="alert"');
  });

  it('keeps the composer actions explicit non-submit buttons', () => {
    expect(source).toMatch(/<button\s+type="button"\s+class="fab"/);
    expect(source).toMatch(/<button type="button" class="btn-cancel"/);
    expect(source).toMatch(/<button type="button" class="btn-submit"/);
  });
});
