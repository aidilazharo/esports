#!/usr/bin/env bash
# Empty generated HTML only; never touch uploads/ or download/.
set -Eeuo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
directory="$root/html"
if [[ -L "$directory" ]]; then
  echo "Refusing to reset html/: directory is a symlink" >&2
  exit 1
fi
if [[ -e "$directory" && ! -d "$directory" ]]; then
  echo "Refusing to reset html/: path exists but is not a directory" >&2
  exit 1
fi
mkdir -p -- "$directory"
find "$directory" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
echo "Generated html/ cache emptied. download/ and uploads/ were not modified."
