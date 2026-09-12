export function getSessionUserId(candidate) {
  const id = candidate?.user?.id;
  return typeof id === 'string' && id.trim() ? id : null;
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
