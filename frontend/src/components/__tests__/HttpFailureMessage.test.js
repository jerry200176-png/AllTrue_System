import { describe, it, expect } from 'vitest';
import { httpFailureMessage } from '../../lib/humanizeApiErrorMessage.js';

// in-app #375 family: a rejected request must name the reason or the HTTP status,
// never "網路錯誤" / "未知錯誤".
describe('httpFailureMessage', () => {
  it('prefers the first field error', () => {
    expect(httpFailureMessage(422, { errors: { x: ['日期不可早於合約開始日'] }, message: 'The given data was invalid.' }))
      .toBe('日期不可早於合約開始日');
  });
  it('falls back to the server message', () => {
    expect(httpFailureMessage(409, { message: '帳單已作廢' })).toBe('帳單已作廢');
  });
  it('names the HTTP status when the body has no reason (HTML 502, empty body)', () => {
    expect(httpFailureMessage(502, {})).toContain('HTTP 502');
    expect(httpFailureMessage(500, null)).toContain('HTTP 500');
  });
});
