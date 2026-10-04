import { existsSync, readFileSync } from 'node:fs';
import { defineComponent, h, nextTick, onMounted, ref } from 'vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { useDirectorBranchAuthorization } from '../../lib/directorBranchAuthorization';

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

function harness(page, requests, context = 'director-token') {
  const gate = useDirectorBranchAuthorization();
  const branch = ref(17); // Public default; director is assigned to campus 9.
  const BranchPage = defineComponent({
    setup() {
      onMounted(() => requests.push(`${page}?branch_id=${branch.value}`));
      return () => h('main', page);
    },
  });
  const Host = defineComponent({
    setup() {
      return () => gate.canMount(context, branch.value)
        ? h(BranchPage, { key: branch.value })
        : h('p', '等待授權分校');
    },
  });
  return { gate, branch, wrapper: mount(Host) };
}

describe.each(['tuition', 'rooms'])('director %s branch readiness', (page) => {
  it('does not mount against a public default while campuses is delayed, then uses the authorized branch', async () => {
    const requests = [];
    const { gate, branch, wrapper } = harness(page, requests);
    const response = deferred();
    const pending = gate.load('director-token', () => response.promise, (campuses) => {
      branch.value = campuses[0].id;
    });
    await nextTick();
    expect(requests).toEqual([]);
    response.resolve([{ id: 9, name: 'authorized campus' }]);
    expect(await pending).toBe(true);
    await nextTick();
    expect(requests).toEqual([`${page}?branch_id=9`]);
    branch.value = 17;
    await nextTick();
    expect(wrapper.find('main').exists()).toBe(false);
    expect(requests).toEqual([`${page}?branch_id=9`]);
    wrapper.unmount();
  });

  it('fails closed on empty or failed campuses and allows a later retry', async () => {
    const requests = [];
    const { gate, branch, wrapper } = harness(page, requests);
    expect(await gate.load('director-token', async () => [], () => { throw new Error('must not apply'); })).toBe(false);
    await nextTick();
    expect(gate.status.value).toBe('failed');
    expect(requests).toEqual([]);
    expect(await gate.load('director-token', async () => { throw new Error('campuses unavailable'); }, () => {})).toBe(false);
    await nextTick();
    expect(requests).toEqual([]);
    expect(await gate.load('director-token', async () => [{ id: 9 }], () => { branch.value = 9; })).toBe(true);
    await nextTick();
    expect(requests).toEqual([`${page}?branch_id=9`]);
    wrapper.unmount();
  });

  it('ignores a stale campuses response after identity reset', async () => {
    const requests = [];
    const { gate, branch, wrapper } = harness(page, requests);
    const response = deferred();
    const pending = gate.load('director-token', () => response.promise, () => { branch.value = 9; });
    gate.reset();
    response.resolve([{ id: 9 }]);
    expect(await pending).toBe(false);
    await nextTick();
    expect(requests).toEqual([]);
    wrapper.unmount();
  });
});

it('wires the gate to the real dashboard and classroom mounts', () => {
  const appPath = existsSync(`${process.cwd()}/src/App.vue`)
    ? `${process.cwd()}/src/App.vue`
    : `${process.cwd()}/frontend/src/App.vue`;
  const app = readFileSync(appPath, 'utf8');
  expect(app).toMatch(/<DirectorDashboard v-if="[^"]*directorBranchReady[^"]*active === 'director'/);
  expect(app).toMatch(/<ClassroomManagement v-if="[^"]*directorBranchReady[^"]*active === 'classroom'/);
  expect(app).toContain('directorBranchAuthorization.load(contextKey, () => loadBranchesForDirector(s.access_token)');
});

it('preserves a super admin with an authenticated all-campus response but rejects a public fallback on failure', async () => {
  const gate = useDirectorBranchAuthorization();
  const publicBranches = [{ id: 17 }, { id: 9 }];
  expect(await gate.load('super-admin-token', async () => [], () => { throw new Error('public fallback used'); })).toBe(false);
  expect(gate.canMount('super-admin-token', publicBranches[0].id)).toBe(false);
  expect(await gate.load('super-admin-token', async () => publicBranches, () => {})).toBe(true);
  expect(gate.canMount('super-admin-token', 17)).toBe(true);
  expect(gate.canMount('super-admin-token', 9)).toBe(true);
});

it('fails closed after a bounded wait when the authenticated campus request never finishes', async () => {
  const gate = useDirectorBranchAuthorization();
  const result = await gate.load('director-token', () => new Promise(() => {}), () => {}, { timeoutMs: 1 });
  expect(result).toBe(false);
  expect(gate.status.value).toBe('failed');
  expect(gate.canMount('director-token', 17)).toBe(false);
});

it('keeps an active page mounted while the same user context revalidates campus scope', async () => {
  const requests = [];
  const context = 'director-user:director';
  const { gate, branch, wrapper } = harness('tuition', requests, context);
  expect(await gate.load(context, async () => [{ id: 9 }], (campuses) => { branch.value = campuses[0].id; })).toBe(true);
  await nextTick();
  expect(requests).toEqual(['tuition?branch_id=9']);

  const response = deferred();
  const refresh = gate.load(context, () => response.promise, (campuses) => { branch.value = campuses[0].id; }, { refresh: true });
  await nextTick();
  expect(gate.canMount(context, 9)).toBe(true);
  expect(wrapper.find('main').exists()).toBe(true);
  expect(requests).toEqual(['tuition?branch_id=9']);

  response.resolve([{ id: 11 }]);
  expect(await refresh).toBe(true);
  await nextTick();
  expect(branch.value).toBe(11);
  expect(gate.canMount(context, 11)).toBe(true);
  expect(requests).toEqual(['tuition?branch_id=9', 'tuition?branch_id=11']);
  wrapper.unmount();
});

it('fails closed and unmounts branch pages when same-context campus revalidation fails', async () => {
  const requests = [];
  const context = 'director-user:director';
  const { gate, branch, wrapper } = harness('rooms', requests, context);
  await gate.load(context, async () => [{ id: 9 }], (campuses) => { branch.value = campuses[0].id; });
  await nextTick();
  expect(wrapper.find('main').exists()).toBe(true);

  expect(await gate.load(context, async () => { throw new Error('campus refresh failed'); }, () => {}, { refresh: true })).toBe(false);
  await nextTick();
  expect(gate.status.value).toBe('failed');
  expect(wrapper.find('main').exists()).toBe(false);
  expect(requests).toEqual(['rooms?branch_id=9']);
  wrapper.unmount();
});

it('wires routine token refresh and badge requests through the authorization context', () => {
  const appPath = existsSync(`${process.cwd()}/src/App.vue`)
    ? `${process.cwd()}/src/App.vue`
    : `${process.cwd()}/frontend/src/App.vue`;
  const app = readFileSync(appPath, 'utf8');
  expect(app).toContain('canMount(directorContextKey.value, currentBranch.value)');
  expect(app).toContain('await ensureDirectorBranches({ refresh: sameContext && tokenChanged });');
  expect(app).toContain("if (normalized === 'director') await ensureDirectorBranches();");
  expect(app).toMatch(/async function runBadgeRefresh\(\) \{\s*if \([^\n]*directorBranchReady\.value/);
  expect(app).toMatch(/async function mergeBugUnreadBadge\(\) \{\s*if \([^\n]*directorBranchReady\.value/);
});
