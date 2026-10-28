// #3868: super-admin 用編號找合約 (backend: StudentClassLookupController). Read-only, at most 50 ids per request.
import { adminKeyRequest, BAD_RESPONSE_MESSAGE } from './adminKeyApi';

export const MAX_CONTRACT_IDS = 50;

/** Comma, space, newline (and the full-width commas of a Chinese keyboard) separate ids. -> { ids, invalid } */
export function parseContractIds(text) {
  const parts = String(text ?? '').split(/[\s,，、;；]+/).filter(Boolean);
  const invalid = parts.filter((p) => !/^[1-9]\d{0,9}$/.test(p));
  return { ids: [...new Set(parts.filter((p) => !invalid.includes(p)).map(Number))], invalid: [...new Set(invalid)] };
}

export async function lookupContracts(token, ids) {
  const json = await adminKeyRequest('student-classes', token, 'GET', `/lookup?ids=${ids.join(',')}`);
  if (!Array.isArray(json.data)) throw new Error(BAD_RESPONSE_MESSAGE);
  return json.data;
}
