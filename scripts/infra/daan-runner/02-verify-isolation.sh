#!/usr/bin/env bash
# Step 2/3 — PROVE the isolation before any runner is enabled. Prints PASS/FAIL per check; exit 1 on any FAIL.
# Run on daan-ai:  sudo bash 02-verify-isolation.sh
# Every check runs AS ghrunner (the identity CI jobs run as), both on the host and inside its rootless Docker.
set -uo pipefail

RUNNER_USER=ghrunner
STAGING_DIR=/home/admin/alltrue-stage
STAGING_ENV=$STAGING_DIR/AllTrue_System/backend/.env
RUNNER_UID=$(id -u "$RUNNER_USER")
AS=(sudo -iu "$RUNNER_USER" env XDG_RUNTIME_DIR="/run/user/$RUNNER_UID" DOCKER_HOST="unix:///run/user/$RUNNER_UID/docker.sock")
fails=0
pass() { echo "PASS  $1"; }
fail() { echo "FAIL  $1"; fails=$((fails + 1)); }
must_fail() { local name="$1"; shift; if "$@" >/dev/null 2>&1; then fail "$name"; else pass "$name"; fi; }

echo "== identity"
groups_of=$(id -nG "$RUNNER_USER")
case " $groups_of " in *" docker "*|*" sudo "*|*" admin "*|*" root "*) fail "ghrunner groups: $groups_of";; *) pass "ghrunner not in docker/sudo/admin/root ($groups_of)";; esac
must_fail "ghrunner has no sudo" "${AS[@]}" sudo -n true
must_fail "ghrunner cannot use the SYSTEM docker daemon" "${AS[@]}" docker -H unix:///var/run/docker.sock ps

must_fail "ghrunner cannot modify cleanup hooks" "${AS[@]}" bash -c 'echo x >> /usr/local/lib/daan-runner/job-completed.sh'
must_fail "ghrunner cannot modify runner binaries" "${AS[@]}" bash -c 'echo x >> /opt/actions-runner/runsvc.sh'
must_fail "ghrunner cannot add files to runner bin/" "${AS[@]}" touch /opt/actions-runner/bin/planted
must_fail "ghrunner cannot rewrite runner .env (hooks)" "${AS[@]}" bash -c 'echo x >> /opt/actions-runner/.env'
nft list table inet daan_runner_guard >/dev/null 2>&1 && pass "firewall guard table loaded" || fail "firewall guard table NOT loaded"
echo "== staging files and credentials (host)"
must_fail "cannot list staging dir"          "${AS[@]}" ls "$STAGING_DIR"
must_fail "cannot read staging .env"         "${AS[@]}" cat "$STAGING_ENV"
must_fail "cannot read /etc/mysql/debian.cnf" "${AS[@]}" cat /etc/mysql/debian.cnf
must_fail "cannot read admin ssh keys"       "${AS[@]}" ls /home/admin/.ssh
leaks=$("${AS[@]}" bash -c 'grep -rlsI -E "DB_PASSWORD=.+|APP_KEY=base64" /etc /opt /srv /var/www /home 2>/dev/null | grep -v "^/home/'"$RUNNER_USER"'/" | head -5')
[ -z "$leaks" ] && pass "no readable file with DB_PASSWORD/APP_KEY outside ghrunner home" || fail "readable secrets: $leaks"

echo "== staging files and credentials (inside rootless container)"
"${AS[@]}" docker info --format '{{.SecurityOptions}}' 2>/dev/null | grep -q rootless && pass "ghrunner docker is rootless" || fail "ghrunner docker is NOT rootless"
must_fail "container cannot read staging .env via bind mount" "${AS[@]}" docker run --rm -v "$STAGING_DIR:/s:ro" alpine:3.20 cat /s/AllTrue_System/backend/.env
must_fail "container cannot read /etc/shadow via / mount"     "${AS[@]}" docker run --rm -v /:/host:ro alpine:3.20 cat /host/etc/shadow
must_fail "container cannot run privileged into host ns"      "${AS[@]}" docker run --rm --privileged --pid=host alpine:3.20 nsenter -t 1 -m cat /etc/shadow

echo "== staging database"
must_fail "host TCP 127.0.0.1:3306 blocked"   "${AS[@]}" timeout 5 bash -c 'exec 3<>/dev/tcp/127.0.0.1/3306'
must_fail "host TCP ::1:3306 blocked"         "${AS[@]}" timeout 5 bash -c 'exec 3<>/dev/tcp/::1/3306'
must_fail "mysql socket login as root fails"  "${AS[@]}" mysql -uroot -e 'select 1'
must_fail "mysql socket login as ghrunner fails" "${AS[@]}" mysql -e 'select 1'
must_fail "container cannot reach host MySQL (host loopback via 10.0.2.2)" "${AS[@]}" docker run --rm alpine:3.20 nc -z -w 3 10.0.2.2 3306
host_ip=$(hostname -I | awk '{print $1}')
must_fail "container cannot reach host MySQL on $host_ip" "${AS[@]}" docker run --rm alpine:3.20 nc -z -w 3 "$host_ip" 3306
# Positive control: CI's own throwaway DB on 33306 still works (so blocking is specific, not broken networking).
"${AS[@]}" docker run -d --rm --name verify-ci-db -p 127.0.0.1:33306:3306 -e MYSQL_ALLOW_EMPTY_PASSWORD=1 mysql:8.0 >/dev/null 2>&1
for _ in $(seq 1 30); do "${AS[@]}" docker exec verify-ci-db mysqladmin ping -h 127.0.0.1 >/dev/null 2>&1 && break; sleep 2; done
"${AS[@]}" docker exec verify-ci-db mysqladmin ping -h 127.0.0.1 >/dev/null 2>&1 && pass "control: CI's own MySQL container works" || fail "control: CI's own MySQL container did not start"
"${AS[@]}" docker rm -f verify-ci-db >/dev/null 2>&1

echo "== cleanup deletes only this job's resources"
SYS_BEFORE=$(docker ps -aq 2>/dev/null | sort | md5sum)
"${AS[@]}" docker run -d --name pre-existing-not-ours alpine:3.20 sleep 600 >/dev/null
TMP=$(mktemp -d); chown "$RUNNER_USER" "$TMP"
"${AS[@]}" env RUNNER_TEMP="$TMP" GITHUB_WORKSPACE=/opt/actions-runner/_work/verify/verify bash /usr/local/lib/daan-runner/job-started.sh
"${AS[@]}" mkdir -p /opt/actions-runner/_work/verify/verify && "${AS[@]}" touch /opt/actions-runner/_work/verify/verify/job-file
"${AS[@]}" docker run -d --name created-by-job alpine:3.20 sleep 600 >/dev/null
"${AS[@]}" docker network create created-by-job-net >/dev/null
"${AS[@]}" env RUNNER_TEMP="$TMP" bash /usr/local/lib/daan-runner/job-completed.sh
"${AS[@]}" docker ps -a --format '{{.Names}}' | grep -qx created-by-job && fail "job container not removed" || pass "job container removed"
"${AS[@]}" docker network ls --format '{{.Name}}' | grep -qx created-by-job-net && fail "job network not removed" || pass "job network removed"
"${AS[@]}" docker ps -a --format '{{.Names}}' | grep -qx pre-existing-not-ours && pass "pre-existing container kept" || fail "pre-existing container was deleted"
[ -z "$(ls -A /opt/actions-runner/_work/verify/verify 2>/dev/null)" ] && pass "job workspace emptied" || fail "job workspace not emptied"
[ -d "$STAGING_DIR" ] && pass "staging dir untouched" || fail "staging dir missing"
SYS_AFTER=$(docker ps -aq 2>/dev/null | sort | md5sum)
[ "$SYS_BEFORE" = "$SYS_AFTER" ] && pass "system docker containers unchanged" || fail "system docker containers changed"
"${AS[@]}" docker rm -f pre-existing-not-ours >/dev/null 2>&1; rm -rf "$TMP" /opt/actions-runner/_work/verify

echo
if [ "$fails" -eq 0 ]; then echo "ALL CHECKS PASSED — safe to run 03-enable-runner.sh"; exit 0; fi
echo "$fails CHECK(S) FAILED — do NOT enable the runner"; exit 1
