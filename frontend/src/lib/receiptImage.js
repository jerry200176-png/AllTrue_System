export class ReceiptImageGenerationError extends Error {
  constructor(code, cause = null) {
    super(code);
    this.name = 'ReceiptImageGenerationError';
    this.code = code;
    this.cause = cause;
  }
}

/**
 * One receipt PNG generator for both clipboard and download: captures the
 * rendered BillingDocument with modern-screenshot (MIT, lazy-loaded), the
 * same path as the payment slip.
 *
 * The 2026-08 production failure (#2049) came from serialising the clone
 * with outerHTML (not XML: `<br>` breaks the SVG image decode);
 * modern-screenshot serialises XHTML. The slip uses system fonts, so no web
 * font is fetched or embedded (font: false).
 */
export async function receiptImageBlob({ source } = {}) {
  if (!source) throw new ReceiptImageGenerationError('RECEIPT_NOT_READY');
  let blob;
  try {
    const { domToBlob } = await import('modern-screenshot');
    blob = await domToBlob(source, { scale: 2, backgroundColor: '#ffffff', type: 'image/png', font: false });
  } catch (error) {
    throw new ReceiptImageGenerationError('RENDER_FAILED', error);
  }
  if (!blob || blob.type !== 'image/png') throw new ReceiptImageGenerationError('PNG_ENCODING_FAILED');
  return blob;
}
