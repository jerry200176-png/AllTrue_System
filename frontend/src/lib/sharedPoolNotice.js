// A shared lesson plan that has used what it bought still checks in; the message says to renew (Founder 2026-11-01).
export const withPoolNotice = (msg, ...bodies) => [msg, ...new Set(bodies.map((b) => b?.package_notice).filter(Boolean))].join(' ');
