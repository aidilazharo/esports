#!/usr/bin/env bash
# Increment VERSION's patch component; only one writer should run at a time.
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."

if [[ ! -f VERSION ]]; then
  echo 'VERSION file missing' >&2
  exit 1
fi

version="$(tr -d '\r\n' < VERSION)"
if [[ ! "$version" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
  echo "Invalid VERSION: $version; expected major.minor.patch" >&2
  exit 1
fi
major="${BASH_REMATCH[1]}"
minor="${BASH_REMATCH[2]}"
patch="${BASH_REMATCH[3]}"
printf '%s.%s.%d\n' "$major" "$minor" "$((10#$patch + 1))" > VERSION
echo "v$version => v$(cat VERSION)"
