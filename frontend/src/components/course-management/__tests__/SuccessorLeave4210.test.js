import { expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import LeaveModal from '../LeaveModal.vue';
import Blockers from '../SuccessorLeaveBlockers.vue';

it('in-app410 blocker button uses the existing calendar context navigation', async () => {
 const w=mount(Blockers,{props:{blockers:[{message:'需核對歷史',course_id:8,session_id:9}],date:'2026-12-01'}});
 expect(w.text()).toContain('需核對歷史');await w.find('button').trigger('click');
 expect(w.emitted('navigate')[0][0]).toEqual({target:'calendar',courseId:8,date:'2026-12-01',intent:''});
});
it('in-app410 same-day lessons remain independently selectable and weekday/duration follow the occurrence', async()=>{
 const form={schedule_date:'2026-12-01',session_id:11,start_time:'16:00',end_time:'17:00',day_of_week:2};
 const options=[{date:'2026-12-01',session_id:11,start_time:'16:00',end_time:'17:00'}, {date:'2026-12-01',session_id:12,start_time:'19:00',end_time:'19:20'},{date:'2026-12-02',session_id:13,start_time:'18:00',end_time:'18:45'}];
 const w=mount(LeaveModal,{props:{show:true,form,sessionOptions:options,impactPreview:{items:['preview']},previewReady:true}});
 await w.find('select').setValue('id:12');expect(form.session_id).toBe(12);expect(form.end_time).toBe('19:20');
 await w.find('select').setValue('id:13');expect(form.day_of_week).toBe(3);expect(form.start_time).toBe('18:00');
 await w.find('input[type=checkbox]').setValue(true);expect(w.find('.primary').element.disabled).toBe(false);
 await w.setProps({previewReady:false});expect(w.find('.primary').element.disabled).toBe(true);
 await w.setProps({previewReady:true,submitting:true});expect(w.find('select').element.disabled).toBe(true);expect(w.find('.primary').element.disabled).toBe(true);
});
