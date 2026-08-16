import assert from 'node:assert/strict';
import { humanizeApiErrorMessage } from './humanizeApiErrorMessage.js';

assert.equal(humanizeApiErrorMessage('Forbidden'), '沒有權限執行此操作');
assert.equal(humanizeApiErrorMessage('Invalid payment_method'), '繳費方式無效');
assert.equal(humanizeApiErrorMessage('branch_id required'), '請指定分校');
assert.equal(humanizeApiErrorMessage(''), '操作失敗，請稍後再試');
assert.equal(humanizeApiErrorMessage('帳單已作廢'), '帳單已作廢');
assert.equal(humanizeApiErrorMessage('Something weird happened'), 'Something weird happened');

console.log('humanizeApiErrorMessage.test.js: ok');
