// ContractCard keeps its less-used actions in the shared ••• menu (teleported to body).
import { flushPromises } from '@vue/test-utils';

export async function openCardMenu(wrapper) {
  const t = wrapper.find('.am__trigger');
  if (t.attributes('aria-expanded') !== 'true') await t.trigger('click');
  await flushPromises();
}
export const menuItem = (id) => [...document.body.querySelectorAll('.am__menu')].at(-1)?.querySelector(`[data-action="${id}"]`) ?? null;
export async function pickMenu(wrapper, id) {
  await openCardMenu(wrapper);
  menuItem(id).click();
  await flushPromises();
}

// The drawer folds 帳單 / 收款紀錄 / 已刪除課程的帳單備註; open one in place.
export async function unfold(wrapper, which) {
  const btn = wrapper.find(`[data-testid="ledger-fold-${which}"]`);
  if (btn.exists() && btn.attributes('aria-expanded') !== 'true') await btn.trigger('click');
  await flushPromises();
}
// Expand a collapsed contract card in the drawer.
export async function expandCard(wrapper, courseId) {
  const btn = wrapper.find(`[data-testid="contract-card-${courseId}"] [data-testid="contract-toggle"]`);
  if (btn.exists() && btn.attributes('aria-expanded') !== 'true') await btn.trigger('click');
  await flushPromises();
}
export async function expandAll(wrapper) {
  for (const b of wrapper.findAll('[data-testid="contract-toggle"]')) {
    if (b.attributes('aria-expanded') !== 'true') await b.trigger('click');
  }
  await flushPromises();
}
// Open every card and fold, so a test can read the drawer as it was before the one-line cards.
export async function openAll(wrapper) {
  await expandAll(wrapper);
  for (const k of ['invoices', 'receipts', 'voided']) await unfold(wrapper, k);
}

// Items a card's ••• menu offers right now (opens it, reads it, closes it with Escape). Throws if the menu never opened,
// so "item X is absent" can never pass on a menu that did not render.
export async function menuIds(scope) {
  const t = scope.find('.am__trigger');
  if (!t.exists()) throw new Error('card has no ••• menu');
  if (t.attributes('aria-expanded') !== 'true') await t.trigger('click');
  await flushPromises();
  const menu = [...document.body.querySelectorAll('.am__menu')].at(-1);
  if (!menu) throw new Error('••• menu did not open');
  const ids = [...menu.querySelectorAll('[data-action]')].map((n) => n.dataset.action);
  menu.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
  await flushPromises();
  return ids;
}
