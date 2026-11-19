import assert from 'node:assert';
import { rankTierProgress, xpRemainingToNext } from './engagementRankProgress.js';

// Payload shape from UserEngagementPresenter::forMe (TD-017: the server owns the rank table).
const start = { xp_total: 0, xp_at_current: 0, next_rank_xp: 50 };
assert.deepStrictEqual(rankTierProgress(start), { pct: 0, xpAtCurrent: 0, xpAtNext: 50, isMax: false });
assert.strictEqual(xpRemainingToNext(start), 50);

const mid = { xp_total: 500, xp_at_current: 450, next_rank_xp: 700 };
assert.strictEqual(rankTierProgress(mid).pct, 20);
assert.strictEqual(xpRemainingToNext(mid), 200);

const top = { xp_total: 25000, xp_at_current: 23500, next_rank_xp: null };
assert.deepStrictEqual(rankTierProgress(top), { pct: 100, xpAtCurrent: 23500, xpAtNext: null, isMax: true });
assert.strictEqual(xpRemainingToNext(top), 0);

console.log('engagementRankProgress.test.js OK');
