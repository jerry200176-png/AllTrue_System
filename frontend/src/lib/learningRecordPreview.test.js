import assert from 'node:assert/strict';
import { buildLearningRecordPreview } from './learningRecordPreview.js';

const preview = buildLearningRecordPreview({
  HomeworkStatus: 'partial',
  QuizScore: '92',
  Performance: 'good',
  Progress: '完成分數與比例的應用題',
  NextHomework: '課本第 12 頁',
  Comment: '今天能主動說明解題步驟。',
});

assert.deepEqual(preview.signals, [
  { label: '作業', value: '部分完成' },
  { label: '週考', value: '92' },
  { label: '上課狀況', value: '良好' },
]);
assert.deepEqual(preview.details, [
  { label: '授課進度', value: '完成分數與比例的應用題' },
  { label: '本次作業範圍', value: '課本第 12 頁' },
  { label: '學習進度與家長溝通', value: '今天能主動說明解題步驟。' },
]);
assert.equal(preview.hasContent, true);
assert.equal(buildLearningRecordPreview({}).hasContent, false);
