#!/usr/bin/env bash
# Install only on the server via scripts/install-server-html-hook.sh.
set -Eeuo pipefail
root="$(git rev-parse --show-toplevel)"
if [[ "$(git -C "$root" branch --show-current)" == "main" ]]; then
  bash "$root/scripts/reset-html-cache.sh"
fi
