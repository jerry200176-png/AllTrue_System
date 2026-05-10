/**
 * Lightweight GET /api/v1/me for fragments not mirrored in local session state.
 */

export async function fetchMe(accessToken) {
  if (!accessToken) return null;
  try {
    const res = await fetch('/api/v1/me', {
      headers: {
        Authorization: `Bearer ${accessToken}`,
        Accept: 'application/json',
      },
    });
    if (!res.ok) return null;
    return await res.json();
  } catch {
    return null;
  }
}
