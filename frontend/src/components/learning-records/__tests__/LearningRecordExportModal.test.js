import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('../../../supabase', () => ({ supabase: { auth: { getSession: vi.fn() } } }));
vi.mock('../../../lib/learningRecordExport', () => ({ exportStudentCards: vi.fn() }));

import LearningRecordExportModal from '../LearningRecordExportModal.vue';

describe('LearningRecordExportModal', () => {
  it('renders with a prefilled date range and emits close on cancel', async () => {
    const w = mount(LearningRecordExportModal, { props: { branchId: 1 } });
    expect(w.text()).toContain('匯出學習評量圖');
    const [start, end] = w.findAll('input[type="date"]');
    expect(start.element.value).toMatch(/^\d{4}-\d{2}-01$/);
    expect(end.element.value).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    await w.find('button.ghost:not(.icon)').trigger('click');
    expect(w.emitted('close')).toHaveLength(1);
  });
});
