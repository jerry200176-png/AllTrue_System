<template>
  <div class="truefit-shell">
    <header class="truefit-shell__header">
      <div class="truefit-shell__brand">
        <span class="truefit-shell__mark" aria-hidden="true">TF</span>
        <div>
          <p class="truefit-shell__eyebrow">Learning workspace</p>
          <h1 class="truefit-shell__title">TrueFit</h1>
        </div>
      </div>
      <div class="truefit-shell__actions">
        <AtButton
          v-if="!isTrueFitHost()"
          variant="ghost"
          shape="rect"
          icon="arrow_back"
          @click="returnToAdmin"
        >
          返回教務系統
        </AtButton>
      </div>
    </header>

    <main class="truefit-shell__main">
      <TrueFitPrepPlaceholderPage
        v-if="route.view === 'prep'"
        :session="selectedSession"
        :token="token"
        @back="goWorkspace"
      />
      <TrueFitWorkspacePage
        v-else
        :token="token"
        :branch-id="branchId"
        @prepare="goPrep"
      />
    </main>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import AtButton from '../components/design-system/AtButton.vue';
import TrueFitWorkspacePage from './TrueFitWorkspacePage.vue';
import TrueFitPrepPlaceholderPage from './TrueFitPrepPlaceholderPage.vue';
import { parseTrueFitRoute, buildTrueFitPrepUrl, buildTrueFitWorkspaceUrl, buildAdminReturnUrl } from '../lib/truefitRoute.js';
import { isTrueFitHost } from '../lib/truefitHost.js';

const { token, branchId } = defineProps({
  token: { type: String, required: true },
  branchId: { type: [Number, String, null], default: null },
});

const route = ref(parseTrueFitRoute() || { view: 'workspace' });
const selectedSession = ref(null);

const selectedSessionId = computed(() => route.value?.classSessionId || null);

function syncRouteFromHash() {
  route.value = parseTrueFitRoute() || { view: 'workspace' };
  hydrateSessionFromRoute();
}

function hydrateSessionFromRoute() {
  const r = route.value;
  if (!r || r.view !== 'prep') return;
  if (selectedSession.value) return;
  if (r.classSessionId) {
    selectedSession.value = { class_session_id: r.classSessionId };
    return;
  }
  if (r.studentClassId) {
    const hm = String(r.projectedStartHm || '0000');
    selectedSession.value = {
      class_session_id: null,
      student_class_id: r.studentClassId,
      start_time: `${hm.slice(0, 2)}:${hm.slice(2, 4)}`,
      session_date: new Date().toISOString().slice(0, 10),
    };
  }
}

function goWorkspace() {
  window.location.hash = buildTrueFitWorkspaceUrl();
  selectedSession.value = null;
  syncRouteFromHash();
}

function goPrep(session) {
  selectedSession.value = session;
  window.location.hash = buildTrueFitPrepUrl(session);
  syncRouteFromHash();
}

function returnToAdmin() {
  window.location.href = buildAdminReturnUrl();
}

if (typeof window !== 'undefined') {
  window.addEventListener('hashchange', syncRouteFromHash);
}

hydrateSessionFromRoute();

watch(selectedSessionId, (id) => {
  if (!id && !route.value?.studentClassId) {
    selectedSession.value = null;
  }
});
</script>

<style scoped>
.truefit-shell {
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
  background:
    radial-gradient(1200px 420px at 10% -10%, color-mix(in srgb, var(--ds-primary) 8%, transparent), transparent 60%),
    var(--ds-canvas);
  color: var(--ds-text-primary);
}

.truefit-shell__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--ds-space-3);
  padding: var(--ds-space-4) var(--ds-space-4) var(--ds-space-3);
  border-bottom: 1px solid var(--ds-hairline);
  background: color-mix(in srgb, var(--ds-surface-0) 92%, transparent);
  backdrop-filter: blur(8px);
  position: sticky;
  top: 0;
  z-index: 10;
}

.truefit-shell__brand {
  display: flex;
  align-items: center;
  gap: var(--ds-space-3);
  min-width: 0;
}

.truefit-shell__mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border-radius: 12px;
  background: linear-gradient(145deg, var(--ds-primary-deep), var(--ds-primary) 55%, var(--ds-primary-soft));
  color: var(--ds-on-primary);
  font-weight: 800;
  letter-spacing: -0.04em;
  box-shadow: var(--ds-shadow-md);
}

.truefit-shell__eyebrow {
  margin: 0;
  font-size: var(--ds-font-size-xs);
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--ds-text-tertiary);
}

.truefit-shell__title {
  margin: 0;
  font-size: 1.35rem;
  line-height: 1.1;
  letter-spacing: -0.03em;
}

.truefit-shell__main {
  flex: 1;
  padding: var(--ds-space-4);
  padding-bottom: calc(var(--ds-space-6) + env(safe-area-inset-bottom, 0px));
}

@media (max-width: 560px) {
  .truefit-shell__header {
    padding-inline: var(--ds-space-3);
  }

  .truefit-shell__main {
    padding-inline: var(--ds-space-3);
  }
}
</style>
