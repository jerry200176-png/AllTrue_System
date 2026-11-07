<script setup>
/*
 * Adapted from Inspira UI Sparkles (drifting, twinkling particles on a canvas)
 *   repo:    https://github.com/unovue/inspira-ui
 *   path:    app/components/inspira/ui/sparkles/Sparkles.vue
 *   commit:  eec920b047589058b4b02ba64deb614869e7237a
 *   licence: MIT, Copyright (c) 2024-2026 rahulv.dev (full text in ../LICENSE-inspira-ui)
 * Changes: plain JS; @vueuse useRafFn replaced by requestAnimationFrame that pauses while the tab is hidden;
 * transparent canvas drawn over the page's own orange background; colours from design tokens (white + gold);
 * pixel ratio capped at 1.5; fewer particles on small screens; hex alpha strings replaced by globalAlpha.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { cappedDpr, runFrames } from './fxGate';

const host = ref(null);
const canvas = ref(null);
let stop = () => {};
let ro = null;

onMounted(() => {
  const ctx = canvas.value.getContext('2d');
  const cs = getComputedStyle(document.documentElement);
  const colors = [cs.getPropertyValue('--ds-canvas').trim(), cs.getPropertyValue('--ds-brand-sun').trim()];
  let w = 0;
  let h = 0;
  let parts = [];
  const make = () => {
    const n = w < 600 ? 80 : 150;
    parts = Array.from({ length: n }, () => ({
      x: Math.random() * w, y: Math.random() * h,
      r: Math.random() * 2.6 + 1.2,
      vx: (Math.random() - 0.5) * 8, vy: -(Math.random() * 10 + 4),
      ph: Math.random() * Math.PI * 2, c: colors[Math.random() < 0.7 ? 0 : 1],
    }));
  };
  const resize = () => {
    const r = host.value.getBoundingClientRect();
    const d = cappedDpr();
    w = r.width; h = r.height;
    canvas.value.width = Math.round(w * d); canvas.value.height = Math.round(h * d);
    ctx.setTransform(d, 0, 0, d, 0, 0);
    make();
  };
  resize();
  ro = new ResizeObserver(resize);
  ro.observe(host.value);
  stop = runFrames((dt) => {
    ctx.clearRect(0, 0, w, h);
    for (const p of parts) {
      p.x += p.vx * dt; p.y += p.vy * dt; p.ph += dt * 1.6;
      if (p.y < -4) { p.y = h + 4; p.x = Math.random() * w; }
      if (p.x < -4) p.x = w + 4; else if (p.x > w + 4) p.x = -4;
      ctx.globalAlpha = 0.35 + 0.55 * (0.5 + 0.5 * Math.sin(p.ph));
      ctx.fillStyle = p.c;
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
      ctx.fill();
    }
  });
});
onBeforeUnmount(() => { stop(); ro?.disconnect(); });
</script>

<template>
  <div ref="host" class="sparkles" aria-hidden="true"><canvas ref="canvas" /></div>
</template>

<style scoped>
.sparkles { position: absolute; inset: 0; overflow: hidden; pointer-events: none; }
.sparkles canvas { position: absolute; inset: 0; width: 100%; height: 100%; }
</style>
