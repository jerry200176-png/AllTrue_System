import { toast } from 'vue-sonner';

// Results only (「已儲存」「已刪除」). Errors stay inline in the form that failed; do not toast them.
// Mount <Toaster /> from components/ui/sonner once in App.vue.
export const toastResult = (message, options = {}) => toast.success(message, { duration: 4000, ...options });
export const toastUndo = (message, onUndo) => toast.success(message, { duration: 6000, action: { label: '復原', onClick: onUndo } });
