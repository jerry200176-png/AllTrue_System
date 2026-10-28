import { describe, it, expect, vi } from 'vitest';
import { previewSuccessorLeave, applySuccessorLeave, successorImpactItems, leaveWithRenewalShift } from '../../lib/leaveSuccessorShift.js';
import actualPreview from './fixtures/leaveSuccessorPreview4210.json';
const plan = { applicable: true, apply_enabled: true, eligible: true, leave_session: { id: 11 }, preview_token: 'a'.repeat(64), old_end_date: '2026-12-08', successor_end_date: '2026-12-22', blockers: [], moves: [{ role:'old_tail', session_id:null, from:null, to:{date:'2026-12-08',start:'19:00:00',end:'21:00:00'} }, { role:'successor', session_id: 21, from: {date:'2026-12-08',start:'16:00:00',end:'18:00:00'}, to:{date:'2026-12-15',start:'16:00:00',end:'18:00:00'} }] };
const response = (body, ok=true, status=200) => ({ok,status,json:async()=>body});
describe('in-app410 successor leave API boundary', () => {
 it('accepts an actual synthetic backend preview including new tail and seconds', async () => {
  const request=vi.fn().mockResolvedValue(response(actualPreview));
  const result=await previewSuccessorLeave({role:'director',sessionId:actualPreview.leave_session.id,request});
  expect(result).toEqual(actualPreview);
  expect(successorImpactItems(result)[1]).toContain('原課程新增補堂');
  expect(successorImpactItems(result)).toHaveLength(actualPreview.moves.length+3);
 });
 it('preserves teacher access without calling restricted endpoints', async () => {
  const request=vi.fn(); expect(await previewSuccessorLeave({role:'teacher',sessionId:11,request})).toBeNull(); expect(request).not.toHaveBeenCalled();
 });
 it('keeps a disabled applicable preview blocked and cannot apply or fall through', async () => {
  const request=vi.fn().mockResolvedValue(response({...plan,apply_enabled:false}));
  const disabled=await previewSuccessorLeave({role:'director',sessionId:11,request});
  expect(disabled.applicable).toBe(true);expect(disabled.eligible).toBe(false);
  expect(disabled.blockers.at(-1).message).toContain('尚未開放');
  request.mockClear();await expect(applySuccessorLeave(disabled,{request})).rejects.toThrow();expect(request).not.toHaveBeenCalled();
 });
 it.each([undefined,null,'false'])('rejects unknown or malformed capability %j', async apply_enabled => {
  const request=vi.fn().mockResolvedValue(response({...plan,apply_enabled}));
  await expect(previewSuccessorLeave({role:'director',sessionId:11,request})).rejects.toThrow('預覽資料不完整');
  await expect(applySuccessorLeave({...plan,apply_enabled},{request})).rejects.toThrow();
  expect(request).toHaveBeenCalledOnce();
 });
 it('resolves exactly one materialized course/date/start occurrence', async () => {
  const resolve=vi.fn().mockResolvedValue({items:[{id:11,studentClassId:1,date:'2026-12-01',startTime:'16:00',isProjected:false},{id:12,studentClassId:1,date:'2026-12-01',startTime:'19:00',isProjected:false}]});
  const request=vi.fn().mockResolvedValue(response(plan));
  expect(await previewSuccessorLeave({role:'director',courseId:1,date:'2026-12-01',start:'16:00',request,resolve})).toEqual(plan);
  expect(JSON.parse(request.mock.calls[0][1].body)).toEqual({class_session_id:11});
  await expect(previewSuccessorLeave({role:'director',courseId:1,date:'2026-12-01',start:'',request,resolve})).rejects.toThrow('堂次');
 });
 it('#4216 r: finds an off-half-hour lesson from the rounded calendar time, still only when unique', async () => {
  const request=vi.fn(async()=>({ok:true,json:async()=>({...plan,leave_session:{id:21}})}));
  const resolve=vi.fn().mockResolvedValue({items:[{id:21,studentClassId:1,date:'2026-12-01',startTime:'17:15',isProjected:false}]});
  expect((await previewSuccessorLeave({role:'director',courseId:1,date:'2026-12-01',start:'17:30',request,resolve})).leave_session.id).toBe(21);
  expect(JSON.parse(request.mock.calls[0][1].body)).toEqual({class_session_id:21});
  const two=vi.fn().mockResolvedValue({items:[{id:21,studentClassId:1,date:'2026-12-01',startTime:'17:15',isProjected:false},{id:22,studentClassId:1,date:'2026-12-01',startTime:'17:40',isProjected:false}]});
  await expect(previewSuccessorLeave({role:'director',courseId:1,date:'2026-12-01',start:'17:30',request,resolve:two})).rejects.toThrow('堂次');
 });
 it('falls back only for explicit non-applicability and rejects malformed plans', async () => {
  const opts={role:'director',sessionId:11};
  expect(await previewSuccessorLeave({...opts,request:vi.fn().mockResolvedValue(response({applicable:false}))})).toBeNull();
  for(const bad of [{}, {...plan,preview_token:''}, {...plan,leave_session:{id:12}}, {...plan,moves:[]}, {...plan,blockers:[{message:'blocked'}]}]) await expect(previewSuccessorLeave({...opts,request:vi.fn().mockResolvedValue(response(bad))})).rejects.toThrow();
  await expect(previewSuccessorLeave({...opts,request:vi.fn().mockResolvedValue(response({message:'Forbidden'},false,403))})).rejects.toThrow('Forbidden');
 });
 it('shows blocker reasons and identifiers and refuses blocked apply', async () => {
  const blocked={...plan,eligible:false,blockers:[{message:'歷史紀錄需核對',course_id:1,session_id:11}]};
  expect(successorImpactItems(blocked).join(' ')).toContain('歷史紀錄需核對');
  const request=vi.fn(); await expect(applySuccessorLeave(blocked,{request})).rejects.toThrow(); expect(request).not.toHaveBeenCalled();
 });
 it('applies the exact token and surfaces gate and stale errors', async () => {
  const request=vi.fn().mockResolvedValue(response({applied:true}));await applySuccessorLeave(plan,{request});
  expect(JSON.parse(request.mock.calls[0][1].body)).toEqual({class_session_id:11,preview_token:plan.preview_token});
  for(const [status,message] of [[403,'尚未開放'],[409,'重新預覽']]) {request.mockResolvedValue(response({message},false,status));await expect(applySuccessorLeave(plan,{request})).rejects.toThrow(message);}
 });
});

// #4578: an unmarked lesson (no attendance row yet) is the one a director can actually shift, so the attendance page runs the same pass.
describe('in-app410 leaveWithRenewalShift (attendance page, unmarked lesson)', () => {
 const run = (over = {}) => {
  const request = vi.fn(async path => path.endsWith('/preview') ? response(over.preview ?? plan) : response(over.apply ?? { applied: true }));
  const args = { role: 'director', sessionId: 11, token: 't', request, ask: vi.fn().mockResolvedValue(over.accept ?? true), onBlocked: vi.fn(), ...over.args };
  return { args, request, result: leaveWithRenewalShift(args) };
 };
 const calls = request => request.mock.calls.map(([path]) => path.split('/').pop());
 it('previews, shows the server effects, then applies the previewed token once', async () => {
  const { args, request, result } = run();
  expect(await result).toBe('done');
  expect(calls(request)).toEqual(['preview', 'apply']);
  expect(args.ask.mock.calls[0][0]).toContain('2026-12-15');
  expect(JSON.parse(request.mock.calls[1][1].body)).toEqual({ class_session_id: 11, preview_token: plan.preview_token });
 });
 it('falls back to the ordinary leave when no shift applies, and for teachers without a request', async () => {
  const none = run({ preview: { applicable: false } });
  expect(await none.result).toBe('ordinary'); expect(none.args.ask).not.toHaveBeenCalled();
  const teacher = run({ args: { role: 'teacher' } });
  expect(await teacher.result).toBe('ordinary'); expect(teacher.request).not.toHaveBeenCalled();
 });
 it('stops on a blocked preview, a refused confirmation or a superseded page, and never applies', async () => {
  const blocked = { ...plan, eligible: false, blockers: [{ message: '歷史需核對', course_id: 1, session_id: 11 }] };
  const b = run({ preview: blocked });
  expect(await b.result).toBe('stopped'); expect(b.args.onBlocked).toHaveBeenCalledWith(expect.objectContaining({ blockers: blocked.blockers }));
  const no = run({ accept: false });
  expect(await no.result).toBe('stopped'); expect(calls(no.request)).toEqual(['preview']);
  const gone = run({ args: { owns: () => false } });
  expect(await gone.result).toBe('stopped'); expect(gone.args.ask).not.toHaveBeenCalled(); expect(calls(gone.request)).toEqual(['preview']);
 });
 it('lets a gate or stale-token failure reach the page to report', async () => {
  const request = vi.fn(async path => path.endsWith('/preview') ? response(plan) : response({ message: '課表已變動，請重新預覽後再確認' }, false, 409));
  await expect(leaveWithRenewalShift({ role: 'director', sessionId: 11, token: 't', request, ask: async () => true, onBlocked: vi.fn() })).rejects.toThrow('重新預覽');
 });
});
