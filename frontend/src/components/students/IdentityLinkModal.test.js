import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import IdentityLinkModal from './IdentityLinkModal.vue';

const props = {
  show: true,
  query: '',
  students: [{ student_id: 1, name: '小明', campus_id: 2, campus_name: '新莊', identity_group_id: null }],
  groups: [{ id: 9, display_name: '群組', mode: 'off', members: [] }],
  selectedIds: [],
};

describe('IdentityLinkModal', () => {
  it('renders nothing when hidden', () => {
    expect(mount(IdentityLinkModal, { props: { ...props, show: false } }).find('[role="dialog"]').exists()).toBe(false);
  });

  it('renders candidates and emits its events', async () => {
    const w = mount(IdentityLinkModal, { props });
    expect(w.text()).toContain('小明');
    await w.find('.identity-candidate').trigger('click');
    expect(w.emitted('toggle')[0]).toEqual([1]);
    await w.find('.identity-search-row button').trigger('click');
    expect(w.emitted('search')).toHaveLength(1);
    await w.find('select').setValue('readonly');
    expect(w.emitted('update-mode')[0][1]).toBe('readonly');
    await w.find('.identity-group-card button').trigger('click');
    expect(w.emitted('load-audit')[0]).toEqual([9]);
    await w.find('.modal-header-row button').trigger('click');
    expect(w.emitted('close')).toHaveLength(1);
  });

  it('emits link when two are selected', async () => {
    const w = mount(IdentityLinkModal, { props: { ...props, selectedIds: [1, 2] } });
    await w.find('button.primary').trigger('click');
    expect(w.emitted('link')).toHaveLength(1);
  });
});
