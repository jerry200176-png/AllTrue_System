import { describe, it, expect, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import ParentTelegramBind from '../ParentTelegramBind.vue';

vi.mock('../../api', () => ({ createParentTelegramBindCode: vi.fn(async () => ({ code: 'abc123def4', expires_in: 600 })) }));

describe('ParentTelegramBind', () => {
  it('shows the button, then the code and the instruction after a click', async () => {
    const w = mount(ParentTelegramBind, { props: { token: 't' } });
    expect(w.text()).toContain('綁定 Telegram 通知');
    expect(w.find('[data-testid="tg-code"]').exists()).toBe(false);
    await w.find('button').trigger('click');
    await flushPromises();
    expect(w.get('[data-testid="tg-code"]').text()).toBe('abc123def4');
    expect(w.text()).toContain('/start 加上這組代碼，10 分鐘內有效');
  });
});
