import { defineAsyncComponent, defineComponent, h, onScopeDispose, ref, watch } from 'vue';
import { LOADING_DELAY_MS, useDelayedLoading } from '../composables/useDelayedLoading.js';
import AtSkeleton from './design-system/AtSkeleton.vue';

export function defineAsyncPage(loader) {
  const visibleSkeletons = new Set();
  const loadingComponent = defineComponent({
    inheritAttrs: false,
    setup() {
      const show = useDelayedLoading(ref(true));
      let finish;
      watch(show, (visible) => {
        if (!visible) return;
        const minimum = new Promise((resolve) => {
          const timer = setTimeout(resolve, LOADING_DELAY_MS);
          finish = () => {
            clearTimeout(timer);
            resolve();
          };
        });
        visibleSkeletons.add(minimum);
        minimum.then(() => visibleSkeletons.delete(minimum));
      }, { flush: 'sync' });
      onScopeDispose(() => finish?.());
      return () => show.value ? h(AtSkeleton, { revealImmediately: true }) : null;
    },
  });

  return defineAsyncComponent({
    delay: 0,
    loadingComponent,
    errorComponent: () => null,
    async loader() {
      try {
        const module = await loader();
        return module.default ?? module;
      } finally {
        // Wait for visible skeletons on success and failure, including any shown while waiting.
        while (visibleSkeletons.size) await Promise.all(visibleSkeletons);
      }
    },
  });
}
