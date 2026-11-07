// Shared gate + helpers for the heavy Login effects. Everything here is cheap and runs before any effect chunk loads.
import { prefersReducedMotion } from '../reduced';

export function fxAllowed({ needsWebGL = false } = {}) {
  if (typeof window === 'undefined' || prefersReducedMotion()) return false;
  const nav = navigator;
  if (nav.connection?.saveData) return false;
  if (typeof nav.deviceMemory === 'number' && nav.deviceMemory < 4) return false;
  if (needsWebGL) {
    try {
      const gl = document.createElement('canvas').getContext('webgl');
      if (!gl) return false;
      gl.getExtension('WEBGL_lose_context')?.loseContext(); // release the probe context
    } catch { return false; }
  }
  return true;
}

export const cappedDpr = () => Math.min(window.devicePixelRatio || 1, 1.5);

// Resolve a CSS token (var(--ds-...)) to [r, g, b] in 0..1, so components never hold a raw hex.
export function tokenRgb(name) {
  const probe = document.createElement('i');
  probe.style.color = `var(${name})`;
  document.body.appendChild(probe);
  const m = getComputedStyle(probe).color.match(/[\d.]+/g) || [0, 0, 0];
  probe.remove();
  return [m[0] / 255, m[1] / 255, m[2] / 255];
}

// Run `loop(dtSeconds)` every frame, paused while the tab is hidden. Returns stop().
export function runFrames(loop) {
  let raf = 0;
  let last = 0;
  const tick = (t) => {
    const dt = last ? Math.min((t - last) / 1000, 0.1) : 0;
    last = t;
    loop(dt);
    raf = requestAnimationFrame(tick);
  };
  const start = () => { if (!raf) { last = 0; raf = requestAnimationFrame(tick); } };
  const stop = () => { if (raf) cancelAnimationFrame(raf); raf = 0; };
  const onVis = () => (document.hidden ? stop() : start());
  document.addEventListener('visibilitychange', onVis);
  start();
  return () => { stop(); document.removeEventListener('visibilitychange', onVis); };
}
