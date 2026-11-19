#!/usr/bin/env bash
# PreToolUse (Skill): timestamps /lite: steps the model starts itself, which the prompt hook can't see. bash 3.2 compatible.
root="${CLAUDE_PROJECT_DIR:-.}"
mkdir -p "$root/.lite"
name="$(python3 -c '
import json, re, sys
try:
    data = json.load(sys.stdin).get("tool_input", {}) or {}
except Exception:
    sys.exit(0)
for value in (data.values() if isinstance(data, dict) else [data]):
    m = re.search(r"lite:([a-z-]+)", str(value))
    if m:
        print("/lite:" + m.group(1)); break
' 2>/dev/null)"
[[ -n "$name" ]] && echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) command $name" >> "$root/.lite/events"
exit 0
