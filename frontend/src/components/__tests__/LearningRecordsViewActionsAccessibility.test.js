import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  resolveLearningRecordViewMode,
  resolveLearningRecordViewModeOptions,
} from '../../lib/learningRecordViewPreferences.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/LearningRecordsPage.vue'), 'utf8');

describe('LearningRecords view and action accessibility', () => {
  it('announces the active list/card view and content preview state', () => {
    expect(source).toContain("v-if=\"!isNarrowViewport || !isTeacher\" class=\"lr-view-toggle\" role=\"group\" aria-label=\"切換列表或卡片\"");
    expect(source).toContain(":aria-pressed=\"effectiveViewMode === 'table' ? 'true' : 'false'\"");
    expect(source).toContain(":aria-pressed=\"effectiveViewMode === 'card' ? 'true' : 'false'\"");
    expect(source).toContain(":aria-pressed=\"showContentPreview ? 'true' : 'false'\"");
    expect(source).toContain("showContentPreview ? '收合完整評量' : '顯示完整評量'");
  });

  it('only offers view modes that take effect at the current width (in-app #417)', () => {
    expect(source).toMatch(/v-if="availableViewModes\.includes\('table'\)"\s+type="button"\s+:class="\['lr-view-btn', \{ active: effectiveViewMode === 'table' \}\]"/);
    expect(resolveLearningRecordViewModeOptions({ viewportWidth: 390 })).toEqual(['card']);
    expect(resolveLearningRecordViewModeOptions({ viewportWidth: 1440 })).toEqual(['table', 'card']);
    for (const viewportWidth of [390, 640, 641, 1440]) {
      for (const mode of resolveLearningRecordViewModeOptions({ viewportWidth })) {
        expect(resolveLearningRecordViewMode({ viewportWidth, viewMode: mode })).toBe(mode);
      }
    }
  });

  it('gives every native LearningRecords button an explicit non-submit type', () => {
    const buttons = source.match(/<button\b[\s\S]*?<\/button>/g) || [];
    expect(buttons.length).toBeGreaterThan(0);
    expect(buttons.filter((button) => !/\btype=\"(?:button|submit)\"/.test(button))).toEqual([]);
  });
});
