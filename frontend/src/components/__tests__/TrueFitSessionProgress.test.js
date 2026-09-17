import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  trueFitSessionProgressRef,
  trueFitProgressLookupKey,
} from '../../lib/truefitApi.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const workspaceSource = readFileSync(resolve(__dirname, '../../pages/TrueFitWorkspacePage.vue'), 'utf8');
const apiSource = readFileSync(resolve(__dirname, '../../lib/truefitApi.js'), 'utf8');

describe('TrueFit S6-01 session progress strip', () => {
  it('exposes aggregate progress client helpers', () => {
    expect(apiSource).toContain('fetchTrueFitSessionProgress');
    expect(apiSource).toContain('/api/v1/truefit/session-progress');
    expect(trueFitSessionProgressRef({ class_session_id: 9 })).toEqual({ class_session_id: 9 });
    expect(trueFitSessionProgressRef({
      student_class_id: 55,
      session_date: '2026-09-17',
      start_time: '16:30:00',
    })).toEqual({
      student_class_id: 55,
      session_date: '2026-09-17',
      start_time: '16:30',
    });
    expect(trueFitProgressLookupKey({ class_session_id: 9 })).toBe('m:9');
    expect(trueFitProgressLookupKey({
      student_class_id: 55,
      session_date: '2026-09-17',
      start_time: '16:30',
    })).toBe('p:55|2026-09-17|16:30');
  });

  it('workspace loads aggregate progress and keeps loading/error honest', () => {
    expect(workspaceSource).toContain('fetchTrueFitSessionProgress');
    expect(workspaceSource).toContain('data-testid="truefit-session-progress"');
    expect(workspaceSource).toContain('進度載入中');
    expect(workspaceSource).toContain('進度暫不可用');
    expect(workspaceSource).toContain("progressState.value = 'loading'");
    // Failures must not replace prior progress with empty markers.
    expect(workspaceSource).toContain('progressByKey.value = next');
    expect(workspaceSource).toContain("progressState.value = 'error'");
    expect(workspaceSource).toMatch(/catch \(e\) \{[\s\S]*progressState\.value = 'error'/);
    expect(workspaceSource).not.toMatch(/catch \(e\) \{[\s\S]*progressByKey\.value = \{\}/);
  });

  it('renders saved/empty stage markers only after ready progress', () => {
    expect(workspaceSource).toContain("data-saved=");
    expect(workspaceSource).toContain("'prep'");
    expect(workspaceSource).toContain("'observation'");
    expect(workspaceSource).toContain("'diagnosis'");
    expect(workspaceSource).toContain("'remediation'");
    expect(workspaceSource).toContain("'mastery'");
    expect(workspaceSource).toContain('已存');
    expect(workspaceSource).toContain('未存');
    expect(workspaceSource).toContain("if (progressState.value === 'loading') return null");
  });
});
