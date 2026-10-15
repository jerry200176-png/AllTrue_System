// Founder 2026-10-15 Q8=A (in-app #399, #4014): adding lessons to an existing course goes
// through 續課 (the existing renewal/purchase entry). The 合約／堂次調整 dialog only lowers or
// ends a contract, so it points directors to 續課 instead of offering an increase.
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import ContractAdjustmentChoiceModal from '../ContractAdjustmentChoiceModal.vue';
import { editabilityReasonSummary } from '../../../lib/courseEditability';

const __dirname = dirname(fileURLToPath(import.meta.url));
const read = (p) => readFileSync(resolve(__dirname, p), 'utf8');

describe('add lessons via 續課 (#4014)', () => {
  it('the adjustment dialog says adding lessons is done with 續課 and offers the way there', async () => {
    const wrapper = mount(ContractAdjustmentChoiceModal, {
      props: { show: true, studentName: '測試學生', subject: 'math', paymentStatus: 'paid' },
    });
    const hint = wrapper.find('[data-testid="contract-adjust-renew-hint"]');
    expect(hint.exists()).toBe(true);
    expect(hint.text()).toContain('要加堂');
    expect(hint.text()).toContain('續課');

    await wrapper.find('[data-testid="contract-adjust-renew"]').trigger('click');
    expect(wrapper.emitted('choose')).toEqual([['renew']]);
    // The three existing choices keep their order and are not joined by an increase option.
    expect(wrapper.findAll('.choice-card')).toHaveLength(3);
  });

  it('the course page routes 續課 to the existing renewal entry, with no new write path', () => {
    const page = read('../../../pages/CourseManagement.vue');
    const fn = page.slice(page.indexOf('function chooseContractAdjustment('), page.indexOf('async function submitBillingCorrection('));
    expect(fn).toMatch(/action === 'renew'\)\s*\{\s*openCommercialPurchaseEntry\(course\);/);
    expect(fn).not.toMatch(/authedFetch|apiFetch/);
  });

  it('locked lesson-count copy names 續課 for adding lessons', () => {
    expect(editabilityReasonSummary({ message: '已有扣堂紀錄。', next_step: 'billing_correction' })).toContain('續課');
    expect(read('../../CourseEditForm.vue')).toMatch(/已有扣堂紀錄[^<]*要加堂請用「續課」/);
  });
});
