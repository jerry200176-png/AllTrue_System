import assert from 'node:assert';
import { rankKeyForXp, rankTierProgress, xpRemainingToNext } from './engagementRankProgress.js';

assert.strictEqual(rankKeyForXp(0, 'teacher'), 'private_second');
assert.strictEqual(rankKeyForXp(24, 'teacher'), 'private_second');
assert.strictEqual(rankKeyForXp(25, 'teacher'), 'private_first');
assert.strictEqual(rankKeyForXp(1620, 'teacher'), 'major_general'); // 1425–1649

assert.strictEqual(rankKeyForXp(0, 'staff'), 'private_second');
assert.strictEqual(rankKeyForXp(39, 'staff'), 'private_second');
assert.strictEqual(rankKeyForXp(40, 'staff'), 'private_first');

const p0 = rankTierProgress(0, 'teacher');
assert.strictEqual(p0.pct, 0);
assert.strictEqual(p0.xpAtNext, 25);
assert.strictEqual(p0.isMax, false);

const pMid = rankTierProgress(12, 'teacher');
assert.ok(pMid.pct > 0 && pMid.pct < 100);

const pMax = rankTierProgress(5000, 'teacher');
assert.strictEqual(pMax.isMax, true);
assert.strictEqual(pMax.xpAtNext, null);

assert.strictEqual(xpRemainingToNext(24, 'teacher'), 1);
assert.strictEqual(xpRemainingToNext(25, 'teacher'), 30); // to private_specialist at 55

console.log('engagementRankProgress.test.js OK');
