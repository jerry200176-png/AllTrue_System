<script setup>
/*
 * Login background: the CSS aurora is always the base (and the fallback); on capable devices the silk shader
 * (Vue Bits Silk, raw WebGL, ./fx/SilkBackground.vue) loads lazily on top.
 * Falls back to the aurora when: prefers-reduced-motion, navigator.deviceMemory < 4, Save-Data, no WebGL, the
 * context is lost, or the effect chunk fails. The effect starts on idle so typing and the 登入 button are never delayed.
 */
import { defineAsyncComponent, onMounted, ref } from 'vue';
import AuroraBackground from './AuroraBackground.vue';
import { fxAllowed } from './fx/fxGate';

const Silk = defineAsyncComponent(() => import('./fx/SilkBackground.vue'));
const active = ref(false);

onMounted(() => {
  if (!fxAllowed({ needsWebGL: true })) return;
  const start = () => { active.value = true; };
  if (window.requestIdleCallback) window.requestIdleCallback(start, { timeout: 600 });
  else setTimeout(start, 300);
});
</script>

<template>
  <AuroraBackground />
  <Silk v-if="active" @fail="active = false" />
</template>
