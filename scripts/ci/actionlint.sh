#!/usr/bin/env bash
set -euo pipefail

# actionlint is upstream's workflow parser/checker; keep the release and
# artifact digest explicit so CI does not execute an unpinned downloader.
ACTIONLINT_VERSION='1.7.12'
ACTIONLINT_SHA256='8aca8db96f1b94770f1b0d72b6dddcb1ebb8123cb3712530b08cc387b349a3d8'
ACTIONLINT_URL="https://github.com/rhysd/actionlint/releases/download/v${ACTIONLINT_VERSION}/actionlint_${ACTIONLINT_VERSION}_linux_amd64.tar.gz"

# GitHub's workflow schema accepts concurrency `queue: single|max` (actions/languageservices
# workflow-v1.0.json `concurrency-queue`), but actionlint 1.7.12 (and its main branch) does not
# know the key yet. Ignore only that one message; every other concurrency error still fails.
actionlint_args=('-ignore' 'unexpected key "queue" for "concurrency" section')
if [ "${1:-}" = '--parser-only' ]; then
  actionlint_args+=('-shellcheck' '')
  shift
fi

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT
archive="$tmp_dir/actionlint.tar.gz"
curl --fail --silent --show-error --location "$ACTIONLINT_URL" --output "$archive"
printf '%s  %s\n' "$ACTIONLINT_SHA256" "$archive" | sha256sum --check --status
tar --extract --file "$archive" --directory "$tmp_dir"
exec "$tmp_dir/actionlint" "${actionlint_args[@]}" "$@"
