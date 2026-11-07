import { onBeforeUnmount, ref, watch } from 'vue';

// iOS large-title collapse: `collapsed` turns true once the large title (the element behind `titleRef`) has scrolled
// up under the sticky bar (`barHeight` px). IntersectionObserver, no scroll listener; follows the ref so a title
// that mounts later (after data loads) is still observed. Reduced motion is handled in CSS.
export function useCollapse(titleRef, barHeight = 48) {
  const collapsed = ref(false);
  let io = null;
  watch(titleRef, (target) => {
    io?.disconnect();
    io = null;
    const el = target?.$el || target;
    if (!el || typeof IntersectionObserver === 'undefined') { collapsed.value = false; return; }
    io = new IntersectionObserver(([e]) => { collapsed.value = !e.isIntersecting && e.boundingClientRect.top < barHeight; }, { rootMargin: `-${barHeight}px 0px 0px 0px` });
    io.observe(el);
  }, { flush: 'post' });
  onBeforeUnmount(() => io?.disconnect());
  return collapsed;
}
