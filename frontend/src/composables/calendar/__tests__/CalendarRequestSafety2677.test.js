import { afterEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import { useCalendarLeaveExtra } from '../useCalendarLeaveExtra.js';
const scopes = [];
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; };
const response = body => ({ ok: true, json: async () => body });
const check = { can_add: true, is_ended: false, conflict_type: 'none' };
const plan = { policy: 'KEEP_FUTURE_DATES_APPEND_TAIL', leave_session_date: '2026-06-10', future_dates_unchanged: true, moves: [], vacated: [], append: '2026-06-17', extended_end_date: '2026-06-17', next_billable_session: null };
const settle = async () => { for (let i=0; i<8; i++) await nextTick(); };
function setup() {
 const deps = { supabase: { from: vi.fn(() => ({ insert: vi.fn().mockResolvedValue({}) })) }, branchId: ref(1), showModal: ref(true), modalForm: ref({ student_id: 10, subject: 'Math', teacher_id: 5, day_of_week: 3, start_time: '16:00', end_time: '18:00', duration_hours: 2, class_type: 'one_on_one', action_date: '2026-06-10' }), editingCourseId: ref(99), contextMenu: ref({}), loadCourses: vi.fn().mockResolvedValue(), getToken: vi.fn().mockResolvedValue('synthetic-token'), allStudents: ref([{ id: 10, name: 'Synthetic' }]), getSubjectLabel: x=>x, toastRef: ref({show: vi.fn()}) };
 const scope=effectScope(); scopes.push(scope); const api=scope.run(()=>useCalendarLeaveExtra(deps));
 vi.stubGlobal('alert',vi.fn());vi.stubGlobal('fetch',vi.fn().mockImplementation(async url=>response(url.includes('/check')?check:plan)));
 localStorage.setItem('alltrue_session',JSON.stringify({access_token:'synthetic-token'}));
 return {deps,api};
}
afterEach(()=>{scopes.splice(0).forEach(s=>s.stop());vi.unstubAllGlobals();localStorage.clear();});
describe('#2677 complete request ownership',()=>{
 it('blocks a second leave submit before the first response',async()=>{
  const {api}=setup();api.openLeaveModal();await settle();fetch.mockClear();const held=deferred();fetch.mockImplementation(()=>held.promise);
  const one=api.submitLeave();const two=api.submitLeave();await settle();expect(fetch).toHaveBeenCalledTimes(1);held.resolve(response({}));await Promise.all([one,two]);
 });
 it('extra check is invalidated by duration, teacher, student, subject, class, approval or course changes',async()=>{
  const {api,deps}=setup();api.openExtraLesson();await settle();
  for(const [key,value] of [['duration_hours',1],['auto_approve',true]]) {
   api.extraForm.value[key]=value;expect(api.extraSessionReady.value).toBe(false);await settle();expect(api.extraSessionReady.value).toBe(true);
  }
  for(const [key,value] of [['teacher_id',6],['student_id',11],['subject','Eng'],['class_type','one_on_two']]) {
   api.openExtraLesson();await settle();api.extraForm.value[key]=value;expect(api.extraSessionReady.value).toBe(false);await settle();expect(api.extraSessionReady.value).toBe(false);
  }
  api.openExtraLesson();await settle();deps.editingCourseId.value=100;expect(api.extraSessionReady.value).toBe(false);await settle();expect(api.extraSessionReady.value).toBe(false);
 });
 it('does not send an old check or mutated payload after token resolves',async()=>{
  const {api,deps}=setup();api.openExtraLesson();await settle();fetch.mockClear();const token=deferred();deps.getToken.mockImplementation(()=>token.promise);
  const submit=api.submitExtraLesson();api.extraForm.value.duration_hours=1;token.resolve('synthetic-token');await submit;await settle();
  expect(fetch.mock.calls.filter(([u])=>u.endsWith('/add-session'))).toHaveLength(0);
 });
 it('pending extra prevents close, reopen and duplicate submit; success keeps captured target',async()=>{
  const {api,deps}=setup();api.openExtraLesson();await settle();const held=deferred();fetch.mockClear();fetch.mockImplementation(()=>held.promise);
  const one=api.submitExtraLesson();const two=api.submitExtraLesson();await settle();api.closeExtraModal();deps.editingCourseId.value=100;api.openExtraLesson();
  expect(api.showExtraModal.value).toBe(true);expect(fetch.mock.calls.filter(([u])=>u.endsWith('/add-session'))).toHaveLength(1);
  const [url,opt]=fetch.mock.calls.find(([u])=>u.endsWith('/add-session'));expect(url).toContain('/99/');expect(JSON.parse(opt.body).duration_minutes).toBe(120);
  held.resolve(response({message:'ok'}));await Promise.all([one,two]);expect(api.extraSubmitting.value).toBe(false);expect(deps.loadCourses).toHaveBeenCalledTimes(1);
 });
 it('HTTP200 empty or malformed preview never unlocks leave',async()=>{
  const {api}=setup();fetch.mockResolvedValue(response({}));api.openLeaveModal();await settle();expect(api.leavePreviewReady.value).toBe(false);
  for(const bad of [{...plan,moves:{}},{...plan,append:0},{...plan,leave_session_date:'2026-06-11'},{...plan,future_dates_unchanged:false}]) {fetch.mockResolvedValue(response(bad));await api.refreshLeaveCascadePreview();expect(api.leavePreviewReady.value).toBe(false);}
 });
 it('leave tuple changes invalidate preview and closed old response cannot populate reopened modal',async()=>{
  const {api}=setup();api.openLeaveModal();await settle();api.leaveForm.value.teacher_id=6;expect(api.leavePreviewReady.value).toBe(false);await settle();
  const old=deferred();fetch.mockImplementationOnce(()=>old.promise);const request=api.refreshLeaveCascadePreview();await settle();api.closeLeaveModal();api.openLeaveModal();await settle();
  old.resolve(response({...plan,append:'2026-12-31'}));await request;await settle();expect(api.leaveCascadePlan.value.append).not.toBe('2026-12-31');
 });
 it('explicit zero undo window does not create undo toast',async()=>{
  const {api,deps}=setup();api.openLeaveModal();await settle();fetch.mockResolvedValue(response({undo:{schedule_id:41,undo_window_seconds:0},leave_mode:'count_append_tail'}));await api.submitLeave();expect(deps.toastRef.value.show).not.toHaveBeenCalled();
 });
 it('monthly preview follows authoritative fields despite stale modal payment_type',async()=>{
  const {api,deps}=setup();deps.modalForm.value.payment_type='session';fetch.mockResolvedValue(response({...plan,append:null,extended_end_date:null,contract_end_date:'2026-06-30'}));api.openLeaveModal();await settle();expect(api.leavePreviewReady.value).toBe(true);expect(api.leaveImpactPreview.value.items.join(' ')).not.toContain('尾端補');
 });
 it('extra malformed check and network error cannot submit and permit retry',async()=>{
  const {api}=setup();fetch.mockResolvedValue(response({can_add:'true',is_ended:false}));api.openExtraLesson();await settle();expect(api.extraSessionReady.value).toBe(false);
  fetch.mockRejectedValue(new Error('network'));await api.refreshExtraSessionCheck();expect(api.extraSessionReady.value).toBe(false);expect(api.extraSessionChecking.value).toBe(false);
  fetch.mockResolvedValue(response(check));await api.refreshExtraSessionCheck();expect(api.extraSessionReady.value).toBe(true);
 });
 it('old leave failure cannot pollute changed target',async()=>{
  const {api}=setup();api.openLeaveModal();await settle();const held=deferred();fetch.mockImplementation(()=>held.promise);
  const one=api.submitLeave();await settle();api.leaveForm.value.student_id=11;held.resolve({ok:false,json:async()=>({message:'old failure'})});await one;
  expect(api.leaveSubmitError.value).toBe('');expect(api.leaveSubmitting.value).toBe(false);
 });
 it('old extra failure or exception cannot alert a changed or reopened target',async()=>{
  const {api,deps}=setup();api.openExtraLesson();await settle();const held=deferred();fetch.mockImplementation(()=>held.promise);alert.mockClear();
  const one=api.submitExtraLesson();await settle();deps.editingCourseId.value=100;held.resolve({ok:false,json:async()=>({message:'old failure'})});await one;
  expect(alert).not.toHaveBeenCalled();expect(api.extraSubmitting.value).toBe(false);
 });
});
