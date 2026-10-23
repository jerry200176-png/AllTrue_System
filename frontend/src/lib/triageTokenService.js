// Super-admin management of read-only AI triage tokens (backend: TriageTokenController).
import { adminKeyRequest, BAD_RESPONSE_MESSAGE } from './adminKeyApi';

const request = (token, method, path, body) => adminKeyRequest('triage-tokens', token, method, path, body);

export async function listTriageTokens(token) {
  const json = await request(token, 'GET', '');
  if (!Array.isArray(json.data)) throw new Error(BAD_RESPONSE_MESSAGE);
  return json;
}

export async function createTriageToken(token, name) {
  const json = await request(token, 'POST', '', { name });
  if (typeof json.token !== 'string' || json.token === '') {
    throw new Error('金鑰可能已建立，但沒有收到內容。請重新整理，撤銷名稱相同的金鑰後再建立一次。');
  }
  return json;
}

export function revokeTriageToken(token, id) {
  return request(token, 'DELETE', `/${encodeURIComponent(id)}`);
}
