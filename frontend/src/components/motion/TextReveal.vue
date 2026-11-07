<script setup>
/*
 * Per-character blur-in title reveal.
 *   concept: Inspira UI Text Generate Effect (https://github.com/unovue/inspira-ui, MIT, commit eec920b047589058b4b02ba64deb614869e7237a,
 *            app/components/inspira/ui/text-generate-effect) and Vue Bits SplitText (DavidHDev/vue-bits, MIT + Commons Clause).
 *            Both drive per-word / per-char motion with JS timelines (motion-v / GSAP); this re-implements the idea with one
 *            CSS animation and a per-character delay. Full licence text for Inspira: ./LICENSE-inspira-ui.
 * Screen readers get the whole title once (sr-only text); the animated characters sit in one aria-hidden wrapper. prefers-reduced-motion shows it at once.
 */
defineProps({
  text: { type: String, required: true },
  delay: { type: Number, default: 0 }, // ms before the first character
});
</script>

<template>
  <span class="text-reveal"><span class="tw:sr-only">{{ text }}</span><span aria-hidden="true"><span v-for="(c, i) in [...text]" :key="i" class="text-reveal__c" :style="{ '--i': i, '--d': `${delay}ms` }">{{ c }}</span></span></span>
</template>

<style scoped>
.text-reveal__c { display: inline-block; animation: text-reveal-in 360ms ease-out calc(var(--d) + var(--i) * 40ms) both; }
@keyframes text-reveal-in { from { opacity: 0; filter: blur(6px); transform: translateY(8px); } to { opacity: 1; filter: none; transform: none; } }
@media (prefers-reduced-motion: reduce) { .text-reveal__c { animation: none; } }
</style>
