import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

const signIn = vi.fn();
const choose = vi.fn();
vi.mock('../../supabase', () => ({
  supabase: { auth: { signInWithPassword: (...a) => signIn(...a), chooseAccount: (...a) => choose(...a) } },
}));

import Login from '../../pages/Login.vue';

const choiceResult = {
  data: {
    requires_account_choice: true,
    choice_token: 'tok',
    choices: [
      { choice_id: 'a', role_label: '老師帳號' },
      { choice_id: 'b', role_label: '主任帳號（即將合併）' },
    ],
  },
};

async function submit(w) {
  await w.find('#login-account').setValue('dual');
  await w.find('#login-password').setValue('pw');
  await w.find('form').trigger('submit');
  await flushPromises();
}

describe('Login unified entry', () => {
  beforeEach(() => {
    signIn.mockReset();
    choose.mockReset();
    localStorage.clear();
  });

  it('has no role picker and sends no role', async () => {
    signIn.mockResolvedValue({ error: { message: 'x' } });
    const w = mount(Login);
    expect(w.text()).not.toContain('登入身分');
    await submit(w);
    expect(signIn).toHaveBeenCalledWith({ account: 'dual', password: 'pw' });
  });

  it('shows chooser, logs in with chosen id, stores nothing', async () => {
    signIn.mockResolvedValue(choiceResult);
    const session = { user: { id: 1, role: 'teacher' } };
    choose.mockResolvedValue({ data: { session } });
    const w = mount(Login);
    await submit(w);
    expect(w.text()).toContain('你有兩個身分，要用哪一個登入？');
    const buttons = w.findAll('[data-testid="account-choice"] .role-btn');
    expect(buttons.map((b) => b.text())).toEqual(['老師帳號', '主任帳號（即將合併）']);
    await buttons[0].trigger('click');
    await flushPromises();
    expect(choose).toHaveBeenCalledWith({ choiceToken: 'tok', choiceId: 'a' });
    expect(w.emitted('login-success')[0][0].user).toEqual(session.user);
    expect(localStorage.length).toBe(0);
  });

  it('returns to the form when the choice fails', async () => {
    signIn.mockResolvedValue(choiceResult);
    choose.mockResolvedValue({ error: { message: '選擇已失效，請重新登入' } });
    const w = mount(Login);
    await submit(w);
    await w.findAll('[data-testid="account-choice"] .role-btn')[1].trigger('click');
    await flushPromises();
    expect(w.find('[data-testid="account-choice"]').exists()).toBe(false);
    expect(w.text()).toContain('選擇已失效');
  });
});
