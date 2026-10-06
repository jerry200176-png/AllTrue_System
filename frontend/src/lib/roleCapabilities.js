// Single definition of "who counts as a director" for frontend presentation.
// Backend permissions remain the security boundary. The backend only emits
// teacher | director | super_admin (AuthController); 'admin' is kept for legacy sessions.
const DIRECTOR_ROLES = new Set(['director', 'admin', 'super_admin']);

export function isDirectorRole(role) {
  return DIRECTOR_ROLES.has(role);
}
