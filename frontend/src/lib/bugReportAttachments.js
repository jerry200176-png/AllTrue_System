export const BUG_ATTACHMENT_TYPES = Object.freeze([
  'image/jpeg',
  'image/png',
  'image/gif',
  'image/webp',
]);

export const MAX_BUG_ATTACHMENTS = 5;
export const MAX_BUG_ATTACHMENT_BYTES = 5 * 1024 * 1024;

const EXTENSION_BY_TYPE = Object.freeze({
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/gif': 'gif',
  'image/webp': 'webp',
});

function displayName(file) {
  return String(file?.name || '').trim() || '圖片';
}

export function isAcceptedBugAttachment(file) {
  return BUG_ATTACHMENT_TYPES.includes(String(file?.type || '').toLowerCase());
}

/**
 * Clipboard screenshots are sometimes exposed as a nameless Blob/File.
 * A generated name makes the attachment understandable to triage staff.
 */
export function namePastedImage(file, timestamp = Date.now()) {
  if (!file) return file;

  const name = String(file.name || '').trim();
  if (name && name.toLowerCase() !== 'blob') return file;

  const type = String(file.type || '').toLowerCase();
  const extension = EXTENSION_BY_TYPE[type] || 'png';
  if (typeof File === 'undefined') return file;

  return new File([file], `pasted-image-${timestamp}.${extension}`, {
    type: type || 'image/png',
    lastModified: Number(timestamp),
  });
}

/**
 * Read image files from a native drop or paste event. Text clipboard content
 * deliberately returns an empty list so the textarea keeps its normal paste.
 */
export function extractTransferFiles(dataTransfer) {
  if (!dataTransfer) return [];

  const items = Array.from(dataTransfer.items || []);
  const itemFiles = items
    .filter((item) => item?.kind === 'file' && typeof item.getAsFile === 'function')
    .map((item) => item.getAsFile())
    .filter(Boolean);

  if (itemFiles.length) return itemFiles;

  return Array.from(dataTransfer.files || []).filter(Boolean);
}

export function extractImageFiles(dataTransfer) {
  return extractTransferFiles(dataTransfer)
    .filter((file) => String(file?.type || '').toLowerCase().startsWith('image/'));
}

/**
 * Client-side feedback only; the Laravel validator remains authoritative.
 * Valid files are kept when one item in a batch is invalid, matching mature
 * uploaders' accepted/rejected split instead of rejecting the whole batch.
 */
export function validateBugAttachments(files, existingCount = 0) {
  const accepted = [];
  const errors = [];
  let remaining = Math.max(0, MAX_BUG_ATTACHMENTS - Number(existingCount || 0));

  for (const file of Array.from(files || [])) {
    if (remaining <= 0) {
      errors.push(`最多附加 ${MAX_BUG_ATTACHMENTS} 張圖片`);
      break;
    }

    if (!isAcceptedBugAttachment(file)) {
      errors.push(`「${displayName(file)}」不是支援的圖片格式`);
      continue;
    }

    if (Number(file.size || 0) > MAX_BUG_ATTACHMENT_BYTES) {
      errors.push(`「${displayName(file)}」超過 5MB，請壓縮或換一張`);
      continue;
    }

    accepted.push(file);
    remaining -= 1;
  }

  return { accepted, errors };
}
