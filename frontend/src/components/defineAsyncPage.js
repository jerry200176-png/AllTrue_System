import { defineComponent, h, onBeforeMount, ref, shallowRef } from 'vue';
import { useDelayedLoading } from '../composables/useDelayedLoading.js';
import AtSkeleton from './design-system/AtSkeleton.vue';

export function defineAsyncPage(loader) {
  let request;
  let resolvedPage;

  function loadPage() {
    request ??= Promise.resolve().then(loader).then((module) => {
      resolvedPage = module.default ?? module;
      return resolvedPage;
    }).catch((error) => {
      request = undefined;
      throw error;
    });
    return request;
  }

  return defineComponent({
    name: 'AsyncPage',
    inheritAttrs: false,
    setup(_, { attrs, slots }) {
      const page = shallowRef(resolvedPage);
      const loading = ref(!page.value);
      const showSkeleton = useDelayedLoading(loading);

      if (loading.value) {
        onBeforeMount(async () => {
          try {
            page.value = await loadPage();
          } finally {
            loading.value = false;
          }
        });
      }

      return () => {
        if (showSkeleton.value) return h(AtSkeleton, { revealImmediately: true });
        return page.value ? h(page.value, attrs, slots) : null;
      };
    },
  });
}
