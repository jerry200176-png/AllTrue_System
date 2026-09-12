import assert from 'node:assert/strict';
import {
  resolveLearningRecordViewDefaults,
  resolveLearningRecordViewMode,
} from './learningRecordViewPreferences.js';

assert.deepEqual(
  resolveLearningRecordViewDefaults({ viewportWidth: 1280, savedViewMode: null }),
  { viewMode: 'table', showContentPreview: false },
);
assert.deepEqual(
  resolveLearningRecordViewDefaults({ viewportWidth: 390, savedViewMode: 'table' }),
  { viewMode: 'table', showContentPreview: false },
);
assert.equal(resolveLearningRecordViewMode({ viewportWidth: 390, viewMode: 'table' }), 'card');
assert.equal(resolveLearningRecordViewMode({ viewportWidth: 768, viewMode: 'table' }), 'table');
