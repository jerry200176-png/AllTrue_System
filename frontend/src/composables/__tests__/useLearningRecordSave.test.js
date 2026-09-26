import { describe, expect, it, vi } from 'vitest';
import { extractLearningRecordResponse, saveLearningRecord, createLearningRecordSaver } from '../useLearningRecordSave';
const snapshot={id:null,StudentID:7,TeacherID:9,ClassSessionID:11,Status:'pending'};
const response=(status,body)=>({ok:status>=200&&status<300,status,json:async()=>body});
describe('learning record save contract',()=>{
 it('accepts only matching identity and valid status',()=>{expect(extractLearningRecordResponse({id:3,StudentID:7,TeacherID:9,ClassSessionID:11,Status:'pending'},snapshot)).toBeTruthy();expect(extractLearningRecordResponse({id:3,StudentID:8,TeacherID:9,ClassSessionID:11,Status:'pending'},snapshot)).toBeNull();expect(extractLearningRecordResponse({id:3,StudentID:7,TeacherID:9,ClassSessionID:11,Status:'weird'},snapshot)).toBeNull()});
 it('accepts the hydrated backend student_id response without weakening identity checks',()=>{
   const hydrated={id:3,student_id:7,TeacherID:9,ClassSessionID:11,Status:'pending'};
   expect(extractLearningRecordResponse(hydrated,snapshot)).toBeTruthy();
   expect(extractLearningRecordResponse({...hydrated,student_id:8},snapshot)).toBeNull();
   expect(extractLearningRecordResponse({...hydrated,StudentID:8},snapshot)).toBeNull();
   expect(extractLearningRecordResponse({...hydrated,StudentID:0},snapshot)).toBeNull();
   expect(extractLearningRecordResponse({...hydrated,StudentID:7},snapshot)).toEqual({...hydrated,StudentID:7});
 });
 it('keeps malformed, conflict, and network failures explicit',async()=>{expect((await saveLearningRecord({fetchImpl:vi.fn(async()=>response(201,{})),url:'/',snapshot})).kind).toBe('malformed');expect((await saveLearningRecord({fetchImpl:vi.fn(async()=>response(409,{message:'duplicate'})),url:'/',snapshot})).kind).toBe('conflict');expect((await saveLearningRecord({fetchImpl:vi.fn(async()=>{throw new Error('offline')}),url:'/',snapshot})).kind).toBe('network')});
 it('single-flights two deferred submits without sharing other page instances',async()=>{
   let release;
   const f=vi.fn(()=>new Promise(resolve=>{release=()=>resolve(response(201,{id:4,StudentID:7,TeacherID:9,ClassSessionID:11,Status:'pending'}))}));
   const save = createLearningRecordSaver(), options = {fetchImpl:f,url:'/same',token:'isolated',snapshot};
   const first=save(options), second=save(options);
   expect(f).toHaveBeenCalledTimes(1);
   expect(first).toBe(second);
   release();
   expect(await first).toMatchObject({ok:true});
   expect(await second).toMatchObject({ok:true});
   const other = createLearningRecordSaver();
   const third = other(options); expect(f).toHaveBeenCalledTimes(2); release(); await third;
 });
 it('rejects wrong record/session identity and invalid JSON without claiming success', async () => {
   const valid={...snapshot,id:3};
   expect(extractLearningRecordResponse({...valid,id:4},valid)).toBeNull();
   expect(extractLearningRecordResponse({...valid,ClassSessionID:12},valid)).toBeNull();
   const result=await saveLearningRecord({url:'/',snapshot,fetchImpl:async()=>({ok:true,json:async()=>{throw new Error('invalid')}})});
   expect(result).toMatchObject({ok:false,kind:'malformed'});
 });
});
