#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"
OS="$(uname -s)"

if [[ "${DANACO_NONINTERACTIVE:-}" != "1" && ! -t 0 ]]; then
  echo "install.sh needs an interactive terminal."
  exit 1
fi

clone_repo() {
  local dest="$1"
  if command -v gh >/dev/null 2>&1 && gh auth status >/dev/null 2>&1; then
    gh repo clone DanacoDev/danaco-agent "$dest"
  else
    git clone https://github.com/DanacoDev/danaco-agent.git "$dest"
  fi
}

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

replace_existing_files() {
  local stage ref
  if [[ -d "$ROOT/.git" ]]; then
    git -C "$ROOT" fetch origin
    if git -C "$ROOT" show-ref --verify --quiet refs/remotes/origin/main; then
      ref="origin/main"
    else
      ref="origin/master"
    fi
    git -C "$ROOT" checkout -B "${ref#origin/}" "$ref"
    git -C "$ROOT" reset --hard "$ref"
    git -C "$ROOT" clean -fdx
    return 0
  fi
  stage="$(mktemp -d "${TMPDIR:-/tmp}/danaco.XXXXXX")"
  clone_repo "$stage/fresh"
  find "$ROOT" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
  shopt -s dotglob
  mv "$stage/fresh"/* "$ROOT/"
  shopt -u dotglob
  rm -rf "$stage"
}

if [[ -f "$ROOT/data/app.sqlite" ]]; then
  parent="$(dirname "$ROOT")"
  base="$(basename "$ROOT")"
  stem="$base"
  if [[ "$base" =~ ^(.+[^0-9])[0-9]+$ ]]; then
    stem="${BASH_REMATCH[1]}"
  fi
  next_number=1
  while [[ -e "$parent/${stem}${next_number}" ]]; do
    next_number=$((next_number + 1))
  done
  next_dir="$parent/${stem}${next_number}"
  if [[ "${DANACO_NONINTERACTIVE:-}" == "1" ]]; then
    REUSE_CHOICE="${DANACO_REUSE_CHOICE:-}"
    case "$REUSE_CHOICE" in
      1|2|3) ;;
      *)
        echo "Missing or invalid DANACO_REUSE_CHOICE." >&2
        exit 1
        ;;
    esac
  else
    echo
    echo "An existing Danaco Agent install was found at $ROOT"
    echo "  1) Replace the old files"
    echo "  2) Use the existing files"
    echo "  3) Create a new folder ($(basename "$next_dir"))"
    while true; do
      read -r -p "Choice: " REUSE_CHOICE
      case "$REUSE_CHOICE" in
        1|2|3) break ;;
        *) echo "Enter 1, 2, or 3." ;;
      esac
    done
  fi
  if [[ "$REUSE_CHOICE" == "1" ]]; then
    echo "Replacing the old files in $ROOT"
    replace_existing_files
    exec bash "$ROOT/install.sh"
  elif [[ "$REUSE_CHOICE" == "3" ]]; then
    echo "Installing a new copy at $next_dir"
    clone_repo "$next_dir"
    exec bash "$next_dir/install.sh"
  else
    echo "Using the existing files."
  fi
fi

if [[ ! -f "$ROOT/bin/danaco" ]]; then
  echo "Fetching Danaco Agent from GitHub..."
  stage="$(mktemp -d "${TMPDIR:-/tmp}/danaco.XXXXXX")"
  clone_repo "$stage/fresh"
  keep_installer="$stage/install.sh"
  cp "$ROOT/install.sh" "$keep_installer"
  find "$ROOT" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
  shopt -s dotglob
  mv "$stage/fresh"/* "$ROOT/"
  shopt -u dotglob
  cp "$keep_installer" "$ROOT/install.sh"
  chmod +x "$ROOT/install.sh"
  rm -rf "$stage"
  exec bash "$ROOT/install.sh"
fi

if [[ "${DANACO_NONINTERACTIVE:-}" == "1" ]]; then
  PASS="${DANACO_PASSWORD:-}"
  unset DANACO_PASSWORD
  if [[ ${#PASS} -lt 8 ]]; then
    echo "Missing DANACO_PASSWORD, or it is shorter than 8 characters." >&2
    exit 1
  fi
fi

deps=0
bash "$ROOT/bin/ensure-php.sh" || deps=$?
if [[ "$deps" -ne 0 && "$deps" -ne 10 ]]; then
  exit 1
fi

echo
echo "Hardware"
VRAM_MB=0
RAM_MB=0
NVIDIA_CC=""
GPU_LABEL=""
if command -v nvidia-smi >/dev/null 2>&1; then
  while IFS= read -r gpu_line; do
    [[ -z "$gpu_line" ]] && continue
    gpu_cap="${gpu_line##*, }"
    gpu_rest="${gpu_line%, *}"
    gpu_mem="${gpu_rest##*, }"
    gpu_name="${gpu_rest%, *}"
    gpu_mem="${gpu_mem// /}"
    gpu_cap="${gpu_cap// /}"
    if [[ "$gpu_mem" =~ ^[0-9]+$ ]] && [[ "$gpu_mem" -gt "$VRAM_MB" ]]; then
      VRAM_MB="$gpu_mem"
      NVIDIA_CC="$gpu_cap"
      GPU_LABEL="$gpu_name"
    fi
  done < <(nvidia-smi --query-gpu=name,memory.total,compute_cap --format=csv,noheader,nounits 2>/dev/null || true)
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
    if [[ "$mac_vram" =~ ^[0-9]+$ ]] && [[ "$mac_vram" -gt 0 ]]; then
      VRAM_MB="$mac_vram"
      GPU_LABEL="Discrete GPU"
    fi
  fi
elif [[ -r /proc/meminfo ]]; then
  RAM_MB="$(awk '/MemTotal:/ { print int($2 / 1024) }' /proc/meminfo)"
fi
if [[ -n "$GPU_LABEL" && "$VRAM_MB" -gt 0 ]]; then
  echo "${GPU_LABEL}: ${VRAM_MB} MB"
elif [[ "$OS" == "Darwin" ]]; then
  echo "No separate GPU detected. System memory: ${RAM_MB} MB"
else
  echo "No NVIDIA GPU detected. System memory: ${RAM_MB} MB"
fi

FAILSAFE_MODEL="qwen2.5:0.5b"
SUGGESTED_MODE="local"
if [[ "$VRAM_MB" -ge 15360 ]]; then
  nvfp4=0
  if [[ -n "$NVIDIA_CC" ]] && awk -v cc="$NVIDIA_CC" 'BEGIN { split(cc, a, "."); exit ((a[1] + 0) >= 10 ? 0 : 1) }'; then
    nvfp4=1
  fi
  if [[ "$nvfp4" -eq 1 ]]; then
    SUGGESTED_MODEL="qwen3.6:35b-a3b-nvfp4"
  else
    SUGGESTED_MODEL="qwen3.6:35b-a3b-q4_K_M"
  fi
elif [[ "$RAM_MB" -ge 7168 ]]; then
  SUGGESTED_MODEL="qwen3.5:9b-q4_K_M"
else
  SUGGESTED_MODE="cpu_failsafe"
  SUGGESTED_MODEL="$FAILSAFE_MODEL"
fi
echo "Suggested model: ${SUGGESTED_MODEL}"

LOCAL_MODEL=""
MODELS=()
if command -v ollama >/dev/null 2>&1; then
  while IFS= read -r model_name; do
    [[ -z "$model_name" || "$model_name" == "NAME" ]] && continue
    MODELS+=("$model_name")
  done < <(ollama list 2>/dev/null | awk 'NR>1 { print $1 }')
fi

echo
if [[ "${DANACO_NONINTERACTIVE:-}" == "1" ]]; then
  MODEL_MODE="${DANACO_MODEL_MODE:-}"
  LOCAL_MODEL=""
  case "$MODEL_MODE" in
    cloud|cpu_failsafe) ;;
    local)
      LOCAL_MODEL="${DANACO_LOCAL_MODEL:-}"
      if [[ -z "$LOCAL_MODEL" ]]; then
        echo "Missing DANACO_LOCAL_MODEL." >&2
        exit 1
      fi
      ;;
    *)
      echo "Missing or invalid DANACO_MODEL_MODE." >&2
      exit 1
      ;;
  esac
  if [[ -n "$LOCAL_MODEL" ]]; then
    echo "Using ${MODEL_MODE} (${LOCAL_MODEL})."
  else
    echo "Using ${MODEL_MODE}."
  fi
else
  CHOICE_MODE=()
  CHOICE_MODEL=()
  add_choice() {
    CHOICE_MODE+=("$2")
    CHOICE_MODEL+=("$3")
    echo "  ${#CHOICE_MODE[@]}) $1"
  }
  if [[ "$SUGGESTED_MODE" == "cpu_failsafe" ]]; then
    echo "This computer will use the CPU failsafe (${FAILSAFE_MODEL})."
  else
    echo "Suggested main model for this computer: ${SUGGESTED_MODEL}"
  fi
  if command -v ollama >/dev/null 2>&1; then
    if [[ ${#MODELS[@]} -gt 0 ]]; then
      echo "Ollama is installed. Choose a model:"
    else
      echo "Ollama is installed, but it has no models yet."
    fi
  else
    echo "Ollama is not installed."
  fi
  if [[ "$SUGGESTED_MODE" == "cpu_failsafe" ]]; then
    add_choice "CPU failsafe (suggested)" "cpu_failsafe" ""
    if [[ ${#MODELS[@]} -gt 0 ]]; then
      for model_name in "${MODELS[@]}"; do
        [[ "$model_name" == "$FAILSAFE_MODEL" || "$model_name" == "${FAILSAFE_MODEL}:latest" ]] && continue
        add_choice "$model_name" "local" "$model_name"
      done
    fi
    if [[ ${#CHOICE_MODE[@]} -eq 1 ]]; then
      add_choice "Local qwen3.5:9b-q4_K_M" "local" "qwen3.5:9b-q4_K_M"
    fi
    add_choice "External LLM" "cloud" ""
  else
    add_choice "Local ${SUGGESTED_MODEL} (suggested)" "local" "$SUGGESTED_MODEL"
    if [[ ${#MODELS[@]} -gt 0 ]]; then
      for model_name in "${MODELS[@]}"; do
        [[ "$model_name" == "$SUGGESTED_MODEL" || "$model_name" == "${SUGGESTED_MODEL}:latest" ]] && continue
        add_choice "$model_name" "local" "$model_name"
      done
    fi
    add_choice "External LLM" "cloud" ""
    add_choice "CPU failsafe" "cpu_failsafe" ""
  fi
  read -r -p "Choice [1]: " MODE_CHOICE
  MODE_CHOICE="${MODE_CHOICE:-1}"
  if [[ "$MODE_CHOICE" =~ ^[0-9]+$ && "$MODE_CHOICE" -ge 1 && "$MODE_CHOICE" -le ${#CHOICE_MODE[@]} ]]; then
    MODEL_MODE="${CHOICE_MODE[$((MODE_CHOICE - 1))]}"
    LOCAL_MODEL="${CHOICE_MODEL[$((MODE_CHOICE - 1))]}"
  else
    MODEL_MODE="${CHOICE_MODE[0]}"
    LOCAL_MODEL="${CHOICE_MODEL[0]}"
    if [[ -n "$LOCAL_MODEL" ]]; then
      echo "Unknown choice. Using ${LOCAL_MODEL}."
    elif [[ "$MODEL_MODE" == "cpu_failsafe" ]]; then
      echo "Unknown choice. Using CPU failsafe."
    else
      echo "Unknown choice. Using External LLM."
    fi
  fi
fi

echo
if [[ "${DANACO_NONINTERACTIVE:-}" == "1" ]]; then
  BIND_HOST="${DANACO_BIND_HOST:-}"
  case "$BIND_HOST" in
    127.0.0.1|0.0.0.0) ;;
    *)
      echo "Missing or invalid DANACO_BIND_HOST." >&2
      exit 1
      ;;
  esac
  if [[ "$BIND_HOST" == "0.0.0.0" ]]; then
    echo "The agent will be reachable from other devices on this network."
  else
    echo "The agent will be reachable only from this computer."
  fi
else
  read -r -p "Should the agent be accessible from other devices on the same LAN? [y/N]: " LAN_CHOICE
  case "${LAN_CHOICE:-n}" in
    y|Y|yes|YES) BIND_HOST="0.0.0.0" ;;
    *) BIND_HOST="127.0.0.1" ;;
  esac
fi

port_busy() {
  local port="$1"
  if command -v ss >/dev/null 2>&1 && ss -H -ltn 2>/dev/null | awk '{ print $4 }' | grep -Eq "[.:]${port}$"; then
    return 0
  fi
  if command -v lsof >/dev/null 2>&1 && lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1; then
    return 0
  fi
  if command -v timeout >/dev/null 2>&1 && timeout 0.2 bash -c "echo >/dev/tcp/127.0.0.1/${port}" >/dev/null 2>&1; then
    return 0
  fi
  return 1
}

choose_port() {
  local port
  for port in $(seq 8000 8099); do
    if port_busy "$port"; then
      if curl -kfsS -D - -o /dev/null --max-time 1 "https://127.0.0.1:${port}/" 2>/dev/null | grep -qi '^X-Danaco:'; then
        echo "$port"
        return 0
      fi
      if curl -fsS -D - -o /dev/null --max-time 1 "http://127.0.0.1:${port}/" 2>/dev/null | grep -qi '^X-Danaco:'; then
        echo "$port"
        return 0
      fi
      continue
    fi
    echo "$port"
    return 0
  done
  echo "No free port between 8000 and 8099." >&2
  return 1
}

APP_PORT="$(choose_port)"
if [[ "$APP_PORT" == "8000" ]]; then
  echo "Using port ${APP_PORT}."
else
  echo "Port 8000 is already in use. Using port ${APP_PORT}."
fi

if [[ "$BIND_HOST" == "0.0.0.0" && "$OS" == "Linux" ]]; then
  if command -v ufw >/dev/null 2>&1; then
    as_root ufw allow ssh
    as_root ufw allow "${APP_PORT}/tcp"
  else
    echo "ufw is not installed. Open ssh and tcp/${APP_PORT} yourself before using the LAN address."
  fi
fi

read_masked() {
  local prompt="$1"
  local char secret=""
  printf '%s' "$prompt" >&2
  while IFS= read -r -s -n 1 char; do
    if [[ -z "$char" || "$char" == $'\n' || "$char" == $'\r' ]]; then
      printf '\n' >&2
      printf '%s' "$secret"
      return 0
    fi
    if [[ "$char" == $'\177' || "$char" == $'\b' ]]; then
      if [[ -n "$secret" ]]; then
        secret="${secret%?}"
        printf '\b \b' >&2
      fi
      continue
    fi
    secret+="$char"
    printf '*' >&2
  done
  printf '\n' >&2
  return 1
}

if [[ "${DANACO_NONINTERACTIVE:-}" != "1" ]]; then
  while true; do
    PASS="$(read_masked "Web UI password (min 8 characters): ")" || { echo "No password entered."; exit 1; }
    PASS2="$(read_masked "Confirm password: ")" || { echo "No password entered."; exit 1; }
    if [[ "$PASS" == "$PASS2" && ${#PASS} -ge 8 ]]; then
      break
    fi
    echo "Passwords did not match or were shorter than 8 characters."
  done
fi

if [[ "${DANACO_NONINTERACTIVE:-}" == "1" ]]; then
  AI_ACT_APPLIES="${DANACO_AI_ACT:-}"
  case "$AI_ACT_APPLIES" in
    yes|no|unsure) ;;
    *)
      echo "Missing or invalid DANACO_AI_ACT." >&2
      exit 1
      ;;
  esac
else
  echo
  echo "Does the EU AI Act apply to this deployment?"
  echo "  1) Yes — organisation in the EU/EEA, or the system affects people there in a way that brings the Act into scope"
  echo "  2) No"
  echo "  3) Not sure"
  AI_ACT_APPLIES=""
  while [[ -z "$AI_ACT_APPLIES" ]]; do
    read -r -p "Choice [2]: " AI_ACT_CHOICE
    AI_ACT_CHOICE="${AI_ACT_CHOICE:-2}"
    case "$AI_ACT_CHOICE" in
      1|y|Y|yes|Yes) AI_ACT_APPLIES="yes" ;;
      2|n|N|no|No) AI_ACT_APPLIES="no" ;;
      3|u|U|unsure|Unsure) AI_ACT_APPLIES="unsure" ;;
      *) echo "Enter 1, 2, or 3." ;;
    esac
  done
fi

mkdir -p public/assets data/sessions
if [[ ! -s public/assets/vue.global.prod.js ]]; then
  curl -fsSL -o public/assets/vue.global.prod.js "https://unpkg.com/vue@3/dist/vue.global.prod.js" || echo "Could not download Vue. The page will try the CDN."
fi
if [[ ! -s public/assets/tailwind.js ]]; then
  curl -fsSL -o public/assets/tailwind.js "https://cdn.tailwindcss.com" || echo "Could not download Tailwind. The page will try the CDN."
fi

printf '%s' "$PASS" | MODEL_MODE="$MODEL_MODE" LOCAL_MODEL="${LOCAL_MODEL:-}" APP_PORT="$APP_PORT" OLLAMA_URL="http://127.0.0.1:11434" BIND_HOST="$BIND_HOST" AI_ACT_APPLIES="$AI_ACT_APPLIES" php "$ROOT/bin/set-password.php"
unset PASS PASS2

ollama_api_up() {
  curl -sf -o /dev/null --max-time 2 http://127.0.0.1:11434/api/tags
}

ensure_ollama_server() {
  local attempt
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

install_ollama() {
  local script
  if command -v ollama >/dev/null 2>&1; then
    return 0
  fi
  echo "Installing Ollama..."
  if [[ "$OS" == "Darwin" ]]; then
    if ! command -v brew >/dev/null 2>&1; then
      echo "Ollama is not installed. Install it from https://ollama.com and run this installer again." >&2
      return 1
    fi
    brew install ollama || return 1
  else
    script="$(mktemp "${TMPDIR:-/tmp}/ollama-install.XXXXXX")"
    if ! curl -fsSL -o "$script" https://ollama.com/install.sh; then
      rm -f "$script"
      echo "Could not download Ollama. Install it from https://ollama.com and run this installer again." >&2
      return 1
    fi
    if ! as_root sh "$script"; then
      rm -f "$script"
      echo "Could not install Ollama. Install it from https://ollama.com and run this installer again." >&2
      return 1
    fi
    rm -f "$script"
  fi
  export PATH="/opt/homebrew/bin:/usr/local/bin:/usr/bin:${PATH}"
  hash -r || true
  if ! command -v ollama >/dev/null 2>&1; then
    echo "Ollama was installed, but it is not on PATH. Install it from https://ollama.com and run this installer again." >&2
    return 1
  fi
}

model_is_installed() {
  local name="$1"
  ollama list 2>/dev/null | awk 'NR>1 { print $1 }' | grep -Fxq "$name"
}

fetch_model() {
  local name="$1"
  if model_is_installed "$name"; then
    echo "${name} is already downloaded."
    return 0
  fi
  echo
  echo "Downloading ${name}."
  echo "Progress stays on screen. The install will not continue until the download finishes."
  if ! ollama pull "$name"; then
    echo "Could not download ${name}. The browser was not opened." >&2
    exit 1
  fi
  if ! model_is_installed "$name"; then
    echo "${name} is still missing after the download. The browser was not opened." >&2
    exit 1
  fi
  echo "Finished downloading ${name}."
}

export PATH="/opt/homebrew/bin:/usr/local/bin:/usr/bin:${PATH}"
if ! command -v ollama >/dev/null 2>&1; then
  if ! install_ollama; then
    echo "The browser was not opened." >&2
    exit 1
  fi
fi
if ! ensure_ollama_server; then
  echo "Ollama is installed, but it did not start. The browser was not opened." >&2
  exit 1
fi
if [[ "$MODEL_MODE" == "local" && -n "${LOCAL_MODEL:-}" && "$LOCAL_MODEL" != "$FAILSAFE_MODEL" ]]; then
  fetch_model "$LOCAL_MODEL"
fi
fetch_model "$FAILSAFE_MODEL"
if [[ "$MODEL_MODE" == "local" || "$MODEL_MODE" == "cpu_failsafe" ]]; then
  echo "The WebUI will open next. Chat stays closed until the model is loaded."
fi

chmod +x "$ROOT/bin/danaco" "$ROOT/bin/run-agent.sh" "$ROOT/bin/ensure-tls.sh" "$ROOT/bin/git-askpass.sh" "$ROOT/bin/save-github-credentials.sh"
bash "$ROOT/bin/ensure-tls.sh"
if [[ -f "$ROOT/data/tls/ca.crt" ]]; then
  echo "A local certificate was created so the address is encrypted."
  if [[ "$OS" == "Darwin" ]]; then
    echo "macOS will ask to trust it. Safari and Chrome can then open the page."
    as_root security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain "$ROOT/data/tls/ca.crt" || echo "The certificate was not trusted. A browser may ask before the first visit."
  elif [[ "$OS" == "Linux" ]] && [[ -d /usr/local/share/ca-certificates ]]; then
    as_root cp "$ROOT/data/tls/ca.crt" /usr/local/share/ca-certificates/danaco-local-ca.crt
    as_root update-ca-certificates || echo "The certificate was not trusted. A browser may ask before the first visit."
  else
    echo "If a browser asks, trust this file: $ROOT/data/tls/ca.crt"
  fi
fi
bash "$ROOT/bin/danaco" _install

URL="https://127.0.0.1:${APP_PORT}/"
echo "Danaco Agent is running at $URL"
if [[ "$BIND_HOST" == "0.0.0.0" && -f "$ROOT/data/tls/san.list" ]]; then
  echo "From another device on this network, use https and one of these names:"
  while IFS= read -r san_line; do
    san_kind="${san_line%%:*}"
    san_value="${san_line#*:}"
    if [[ "$san_kind" == "IP" && "$san_value" != "127.0.0.1" ]]; then
      echo "  https://${san_value}:${APP_PORT}/"
    elif [[ "$san_kind" == "DNS" && "$san_value" != "localhost" ]]; then
      echo "  https://${san_value}:${APP_PORT}/"
    fi
  done < "$ROOT/data/tls/san.list"
fi
echo "Open it later with: danaco"
if [[ "$OS" == "Darwin" ]]; then
  open "$URL" || true
elif command -v xdg-open >/dev/null 2>&1; then
  xdg-open "$URL" || true
fi
