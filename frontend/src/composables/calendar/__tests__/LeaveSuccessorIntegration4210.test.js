import { afterEach, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import { useCalendarLeaveExtra } from '../useCalendarLeaveExtra.js';
const plan = { applicable:true, apply_enabled:true, eligible:true, leave_session:{id:11}, preview_token:'a'.repeat(64), old_end_date:'2026-12-08', successor_end_date:'2026-12-22', blockers:[], moves:[{role:'old_tail',session_id:null,from:null,to:{date:'2026-12-08',start:'19:00:00',end:'21:00:00'}},{role:'successor',session_id:21,from:{date:'2026-12-08',start:'16:00:00',end:'18:00:00'},to:{date:'2026-12-15',start:'16:00:00',end:'18:00:00'}}] };
let scope;
afterEach(()=>{scope?.stop();vi.unstubAllGlobals();});
async function setup(blocked=false,applyEnabled=true) {
 const deps={branchId:ref(1),userRole:ref('director'),showModal:ref(true),modalForm:ref({student_id:1,subject:'Math',start_time:'16:00',action_date:'2026-12-01'}),editingCourseId:ref(9),contextMenu:ref({}),loadCourses:vi.fn(),getToken:async()=>'synthetic',allStudents:ref([]),getSubjectLabel:x=>x};
 vi.stubGlobal('alert',vi.fn());vi.stubGlobal('fetch',vi.fn(async url=>({ok:true,json:async()=>url.includes('class-sessions?')?{data:[{id:11,student_class_id:9,session_date:'2026-12-01',start_time:'16:00'}]}:url.endsWith('/apply')?{applied:true}:blocked?{...plan,eligible:false,blockers:[{message:'歷史需核對',course_id:9,session_id:11}]}:{...plan,apply_enabled:applyEnabled}})));
 scope=effectScope(); const api=scope.run(()=>useCalendarLeaveExtra(deps));api.openLeaveModal();for(let i=0;i<30;i++)await nextTick();return {api,deps};
}
it('in-app410 calendar previews exact occurrence, shows moves and applies exact token',async()=>{
 const {api,deps}=await setup();expect(api.leavePreviewReady.value).toBe(true);expect(api.leaveImpactPreview.value.items.join(' ')).toContain('2026-12-15');
 await api.submitLeave();const calls=fetch.mock.calls;expect(calls.some(([url])=>url==='/api/v1/schedules')).toBe(false);expect(JSON.parse(calls.find(([url])=>url.endsWith('/apply'))[1].body)).toEqual({class_session_id:11,preview_token:plan.preview_token});expect(deps.loadCourses).toHaveBeenCalledOnce();
});
it('in-app410 blocked preview gives reason and identifier and cannot submit',async()=>{
 const {api}=await setup(true);expect(api.leavePreviewReady.value).toBe(false);expect(api.leavePreviewError.value).toContain('歷史需核對');expect(api.leaveImpactPreview.value.blockers[0].session_id).toBe(11);await api.submitLeave();expect(fetch.mock.calls.some(([url])=>url.endsWith('/apply')||url==='/api/v1/schedules')).toBe(false);
});
it('in-app410 flag-off or stale apply invalidates approval and requires re-preview',async()=>{
 const {api}=await setup();fetch.mockResolvedValue({ok:false,status:403,json:async()=>({message:'尚未開放'})});await api.submitLeave();expect(api.leavePreviewReady.value).toBe(false);expect(api.leaveSubmitError.value).toContain('尚未開放');expect(api.showLeaveModal.value).toBe(true);
});

it('in-app410 disabled capability blocks calendar before confirmation or ordinary write',async()=>{
 const {api}=await setup(false,false);expect(api.leavePreviewReady.value).toBe(false);expect(api.leavePreviewError.value).toContain('尚未開放');
 expect(api.leaveImpactPreview.value.blockers.at(-1).type).toBe('feature_unavailable');fetch.mockClear();await api.submitLeave();expect(fetch).not.toHaveBeenCalled();
});
