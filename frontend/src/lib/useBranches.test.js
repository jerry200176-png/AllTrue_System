import assert from 'node:assert/strict';
import { campusIdFrom, getBranchName, branches } from './useBranches.js';

assert.equal(campusIdFrom(0), null);
assert.equal(campusIdFrom('0'), null);
assert.equal(campusIdFrom(null), null);
assert.equal(campusIdFrom(undefined), null);
assert.equal(campusIdFrom(Number.NaN), null);
assert.equal(campusIdFrom(16), 16);
assert.equal(campusIdFrom('15'), 15);

const previous = branches.value.slice();
try {
  branches.value = [];
  assert.equal(getBranchName(0), '未設定');
  assert.equal(getBranchName(16), '分校載入中');
  assert.equal(getBranchName(0).includes('Branch'), false);

  branches.value = [{ id: 16, name: '木柵分校', code: 'muzha' }];
  assert.equal(getBranchName('16'), '木柵分校');
  assert.equal(getBranchName(16), '木柵分校');
  assert.equal(getBranchName(9), '未設定');
  assert.equal(getBranchName(0), '未設定');
  assert.equal(JSON.stringify(getBranchName(0)).includes('Branch'), false);
} finally {
  branches.value = previous;
}

console.log('useBranches campus label tests passed');
