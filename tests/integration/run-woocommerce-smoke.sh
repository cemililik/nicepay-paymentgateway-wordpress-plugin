#!/usr/bin/env bash
set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
run_suffix="$$-$(date +%s)"
network_name="nicepay-wc-it-net-${run_suffix}"
database_container="nicepay-wc-it-db-${run_suffix}"
wordpress_container="nicepay-wc-it-wp-${run_suffix}"
temporary_root="$(mktemp -d /tmp/nicepay-wc-it.XXXXXX)"
artifact_path="${temporary_root}/nicepay-payment-gateway.zip"
database_image='mariadb@sha256:8020e05c4c498d06c87f0a1db010eb79bd6f8fb30e9b763d4690c34ce1e61008' # 10.11
wordpress_image='wordpress@sha256:b427cec767f5de2aa649390cb8805aa1fe320e1e0d57fc1f467754edb6cc0a49'
wp_cli_image='wordpress@sha256:837d55d02196b5f4c92d236317c6d089ab1471348b31d1708888d444a0390979' # CLI 2.12.0, PHP 8.2
woocommerce_version='11.0.1'

if [[ ! "$run_suffix" =~ ^[0-9]+-[0-9]+$ ]] ||
   [[ ! "$network_name" =~ ^nicepay-wc-it-net-[0-9]+-[0-9]+$ ]] ||
   [[ ! "$database_container" =~ ^nicepay-wc-it-db-[0-9]+-[0-9]+$ ]] ||
   [[ ! "$wordpress_container" =~ ^nicepay-wc-it-wp-[0-9]+-[0-9]+$ ]] ||
   [[ ! "$temporary_root" =~ ^/tmp/nicepay-wc-it\.[A-Za-z0-9]+$ ]] || [[ ! -d "$temporary_root" ]]; then
	echo 'ERROR: could not establish safe disposable WooCommerce test targets' >&2
	exit 1
fi

cleanup() {
	if [[ "$wordpress_container" =~ ^nicepay-wc-it-wp-[0-9]+-[0-9]+$ ]] && docker container inspect "$wordpress_container" >/dev/null 2>&1; then
		docker container rm --force --volumes "$wordpress_container" >/dev/null
	fi
	if [[ "$database_container" =~ ^nicepay-wc-it-db-[0-9]+-[0-9]+$ ]] && docker container inspect "$database_container" >/dev/null 2>&1; then
		docker container rm --force --volumes "$database_container" >/dev/null
	fi
	if [[ "$network_name" =~ ^nicepay-wc-it-net-[0-9]+-[0-9]+$ ]] && docker network inspect "$network_name" >/dev/null 2>&1; then
		docker network rm "$network_name" >/dev/null
	fi
	if [[ -n "$temporary_root" && "$temporary_root" =~ ^/tmp/nicepay-wc-it\.[A-Za-z0-9]+$ && -d "$temporary_root" ]]; then
		rm -rf -- "$temporary_root"
	fi
}
trap cleanup EXIT

docker network create "$network_name" >/dev/null
docker run --detach --name "$database_container" --network "$network_name" \
	--env MARIADB_DATABASE=nicepay_wc_integration \
	--env MARIADB_USER=nicepay \
	--env MARIADB_PASSWORD=nicepay-test-password \
	--env MARIADB_ROOT_PASSWORD=nicepay-root-password \
	"$database_image" >/dev/null

database_ready='no'
for _attempt in $(seq 1 30); do
	if docker exec "$database_container" mariadb-admin ping \
		--user=nicepay --password=nicepay-test-password --silent >/dev/null 2>&1; then
		database_ready='yes'
		break
	fi
	sleep 2
done
if [[ 'yes' != "$database_ready" ]]; then
	echo 'ERROR: disposable MariaDB did not become ready' >&2
	exit 1
fi

docker run --detach --name "$wordpress_container" --network "$network_name" \
	--env WORDPRESS_DB_HOST="${database_container}:3306" \
	--env WORDPRESS_DB_USER=nicepay \
	--env WORDPRESS_DB_PASSWORD=nicepay-test-password \
	--env WORDPRESS_DB_NAME=nicepay_wc_integration \
	--volume "${repository_root}:/plugin-source:ro" \
	"$wordpress_image" >/dev/null

wordpress_ready='no'
for _attempt in $(seq 1 30); do
	if docker exec "$wordpress_container" test -f /var/www/html/wp-includes/version.php; then
		wordpress_ready='yes'
		break
	fi
	sleep 2
done
if [[ 'yes' != "$wordpress_ready" ]]; then
	echo 'ERROR: disposable WordPress did not become ready' >&2
	exit 1
fi

bash "$repository_root/.github/scripts/build-release.sh" 2.0.0 "$artifact_path"

wp_cli=(docker run --rm --user 0 --volumes-from "$wordpress_container" --network "$network_name"
	--env NICEPAY_WC_INTEGRATION_TEST=1
	--env WORDPRESS_DB_HOST="${database_container}:3306"
	--env WORDPRESS_DB_USER=nicepay
	--env WORDPRESS_DB_PASSWORD=nicepay-test-password
	--env WORDPRESS_DB_NAME=nicepay_wc_integration
	--volume "${artifact_path}:/tmp/nicepay-payment-gateway.zip:ro"
	--volume "${repository_root}:/plugin-source:ro")

"${wp_cli[@]}" "$wp_cli_image" wp core install --url=https://nicepay.test --title=NicePay \
	--admin_user=admin --admin_password=integration-password --admin_email=admin@example.com --skip-email --allow-root
"${wp_cli[@]}" "$wp_cli_image" wp plugin install woocommerce --version="$woocommerce_version" --activate --allow-root
"${wp_cli[@]}" "$wp_cli_image" wp plugin install /tmp/nicepay-payment-gateway.zip --activate --allow-root

"${wp_cli[@]}" "$wp_cli_image" wp option update woocommerce_custom_orders_table_enabled no --allow-root
"${wp_cli[@]}" "$wp_cli_image" wp option update woocommerce_custom_orders_table_data_sync_enabled no --allow-root
"${wp_cli[@]}" --env NICEPAY_HPOS_MODE=legacy "$wp_cli_image" \
	wp eval-file /plugin-source/tests/integration/woocommerce-smoke.php --allow-root

"${wp_cli[@]}" "$wp_cli_image" wp option update woocommerce_custom_orders_table_enabled yes --allow-root
"${wp_cli[@]}" "$wp_cli_image" wp option update woocommerce_custom_orders_table_data_sync_enabled no --allow-root
"${wp_cli[@]}" --env NICEPAY_HPOS_MODE=hpos "$wp_cli_image" \
	wp eval-file /plugin-source/tests/integration/woocommerce-smoke.php --allow-root
