#!/usr/bin/env bash
# PreToolUse (Edit|Write): refuse edits to test files listed in .lite/locked (written after RED is verified).
# bash 3.2 compatible.
root="${CLAUDE_PROJECT_DIR:-.}"
[[ -s "$root/.lite/locked" ]] || exit 0
file="$(python3 -c 'import json,sys; print(json.load(sys.stdin).get("tool_input",{}).get("file_path",""))' 2>/dev/null)"
[[ -n "$file" ]] || exit 0
case "$file" in /*) ;; *) file="$root/$file" ;; esac
while IFS= read -r locked; do
  [[ -n "$locked" ]] || continue
  if [[ "$file" == "$root/$locked" ]]; then
    echo "$locked is locked (RED verified). If the test is wrong, stop and say why; don't edit it." >&2
    exit 2
  fi
done < "$root/.lite/locked"
exit 0
