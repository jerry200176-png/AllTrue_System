/**
 * Session provenance is a claim about THIS change set, not a singleton on main.
 * An inherited .agent-session/manifest.json from a previous merge is leftover
 * text. Binding it to the current branch fabricates a session that was never
 * claimed. Self-authored JSON is only evidence when this PR adds or updates it.
 */

export const AGENT_SESSION_FILE = '.agent-session/manifest.json';
export const HUMAN_AUTHORED_FILE = '.agent-session/human-authored.json';

export function claimedSessionFiles(diffNames = []) {
  const set = new Set(diffNames.filter(Boolean));
  return {
    agent: set.has(AGENT_SESSION_FILE),
    human: set.has(HUMAN_AUTHORED_FILE),
  };
}

/** Bind task/branch/base_sha only when this PR claims an agent session. */
export function shouldBindAgentSession(claimed) {
  return Boolean(claimed?.agent);
}
