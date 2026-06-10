import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import CourseBlockContent from '../CourseBlockContent.vue';
import { getSubjectLabel } from '../../../lib/constants';
import { classTypeLabel } from '../../../lib/calendarFormat.js';

const course = { student_name: '小明', subject: 'math', class_type: 'one_on_one', teacher_id: 7, teacher_name: '王老師' };

describe('CourseBlockContent', () => {
  // 正常（日檢視，無徽章）：學生/科目/班型，無 rc-tag、無 teacher-tag
  it('renders student/subject/type, no badges when none provided', () => {
    const wrapper = mount(CourseBlockContent, { props: { course } });
    expect(wrapper.find('.cb-student').text()).toBe('小明');
    expect(wrapper.find('.cb-detail').text()).toBe(getSubjectLabel('math'));
    expect(wrapper.find('.cb-type').text()).toBe(classTypeLabel('one_on_one'));
    expect(wrapper.find('.rc-tag').exists()).toBe(false);
    expect(wrapper.find('.cb-teacher-tag').exists()).toBe(false);
    // 無徽章時學生姓名不應有 has-rc 預留
    expect(wrapper.find('.cb-student').classes()).not.toContain('cbc-has-rc');
  });

  // 邊界：rollCall 徽章 → rc-tag 帶 rc-{kind}，學生姓名 cbc-has-rc 預留右側
  it('renders rollCall badge as rc-<kind> and adds cbc-has-rc', () => {
    const wrapper = mount(CourseBlockContent, {
      props: { course, badges: { rollCall: { kind: 'missed', label: '!' } } },
    });
    const tag = wrapper.find('.rc-tag');
    expect(tag.exists()).toBe(true);
    expect(tag.classes()).toContain('rc-missed');
    expect(tag.text()).toBe('!');
    expect(wrapper.find('.cb-student').classes()).toContain('cbc-has-rc');
  });

  // 邊界：eval + rollCall 同時 → 第二個角標標記 rc-tag-second
  it('marks eval badge as rc-tag-second when rollCall also present', () => {
    const wrapper = mount(CourseBlockContent, {
      props: { course, badges: { rollCall: { kind: 'done', label: '✓' }, evalMissing: { label: '評' } } },
    });
    const tags = wrapper.findAll('.rc-tag');
    expect(tags).toHaveLength(2);
    const evalTag = wrapper.find('.rc-eval-missing');
    expect(evalTag.classes()).toContain('rc-tag-second');
    // 只有 eval（無 rollCall）時不應 second
    const only = mount(CourseBlockContent, { props: { course, badges: { evalMissing: { label: '評' } } } });
    expect(only.find('.rc-eval-missing').classes()).not.toContain('rc-tag-second');
  });

  // 邊界：週檢視 teacherTag → cb-teacher-tag 帶底色
  it('renders teacher tag with background color (week view)', () => {
    const wrapper = mount(CourseBlockContent, {
      props: { course, badges: { teacherTag: { name: '王老師', color: '#1E88E5' } } },
    });
    const tag = wrapper.find('.cb-teacher-tag');
    expect(tag.exists()).toBe(true);
    expect(tag.text()).toBe('王老師');
    expect(tag.attributes('style')).toContain('background: rgb(30, 136, 229)');
  });

  // 邊界：compact 旗標套到 cb-* 與 rc-tag
  it('applies cbc-compact to cb-* and rc-tag when layout.compact', () => {
    const wrapper = mount(CourseBlockContent, {
      props: { course, badges: { rollCall: { kind: 'leave', label: '假' } }, layout: { compact: true } },
    });
    expect(wrapper.find('.cb-student').classes()).toContain('cbc-compact');
    expect(wrapper.find('.cb-detail').classes()).toContain('cbc-compact');
    expect(wrapper.find('.rc-tag').classes()).toContain('cbc-compact');
  });

  // 邊界：firstBadge 容量徽章預留（full / compact）互斥
  it('applies capacity badge padding class per layout.firstBadge', () => {
    const full = mount(CourseBlockContent, { props: { course, layout: { firstBadge: 'full' } } });
    expect(full.find('.cb-student').classes()).toContain('cbc-badge-full');
    expect(full.find('.cb-student').classes()).not.toContain('cbc-badge-compact-pad');
    const compact = mount(CourseBlockContent, { props: { course, layout: { firstBadge: 'compact' } } });
    expect(compact.find('.cb-student').classes()).toContain('cbc-badge-compact-pad');
  });

  // 空值：badges / layout 預設空物件不崩潰
  it('renders safely with default empty badges/layout', () => {
    const wrapper = mount(CourseBlockContent, { props: { course } });
    expect(wrapper.find('.cb-student').exists()).toBe(true);
    expect(wrapper.find('.cb-student').classes()).not.toContain('cbc-badge-full');
  });
});
