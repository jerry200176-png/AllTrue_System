export async function trackAdoptionEvent(event, branchId, meta = {}) {
  try {
    const payload = {
      event,
      branch_id: Number(branchId) || undefined,
      meta,
    };
    await fetch('/api/v1/adoption/events', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify(payload),
    });
  } catch {
    // Keep UX non-blocking if telemetry endpoint is unavailable.
  }
}

