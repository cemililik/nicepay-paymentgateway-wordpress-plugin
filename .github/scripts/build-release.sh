#!/usr/bin/env bash
set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
version="${1#v}"
output_path="${2:-$repository_root/nicepay-payment-gateway-${version}.zip}"
plugin_slug='nicepay-payment-gateway'

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
    cp -R "$repository_root/$directory" "$package_root/"
done

mkdir -p "$package_root/docs"
for file in API-REFERENCE.md ARCHITECTURE.md CONFIGURATION.md DEVELOPER-GUIDE.md USER-GUIDE.md; do
    if [[ ! -f "$repository_root/docs/$file" ]]; then
        echo "ERROR: required release documentation is missing: docs/$file" >&2
        exit 1
    fi
    cp "$repository_root/docs/$file" "$package_root/docs/"
done

for file in nicepay-payment-gateway.php readme.txt README.md LICENSE CHANGELOG.md CONTRIBUTING.md CODE_OF_CONDUCT.md SECURITY.md; do
    if [[ ! -f "$repository_root/$file" ]]; then
        echo "ERROR: required release file is missing: $file" >&2
        exit 1
    fi
    cp "$repository_root/$file" "$package_root/"
done

mkdir -p "$(dirname "$output_path")"
(
    cd "$temporary_root"
    zip -q -r "$output_path" "$plugin_slug"
)

echo "Release artifact created: $output_path"
