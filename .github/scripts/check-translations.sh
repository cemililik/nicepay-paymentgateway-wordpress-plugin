#!/usr/bin/env bash
set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
temporary_root="$(mktemp -d "${TMPDIR:-/tmp}/nicepay-i18n.XXXXXX")"
wp_cli_image='wordpress@sha256:837d55d02196b5f4c92d236317c6d089ab1471348b31d1708888d444a0390979'
english_catalog="$repository_root/languages/nicepay-payment-gateway-en_US.po"
turkish_catalog="$repository_root/languages/nicepay-payment-gateway-tr_TR.po"

if [[ -z "$temporary_root" || ! -d "$temporary_root" || "$(basename "$temporary_root")" != nicepay-i18n.* ]]; then
    echo 'ERROR: could not create a validated translation-check directory' >&2
    exit 1
fi

cleanup() {
    if [[ -n "${temporary_root:-}" && -d "$temporary_root" && "$(basename "$temporary_root")" == nicepay-i18n.* ]]; then
        rm -rf -- "$temporary_root"
    fi
}
trap cleanup EXIT

docker run --rm \
    --volume "$repository_root:/work:ro" \
    --volume "$temporary_root:/output" \
    --workdir /work \
    "$wp_cli_image" \
    wp i18n make-pot . /output/fresh.pot \
        --exclude=docs,tests,vendor,node_modules \
        --skip-js \
        --headers='{"Report-Msgid-Bugs-To":"https://github.com/cemililik/nicepay-paymentgateway-wordpress-plugin/issues"}' >/dev/null

# The creation timestamp is intentionally non-deterministic. Every other line,
# including source references and plural declarations, must match the committed POT.
sed '/^"POT-Creation-Date:/d' "$repository_root/languages/nicepay-payment-gateway.pot" > "$temporary_root/committed.normalized.pot"
sed '/^"POT-Creation-Date:/d' "$temporary_root/fresh.pot" > "$temporary_root/fresh.normalized.pot"

if ! diff -u "$temporary_root/committed.normalized.pot" "$temporary_root/fresh.normalized.pot"; then
    echo 'ERROR: languages/nicepay-payment-gateway.pot is stale; regenerate the translation catalogs.' >&2
    exit 1
fi

node "$repository_root/.github/scripts/check-po-placeholders.js" "$repository_root"/languages/*.po

if msgattrib --only-fuzzy --no-obsolete "$english_catalog" | grep -q '^msgid '; then
    echo 'ERROR: en_US must not contain fuzzy entries' >&2
    exit 1
fi
if msgattrib --untranslated --no-obsolete "$english_catalog" | grep -q '^msgid '; then
    echo 'ERROR: en_US must translate every active source entry' >&2
    exit 1
fi
if msgattrib --only-fuzzy --no-obsolete "$turkish_catalog" | grep -q '^msgid '; then
    echo 'ERROR: reviewed tr_TR entries must not be left fuzzy; use an empty translation for an intentional English fallback' >&2
    exit 1
fi

for catalog in "$repository_root"/languages/*.po; do
    if msgattrib --only-obsolete "$catalog" | grep -q '^msgid '; then
        echo "ERROR: obsolete entries remain in $catalog" >&2
        exit 1
    fi

    compiled="$temporary_root/$(basename "${catalog%.po}.mo")"
    msgfmt --check --check-format --output-file="$compiled" "$catalog"
    committed="${catalog%.po}.mo"
    if ! cmp --silent "$compiled" "$committed"; then
        echo "ERROR: $(basename "$committed") is stale; compile it from the matching PO catalog" >&2
        exit 1
    fi
done

echo 'Translation source, catalog quality, and MO freshness checks passed.'
