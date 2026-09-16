import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseTrueFitRoute, buildTrueFitPrepUrl } from '../../lib/truefitRoute.js';
import { isTrueFitHost } from '../../lib/truefitHost.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const appSource = readFileSync(resolve(__dirname, '../../App.vue'), 'utf8');
const workspaceSource = readFileSync(resolve(__dirname, '../../pages/TrueFitWorkspacePage.vue'), 'utf8');
const navSource = readFileSync(resolve(__dirname, '../../lib/navigationRegistry.js'), 'utf8');

describe('TrueFit Slice 0 shell contract', () => {
  it('detects truefit subdomain host without path changes', () => {
    expect(isTrueFitHost('truefit.daan.lifenet.com.tw')).toBe(true);
    expect(isTrueFitHost('daan.lifenet.com.tw')).toBe(false);
  });

  it('parses workspace and prep hash routes', () => {
    expect(parseTrueFitRoute({ hash: '#/truefit', search: '', hostname: 'localhost' })).toEqual({ view: 'workspace' });
    expect(parseTrueFitRoute({ hash: '#/truefit/prep/42', search: '', hostname: 'localhost' })).toEqual({
      view: 'prep',
      classSessionId: 42,
    });
    expect(parseTrueFitRoute({ hash: '', search: '?truefit=1', hostname: 'localhost' })).toEqual({ view: 'workspace' });
  });

  it('builds prep deep links for session cards', () => {
    expect(buildTrueFitPrepUrl(901)).toBe('#/truefit/prep/901');
  });

  it('registers teacher nav entry behind truefitEnabled option', () => {
    expect(navSource).toContain("page: 'truefit'");
    expect(navSource).toContain('truefitEnabled');
  });

  it('renders standalone TrueFit shell separate from admin layout', () => {
    expect(appSource).toContain('standalone-truefit-shell');
    expect(appSource).toContain('isStandaloneTrueFit');
    expect(appSource).toContain("page === 'truefit'");
  });

  it('shows required session card fields and CTA copy', () => {
    expect(workspaceSource).toContain('準備課程');
    expect(workspaceSource).toContain('student_name');
    expect(workspaceSource).toContain('subject_name');
    expect(workspaceSource).toContain('campus_name');
    expect(workspaceSource).toContain('start_time');
  });
});
