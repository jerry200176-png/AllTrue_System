import assert from 'node:assert/strict';
import {
  resolveLearningRecordViewDefaults,
  resolveLearningRecordViewMode,
  resolveLearningRecordViewModeOptions,
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

// In-app #417: the chooser must only offer modes that take effect at that width.
assert.deepEqual(resolveLearningRecordViewModeOptions({ viewportWidth: 390 }), ['card']);
assert.deepEqual(resolveLearningRecordViewModeOptions({ viewportWidth: 640 }), ['card']);
assert.deepEqual(resolveLearningRecordViewModeOptions({ viewportWidth: 641 }), ['table', 'card']);
for (const viewportWidth of [320, 390, 640, 641, 768, 1440]) {
  for (const mode of resolveLearningRecordViewModeOptions({ viewportWidth })) {
    assert.equal(resolveLearningRecordViewMode({ viewportWidth, viewMode: mode }), mode);
  }
}
