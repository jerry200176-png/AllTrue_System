import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, expect, it, vi } from 'vitest';
import { previewSuccessorLeave, applySuccessorLeave, successorImpactItems } from '../../lib/leaveSuccessorShift.js';

// Execute the actual page command with synthetic boundary adapters, without mounting its unrelated workspaces.
const source = readFileSync(resolve(__dirname, '../../pages/AttendancePage.vue'), 'utf8');
const command = source.slice(source.indexOf('const saveStatusEdit ='), source.indexOf('const submitManual =')).replace('const saveStatusEdit =', 'return');
const plan={applicable:true,apply_enabled:true,eligible:true,leave_session:{id:11},preview_token:'a'.repeat(64),old_end_date:'2026-12-08',successor_end_date:'2026-12-22',blockers:[],moves:[{role:'old_tail',session_id:null,from:null,to:{date:'2026-12-08',start:'19:00:00',end:'21:00:00'}},{role:'successor',session_id:21,from:{date:'2026-12-08',start:'16:00:00',end:'18:00:00'},to:{date:'2026-12-15',start:'16:00:00',end:'18:00:00'}}]};
function setup({blocked=false,accepted=true,role='director',applyStatus=200,applyEnabled=true}={}) {
 const deps={statusEditOwner:{value:0},confirmSuccessorLeave:vi.fn().mockResolvedValue(accepted),getToken:async()=>'synthetic',props:{userRole:role,branchId:1},previewSuccessorLeave,applySuccessorLeave,successorImpactItems,leaveBlockers:{value:[]},leaveBlockerDate:{value:''},pendingMarkMsg:{value:''},pendingMarkMsgType:{value:''},fetchPendingSessions:vi.fn(),fetchRecords:vi.fn(),httpFailureMessage:(_s,b)=>b.message};
 vi.stubGlobal('alert',vi.fn());vi.stubGlobal('confirm',vi.fn(()=>accepted));
 vi.stubGlobal('fetch',vi.fn(async url=>({ok:!url.endsWith('/apply')||applyStatus===200,status:applyStatus,json:async()=>url.endsWith('/preview')?blocked?{...plan,eligible:false,blockers:[{message:'歷史需核對',course_id:1,session_id:11}]}:{...plan,apply_enabled:applyEnabled}:url.endsWith('/apply')?applyStatus===200?{applied:true}:{message:'尚未開放'}:{}})));
 const save=Function(...Object.keys(deps),command)(...Object.values(deps));
 return {save,deps,record:{ClassSessionID:11,StudentClassID:1,Status:'absent',_newStatus:'leave',_editing:true}};
}
afterEach(()=>vi.unstubAllGlobals());
it.each([false,null])('in-app410 attendance cannot confirm disabled or unknown capability %j',async applyEnabled=>{
 const {save,deps,record}=setup({applyEnabled});await save(record);
 expect(deps.confirmSuccessorLeave).not.toHaveBeenCalled();expect(confirm).not.toHaveBeenCalled();
 expect(fetch).toHaveBeenCalledTimes(1);expect(record.Status).toBe('absent');
});
it.each(['branch','session','role','status','course','existing-status','unmount'])('in-app410 token wait loses ownership after %s changes',async field=>{
 const {save,deps,record}=setup();let finishToken;deps.getToken=()=>new Promise(resolve=>{finishToken=resolve;});
 const saveWithToken=Function(...Object.keys(deps),command)(...Object.values(deps));
 const pending=saveWithToken(record);
 if(field==='branch')deps.props.branchId=2;
 if(field==='session')record.ClassSessionID=12;
 if(field==='role')deps.props.userRole='teacher';
 if(field==='status')record._newStatus='present';
 if(field==='course')record.StudentClassID=2;
 if(field==='existing-status')record.Status='present';
 if(field==='unmount')deps.statusEditOwner.value+=1;
 finishToken('synthetic');await pending;
 expect(fetch).not.toHaveBeenCalled();expect(deps.confirmSuccessorLeave).not.toHaveBeenCalled();expect(confirm).not.toHaveBeenCalled();expect(record._saving).toBe(false);
});
it('in-app410 unmount while preview is pending cannot open a late confirmation',async()=>{
 const {save,deps,record}=setup();let finishPreview;fetch.mockImplementation(()=>new Promise(resolve=>{finishPreview=resolve;}));
 const pending=save(record);await Promise.resolve();await Promise.resolve();deps.statusEditOwner.value+=1;
 finishPreview({ok:true,json:async()=>plan});await pending;
 expect(deps.confirmSuccessorLeave).not.toHaveBeenCalled();expect(fetch).toHaveBeenCalledTimes(1);expect(record.Status).toBe('absent');
});
it('in-app410 attendance confirms server effects before applying and refreshes both authorities',async()=>{
 const {save,deps,record}=setup();await save(record);expect(deps.confirmSuccessorLeave.mock.calls[0][0]).toContain('2026-12-15');expect(fetch.mock.calls.filter(([u])=>u.endsWith('/apply'))).toHaveLength(1);expect(record.Status).toBe('leave');expect(deps.fetchRecords).toHaveBeenCalledOnce();expect(deps.fetchPendingSessions).toHaveBeenCalledOnce();
});
it.each([{blocked:true},{accepted:false},{applyStatus:403}])('in-app410 attendance refusal/cancel/failure never reports successful leave: %j',async options=>{
 const {save,record}=setup(options);await save(record);expect(record.Status).toBe('absent');expect(record._editing).toBe(true);expect(record._saving).toBe(false);
});
it('in-app410 teacher retains existing ordinary endpoint and attended rows retain retro-leave',async()=>{
 for(const attended of [false,true]) {
  const {save,record}=setup({role:'teacher'});record.Status=attended?'present':'absent';await save(record);expect(fetch.mock.calls.some(([u])=>u.includes('leave-successor-shift'))).toBe(false);expect(fetch.mock.calls[0][0]).toBe(attended?'/api/v1/schedules/retro-leave':'/api/v1/schedules/leave-by-session');
 }
});
it.each(['branch','session','role','status'])('in-app410 delayed ordinary fallback cannot write after %s changes',async field=>{
 const {save,deps,record}=setup();let resolvePreview;
 fetch.mockImplementation(()=>new Promise(resolve=>{resolvePreview=resolve;}));
 const pending=save(record);await Promise.resolve();await Promise.resolve();
 if(field==='branch')deps.props.branchId=2;
 if(field==='session')record.ClassSessionID=12;
 if(field==='role')deps.props.userRole='teacher';
 if(field==='status')record._newStatus='present';
 resolvePreview({ok:true,json:async()=>({applicable:false})});await pending;
 expect(confirm).not.toHaveBeenCalled();expect(fetch).toHaveBeenCalledTimes(1);expect(record.Status).toBe('absent');
});
it.each(['branch','session','role','status'])('in-app410 async design-system confirmation cannot apply after %s changes',async field=>{
 const {save,deps,record}=setup();let accept;
 deps.confirmSuccessorLeave.mockImplementation(()=>new Promise(resolve=>{accept=resolve;}));
 const pending=save(record);for(let i=0;i<12;i++)await Promise.resolve();expect(deps.confirmSuccessorLeave).toHaveBeenCalledOnce();
 if(field==='branch')deps.props.branchId=2;
 if(field==='session')record.ClassSessionID=12;
 if(field==='role')deps.props.userRole='teacher';
 if(field==='status')record._newStatus='present';
 accept(true);await pending;expect(fetch.mock.calls.some(([url])=>url.endsWith('/apply'))).toBe(false);expect(record.Status).toBe('absent');
});
