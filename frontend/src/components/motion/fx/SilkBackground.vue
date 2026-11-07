<script setup>
/*
 * Adapted from Vue Bits Silk (flowing silk shader background)
 *   repo:    https://github.com/DavidHDev/vue-bits
 *   path:    src/content/Backgrounds/Silk/Silk.vue
 *   commit:  3766ebeeed37ec35bce29f001b6d101c7b17da35
 *   licence: MIT + Commons Clause (c) 2025 David Haz: use inside an application is allowed, selling the code itself is not.
 * Changes: the `ogl` dependency is replaced with raw WebGL (one full-screen triangle, same fragment maths); colours come
 * from design tokens (deep orange -> gold) instead of one hex; canvas pixel ratio capped at 1.5; the loop pauses while
 * the tab is hidden; the component emits `fail` when WebGL is missing or the context is lost so the parent keeps the
 * CSS aurora. Decorative: pointer-events none, aria-hidden.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { cappedDpr, runFrames, tokenRgb } from './fxGate';

const emit = defineEmits(['fail']);
const host = ref(null);
let stopFrames = () => {};
let ro = null;
let gl = null;

const VERT = 'attribute vec2 p; varying vec2 vUv; void main(){ vUv = p * 0.5 + 0.5; gl_Position = vec4(p, 0.0, 1.0); }';
const FRAG = `
precision mediump float;
varying vec2 vUv;
uniform float uTime; uniform float uAspect; uniform vec3 uDeep; uniform vec3 uGold;
float noise(vec2 t){ vec2 r = 2.71828 * sin(2.71828 * t); return fract(r.x * r.y * (1.0 + t.x)); }
void main(){
  float rnd = noise(gl_FragCoord.xy);
  vec2 tex = vec2(vUv.x * uAspect, vUv.y) * 1.3;
  float t = 5.0 * uTime;
  tex.y += 0.03 * sin(8.0 * tex.x - t);
  float pattern = 0.6 + 0.4 * sin(5.0 * (tex.x + tex.y + cos(3.0 * tex.x + 5.0 * tex.y) + 0.02 * t) + sin(20.0 * (tex.x + tex.y - 0.1 * t)));
  vec3 col = mix(uDeep, uGold, pattern) - rnd / 15.0 * 0.5;
  gl_FragColor = vec4(col, 1.0);
}`;

onMounted(() => {
  const canvas = document.createElement('canvas');
  canvas.className = 'silk__canvas';
  gl = canvas.getContext('webgl', { antialias: false, powerPreference: 'low-power' });
  if (!gl) { emit('fail'); return; }
  const sh = (type, src) => { const s = gl.createShader(type); gl.shaderSource(s, src); gl.compileShader(s); return s; };
  const prog = gl.createProgram();
  gl.attachShader(prog, sh(gl.VERTEX_SHADER, VERT));
  gl.attachShader(prog, sh(gl.FRAGMENT_SHADER, FRAG));
  gl.linkProgram(prog);
  if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) { emit('fail'); return; }
  gl.useProgram(prog);
  const buf = gl.createBuffer();
  gl.bindBuffer(gl.ARRAY_BUFFER, buf);
  gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW);
  const loc = gl.getAttribLocation(prog, 'p');
  gl.enableVertexAttribArray(loc);
  gl.vertexAttribPointer(loc, 2, gl.FLOAT, false, 0, 0);
  const u = (n) => gl.getUniformLocation(prog, n);
  gl.uniform3fv(u('uDeep'), tokenRgb('--ds-primary'));
  gl.uniform3fv(u('uGold'), tokenRgb('--ds-brand-sun'));
  const uTime = u('uTime');
  const uAspect = u('uAspect');
  host.value.appendChild(canvas);
  canvas.addEventListener('webglcontextlost', (e) => { e.preventDefault(); stopFrames(); canvas.remove(); emit('fail'); });

  const resize = () => {
    const r = host.value.getBoundingClientRect();
    const d = cappedDpr();
    canvas.width = Math.max(1, Math.round(r.width * d));
    canvas.height = Math.max(1, Math.round(r.height * d));
    gl.viewport(0, 0, canvas.width, canvas.height);
    gl.uniform1f(uAspect, r.width / Math.max(1, r.height));
  };
  resize();
  ro = new ResizeObserver(resize);
  ro.observe(host.value);
  let time = 0;
  stopFrames = runFrames((dt) => {
    time += 0.1 * dt;
    gl.uniform1f(uTime, time);
    gl.drawArrays(gl.TRIANGLES, 0, 3);
  });
});

onBeforeUnmount(() => {
  stopFrames();
  ro?.disconnect();
  gl?.getExtension('WEBGL_lose_context')?.loseContext();
});
</script>

<template>
  <div ref="host" class="silk" aria-hidden="true" />
</template>

<style scoped>
.silk { position: absolute; inset: 0; overflow: hidden; pointer-events: none; }
.silk :deep(.silk__canvas) { position: absolute; inset: 0; width: 100%; height: 100%; display: block; }
</style>
