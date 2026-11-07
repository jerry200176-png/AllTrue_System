#!/usr/bin/env bash
# Pure decision rule for auto-update.sh. rule_decide <repo> <good> <sha> -> prints DEPLOY | NOOP_NOT_ON_MAIN | NOOP_OLDER | SAME
rule_decide() {
  local g=(git -C "$1") good="$2" sha="$3"
  "${g[@]}" merge-base --is-ancestor "${sha}" origin/main 2>/dev/null || { echo NOOP_NOT_ON_MAIN; return; }
  [[ "${sha}" == "${good}" ]] && { echo SAME; return; }
  if [[ -n "${good}" ]] && "${g[@]}" merge-base --is-ancestor "${good}" origin/main 2>/dev/null \
     && ! "${g[@]}" merge-base --is-ancestor "${good}" "${sha}" 2>/dev/null; then echo NOOP_OLDER; return; fi
  echo DEPLOY
}
