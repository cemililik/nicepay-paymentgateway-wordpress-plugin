#!/usr/bin/env bash
set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
checked=0

while IFS= read -r -d '' php_file; do
    php -l "$php_file" >/dev/null
    checked=$((checked + 1))
done < <(
    find "$repository_root" \
        -type f \
        -name '*.php' \
        -not -path "$repository_root/vendor/*" \
        -not -path "$repository_root/tests/coverage/*" \
        -print0
)

if [[ "$checked" -eq 0 ]]; then
    echo 'ERROR: no PHP files were found' >&2
    exit 1
fi

echo "PHP syntax check passed: ${checked} file(s)"
