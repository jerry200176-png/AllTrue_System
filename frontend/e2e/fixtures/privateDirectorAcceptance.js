// Fixed diagnostic vocabulary only. Never copy original errors, DOM, URLs or credential input into CI output.
const STAGES = new Set(['start', 'identity', 'calendar-fixture', 'login-page', 'login-form', 'login-submit', 'login-complete',
  'navigate-assessment', 'navigate-billing', 'navigate-calendar', 'assessment-editor', 'cancel-rollback', 'choose-student',
  'export-workbook', 'validate-workbook', 'calendar-original', 'calendar-reschedule', 'calendar-replacement', 'verify-guards', 'verify-identity']);

export async function privateAcceptance(work) {
  let stage = 'start', loginHttp = null;
  const diagnostic = {
    stage(value) { stage = STAGES.has(value) ? value : 'unknown'; },
    loginStatus(value) { loginHttp = Number.isInteger(value) && value >= 100 && value <= 599 ? value : null; },
  };
  try { return await work(diagnostic); }
  catch {
    throw new Error(`UNVERIFIED: director path acceptance did not complete; stage=${stage}; login_http=${loginHttp ?? 'unknown'}; private details suppressed.`);
  }
}
