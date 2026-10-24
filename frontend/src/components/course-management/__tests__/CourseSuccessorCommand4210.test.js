import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, expect, it, vi } from 'vitest';
import { previewSuccessorLeave, applySuccessorLeave } from '../../../lib/leaveSuccessorShift.js';
import plan from '../../../composables/__tests__/fixtures/leaveSuccessorPreview4210.json';
const source=readFileSync(resolve(__dirname,'../../../pages/CourseManagement.vue'),'utf8');
const submit=source.slice(source.indexOf('async function submitLeave()'),source.indexOf('// ----- Reschedule')).replace('async function submitLeave()', 'return async function submitLeave()');
const preview=source.slice(source.indexOf('async function refreshLeaveCascadePreview()'),source.indexOf('async function openLeave(c)')).replace('async function refreshLeaveCascadePreview()', 'return async function refreshLeaveCascadePreview()');
function setup() {
 const deps={toastRef:{value:{show:vi.fn()}},props:{branchId:1,userRole:'director'},leaveForm:{value:{session_id:plan.leave_session.id,course_id:plan.old_course_id,schedule_date:plan.leave_session.date,start_time:plan.leave_session.start}},leaveRequestKey:{value:'one'},leavePreviewSuccessKey:{value:'one'},leavePreviewVersion:0,leaveCascadePlan:{value:plan},leavePreviewReady:{value:true},leaveSubmitting:{value:false},leaveCascadePlanLoading:{value:false},leavePreviewError:{value:''},isSelectedRetroLeave:{value:false},showLeaveModal:{value:true},leaveCourse:{value:{}},getAccessToken:vi.fn().mockResolvedValue('synthetic'),applySuccessorLeave,previewSuccessorLeave,loadCourses:vi.fn(),authedFetch:vi.fn().mockResolvedValue({ok:false,json:async()=>({message:'ordinary should not write'})})};
 vi.stubGlobal('alert',vi.fn());vi.stubGlobal('fetch',vi.fn().mockResolvedValue({ok:true,json:async()=>({applied:true})}));
 const make=body=>Function(...Object.keys(deps),body)(...Object.values(deps));return {deps,submit:make(submit),preview:make(preview)};
}
afterEach(()=>vi.unstubAllGlobals());
it('in-app410 CourseManagement submits the confirmed token and reloads without ordinary leave or undo',async()=>{
 const {deps,submit}=setup();await submit();expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({class_session_id:plan.leave_session.id,preview_token:plan.preview_token});expect(deps.authedFetch).not.toHaveBeenCalled();expect(deps.showLeaveModal.value).toBe(false);expect(deps.loadCourses).toHaveBeenCalledOnce();
});
it('in-app410 flag-off invalidates the CourseManagement approval and preserves modal',async()=>{
 const {deps,submit}=setup();fetch.mockResolvedValue({ok:false,status:403,json:async()=>({message:'尚未開放'})});await submit();expect(deps.leaveCascadePlan.value).toBeNull();expect(deps.leavePreviewSuccessKey.value).toBe('');expect(deps.showLeaveModal.value).toBe(true);expect(deps.leaveSubmitting.value).toBe(false);expect(deps.authedFetch).not.toHaveBeenCalled();
});
it('in-app410 pending CourseManagement submit prevents duplicate apply and changed target after token wait',async()=>{
 const {deps,submit}=setup();let resolveToken;deps.getAccessToken.mockImplementation(()=>new Promise(resolve=>{resolveToken=resolve;}));const one=submit();await submit();deps.leaveRequestKey.value='two';resolveToken('synthetic');await one;expect(fetch).not.toHaveBeenCalled();
});
it('in-app410 blocked preview retains navigable blockers and no ordinary fallback',async()=>{
 const {deps,preview}=setup();fetch.mockResolvedValue({ok:true,json:async()=>({...plan,eligible:false,blockers:[{course_id:plan.old_course_id,session_id:plan.leave_session.id,message:'歷史需核對'}]})});await preview();expect(deps.leaveCascadePlan.value.blockers).toHaveLength(1);expect(deps.leavePreviewError.value).not.toBe('');expect(deps.authedFetch).not.toHaveBeenCalled();
});
it('in-app410 closed or changed preview response cannot populate a new CourseManagement target',async()=>{
 const {deps,preview}=setup();let resolveResponse;fetch.mockImplementation(()=>new Promise(resolve=>{resolveResponse=resolve;}));const one=preview();await Promise.resolve();await Promise.resolve();deps.leaveRequestKey.value='two';resolveResponse({ok:true,json:async()=>plan});await one;expect(deps.leaveCascadePlan.value).toBeNull();expect(deps.leavePreviewSuccessKey.value).toBe('');
});
