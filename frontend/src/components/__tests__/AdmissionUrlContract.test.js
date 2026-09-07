import { describe, it, expect } from 'vitest';
import {
  buildPublicAdmissionsUrl,
  parsePublicAdmissionsContext,
  matchPresetCampus,
} from '../../lib/admissionsUrl';

describe('Admission URL and campus context contract', () => {
  describe('buildPublicAdmissionsUrl', () => {
    it('returns generic #/admissions when branchId is omitted or null', () => {
      expect(buildPublicAdmissionsUrl()).toBe('#/admissions');
      expect(buildPublicAdmissionsUrl({ branchId: null })).toBe('#/admissions');
      expect(buildPublicAdmissionsUrl({ branchId: '' })).toBe('#/admissions');
      expect(buildPublicAdmissionsUrl({ origin: 'https://daan.lifenet.com.tw', pathname: '/' })).toBe('https://daan.lifenet.com.tw/#/admissions');
    });

    it('generates campus-specific public URL with branch parameter', () => {
      expect(buildPublicAdmissionsUrl({ branchId: 1 })).toBe('#/admissions?branch=1');
      expect(buildPublicAdmissionsUrl({ branchId: 2 })).toBe('#/admissions?branch=2');
      expect(buildPublicAdmissionsUrl({
        origin: 'https://daan.lifenet.com.tw',
        pathname: '/app/',
        branchId: 3,
      })).toBe('https://daan.lifenet.com.tw/app/#/admissions?branch=3');
      expect(buildPublicAdmissionsUrl({ branchId: 'daan' })).toBe('#/admissions?branch=daan');
    });

    it('ensures different branchId produce different and distinct public URLs', () => {
      const url1 = buildPublicAdmissionsUrl({ origin: 'https://app.alltrue.tw', pathname: '/', branchId: 1 });
      const url2 = buildPublicAdmissionsUrl({ origin: 'https://app.alltrue.tw', pathname: '/', branchId: 2 });
      expect(url1).not.toBe(url2);
      expect(url1).toContain('branch=1');
      expect(url2).toContain('branch=2');
    });

    it('does not leak token, JWT, director identity, or session context into the URL', () => {
      const url = buildPublicAdmissionsUrl({
        origin: 'https://app.alltrue.tw',
        pathname: '/',
        branchId: 1,
      });
      expect(url).not.toContain('token');
      expect(url).not.toContain('jwt');
      expect(url).not.toContain('bearer');
      expect(url).not.toContain('director');
      expect(url).not.toContain('user');
      expect(url).toBe('https://app.alltrue.tw/#/admissions?branch=1');
    });
  });

  describe('parsePublicAdmissionsContext', () => {
    it('prioritizes explicit propBranchId if provided', () => {
      const result = parsePublicAdmissionsContext({
        propBranchId: 1,
        hash: '#/admissions?branch=2',
        search: '?branch=3',
      });
      expect(result).toBe(1);
    });

    it('extracts branch or campus_id from hash query string', () => {
      expect(parsePublicAdmissionsContext({ hash: '#/admissions?branch=2' })).toBe(2);
      expect(parsePublicAdmissionsContext({ hash: '#/admissions?campus_id=5' })).toBe(5);
      expect(parsePublicAdmissionsContext({ hash: '#/admissions?branch=daan' })).toBe('daan');
    });

    it('extracts branch or campus_id from window.location.search', () => {
      expect(parsePublicAdmissionsContext({ search: '?branch=7' })).toBe(7);
      expect(parsePublicAdmissionsContext({ search: '?campus_id=8' })).toBe(8);
      expect(parsePublicAdmissionsContext({ search: '?admissions=1&branch=9' })).toBe(9);
    });

    it('returns null when no branch context is present', () => {
      expect(parsePublicAdmissionsContext({ hash: '#/admissions', search: '' })).toBeNull();
      expect(parsePublicAdmissionsContext()).toBeNull();
    });
  });

  describe('matchPresetCampus', () => {
    const branches = [
      { id: 1, name: '大安分校', code: 'daan' },
      { id: 2, name: '信義分校', code: 'xinyi' },
      { id: 3, name: '敦化分校', code: 'dunhua' },
    ];

    it('matches campus by numeric id', () => {
      const matched = matchPresetCampus(branches, 2);
      expect(matched).toEqual({ id: 2, name: '信義分校', code: 'xinyi' });
    });

    it('matches campus by string id', () => {
      const matched = matchPresetCampus(branches, '3');
      expect(matched).toEqual({ id: 3, name: '敦化分校', code: 'dunhua' });
    });

    it('matches campus by code case-insensitively', () => {
      const matched = matchPresetCampus(branches, 'DAAN');
      expect(matched).toEqual({ id: 1, name: '大安分校', code: 'daan' });
    });

    it('returns null when branch does not exist in branches list', () => {
      expect(matchPresetCampus(branches, 999)).toBeNull();
      expect(matchPresetCampus(branches, 'unknown')).toBeNull();
      expect(matchPresetCampus([], 1)).toBeNull();
      expect(matchPresetCampus(null, 1)).toBeNull();
    });
  });
});
