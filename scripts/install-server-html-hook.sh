#!/usr/bin/env bash
# Install the repository's post-merge hook only in this server checkout.
set -Eeuo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd -- "$root"
if [[ "$(git branch --show-current)" != "main" ]]; then
  echo "Expected to install server hook on main branch." >&2
  exit 1
fi
custom="$(git config --local --get core.hooksPath || true)"
if [[ -n "$custom" ]]; then
  echo "An existing core.hooksPath is configured ($custom); not replacing it." >&2
  exit 1
fi
hooks_dir="$(git rev-parse --git-path hooks)"
mkdir -p -- "$hooks_dir"
if [[ -e "$hooks_dir/post-merge" || -L "$hooks_dir/post-merge" ]]; then
  echo "An existing post-merge hook was found; not overwriting it." >&2
  exit 1
fi
cp -- "$root/scripts/server-post-merge.sh" "$hooks_dir/post-merge"
chmod 700 "$hooks_dir/post-merge"
echo "Installed server post-merge hook at $hooks_dir/post-merge"
