import { beforeEach, describe, expect, it } from 'vitest';
import { applyDraftToForm, clearDraft, listDrafts, loadDraft, saveDraft, removeDraftByKey, clearAllDraftsByTeacher } from '../../lib/learningRecordDrafts';

const form = { HomeworkStatus: 'incomplete', Comment: '隔離內容' };
const meta = { studentName: '測試學生', sessionDate: '2026-09-11' };

describe('learning record draft scope', () => {
  beforeEach(() => localStorage.clear());
  it('restores intentionally cleared content without replacing identity', () => {
    const scope = { teacherId: 7, branchId: 1, classSessionId: 101 };
    expect(saveDraft({ ...scope, form: { id: 33, Comment: '' } }).saved).toBe(true);
    const restored = { id: 33, TeacherID: 7, Comment: 'server content' };
    applyDraftToForm(loadDraft(scope).draft, restored);
    expect(restored).toMatchObject({ id: 33, TeacherID: 7, Comment: '' });
  });
  it('isolates actor, branch and class session', () => {
    saveDraft({ teacherId: 7, branchId: 1, classSessionId: 101, form, meta });
    saveDraft({ teacherId: 7, branchId: 2, classSessionId: 101, form: { ...form, Comment: '另一分校' }, meta });
    expect(loadDraft({ teacherId: 7, branchId: 1, classSessionId: 101 }).draft.Comment).toBe('隔離內容');
    expect(loadDraft({ teacherId: 7, branchId: 2, classSessionId: 101 }).draft.Comment).toBe('另一分校');
    expect(loadDraft({ teacherId: 8, branchId: 1, classSessionId: 101 }).draft).toBeNull();
    expect(loadDraft({ teacherId: 7, branchId: 1, classSessionId: 102 }).draft).toBeNull();
    expect(listDrafts(7)).toEqual([]);
    const otherKey = listDrafts(7, 2)[0].key;
    removeDraftByKey(otherKey, { teacherId: 7, branchId: 1 });
    expect(loadDraft({ teacherId: 7, branchId: 2, classSessionId: 101 }).draft).not.toBeNull();
    expect(listDrafts(7, 1)).toHaveLength(1);
    clearDraft({ teacherId: 7, branchId: 1, classSessionId: 101 });
    expect(loadDraft({ teacherId: 7, branchId: 2, classSessionId: 101 }).draft).not.toBeNull();
    clearAllDraftsByTeacher(7);
    expect(listDrafts(7, 2)).toEqual([]);
  });
  it('does not guess a legacy/new-record scope without class session', () => {
    expect(saveDraft({ teacherId: 7, branchId: 1, classSessionId: 0, form, meta })).toMatchObject({ saved: false, error: 'no_key' });
    localStorage.setItem('lr_draft_7_55_2026-09-11', JSON.stringify({ Comment: 'legacy' }));
    expect(loadDraft({ teacherId: 7, branchId: 1, classSessionId: 0 }).draft).toBeNull();
    expect(localStorage.getItem('lr_draft_7_55_2026-09-11')).not.toBeNull();
    expect(saveDraft({teacherId:7,classSessionId:101,form}).saved).toBe(false);
    localStorage.setItem('lr_draft_v1_7_101', JSON.stringify({Comment:'legacy'}));
    expect(loadDraft({teacherId:7,branchId:1,classSessionId:101}).draft).toBeNull();
    expect(localStorage.getItem('lr_draft_v1_7_101')).not.toBeNull();
  });
});
