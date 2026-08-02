import assert from 'node:assert/strict';
import { buildLearningReviewQueue, learningReviewPreview } from './learningReviewQueue.js';

const queue = buildLearningReviewQueue([
  { id: 1, Status: 'approved', SessionDate: '2026-08-02', StartTime: '10:00' },
  { id: 2, Status: 'pending', SessionDate: '2026-08-01', StartTime: '10:00' },
  { id: 3, Status: 'changes_requested', SessionDate: '2026-07-31', StartTime: '10:00' },
  { id: 4, Status: 'pending', SessionDate: '2026-08-02', StartTime: '09:00' },
]);

assert.deepEqual(queue.map((record) => record.id), [3, 4, 2]);
assert.equal(learningReviewPreview({ Progress: '完成比例應用題' }), '完成比例應用題');
assert.equal(learningReviewPreview({ body: '舊欄位內容' }), '舊欄位內容');
assert.equal(learningReviewPreview({}), '尚未填寫授課進度');
