#!/usr/bin/env bash
# Offline check of rule_decide on a scratch repo (no network, no docker): bash test-deploy-rule.sh
set -euo pipefail
source "$(cd "$(dirname "$0")" && pwd)/deploy-rule.sh"
T="$(mktemp -d)"; trap 'rm -rf "${T}"' EXIT; cd "${T}"
git init -q -b main . && git config user.email t@t && git config user.name t
c() { git commit -q --allow-empty -m "$1"; git rev-parse HEAD; }
A=$(c a); X=$(c x)
git checkout -q -b br "${A}"; F=$(c f); git checkout -q main
git update-ref refs/remotes/origin/main "${X}"          # origin/main = A..X
exp() { [[ "$(rule_decide "${T}" "$1" "$2")" == "$3" ]] || { echo "FAIL good=${1:0:4} sha=${2:0:4} want $3 got $(rule_decide "${T}" "$1" "$2")"; exit 1; }; }
exp "" "${A}" DEPLOY; exp "${A}" "${X}" DEPLOY; exp "${X}" "${X}" SAME; exp "${X}" "${A}" NOOP_OLDER
exp "${A}" "${F}" NOOP_NOT_ON_MAIN                       # branch-only commit
exp "${A}" "0000000000000000000000000000000000000000" NOOP_NOT_ON_MAIN
git reset -q --hard "${A}"; Y=$(c y); git update-ref refs/remotes/origin/main "${Y}"   # force-push: X dropped, Y is new tip
exp "${X}" "${X}" NOOP_NOT_ON_MAIN                       # dropped commit never deployed
exp "${X}" "${Y}" DEPLOY                                 # stale good (off main) must not wedge updates
echo RULE_OK
