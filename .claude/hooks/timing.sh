#!/usr/bin/env bash
# UserPromptSubmit: timestamp each /lite: command and count other messages (content never stored). bash 3.2 compatible.
root="${CLAUDE_PROJECT_DIR:-.}"
mkdir -p "$root/.lite"
prompt="$(python3 -c 'import json,sys; print((json.load(sys.stdin).get("prompt") or "").strip().split("\n")[0][:80])' 2>/dev/null)"
now="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
case "$prompt" in
  /lite:*) echo "$now command ${prompt%% *}" >> "$root/.lite/events" ;;
  *) echo "$now message" >> "$root/.lite/events" ;;
esac
exit 0
