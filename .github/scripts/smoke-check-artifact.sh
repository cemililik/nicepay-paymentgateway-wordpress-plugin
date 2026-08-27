#!/usr/bin/env bash
set -euo pipefail

artifact_path="${1:-}"
plugin_slug="${2:-nicepay-payment-gateway}"

if [[ -z "$artifact_path" || ! -f "$artifact_path" || ! "$plugin_slug" =~ ^[a-z0-9]+(-[a-z0-9]+)*$ ]]; then
    echo 'Usage: smoke-check-artifact.sh <artifact.zip> [plugin-slug]' >&2
    exit 1
fi

unzip -t "$artifact_path" >/dev/null
archive_listing="$(unzip -Z1 "$artifact_path")"

for required_path in \
    "$plugin_slug/nicepay-payment-gateway.php" \
	"$plugin_slug/uninstall.php" \
    "$plugin_slug/includes/class-nicepay-api.php" \
    "$plugin_slug/includes/class-nicepay-gateway.php" \
    "$plugin_slug/includes/class-nicepay-inbound-validator.php" \
    "$plugin_slug/includes/class-nicepay-installer.php" \
	"$plugin_slug/includes/class-nicepay-retention.php" \
    "$plugin_slug/includes/class-nicepay-transaction-schema.php" \
    "$plugin_slug/includes/class-nicepay-blocks-integration.php" \
    "$plugin_slug/includes/class-nicepay-privacy.php" \
    "$plugin_slug/assets/js/nicepay.js" \
    "$plugin_slug/assets/js/nicepay-admin.js" \
    "$plugin_slug/assets/js/nicepay-shortcode-admin.js" \
    "$plugin_slug/assets/js/nicepay-blocks.js" \
    "$plugin_slug/templates/payment-form.php" \
	"$plugin_slug/readme.txt" \
    "$plugin_slug/README.md" \
    "$plugin_slug/LICENSE" \
    "$plugin_slug/CHANGELOG.md" \
    "$plugin_slug/CONTRIBUTING.md" \
    "$plugin_slug/CODE_OF_CONDUCT.md" \
    "$plugin_slug/SECURITY.md" \
    "$plugin_slug/docs/USER-GUIDE.md" \
    "$plugin_slug/docs/CONFIGURATION.md" \
    "$plugin_slug/docs/ARCHITECTURE.md" \
    "$plugin_slug/docs/DEVELOPER-GUIDE.md" \
    "$plugin_slug/docs/API-REFERENCE.md"; do
    if ! grep -Fxq "$required_path" <<< "$archive_listing"; then
        echo "ERROR: release artifact is missing $required_path" >&2
        exit 1
    fi
done

for forbidden_pattern in \
    '(^|/)(\.git|\.github|tests|vendor|node_modules|build)(/|$)' \
    '(^|/)docs/analysis(/|$)' \
    '(^|/)(composer\.(json|lock)|package(-lock)?\.json|eslint\.config\.js|phpunit\.xml)(/|$)' \
    '(^|/)\.env([^/]*$|/)' \
    '(^|/)(\.DS_Store|[^/]+\.(map|bak|orig|swp|zip))$'; do
    if grep -Eq "$forbidden_pattern" <<< "$archive_listing"; then
        echo 'ERROR: release artifact contains development-only or sensitive paths' >&2
        grep -E "$forbidden_pattern" <<< "$archive_listing" >&2
        exit 1
    fi
done

if ! awk -F/ -v slug="$plugin_slug" 'NF && $1 != slug { exit 1 }' <<< "$archive_listing"; then
    echo "ERROR: release artifact must contain only the $plugin_slug top-level directory" >&2
    exit 1
fi

temporary_root="$(mktemp -d)"
if [[ -z "$temporary_root" || ! -d "$temporary_root" ]]; then
    echo 'ERROR: could not create a temporary verification directory' >&2
    exit 1
fi

cleanup() {
    if [[ -n "${temporary_root:-}" && -d "$temporary_root" ]]; then
        rm -rf -- "$temporary_root"
    fi
}
trap cleanup EXIT

unzip -q "$artifact_path" -d "$temporary_root"

while IFS= read -r -d '' php_file; do
    php -l "$php_file" >/dev/null
done < <(find "$temporary_root/$plugin_slug" -type f -name '*.php' -print0)

while IFS= read -r -d '' js_file; do
    node --check "$js_file" >/dev/null
done < <(find "$temporary_root/$plugin_slug" -type f -name '*.js' -print0)

echo "Release artifact smoke check passed: $artifact_path"
