import assert from 'node:assert';
import { rankKeyForXp, rankTierProgress, xpRemainingToNext } from './engagementRankProgress.js';

// 長期養成曲線（2026-06-14 重設）：teacher private_first=50、staff private_first=70
assert.strictEqual(rankKeyForXp(0, 'teacher'), 'private_second');
assert.strictEqual(rankKeyForXp(49, 'teacher'), 'private_second');
assert.strictEqual(rankKeyForXp(50, 'teacher'), 'private_first');
assert.strictEqual(rankKeyForXp(12500, 'teacher'), 'major_general'); // 12300–15299

assert.strictEqual(rankKeyForXp(0, 'staff'), 'private_second');
assert.strictEqual(rankKeyForXp(69, 'staff'), 'private_second');
assert.strictEqual(rankKeyForXp(70, 'staff'), 'private_first');

const p0 = rankTierProgress(0, 'teacher');
assert.strictEqual(p0.pct, 0);
assert.strictEqual(p0.xpAtNext, 50);
assert.strictEqual(p0.isMax, false);

const pMid = rankTierProgress(12, 'teacher');
assert.ok(pMid.pct > 0 && pMid.pct < 100);

const pMax = rankTierProgress(25000, 'teacher'); // > general_first_class (23500)
assert.strictEqual(pMax.isMax, true);
assert.strictEqual(pMax.xpAtNext, null);

assert.strictEqual(xpRemainingToNext(24, 'teacher'), 26); // to private_first at 50
assert.strictEqual(xpRemainingToNext(50, 'teacher'), 80); // to private_specialist at 130

console.log('engagementRankProgress.test.js OK');
