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
  it('preserves the pause/resume re-entry and authentication guards', () => {
    const flow = section('async function confirmCoursePause()', 'function canCloseCourse');

    expect(flow).toContain('if (pauseConfirmSubmitting.value) return;');
    expect(flow).toContain('const course = pauseConfirmTarget.value;');
    expect(flow).toContain('if (!course) return;');
    expect(flow).toContain('pauseConfirmSubmitting.value = true;');
    expect(flow).toContain('await supabase.auth.getSession()');
    expect(flow).toContain("if (!token) { alert('請重新登入'); return; }");
    expect(flow).toContain('pauseConfirmSubmitting.value = false;');
  });

  it('keeps pause and resume as explicit actions with asymmetric cancellation semantics', () => {
    const flow = section('async function confirmCoursePause()', 'function canCloseCourse');

    expect(flow).toContain("const body = { action: isPaused ? 'resume' : 'pause' };");
    expect(flow).toContain('if (!isPaused) body.cancel_remaining = !!pauseCancelRemaining.value;');
    expect(flow).toContain('method: \'POST\'');
    expect(flow).toContain('`/api/v1/student-classes/${course.id}/pause`');
    expect(flow).toContain('body: JSON.stringify(body)');
    expect(flow).toContain("'Authorization': `Bearer ${token}`");
    expect(flow).toContain('pauseConfirmTarget.value = null;');
    expect(flow).toContain('await loadCourses();');
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
});
