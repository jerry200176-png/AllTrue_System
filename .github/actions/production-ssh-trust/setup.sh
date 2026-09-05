#!/usr/bin/env bash
set -euo pipefail

: "${INPUT_HOST:?production SSH host is required}"
: "${INPUT_HOST_KEY:?pinned production SSH host key is required}"

host=${INPUT_HOST}
host_key=${INPUT_HOST_KEY}

# The host is used in an SSH config pattern. Keep it strictly hostname/IP-like
# so neither input can inject config directives or a second known_hosts entry.
if [[ ! "$host" =~ ^[A-Za-z0-9][A-Za-z0-9._:-]*$ ]]; then
  echo '::error::invalid production SSH host' >&2
  exit 1
fi
ssh_dir=${HOME}/.ssh
known_hosts=${ssh_dir}/known_hosts
config=${ssh_dir}/config
candidate=${known_hosts}.candidate
install -d -m 700 "$ssh_dir"
umask 077
trap 'rm -f "$candidate"' EXIT
printf '%s\n' "$host_key" > "$candidate"

# Validate that the pinned entry actually covers the host before any SSH call.
# ssh-keygen -F supports both clear-host and hashed-host known_hosts entries.
entry_count=$(awk 'NF && $1 !~ /^#/ { count++ } END { print count + 0 }' "$candidate")
parsed_count=$(ssh-keygen -lf "$candidate" 2>/dev/null | wc -l)
if [[ "$entry_count" -lt 1 || "$parsed_count" -ne "$entry_count" ]]; then
  echo '::error::PI_HOST_KEY must contain only parseable pinned known_hosts entries' >&2
  exit 1
fi

# Multiple pinned algorithms and host aliases are valid in the authoritative
# secret. Narrow the runtime file to entries matching this exact target so an
# unrelated alias is never consulted, while still refusing a missing pin.
if ! ssh-keygen -F "$host" -f "$candidate" 2>/dev/null \
  | awk '$1 !~ /^#/ { print }' > "$known_hosts"; then
  echo '::error::unable to extract the configured production host pin' >&2
  exit 1
fi
matched_count=$(awk 'NF && $1 !~ /^#/ { count++ } END { print count + 0 }' "$known_hosts")
echo "Pinned production host-key validation: entries=$entry_count parsed=$parsed_count matched=$matched_count"
if [[ "$matched_count" -lt 1 ]]; then
  echo '::error::PI_HOST_KEY does not contain the configured production host' >&2
  exit 1
fi

# Make the fail-closed policy apply even to legacy workflow SSH invocations
# that do not repeat -o flags. The action is run in the same job before SSH.
{
  printf '%s\n' 'Host *'
  printf '  StrictHostKeyChecking yes\n'
  printf '  UserKnownHostsFile %s\n' "$known_hosts"
  printf '%s\n' '  GlobalKnownHostsFile /dev/null'
  printf '%s\n' '  CheckHostIP no'
} > "$config"
chmod 600 "$known_hosts" "$config"

if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
  printf 'known_hosts=%s\n' "$known_hosts" >> "$GITHUB_OUTPUT"
fi
