export function getSessionUserId(candidate) {
  const id = candidate?.user?.id;
  if (typeof id !== 'string' && typeof id !== 'number') return null;
  const normalized = String(id).trim();
  return normalized || null;
}

export function isLocallyCorruptSession(candidate) {
  return candidate != null && getSessionUserId(candidate) == null;
}

export function shouldClearLocalIdentity({ event, session, responseStatus }) {
  return isLocallyCorruptSession(session)
    || event === 'SIGNED_OUT'
    || responseStatus === 401;
}

export function isCurrentAuthRevision(expected, current) {
  return expected === current;
}
