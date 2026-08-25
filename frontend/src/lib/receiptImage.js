const DEFAULT_WIDTH = 560;
const MIN_HEIGHT = 520;
const FONT_FAMILY = "'Noto Sans CJK TC', 'Microsoft JhengHei', Arial, sans-serif";

export class ReceiptImageGenerationError extends Error {
  constructor(code, cause = null) {
    super(code);
    this.name = 'ReceiptImageGenerationError';
    this.code = code;
    this.cause = cause;
  }
}

function escapeXml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&apos;');
}

function safeText(value, fallback = '—') {
  const text = String(value ?? '').trim();
  return text || fallback;
}

function wrapText(value, maxChars = 36) {
  const text = safeText(value);
  const chars = Array.from(text);
  const lines = [];
  for (let index = 0; index < chars.length; index += maxChars) {
    lines.push(chars.slice(index, index + maxChars).join(''));
  }
  return lines.length ? lines : ['—'];
}

function svgText(text, x, y, options = {}) {
  const {
    size = 13,
    fill = '#172033',
    weight = 400,
    anchor = 'start',
  } = options;
  return `<text x="${x}" y="${y}" fill="${fill}" font-family="${FONT_FAMILY}" font-size="${size}px" font-weight="${weight}" text-anchor="${anchor}">${escapeXml(text)}</text>`;
}

function addWrappedText(parts, text, x, y, options = {}) {
  const { maxChars = 36, lineHeight = 20 } = options;
  wrapText(text, maxChars).forEach((line, index) => {
    parts.push(svgText(line, x, y + (index * lineHeight), options));
  });
  return y + (wrapText(text, maxChars).length * lineHeight);
}

function formatAmount(value) {
  if (value == null || value === '' || Number.isNaN(Number(value))) return '—';
  return `NT$ ${Number(value).toLocaleString('zh-TW')}`;
}

function paymentMethodLabel(method) {
  return {
    cash: '現金',
    transfer: '匯款',
    card: '信用卡',
    line_pay: 'LINE Pay',
    backfill: '現金（補建）',
  }[method] || safeText(method);
}

function receiptRows(snapshot, receiptNumber) {
  const rows = [
    ['學生姓名', safeText(snapshot.student_name)],
    ['分校', safeText(snapshot.campus_name)],
    ['修業期間', snapshot.study_period
      ? `${safeText(snapshot.study_period.start)} ~ ${safeText(snapshot.study_period.end)}`
      : '—'],
    ['收據號碼', safeText(receiptNumber)],
  ];
  rows.push(['收款日期', safeText(snapshot.paid_at)]);
  rows.push(['收款方式', paymentMethodLabel(snapshot.method)]);
  if (snapshot.note) rows.push(['備註', snapshot.note]);
  return rows;
}

/**
 * Build a self-contained SVG using native SVG primitives only.
 * foreignObject is intentionally avoided: Chromium cannot reliably decode a
 * blob SVG containing HTML/CSS foreignObject content in this production path.
 */
export function buildReceiptSvg(snapshot = {}, receiptNumber = '—', width = DEFAULT_WIDTH, height = MIN_HEIGHT) {
  const parts = [
    `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">`,
    `<rect width="${width}" height="${height}" rx="8" fill="#ffffff"/>`,
    `<rect width="${width}" height="72" rx="8" fill="#14532d"/>`,
    `<rect y="64" width="${width}" height="8" fill="#14532d"/>`,
    svgText('電子收據', width / 2, 31, { size: 22, fill: '#ffffff', weight: 700, anchor: 'middle' }),
    svgText(safeText(snapshot.school_name, '台北全真一對一補習班'), width / 2, 54, { size: 13, fill: '#e8f5e9', anchor: 'middle' }),
  ];

  let y = 102;
  const left = 28;
  const right = width - 28;
  receiptRows(snapshot, receiptNumber).forEach(([label, value]) => {
    parts.push(`<line x1="${left}" y1="${y + 10}" x2="${right}" y2="${y + 10}" stroke="#e5e7eb"/>`);
    parts.push(svgText(label, left, y, { size: 12, fill: '#6b7280' }));
    const lines = wrapText(value, 32);
    lines.forEach((line, index) => {
      parts.push(svgText(line, right, y + (index * 18), { size: 13, weight: 500, anchor: 'end' }));
    });
    y += Math.max(32, lines.length * 18 + 10);
  });

  y += 8;
  parts.push(`<rect x="${left}" y="${y}" width="${width - 56}" height="34" rx="5" fill="#f3f4f6"/>`);
  parts.push(svgText('收費項目', left + 12, y + 22, { size: 12, fill: '#6b7280', weight: 600 }));
  parts.push(svgText('金額', right - 12, y + 22, { size: 12, fill: '#6b7280', weight: 600, anchor: 'end' }));
  y += 48;

  const items = Array.isArray(snapshot.items) && snapshot.items.length
    ? snapshot.items
    : [{ description: '課程費用', amount: snapshot.total_amount }];
  items.forEach((item) => {
    const descriptionLines = wrapText(item.description, 30);
    descriptionLines.forEach((line, index) => {
      parts.push(svgText(line, left + 12, y + (index * 18), { size: 12 }));
    });
    parts.push(svgText(formatAmount(item.amount), right - 12, y, { size: 12, anchor: 'end' }));
    y += Math.max(28, descriptionLines.length * 18 + 8);
  });

  parts.push(`<rect x="${left}" y="${y - 4}" width="${width - 56}" height="32" rx="4" fill="#e8f5e9"/>`);
  parts.push(svgText('合計', left + 12, y + 17, { size: 13, weight: 700 }));
  parts.push(svgText(formatAmount(snapshot.total_amount), right - 12, y + 17, { size: 13, weight: 700, anchor: 'end' }));
  y += 52;

  if (Array.isArray(snapshot.session_dates) && snapshot.session_dates.length) {
    parts.push(svgText('上課日期', left, y, { size: 12, fill: '#6b7280', weight: 600 }));
    y += 20;
    const sessionText = snapshot.session_dates
      .slice(0, 16)
      .map((session) => `${safeText(session?.date)}${session?.expected ? '（尚未上）' : ''}`)
      .join('、');
    y = addWrappedText(parts, sessionText, left, y, { size: 11, fill: '#374151', maxChars: 48, lineHeight: 17 });
    if (snapshot.session_dates.length > 16) {
      y = addWrappedText(parts, `共 ${snapshot.session_dates.length} 堂`, left, y, { size: 11, fill: '#374151', maxChars: 48, lineHeight: 17 });
    }
    y += 8;
  }

  const footerY = Math.max(y + 12, height - 64);
  parts.push(`<line x1="${left}" y1="${footerY}" x2="${right}" y2="${footerY}" stroke="#e5e7eb"/>`);
  parts.push(svgText('經辦人：__________', left, footerY + 22, { size: 10, fill: '#6b7280' }));
  parts.push(svgText('補習班用印：', width / 2, footerY + 22, { size: 10, fill: '#6b7280', anchor: 'middle' }));
  parts.push(svgText(`開立時間：${safeText(snapshot.confirmed_at || snapshot.paid_at)}`, right, footerY + 16, { size: 10, fill: '#6b7280', anchor: 'end' }));
  parts.push(svgText('此收據由 AllTrue 系統產生', right, footerY + 34, { size: 10, fill: '#6b7280', anchor: 'end' }));
  parts.push('</svg>');
  return parts.join('');
}

function emitStage(onStage, name, value = true) {
  if (typeof onStage === 'function') onStage(name, value);
}

/**
 * One canonical receipt PNG generator for both clipboard and download.
 * It uses a self-contained SVG (no HTML foreignObject, CSS clone, font fetch,
 * or cross-origin resource) and rasterizes it with the browser canvas.
 */
export function receiptImageBlob({ source, snapshot = {}, receiptNumber = '—', onStage } = {}) {
  emitStage(onStage, 'receiptPrintRefAvailable', Boolean(source));
  if (!source) throw new ReceiptImageGenerationError('RECEIPT_NOT_READY');

  const bounds = source.getBoundingClientRect();
  const width = Math.max(360, Math.ceil(source.scrollWidth || bounds.width || DEFAULT_WIDTH));
  const height = Math.max(MIN_HEIGHT, Math.ceil(source.scrollHeight || bounds.height || MIN_HEIGHT));
  emitStage(onStage, 'sourceDimensions', `${width}x${height}`);

  try {
    source.cloneNode(true);
    emitStage(onStage, 'cloneSuccessful');
  } catch (error) {
    throw new ReceiptImageGenerationError('CLONE_FAILED', error);
  }

  const svg = buildReceiptSvg(snapshot, receiptNumber, width, height);
  emitStage(onStage, 'svgStringGenerated');
  const svgBlob = new Blob([svg], { type: 'image/svg+xml;charset=utf-8' });
  emitStage(onStage, 'svgBlobGenerated', { type: svgBlob.type, size: svgBlob.size });
  const svgUrl = URL.createObjectURL(svgBlob);
  emitStage(onStage, 'objectUrlGenerated');

  const scale = Math.min(3, Math.max(2, window.devicePixelRatio || 1));
  return new Promise((resolve, reject) => {
    const image = new window.Image();
    let settled = false;
    const cleanup = () => {
      if (settled) return;
      settled = true;
      URL.revokeObjectURL(svgUrl);
    };
    const fail = (code, cause = null) => {
      cleanup();
      reject(new ReceiptImageGenerationError(code, cause));
    };
    image.onload = () => {
      emitStage(onStage, 'imageOutcome', 'onload');
      try {
        const canvas = document.createElement('canvas');
        canvas.width = width * scale;
        canvas.height = height * scale;
        const context = canvas.getContext('2d');
        emitStage(onStage, 'canvasContextAvailable', Boolean(context));
        if (!context) {
          fail('CANVAS_CONTEXT_UNAVAILABLE');
          return;
        }
        try {
          context.fillStyle = '#ffffff';
          context.fillRect(0, 0, canvas.width, canvas.height);
          context.drawImage(image, 0, 0, canvas.width, canvas.height);
          emitStage(onStage, 'drawImage', 'SUCCESS');
        } catch (error) {
          emitStage(onStage, 'drawImage', 'FAILED');
          fail('CANVAS_DRAW_FAILED', error);
          return;
        }
        emitStage(onStage, 'canvasToBlob', 'PENDING');
        canvas.toBlob((blob) => {
          emitStage(onStage, 'canvasToBlob', blob ? 'BLOB' : 'NULL');
          emitStage(onStage, 'pngBlob', { type: blob?.type || 'UNKNOWN', size: blob?.size || 0 });
          if (!blob) {
            fail('PNG_ENCODING_FAILED');
            return;
          }
          cleanup();
          resolve(blob);
        }, 'image/png');
      } catch (error) {
        fail('CANVAS_STAGE_FAILED', error);
      }
    };
    image.onerror = () => {
      emitStage(onStage, 'imageOutcome', 'onerror');
      fail('IMAGE_ELEMENT_FAILED_TO_DECODE_SVG');
    };
    image.src = svgUrl;
  });
}
