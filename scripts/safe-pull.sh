#!/usr/bin/env bash
# Preserve locally deployed content when pulling the cleanup commit.
# Run from the repository checkout (including Git Bash on Windows).
set -Eeuo pipefail
repo_root="$(git rev-parse --show-toplevel)"
cd -- "$repo_root"
if [[ "$(git branch --show-current)" != "main" ]]; then
  echo "Switch to the main branch before running this script." >&2
  exit 1
fi
backup="$(mktemp -d "${TMPDIR:-/tmp}/esports-runtime-XXXXXXXX")"
directories=(html download uploads)
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
  if [[ -d "$directory" ]]; then
    mkdir -p -- "$backup/$directory"
    cp -a -- "$directory/." "$backup/$directory/"
  fi
done
echo "Saved runtime directory backup at: $backup"
if ! git pull --ff-only origin main; then
  echo "Pull did not complete. Restoring directory contents; backup remains at $backup." >&2
  restore
  exit 1
fi
if ! restore; then
  echo "Could not restore all files. Backup remains at $backup." >&2
  exit 1
fi
rm -rf -- "$backup"
echo "Pull complete; preserved html/, download/, and uploads/."
