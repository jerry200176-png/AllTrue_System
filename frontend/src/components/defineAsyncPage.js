import { defineAsyncComponent, defineComponent, h, onScopeDispose, ref, watch } from 'vue';
import { LOADING_DELAY_MS, useDelayedLoading } from '../composables/useDelayedLoading.js';
import AtSkeleton from './design-system/AtSkeleton.vue';
import { Button } from './ui/button';

// A chunk that fails for a reason other than a stale deploy (main.js reloads those) used to leave the page area empty.
const errorComponent = () => h('div', { role: 'alert', 'data-testid': 'page-load-error', class: 'tw:flex tw:flex-col tw:items-center tw:gap-3 tw:p-8 tw:text-center' }, [
  h('p', { class: 'tw:m-0 tw:text-tpl-body' }, '頁面載入失敗，請檢查網路後重新整理。'),
  h(Button, { size: 'lg', class: 'tw:min-h-11', onClick: () => window.location.reload() }, () => '重新整理'),
]);

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
    errorComponent,
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
