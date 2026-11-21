#!/usr/bin/env bash
# Turn on 2 Ollama threads when this computer can use them.
# apply: same step as a first install. Exit 10 when the settings were turned on.
# update: do that only when they are not already on. Exit 10 when they were just turned on.
# verify: after the small model is installed, turn the settings off if the GPU rejects them.
set -euo pipefail

export PATH="/opt/homebrew/bin:/usr/local/bin:/usr/bin:${PATH:-/bin}"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OS="$(uname -s)"
FAILSAFE_MODEL="${FAILSAFE_MODEL:-qwen2.5:0.5b}"
OLLAMA_PARALLEL_ON=0

as_root() {
  if [[ "$(id -u)" -eq 0 ]]; then
    "$@"
    return
  fi
  if sudo -n true 2>/dev/null; then
    sudo -n "$@"
    return
  fi
  if [[ -t 0 ]]; then
    sudo "$@"
    return
  fi
  if command -v pkexec >/dev/null 2>&1; then
    pkexec "$@"
    return
  fi
  return 1
}

remember_gpu() {
  local mem="$1"
  mem="${mem// /}"
  [[ "$mem" =~ ^[0-9]+$ ]] || return 0
  [[ "$mem" -gt "$VRAM_MB" ]] || return 0
  VRAM_MB="$mem"
}

detect_memory() {
  local gpu_line gpu_mem card drm_seen drm_key drm_bytes drm_sum drm_found drm_part
  local bank kfd_bytes rocm_text rocm_line amd_text amd_line xpu_text xpu_line mac_vram
  VRAM_MB=0
  RAM_MB=0
  if command -v nvidia-smi >/dev/null 2>&1; then
    while IFS= read -r gpu_line; do
      [[ -z "$gpu_line" ]] && continue
      gpu_mem="${gpu_line##*, }"
      remember_gpu "$gpu_mem"
    done < <(nvidia-smi --query-gpu=name,memory.total --format=csv,noheader,nounits 2>/dev/null || true)
  fi
  if [[ "$OS" == "Linux" ]]; then
    drm_seen=" "
    for card in /sys/class/drm/card[0-9]*; do
      [[ -d "$card/device" ]] || continue
      [[ "${card##*/}" =~ ^card[0-9]+$ ]] || continue
      drm_key="$(readlink -f "$card/device" 2>/dev/null || printf '%s' "$card")"
      case " $drm_seen " in
        *" $drm_key "*) continue ;;
      esac
      drm_seen="$drm_seen $drm_key"
      drm_bytes=""
      if [[ -r "$card/device/mem_info_vram_total" ]]; then
        drm_bytes="$(tr -d '[:space:]' < "$card/device/mem_info_vram_total")"
      else
        drm_sum=0
        drm_found=0
        for tile in "$card"/device/tile[0-9]*/mem_info_vram_total; do
          [[ -r "$tile" ]] || continue
          drm_part="$(tr -d '[:space:]' < "$tile")"
          [[ "$drm_part" =~ ^[0-9]+$ ]] || continue
          drm_sum=$((drm_sum + drm_part))
          drm_found=1
        done
        if [[ "$drm_found" == "1" ]]; then
          drm_bytes="$drm_sum"
        fi
      fi
      [[ "$drm_bytes" =~ ^[0-9]+$ ]] || continue
      remember_gpu "$((drm_bytes / 1048576))"
    done
    for bank in /sys/class/kfd/kfd/topology/nodes/*/mem_banks/*/properties; do
      [[ -r "$bank" ]] || continue
      [[ "$(awk '/^heap_type / { print $2; exit }' "$bank")" == "1" ]] || continue
      kfd_bytes="$(awk '/^size_in_bytes / { print $2; exit }' "$bank")"
      [[ "$kfd_bytes" =~ ^[0-9]+$ ]] || continue
      remember_gpu "$((kfd_bytes / 1048576))"
    done
    if command -v rocm-smi >/dev/null 2>&1; then
      if command -v timeout >/dev/null 2>&1; then
        rocm_text="$(timeout 8 rocm-smi --showmeminfo vram 2>/dev/null || true)"
      else
        rocm_text="$(rocm-smi --showmeminfo vram 2>/dev/null || true)"
      fi
      while IFS= read -r rocm_line; do
        if [[ "$rocm_line" =~ [Vv][Rr][Aa][Mm][[:space:]]+Total[[:space:]]+Memory[[:space:]]+\(B\):[[:space:]]*([0-9]+) ]]; then
          remember_gpu "$((BASH_REMATCH[1] / 1048576))"
        elif [[ "$rocm_line" =~ [Vv][Rr][Aa][Mm][[:space:]]+Total[[:space:]]+Memory[[:space:]]+\(MiB\):[[:space:]]*([0-9]+) ]]; then
          remember_gpu "${BASH_REMATCH[1]}"
        fi
      done <<< "$rocm_text"
    fi
    if command -v amd-smi >/dev/null 2>&1; then
      if command -v timeout >/dev/null 2>&1; then
        amd_text="$(timeout 8 amd-smi static --vram 2>/dev/null || true)"
      else
        amd_text="$(amd-smi static --vram 2>/dev/null || true)"
      fi
      while IFS= read -r amd_line; do
        if [[ "$amd_line" =~ SIZE:[[:space:]]*([0-9]+)[[:space:]]*MB ]]; then
          remember_gpu "${BASH_REMATCH[1]}"
        fi
      done <<< "$amd_text"
    fi
    if command -v xpu-smi >/dev/null 2>&1; then
      if command -v timeout >/dev/null 2>&1; then
        xpu_text="$(timeout 8 xpu-smi discovery 2>/dev/null || true)"
      else
        xpu_text="$(xpu-smi discovery 2>/dev/null || true)"
      fi
      while IFS= read -r xpu_line; do
        if [[ "$xpu_line" =~ Memory[[:space:]]+Physical[[:space:]]+Size:[[:space:]]*([0-9]+) ]]; then
          remember_gpu "${BASH_REMATCH[1]}"
        fi
      done <<< "$xpu_text"
    fi
  fi
  if [[ "$OS" == "Darwin" ]]; then
    RAM_MB="$(( $(sysctl -n hw.memsize 2>/dev/null || echo 0) / 1048576 ))"
    if [[ "$VRAM_MB" -eq 0 ]]; then
      mac_vram="$(system_profiler SPDisplaysDataType 2>/dev/null | awk '
        /^[[:space:]]+Chipset Model:/ {
          chip = $0
          sub(/^[[:space:]]+Chipset Model:[[:space:]]*/, "", chip)
          apple = (chip ~ /^Apple/)
        }
        /^[[:space:]]+VRAM \(Total\):/ && !apple {
          line = $0
          if (match(line, /[0-9]+/)) {
            n = substr(line, RSTART, RLENGTH) + 0
            if (line ~ /GB/) n = n * 1024
            if (n > max) max = n
          }
        }
        END { print max + 0 }
      ')"
      if [[ "$mac_vram" =~ ^[0-9]+$ && "$mac_vram" -gt 0 ]]; then
        VRAM_MB="$mac_vram"
      fi
    fi
  elif [[ -r /proc/meminfo ]]; then
    RAM_MB="$(awk '/MemTotal:/ { print int($2 / 1024) }' /proc/meminfo)"
  fi
}

ollama_api_up() {
  curl -sf -o /dev/null --max-time 2 http://127.0.0.1:11434/api/tags
}

ensure_ollama_server() {
  local attempt
  if [[ -f "$ROOT/data/ollama.env" ]]; then
    set -a
    source "$ROOT/data/ollama.env"
    set +a
  fi
  if ollama_api_up; then
    return 0
  fi
  echo "Starting Ollama..."
  nohup ollama serve >> "$ROOT/data/ollama.log" 2>&1 &
  for attempt in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30; do
    if ollama_api_up; then
      return 0
    fi
    sleep 0.5
  done
  return 1
}

wait_for_ollama() {
  local attempt
  for attempt in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30; do
    if ollama_api_up; then
      return 0
    fi
    sleep 0.5
  done
  return 1
}

ollama_parallel_memory_ok() {
  local gpu need
  gpu="$VRAM_MB"
  if [[ "$OS" == "Darwin" && "$(uname -m)" == "arm64" && "$gpu" -eq 0 ]]; then
    gpu="$RAM_MB"
  fi
  if [[ "$OS" == "Darwin" ]]; then
    need=32768
  else
    # MemTotal is a little under the labeled RAM.
    need=30720
  fi
  [[ "$gpu" =~ ^[0-9]+$ && "$gpu" -ge 12288 && "$RAM_MB" =~ ^[0-9]+$ && "$RAM_MB" -ge "$need" ]]
}

flash_attention_supported() {
  local cap major minor saw=0 bank heap
  if command -v nvidia-smi >/dev/null 2>&1; then
    while IFS= read -r cap; do
      cap="${cap//[[:space:]]/}"
      [[ -z "$cap" ]] && continue
      saw=1
      if [[ ! "$cap" =~ ^([0-9]+)\.([0-9]+) ]]; then
        return 1
      fi
      major="${BASH_REMATCH[1]}"
      minor="${BASH_REMATCH[2]}"
      if [[ "$major" -lt 6 || ( "$major" -eq 7 && "$minor" -eq 2 ) ]]; then
        return 1
      fi
    done < <(nvidia-smi --query-gpu=compute_cap --format=csv,noheader,nounits 2>/dev/null || true)
    if [[ "$saw" -eq 1 ]]; then
      return 0
    fi
  fi
  if [[ "$OS" == "Darwin" && "$(uname -m)" == "arm64" ]]; then
    return 0
  fi
  if [[ "$OS" == "Linux" ]]; then
    if command -v rocm-smi >/dev/null 2>&1 || command -v amd-smi >/dev/null 2>&1; then
      return 0
    fi
    for bank in /sys/class/kfd/kfd/topology/nodes/*/mem_banks/*/properties; do
      [[ -r "$bank" ]] || continue
      heap="$(awk '/^heap_type / { print $2; exit }' "$bank")"
      if [[ "$heap" == "1" ]]; then
        return 0
      fi
    done
  fi
  return 1
}

ollama_unit_present() {
  command -v systemctl >/dev/null 2>&1 || return 1
  systemctl cat ollama.service >/dev/null 2>&1
}

file_size() {
  if [[ -f "$1" ]]; then
    wc -c < "$1" | tr -d ' '
  else
    printf '0'
  fi
}

file_after() {
  local file="$1" offset="$2"
  [[ -f "$file" && "$offset" =~ ^[0-9]+$ ]] || return 0
  tail -c +"$((offset + 1))" "$file" 2>/dev/null || true
}

stop_user_ollama() {
  local attempt
  pkill -x ollama >/dev/null 2>&1 || true
  for attempt in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
    if ! ollama_api_up; then
      return 0
    fi
    sleep 0.3
  done
  return 1
}

brew_ollama_managed() {
  local info
  command -v brew >/dev/null 2>&1 || return 1
  info="$(brew services info ollama 2>/dev/null || true)"
  grep -qE 'Running: true|Loaded: true' <<<"$info"
}

brew_ollama_plists() {
  local prefix
  if command -v brew >/dev/null 2>&1; then
    prefix="$(brew --prefix ollama 2>/dev/null || true)"
    if [[ -n "$prefix" && -f "${prefix}/homebrew.mxcl.ollama.plist" ]]; then
      printf '%s\n' "${prefix}/homebrew.mxcl.ollama.plist"
    fi
  fi
  if [[ -f "${HOME}/Library/LaunchAgents/homebrew.mxcl.ollama.plist" ]]; then
    printf '%s\n' "${HOME}/Library/LaunchAgents/homebrew.mxcl.ollama.plist"
  fi
}

plist_set() {
  local plist="$1" key="$2" value="$3"
  [[ -f "$plist" ]] || return 0
  /usr/libexec/PlistBuddy -c "Add :EnvironmentVariables dict" "$plist" >/dev/null 2>&1 || true
  if ! /usr/libexec/PlistBuddy -c "Set :EnvironmentVariables:${key} ${value}" "$plist" >/dev/null 2>&1; then
    /usr/libexec/PlistBuddy -c "Add :EnvironmentVariables:${key} string ${value}" "$plist" >/dev/null 2>&1
  fi
}

plist_unset() {
  local plist="$1" key="$2"
  [[ -f "$plist" ]] || return 0
  /usr/libexec/PlistBuddy -c "Delete :EnvironmentVariables:${key}" "$plist" >/dev/null 2>&1 || true
}

plist_has_parallel() {
  local plist="$1" key want got
  [[ -f "$plist" ]] || return 1
  for key in OLLAMA_FLASH_ATTENTION OLLAMA_NUM_PARALLEL OLLAMA_KV_CACHE_TYPE; do
    case "$key" in
      OLLAMA_FLASH_ATTENTION) want=1 ;;
      OLLAMA_NUM_PARALLEL) want=2 ;;
      OLLAMA_KV_CACHE_TYPE) want=q8_0 ;;
    esac
    got="$(/usr/libexec/PlistBuddy -c "Print :EnvironmentVariables:${key}" "$plist" 2>/dev/null || true)"
    [[ "$got" == "$want" ]] || return 1
  done
}

expected_env() {
  printf '%s\n' 'OLLAMA_FLASH_ATTENTION=1' 'OLLAMA_NUM_PARALLEL=2' 'OLLAMA_KV_CACHE_TYPE=q8_0'
}

expected_dropin() {
  printf '%s\n' '[Service]' \
    'Environment="OLLAMA_FLASH_ATTENTION=1"' \
    'Environment="OLLAMA_NUM_PARALLEL=2"' \
    'Environment="OLLAMA_KV_CACHE_TYPE=q8_0"'
}

env_file_ok() {
  [[ -f "$ROOT/data/ollama.env" ]] || return 1
  [[ "$(cat "$ROOT/data/ollama.env")" == "$(expected_env)" ]]
}

dropin_ok() {
  local path="/etc/systemd/system/ollama.service.d/danaco.conf"
  [[ -f "$path" ]] || return 1
  [[ "$(cat "$path")" == "$(expected_dropin)" ]]
}

parallel_already_on() {
  local plist
  env_file_ok || return 1
  if [[ "$OS" == "Darwin" ]]; then
    while IFS= read -r plist; do
      [[ -n "$plist" ]] || continue
      plist_has_parallel "$plist" || return 1
    done < <(brew_ollama_plists)
    return 0
  fi
  if ollama_unit_present; then
    dropin_ok || return 1
  fi
  return 0
}

write_ollama_env_file() {
  mkdir -p "$ROOT/data"
  cat > "$ROOT/data/ollama.env" <<'EOF'
OLLAMA_FLASH_ATTENTION=1
OLLAMA_NUM_PARALLEL=2
OLLAMA_KV_CACHE_TYPE=q8_0
EOF
  set -a
  source "$ROOT/data/ollama.env"
  set +a
}

write_ollama_dropin() {
  local tmp
  tmp="$(mktemp "${TMPDIR:-/tmp}/danaco-ollama.XXXXXX")"
  cat > "$tmp" <<'EOF'
[Service]
Environment="OLLAMA_FLASH_ATTENTION=1"
Environment="OLLAMA_NUM_PARALLEL=2"
Environment="OLLAMA_KV_CACHE_TYPE=q8_0"
EOF
  as_root mkdir -p /etc/systemd/system/ollama.service.d || { rm -f "$tmp"; return 1; }
  as_root cp "$tmp" /etc/systemd/system/ollama.service.d/danaco.conf || { rm -f "$tmp"; return 1; }
  rm -f "$tmp"
  as_root systemctl daemon-reload || return 1
}

apply_ollama_parallel() {
  local plist
  write_ollama_env_file
  if [[ "$OS" == "Darwin" ]]; then
    launchctl setenv OLLAMA_FLASH_ATTENTION 1 || true
    launchctl setenv OLLAMA_NUM_PARALLEL 2 || true
    launchctl setenv OLLAMA_KV_CACHE_TYPE q8_0 || true
    while IFS= read -r plist; do
      [[ -n "$plist" ]] || continue
      plist_set "$plist" OLLAMA_FLASH_ATTENTION 1 || return 1
      plist_set "$plist" OLLAMA_NUM_PARALLEL 2 || return 1
      plist_set "$plist" OLLAMA_KV_CACHE_TYPE q8_0 || return 1
    done < <(brew_ollama_plists)
    if brew_ollama_managed; then
      brew services restart ollama || return 1
      wait_for_ollama || return 1
      return 0
    fi
    if ollama_api_up; then
      stop_user_ollama || true
    fi
    return 0
  fi
  if ollama_unit_present; then
    write_ollama_dropin || return 1
    as_root systemctl restart ollama || return 1
    wait_for_ollama || return 1
    return 0
  fi
  if ollama_api_up; then
    stop_user_ollama || return 1
  fi
  return 0
}

revert_ollama_parallel() {
  local plist
  rm -f "$ROOT/data/ollama.env"
  unset OLLAMA_FLASH_ATTENTION OLLAMA_NUM_PARALLEL OLLAMA_KV_CACHE_TYPE || true
  OLLAMA_PARALLEL_ON=0
  if [[ "$OS" == "Darwin" ]]; then
    launchctl unsetenv OLLAMA_FLASH_ATTENTION >/dev/null 2>&1 || true
    launchctl unsetenv OLLAMA_NUM_PARALLEL >/dev/null 2>&1 || true
    launchctl unsetenv OLLAMA_KV_CACHE_TYPE >/dev/null 2>&1 || true
    while IFS= read -r plist; do
      [[ -n "$plist" ]] || continue
      plist_unset "$plist" OLLAMA_FLASH_ATTENTION
      plist_unset "$plist" OLLAMA_NUM_PARALLEL
      plist_unset "$plist" OLLAMA_KV_CACHE_TYPE
    done < <(brew_ollama_plists)
    if brew_ollama_managed; then
      brew services restart ollama || true
      wait_for_ollama || true
      return 0
    fi
    stop_user_ollama || true
    ensure_ollama_server || true
    return 0
  fi
  if [[ -f /etc/systemd/system/ollama.service.d/danaco.conf ]]; then
    as_root rm -f /etc/systemd/system/ollama.service.d/danaco.conf || true
    as_root systemctl daemon-reload || true
  fi
  if ollama_unit_present; then
    as_root systemctl restart ollama || true
    wait_for_ollama || true
    return 0
  fi
  stop_user_ollama || true
  ensure_ollama_server || true
}

clear_saved_ollama_parallel() {
  if [[ -f "$ROOT/data/ollama.env" || -f /etc/systemd/system/ollama.service.d/danaco.conf ]]; then
    revert_ollama_parallel
  fi
}

ollama_log_marks() {
  local brew_log
  : > "$1"
  printf '%s\t%s\n' "$(file_size "$ROOT/data/ollama.log")" "$ROOT/data/ollama.log" >> "$1"
  printf '%s\t%s\n' "$(file_size "${HOME}/.ollama/logs/server.log")" "${HOME}/.ollama/logs/server.log" >> "$1"
  printf '%s\t%s\n' "$(file_size /usr/share/ollama/.ollama/logs/server.log)" /usr/share/ollama/.ollama/logs/server.log >> "$1"
  shopt -s nullglob
  for brew_log in "${HOME}/Library/Logs/Homebrew/ollama/"*.log; do
    printf '%s\t%s\n' "$(file_size "$brew_log")" "$brew_log" >> "$1"
  done
  shopt -u nullglob
}

ollama_log_since() {
  local since="$1" marks="$2" text="" offset path
  if command -v journalctl >/dev/null 2>&1 && ollama_unit_present; then
    if command -v timeout >/dev/null 2>&1; then
      text="$(timeout 10 journalctl -u ollama --since "$since" --no-pager -o cat 2>/dev/null || true)"
    else
      text="$(journalctl -u ollama --since "$since" --no-pager -o cat 2>/dev/null || true)"
    fi
    if [[ -z "$text" ]]; then
      if command -v timeout >/dev/null 2>&1; then
        text="$(as_root timeout 10 journalctl -u ollama --since "$since" --no-pager -o cat 2>/dev/null || true)"
      else
        text="$(as_root journalctl -u ollama --since "$since" --no-pager -o cat 2>/dev/null || true)"
      fi
    fi
  fi
  if [[ -f "$marks" ]]; then
    while IFS=$'\t' read -r offset path; do
      [[ -n "$path" ]] || continue
      text="${text}"$'\n'"$(file_after "$path" "$offset")"
    done < "$marks"
  fi
  printf '%s' "$text"
}

verify_ollama_flash_attention() {
  local since marks log_text
  [[ "$OLLAMA_PARALLEL_ON" == "1" ]] || return 0
  since="$(date '+%Y-%m-%d %H:%M:%S')"
  marks="$(mktemp "${TMPDIR:-/tmp}/danaco-ollama-log.XXXXXX")"
  ollama_log_marks "$marks"
  curl -sf --max-time 180 http://127.0.0.1:11434/api/generate \
    -H 'Content-Type: application/json' \
    -d "{\"model\":\"${FAILSAFE_MODEL}\",\"prompt\":\"hi\",\"stream\":false,\"keep_alive\":0,\"options\":{\"num_predict\":1}}" \
    >/dev/null || true
  sleep 1
  log_text="$(ollama_log_since "$since" "$marks")"
  rm -f "$marks"
  if grep -qiE 'flash attention enabled but not supported by gpu|quantized kv cache requested but flash attention disabled' <<<"$log_text"; then
    echo "Flash attention is not supported on this GPU. Ollama parallel settings left at defaults."
    revert_ollama_parallel
  fi
}

run_apply() {
  local mode="$1"
  if ! command -v ollama >/dev/null 2>&1; then
    return 0
  fi
  if [[ -z "${VRAM_MB+x}" || -z "${RAM_MB+x}" ]]; then
    detect_memory
  fi
  if ! ollama_parallel_memory_ok; then
    echo "Ollama parallel settings left at defaults. Need at least 12 GB VRAM and 32 GB RAM."
    clear_saved_ollama_parallel
    return 0
  fi
  if ! flash_attention_supported; then
    echo "Flash attention is not supported on this GPU. Ollama parallel settings left at defaults."
    clear_saved_ollama_parallel
    return 0
  fi
  if [[ "$mode" == "update" ]] && parallel_already_on; then
    echo "Ollama is already set to 2 parallel threads."
    return 0
  fi
  if ! apply_ollama_parallel; then
    echo "Could not save the Ollama parallel settings. They were left at defaults."
    revert_ollama_parallel
    return 0
  fi
  if ! ollama_api_up; then
    ensure_ollama_server || true
  fi
  echo "This computer has enough memory and supports flash attention. Setting OLLAMA_NUM_PARALLEL=2, OLLAMA_KV_CACHE_TYPE=q8_0, and OLLAMA_FLASH_ATTENTION=1."
  return 10
}

mode="${1:-}"
case "$mode" in
  apply|update)
    status=0
    run_apply "$mode" || status=$?
    exit "$status"
    ;;
  verify)
    if [[ ! -f "$ROOT/data/ollama.env" ]]; then
      exit 0
    fi
    OLLAMA_PARALLEL_ON=1
    verify_ollama_flash_attention
    exit 0
    ;;
  *)
    echo "Usage: ensure-ollama-parallel.sh apply|update|verify" >&2
    exit 1
    ;;
esac
