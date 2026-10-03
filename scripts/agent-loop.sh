#!/usr/bin/env bash
# Runs the development agent stage after stage (see docs/plans/AGENT_PROMPT.md).
# Stops when the agent needs the owner, is blocked, or everything is done.
#
# Usage:
#   scripts/agent-loop.sh                 # claude, up to 3 stages in a row
#   AGENT=codex scripts/agent-loop.sh     # codex instead of claude
#   MAX_RUNS=10 scripts/agent-loop.sh     # more stages per session
#
# Override the exact command if your CLI version uses different flags:
#   CLAUDE_CMD='claude -p --permission-mode acceptEdits' scripts/agent-loop.sh
#   CODEX_CMD='codex exec --full-auto' AGENT=codex scripts/agent-loop.sh
set -euo pipefail

cd "$(dirname "$0")/.."

AGENT="${AGENT:-claude}"
MAX_RUNS="${MAX_RUNS:-3}"
PROMPT_FILE="docs/plans/AGENT_PROMPT.md"
LOG_DIR="storage/agent-logs"
mkdir -p "$LOG_DIR"

# Project permissions for headless claude live in .claude/settings.json.
CLAUDE_CMD="${CLAUDE_CMD:-claude -p --permission-mode acceptEdits}"
# Codex needs network (git push, gh, docker pulls): enable it for the workspace-write sandbox.
CODEX_CMD="${CODEX_CMD:-codex exec --full-auto -c sandbox_workspace_write.network_access=true}"

notify() {
  echo ">>> $1"
  if command -v osascript >/dev/null 2>&1; then
    osascript -e "display notification \"$1\" with title \"VKPoster agent\" sound name \"Glass\"" || true
  fi
}

for ((run = 1; run <= MAX_RUNS; run++)); do
  log="$LOG_DIR/$(date +%Y%m%d-%H%M%S)-$AGENT.log"
  echo "=== Run $run/$MAX_RUNS ($AGENT), log: $log"

  prompt="$(cat "$PROMPT_FILE")"
  set +e
  case "$AGENT" in
    claude) $CLAUDE_CMD "$prompt" 2>&1 | tee "$log" ;;
    codex)  $CODEX_CMD "$prompt" 2>&1 | tee "$log" ;;
    *) echo "Unknown AGENT=$AGENT (use claude or codex)"; exit 2 ;;
  esac
  code=${PIPESTATUS[0]}
  set -e

  status="$(grep -Eo '^AGENT_STATUS: (STAGE_DONE|NEEDS_OWNER|BLOCKED) [0-9]{2}$|^AGENT_STATUS: ALL_DONE$' "$log" | tail -n 1 | sed 's/AGENT_STATUS: //' || true)"
  if [[ -z "$status" ]]; then
    notify "Агент завершился без статуса (exit $code). Смотри $log"
    exit 1
  fi

  case "$status" in
    STAGE_DONE*) echo ">>> $status — запускаю следующий этап" ;;
    NEEDS_OWNER*) notify "Нужна ваша проверка: $status. Вопросы в docs/plans/PROGRESS.md"; exit 0 ;;
    BLOCKED*)     notify "Агент заблокирован: $status. Подробности в docs/plans/PROGRESS.md"; exit 1 ;;
    ALL_DONE)     notify "Все этапы завершены"; exit 0 ;;
    *)            notify "Неизвестный статус: $status"; exit 1 ;;
  esac
done

notify "Достигнут лимит запусков MAX_RUNS=$MAX_RUNS"
