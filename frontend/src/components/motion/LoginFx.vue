<script setup>
/*
 * Login background: the CSS aurora is always the base (and the fallback); on capable devices a heavier effect loads
 * lazily on top. Variant A = silk shader (Vue Bits Silk, raw WebGL), B = sparkles (Inspira UI Sparkles, canvas 2D).
 * The variant comes from `?login_fx=a|b` in dev builds only; production uses A.
 * Falls back to the aurora when: prefers-reduced-motion, navigator.deviceMemory < 4, Save-Data, no WebGL (A), the
 * context is lost, or the effect chunk fails. The effect starts on idle so typing and the 登入 button are never delayed.
 */
import { defineAsyncComponent, onMounted, ref } from 'vue';
import AuroraBackground from './AuroraBackground.vue';
import { fxAllowed } from './fx/fxGate';

const variant = import.meta.env.DEV && typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('login_fx') === 'b' ? 'b' : 'a';
const Silk = defineAsyncComponent(() => import('./fx/SilkBackground.vue'));
const Sparkles = defineAsyncComponent(() => import('./fx/SparklesBackground.vue'));
const active = ref(false);

onMounted(() => {
  if (!fxAllowed({ needsWebGL: variant === 'a' })) return;
  const start = () => { active.value = true; };
  if (window.requestIdleCallback) window.requestIdleCallback(start, { timeout: 600 });
  else setTimeout(start, 300);
});
</script>

<template>
  <AuroraBackground />
  <Silk v-if="active && variant === 'a'" @fail="active = false" />
  <Sparkles v-else-if="active && variant === 'b'" />
</template>
