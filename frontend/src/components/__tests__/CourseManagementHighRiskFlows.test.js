import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/CourseManagement.vue'), 'utf8');

function section(startMarker, endMarker) {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start + startMarker.length);
  expect(start, `missing start marker: ${startMarker}`).toBeGreaterThanOrEqual(0);
  expect(end, `missing end marker: ${endMarker}`).toBeGreaterThan(start);
  return source.slice(start, end);
}

describe('CourseManagement high-risk flow characterization', () => {
  // Pause/resume now lives in useCoursePause (behavior pinned in its own unit test);
  // these keep the source-level intent and check the page still delegates to it.
  const pauseFlow = readFileSync(resolve(__dirname, '../../composables/course-management/useCoursePause.js'), 'utf8');

  it('preserves the pause/resume re-entry and authentication guards', () => {
    expect(pauseFlow).toContain('if (submitting.value) return;');
    expect(pauseFlow).toContain('const course = target.value;');
    expect(pauseFlow).toContain('if (!course) return;');
    expect(pauseFlow).toContain('submitting.value = true;');
    expect(pauseFlow).toContain('await getAccessToken()');
    expect(pauseFlow).toContain("if (!token) { notify('請重新登入'); return; }");
    expect(pauseFlow).toContain('submitting.value = false;');
    expect(source).toContain('} = useCoursePause({');
  });

  it('keeps pause and resume as explicit actions with asymmetric cancellation semantics', () => {
    expect(pauseFlow).toContain("const body = { action: paused ? 'resume' : 'pause' };");
    expect(pauseFlow).toContain('if (!paused) body.cancel_remaining = !!cancelRemaining.value;');
    expect(pauseFlow).toContain("method: 'POST'");
    expect(pauseFlow).toContain('`/api/v1/student-classes/${course.id}/pause`');
    expect(pauseFlow).toContain('body: JSON.stringify(body)');
    expect(pauseFlow).toContain('}, token);');
    expect(pauseFlow).toContain('target.value = null;');
    expect(pauseFlow).toContain('await onChanged();');
    expect(source).toContain('await loadCourses();\n    syncCourseManagerCourseFromList();');
  });

  it('preserves transfer candidate identity and subject boundaries before mutation', () => {
    const lookup = section('async function loadTransferTargetCourses(sourceCourse)', 'async function submitTransferSessions');

    expect(lookup).toContain('per_page: \'100\'');
    expect(lookup).toContain('params.set(\'student_id\', String(studentId));');
    expect(lookup).not.toContain('branch_id: String(props.branchId)');
    expect(lookup).toContain('.filter((course) => course.id !== Number(sourceCourse.id))');
    expect(lookup).toContain('.filter((course) => sameCourseStudent(sourceCourse, course))');
    expect(lookup).toContain('.filter((course) => sameCourseSubject(sourceCourse, course))');
    expect(lookup).toContain('if (requestId === transferTargetCoursesRequest) transferTargetCourses.value = candidates;');
    expect(lookup).toContain('if (requestId === transferTargetCoursesRequest) transferTargetCoursesLoading.value = false;');
  });

  it('preserves the recover-versus-transfer endpoint and reason contract', () => {
    const flow = section('async function submitTransferSessions', 'const purchaseForm = ref');

    expect(flow).toContain('if (!course || sessionIds.length === 0) return;');
    expect(flow).toContain('const hasRecovery = transferSessionsSessionOptions.value.some(');
    expect(flow).toContain("const endpoint = hasRecovery ? 'recover-transfer-sessions' : 'transfer-sessions';");
    expect(flow).toContain('`/api/v1/student-classes/${course.id}/${endpoint}`');
    expect(flow).toContain('session_ids: sessionIds,');
    expect(flow).toContain('target_student_class_id: targetCourseId,');
    expect(flow).toContain('...(hasRecovery ? { reason } : {}),');
    expect(flow).toContain('json?.conflict_session_id');
    expect(flow).toContain('json?.conflict_schedule_id');
    expect(flow).toContain('showTransferSessionsModal.value = false;');
    expect(flow).toContain('await loadCourses();');
  });

  it('preserves purchase validation, authentication, and mode-specific endpoints', () => {
    const flow = section('async function submitPurchaseSessions()', 'async function submitRenewMonthly');

    expect(flow).toContain('if (purchaseSubmitting.value) return;');
    expect(flow).toContain('if (!course?.id) return;');
    expect(flow).toContain("alert('請輸入正確堂數')");
    expect(flow).toContain("alert('請選擇新批次開始日期')");
    expect(flow).toContain('`/api/v1/student-classes/${course.id}/convert-trial`');
    expect(flow).toContain('`/api/v1/student-classes/${course.id}/purchase-batch`');
    expect(flow).toContain("mode: 'new_purchase'");
    expect(flow).toContain('await updatePackage(packageId, { total_sessions: nextTotal });');
    expect(flow).toContain('purchaseSubmitting.value = false;');
  });

  it('preserves monthly renewal preview and mutation contracts', () => {
    // Request/payload logic lives in the shared composable; the page keeps its guards and post-success UI.
    const composable = readFileSync(`${process.cwd()}/src/composables/course-management/useMonthlyRenewal.js`, 'utf8');
    const submit = section('async function submitRenewMonthly', 'function openQuickAddSessionModal');

    expect(composable).toContain('`/api/v1/student-classes/${course.id}/renewal-preview`');
    expect(composable).toContain("mode: 'renew_monthly'");
    expect(composable).toContain("if (res.ok || json.severity === 'blocked')");
    expect(composable).toContain('warnings.value = [...(json.warnings || []), ...(json.blockers || [])];');
    expect(composable).toContain('applyMonthlyRenewalPreview(form.value, json);');
    expect(submit).toContain('if (renewMonthlySubmitting.value) return;');
    expect(submit).toContain("alert('請選擇新到期日或延長月數')");
    expect(composable).toContain('`/api/v1/student-classes/${course.id}/renew-monthly`');
    expect(composable).toContain('JSON.stringify({ end_date: endDate, ...(');
    expect(submit).toContain('monthlyRenewal.submit(course, endDate)');
    expect(submit).toContain('showRenewMonthlyModal.value = false;');
    expect(submit).toContain('await loadCourses();');
    expect(submit).toContain('renewMonthlySubmitting.value = false;');
  });
});
