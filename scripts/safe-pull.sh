#!/usr/bin/env bash
# Server-safe fast-forward pull: preserve uploads/ and download/ and
# replace generated html/ with an empty directory after code changes.
set -Eeuo pipefail
root="$(git rev-parse --show-toplevel)"
cd -- "$root"
if [[ "$(git branch --show-current)" != "main" ]]; then
  echo "Switch to main before running this script." >&2
  exit 1
fi
before="$(git rev-parse HEAD)"
backup="$(mktemp -d "${TMPDIR:-/tmp}/esports-runtime-XXXXXXXX")"
directories=(download uploads)
restore() {
  local directory
  for directory in "${directories[@]}"; do
    if [[ -d "$backup/$directory" ]]; then
      mkdir -p -- "$directory" || return 1
      cp -a -- "$backup/$directory/." "$directory/" || return 1
    fi
  done
}
for directory in "${directories[@]}"; do
  if [[ -L "$directory" ]]; then
    echo "Refusing to deploy: $directory/ is a symlink" >&2
    exit 1
  fi
  if [[ -d "$directory" ]]; then
    mkdir -p -- "$backup/$directory"
    cp -a -- "$directory/." "$backup/$directory/"
  fi
done
echo "Runtime backup: $backup"
if ! git pull --ff-only origin main; then
  echo "Git pull failed. Restoring local data from $backup." >&2
  restore
  exit 1
fi
if ! restore; then
  echo "Restoration failed. Manual backup is available at $backup" >&2
  exit 1
fi
after="$(git rev-parse HEAD)"
if [[ "$before" != "$after" ]]; then
  bash "$root/scripts/reset-html-cache.sh"
fi
rm -rf -- "$backup"
echo "Server updated to $after; html/ cleared only if the revision changed."
