// Super-admin management of the POP in-app agent key (backend: PopInappApiClientController, ADR-POP-015 §3).
import { adminKeyRequest, BAD_RESPONSE_MESSAGE } from './adminKeyApi';

const request = (token, method, path, body) => adminKeyRequest('pop-inapp-agent-clients', token, method, path, body);

export async function listPopInappAgentKeys(token) {
  const json = await request(token, 'GET', '');
  if (!Array.isArray(json.data)) throw new Error(BAD_RESPONSE_MESSAGE);
  return json;
}

export async function issuePopInappAgentKey(token, name, campusIds) {
  // The backend also refuses an empty list; never send one.
  if (!Array.isArray(campusIds) || campusIds.length === 0) throw new Error('請至少勾選一間分校。');
  const json = await request(token, 'POST', '', { name, campus_ids: campusIds });
  if (typeof json.key !== 'string' || json.key === '' || !json.record || typeof json.record !== 'object') {
    throw new Error('金鑰可能已建立，但沒有收到內容。請重新整理，撤銷名稱相同的金鑰後再建立一次。');
  }
  return json;
}

export function revokePopInappAgentKey(token, id) {
  return request(token, 'DELETE', `/${encodeURIComponent(id)}`);
}
