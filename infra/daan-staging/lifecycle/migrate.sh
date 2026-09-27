#!/usr/bin/env bash
# Run Laravel migrations inside staging app container against isolated MySQL.
set -euo pipefail
source "$(cd "$(dirname "$0")" && pwd)/_common.sh"
require_env_file

echo "=== staging migrate --force ==="
compose_exec_noninteractive app php artisan migrate --force
echo "MIGRATE_OK"
