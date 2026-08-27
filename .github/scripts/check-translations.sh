#!/usr/bin/env bash
set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
host_uid="$(id -u)"
host_gid="$(id -g)"

if ! [[ "$host_uid" =~ ^[0-9]+$ && "$host_gid" =~ ^[0-9]+$ ]]; then
	echo "Error: Could not determine the numeric host user and group IDs." >&2
	exit 1
fi

temporary_root="$(mktemp -d "${TMPDIR:-/tmp}/nicepay-i18n.XXXXXX")"
wp_cli_image='wordpress@sha256:837d55d02196b5f4c92d236317c6d089ab1471348b31d1708888d444a0390979'
english_catalog="$repository_root/languages/nicepay-payment-gateway-en_US.po"

if rg --line-number --glob '*.php' \
	'(?:__|_e|_x|esc_html__|esc_attr__)\([[:space:]]*\$[A-Za-z_]' \
	"$repository_root/admin" \
	"$repository_root/includes" \
	"$repository_root/templates" \
	"$repository_root/nicepay-payment-gateway.php"; then
	echo 'ERROR: translation functions must receive extractable literal messages, not variables' >&2
	exit 1
fi

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
	--user "${host_uid}:${host_gid}" \
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

if msgattrib --untranslated --no-obsolete "$english_catalog" | grep -q '^msgid '; then
    echo 'ERROR: en_US must translate every active source entry' >&2
    exit 1
fi

for catalog in "$repository_root"/languages/*.po; do
	if ! msgcmp --use-untranslated "$catalog" "$repository_root/languages/nicepay-payment-gateway.pot"; then
		echo "ERROR: $(basename "$catalog") does not contain the active POT msgid set" >&2
		exit 1
	fi
	if msgattrib --only-fuzzy --no-obsolete "$catalog" | grep -q '^msgid '; then
		echo "ERROR: fuzzy translations are forbidden in $(basename "$catalog"); clear an uncertain translation to use the English fallback" >&2
		exit 1
	fi
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

active_count="$(msgattrib --no-obsolete "$repository_root/languages/nicepay-payment-gateway.pot" | grep -c '^msgid ')"
for requirement in 'tr_TR:100' 'ko_KR:100' 'zh_CN:100'; do
	locale="${requirement%%:*}"
	minimum="${requirement##*:}"
	catalog="$repository_root/languages/nicepay-payment-gateway-${locale}.po"
	translated_count="$(msgattrib --translated --no-fuzzy --no-obsolete "$catalog" | grep -c '^msgid ')"
	completion=$(( translated_count * 100 / active_count ))
	if (( completion < minimum )); then
		echo "ERROR: ${locale} completion ${completion}% is below the reviewed ${minimum}% floor" >&2
		exit 1
	fi
done

echo 'Translation source, catalog quality, and MO freshness checks passed.'
