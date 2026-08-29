const LIMITS = {
  occurrenceAt: 80,
  relatedReference: 300,
  screenSize: 40,
  timeZone: 100,
};

function boundedText(value, maxLength) {
  return typeof value === 'string' ? value.trim().slice(0, maxLength) : '';
}

export function parseBugReportClientInfo(raw) {
  if (typeof raw !== 'string' || !raw.trim()) return null;

  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch {
    return null;
  }
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return null;

  const context = Object.fromEntries(
    Object.entries(LIMITS).map(([key, limit]) => [key, boundedText(parsed[key], limit)]),
  );
  return Object.values(context).some(Boolean) ? context : null;
}
