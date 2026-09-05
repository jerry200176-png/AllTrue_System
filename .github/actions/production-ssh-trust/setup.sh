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
install -d -m 700 "$ssh_dir"
umask 077
printf '%s\n' "$host_key" > "$known_hosts"

# Validate that the pinned entry actually covers the host before any SSH call.
# ssh-keygen -F supports both clear-host and hashed-host known_hosts entries.
if ! ssh-keygen -F "$host" -f "$known_hosts" >/dev/null 2>&1; then
  echo '::error::PI_HOST_KEY does not contain the configured production host' >&2
  exit 1
fi

# Multiple pinned algorithms for the same host are valid. Every non-comment
# entry must parse as a key and must be returned by ssh-keygen -F for this
# exact host; this rejects a mixed-host secret and malformed pin without ever
# discovering or accepting a key from the network.
entry_count=$(awk 'NF && $1 !~ /^#/ { count++ } END { print count + 0 }' "$known_hosts")
parsed_count=$(ssh-keygen -lf "$known_hosts" 2>/dev/null | wc -l)
matched_count=$(ssh-keygen -F "$host" -f "$known_hosts" 2>/dev/null | awk '$1 !~ /^#/ { count++ } END { print count + 0 }')
if [[ "$entry_count" -lt 1 || "$parsed_count" -ne "$entry_count" || "$matched_count" -ne "$entry_count" ]]; then
  echo '::error::PI_HOST_KEY must contain only parseable pinned keys for the configured production host' >&2
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
