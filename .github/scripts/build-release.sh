#!/usr/bin/env bash
set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
version="${1#v}"
output_path="${2:-$repository_root/nicepay-payment-gateway-${version}.zip}"
plugin_slug='nicepay-payment-gateway'
distribution_channel="${NICEPAY_DISTRIBUTION_CHANNEL:-github}"

if [[ "$output_path" != /* ]]; then
    output_path="$(pwd)/$output_path"
fi

if [[ -z "$version" ]]; then
    echo 'Usage: build-release.sh <version> [output.zip]' >&2
    exit 1
fi

if [[ -e "$output_path" ]]; then
    echo "ERROR: refusing to overwrite existing artifact: $output_path" >&2
    exit 1
fi

node "$repository_root/.github/scripts/check-version.js" "$version"

temporary_root="$(mktemp -d)"
if [[ -z "$temporary_root" || ! -d "$temporary_root" ]]; then
    echo 'ERROR: could not create a temporary build directory' >&2
    exit 1
fi

cleanup() {
    if [[ -n "${temporary_root:-}" && -d "$temporary_root" ]]; then
        rm -rf -- "$temporary_root"
    fi
}
trap cleanup EXIT

package_root="$temporary_root/$plugin_slug"
mkdir -p "$package_root"

for directory in admin assets includes languages templates; do
    if [[ ! -d "$repository_root/$directory" ]]; then
        echo "ERROR: required release directory is missing: $directory" >&2
        exit 1
    fi
done

# Reject sensitive/editor/build by-products even though the copy below is an
# extension allowlist. This turns an accidental secret or source map into a
# visible build failure instead of silently ignoring it.
for directory in admin assets includes languages templates; do
    while IFS= read -r -d '' forbidden_file; do
        echo "ERROR: forbidden release-adjacent file: ${forbidden_file#"$repository_root/"}" >&2
        exit 1
    done < <(
        find "$repository_root/$directory" -type f \( \
            -name '.env' -o -name '.env.*' -o -name '*.map' -o \
            -name '*.bak' -o -name '*.orig' -o -name '*.swp' -o \
            -name '.DS_Store' -o -name '*.zip' \
        \) -print0
    )
done

copy_release_file() {
    local source_file="$1"
    local relative_path="${source_file#"$repository_root/"}"
    mkdir -p "$package_root/$(dirname "$relative_path")"
    cp "$source_file" "$package_root/$relative_path"
}

# Release directories are copied through a strict extension allowlist. New
# file types must be reviewed and deliberately added here.
while IFS= read -r -d '' release_file; do
    copy_release_file "$release_file"
done < <(
    find \
        "$repository_root/admin" \
        "$repository_root/assets" \
        "$repository_root/includes" \
        "$repository_root/languages" \
        "$repository_root/templates" \
        -type f \( \
            -name '*.php' -o -name '*.js' -o -name '*.css' -o \
            -name '*.json' -o -name '*.po' -o -name '*.pot' -o \
            -name '*.mo' -o -name '*.png' -o -name '*.jpg' -o \
            -name '*.jpeg' -o -name '*.gif' -o -name '*.svg' -o \
            -name '*.webp' \
        \) -print0
)

mkdir -p "$package_root/docs"
for file in API-REFERENCE.md ARCHITECTURE.md CONFIGURATION.md DEVELOPER-GUIDE.md USER-GUIDE.md; do
    if [[ ! -f "$repository_root/docs/$file" ]]; then
        echo "ERROR: required release documentation is missing: docs/$file" >&2
        exit 1
    fi
    cp "$repository_root/docs/$file" "$package_root/docs/"
done

for file in nicepay-payment-gateway.php uninstall.php readme.txt README.md LICENSE CHANGELOG.md CONTRIBUTING.md CODE_OF_CONDUCT.md SECURITY.md; do
    if [[ ! -f "$repository_root/$file" ]]; then
        echo "ERROR: required release file is missing: $file" >&2
        exit 1
    fi
    cp "$repository_root/$file" "$package_root/"
done

# WordPress.org rejects third-party update headers. Keep the GitHub channel's
# header in source, but remove it from the immutable WordPress.org payload.
if [[ 'wordpress-org-candidate' == "$distribution_channel" ]]; then
	awk '! /^[[:space:]]*\*[[:space:]]*Update URI:/' \
		"$package_root/nicepay-payment-gateway.php" \
		> "$package_root/nicepay-payment-gateway.php.channel"
	mv "$package_root/nicepay-payment-gateway.php.channel" "$package_root/nicepay-payment-gateway.php"
fi

mkdir -p "$(dirname "$output_path")"

# Normalize metadata and traversal order so two builds of the same commit are
# byte-for-byte identical. SOURCE_DATE_EPOCH can be supplied by CI/releasers;
# otherwise use the checked-out commit time.
source_date_epoch="${SOURCE_DATE_EPOCH:-$(git -C "$repository_root" log -1 --format=%ct)}"
if [[ ! "$source_date_epoch" =~ ^[0-9]+$ ]] || (( source_date_epoch < 315532800 )); then
    echo 'ERROR: SOURCE_DATE_EPOCH must be a Unix timestamp on or after 1980-01-01' >&2
    exit 1
fi
if normalized_timestamp="$(date -u -d "@$source_date_epoch" '+%Y%m%d%H%M.%S' 2>/dev/null)"; then
    :
else
    normalized_timestamp="$(date -u -r "$source_date_epoch" '+%Y%m%d%H%M.%S')"
fi
find "$package_root" -type d -exec chmod 755 {} +
find "$package_root" -type f -exec chmod 644 {} +
find "$package_root" -exec touch -h -t "$normalized_timestamp" {} +
(
    cd "$temporary_root"
    find "$plugin_slug" -print | LC_ALL=C sort | zip -X -q "$output_path" -@
)

echo "Release artifact created: $output_path"
