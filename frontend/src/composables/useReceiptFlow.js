import { ref } from 'vue';

/**
 * useReceiptFlow — post-payment receipt navigation + latest-payment-info modal
 * state (student management "未繳費 → 已繳費" fast-path).
 *
 * Rules encoded here — do not relax without re-checking why they exist:
 *   - Viewing a receipt always closes the latest-payment-info modal first. Both
 *     overlays share the same stacking context; opening one without closing the
 *     other leaves two overlays fighting for the same layer.
 *   - Admin “登記已回報” does not open a receipt. Receipts exist only after
 *     accounting confirms the pending PaymentReport (#1827).
 */
export function useReceiptFlow({ refreshCourses, toast } = {}) {
  const paymentEntryOpen = ref(false);
  const paymentEntryRow = ref(null);
  const paymentEntryStudentId = ref(null);

  const receiptOpen = ref(false);
  const receiptReportId = ref(null);

  const latestPaymentOpen = ref(false);
  const latestPaymentCourse = ref(null);

  function openPaymentEntry(row, studentId = null) {
    paymentEntryRow.value = row;
    paymentEntryStudentId.value = studentId;
    paymentEntryOpen.value = true;
  }

  function openReceiptByReport(reportId) {
    latestPaymentOpen.value = false;
    receiptReportId.value = Number(reportId);
    receiptOpen.value = true;
  }

  function openLatestPaymentInfo(course) {
    latestPaymentCourse.value = course;
    latestPaymentOpen.value = true;
  }

  async function onPaymentEntryConfirmed(result) {
    paymentEntryOpen.value = false;
    toast?.('已送出待對帳，請到帳務中心按確認入帳後才會開收據');

    // #1827: admin report is pending — do not open a receipt (R79: receipt is confirmed-only).
    const studentId = paymentEntryStudentId.value;
    paymentEntryStudentId.value = null;
    if (studentId && refreshCourses) {
      try {
        await refreshCourses(studentId);
      } catch (_e) {
        // Refresh failure is independent of the payment outcome — never
        // surfaced as a payment error, never retried against the payment API.
      }
    }
  }

  return {
    paymentEntryOpen,
    paymentEntryRow,
    paymentEntryStudentId,
    receiptOpen,
    receiptReportId,
    latestPaymentOpen,
    latestPaymentCourse,
    openPaymentEntry,
    openReceiptByReport,
    openLatestPaymentInfo,
    onPaymentEntryConfirmed,
  };
}
