/**
 * TrueFit feature gate — compile-time + optional backend confirmation.
 * Both must be true for the workspace to activate.
 */

const COMPILE_ENABLED = import.meta.env.VITE_TRUEFIT_V1 === 'true';

let backendEnabled = null;

export function isTrueFitCompileEnabled() {
  return COMPILE_ENABLED;
}

export function isTrueFitFeatureEnabled() {
  return COMPILE_ENABLED && backendEnabled !== false;
}

export async function loadTrueFitBackendFlag(token) {
  if (!COMPILE_ENABLED) {
    backendEnabled = false;
    return false;
  }
  try {
    const res = await fetch('/api/v1/truefit/today-sessions', {
      headers: {
        Accept: 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    });
    if (res.status === 404) {
      backendEnabled = false;
      return false;
    }
    backendEnabled = res.ok || res.status === 403;
    return backendEnabled;
  } catch {
    backendEnabled = null;
    return COMPILE_ENABLED;
  }
}

export function resetTrueFitBackendFlagForTests() {
  backendEnabled = null;
}
