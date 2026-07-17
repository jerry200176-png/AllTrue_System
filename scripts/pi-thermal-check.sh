#!/usr/bin/env bash
set -uo pipefail

is_valid_temperature() {
  [[ "$1" =~ ^-?[0-9]+([.][0-9]+)?$ ]] &&
    awk -v value="$1" 'BEGIN { exit !(value >= -20 && value <= 150) }'
}

classify_readings() {
  local vc_temp="$1"
  local sys_temp="$2"
  local throttle_state="$3"
  local throttle_hex="$4"
  local effective_temp=""
  local warnings=()
  local criticals=()

  if [[ -n "$vc_temp" ]] && ! is_valid_temperature "$vc_temp"; then
    criticals+=("vcgencmd returned an invalid temperature: ${vc_temp}")
    vc_temp=""
  fi
  if [[ -n "$sys_temp" ]] && ! is_valid_temperature "$sys_temp"; then
    criticals+=("sysfs returned an invalid temperature: ${sys_temp}")
    sys_temp=""
  fi

  if [[ -n "$vc_temp" && -n "$sys_temp" ]]; then
    local delta
    delta="$(awk -v a="$vc_temp" -v b="$sys_temp" 'BEGIN { d=a-b; if (d<0) d=-d; printf "%.1f", d }')"
    if awk -v delta="$delta" 'BEGIN { exit !(delta > 10) }'; then
      warnings+=("vcgencmd disagrees with authoritative sysfs by ${delta}°C (vcgencmd=${vc_temp}°C, sysfs=${sys_temp}°C)")
    fi
    effective_temp="$sys_temp"
  elif [[ -n "$sys_temp" ]]; then
    effective_temp="$sys_temp"
    warnings+=("vcgencmd temperature unavailable; using sysfs")
  elif [[ -n "$vc_temp" ]]; then
    effective_temp="$vc_temp"
    warnings+=("sysfs temperature unavailable; using vcgencmd")
  else
    criticals+=("no credible CPU temperature source is available")
  fi

  if [[ -n "$effective_temp" ]]; then
    if awk -v value="$effective_temp" 'BEGIN { exit !(value > 85) }'; then
      criticals+=("CPU temperature ${effective_temp}°C exceeds 85°C")
    elif awk -v value="$effective_temp" 'BEGIN { exit !(value > 75) }'; then
      warnings+=("CPU temperature ${effective_temp}°C exceeds 75°C")
    fi
  fi

  if [[ "$throttle_state" == "available" && "$throttle_hex" =~ ^0x[0-9a-fA-F]+$ ]]; then
    local throttle_value=$((16#${throttle_hex#0x}))
    if ((throttle_value & 15)); then
      criticals+=("active throttling/undervoltage detected (${throttle_hex})")
    elif ((throttle_value != 0)); then
      warnings+=("historical throttling/undervoltage flags detected (${throttle_hex})")
    fi
  else
    warnings+=("CPU throttling telemetry unavailable")
  fi

  echo "CPU thermal telemetry: vcgencmd=${vc_temp:-unavailable}°C sysfs=${sys_temp:-unavailable}°C throttling=${throttle_state}${throttle_hex:+:${throttle_hex}}"
  for warning in "${warnings[@]}"; do
    echo "⚠️ ${warning}"
  done
  for critical in "${criticals[@]}"; do
    echo "🔴 ${critical}" >&2
  done

  ((${#criticals[@]} == 0))
}

run_check() {
  local vc_temp=""
  local sys_temp=""
  local throttle_state="unavailable"
  local throttle_hex=""
  local vc_raw=""
  local throttle_raw=""
  local throttle_rc=127
  local sysfs_path="${THERMAL_SYSFS_PATH:-/sys/class/thermal/thermal_zone0/temp}"

  if command -v vcgencmd >/dev/null 2>&1; then
    vc_raw="$(vcgencmd measure_temp 2>&1 || true)"
    vc_temp="$(grep -oE -- '-?[0-9]+([.][0-9]+)?' <<<"$vc_raw" | head -1)"

    set +e
    throttle_raw="$(vcgencmd get_throttled 2>&1)"
    throttle_rc=$?
    set -e
    if ((throttle_rc == 0)); then
      throttle_hex="$(grep -oE '0x[0-9a-fA-F]+' <<<"$throttle_raw" | head -1)"
      [[ -n "$throttle_hex" ]] && throttle_state="available"
    fi
  fi

  if [[ -r "$sysfs_path" ]]; then
    local sys_raw
    local sys_samples=()
    for sample in 1 2 3; do
      sys_raw="$(tr -d '[:space:]' < "$sysfs_path")"
      if [[ "$sys_raw" =~ ^-?[0-9]+$ ]]; then
        sys_samples+=("$sys_raw")
      fi
      ((sample < 3)) && sleep 1
    done
    if ((${#sys_samples[@]} == 3)); then
      sys_temp="$(printf '%s\n' "${sys_samples[@]}" | awk '{sum += $1} END {printf "%.1f", sum / NR / 1000}')"
    fi
  fi

  if [[ "$throttle_state" != "available" ]]; then
    throttle_state="unavailable(rc=${throttle_rc})"
  fi

  classify_readings "$vc_temp" "$sys_temp" "$throttle_state" "$throttle_hex"
}

self_test() {
  local failures=0

  assert_result() {
    local expected="$1"
    shift
    set +e
    classify_readings "$@" >/dev/null 2>&1
    local actual=$?
    set -e
    if [[ "$actual" -ne "$expected" ]]; then
      echo "FAIL: expected ${expected}, got ${actual} for: $*" >&2
      failures=$((failures + 1))
    fi
  }

  assert_result 0 50.0 50.5 available 0x0
  assert_result 0 76.0 76.2 unavailable ""
  assert_result 1 90.0 90.5 available 0x0
  assert_result 0 100.0 50.0 unavailable ""
  assert_result 1 100.0 "" unavailable ""
  assert_result 1 "" "" unavailable ""
  assert_result 1 50.0 50.0 available 0x1
  assert_result 0 50.0 50.0 available 0x10000

  if ((failures > 0)); then
    return 1
  fi
  echo "Pi thermal check self-test passed."
}

case "${1:-}" in
  --self-test) self_test ;;
  "") run_check ;;
  *) echo "Usage: $0 [--self-test]" >&2; exit 2 ;;
esac
