#!/bin/sh
# Roda os testes em WordPress real dentro do container do site (sem PHPUnit).
#   TSE_APURACAO_CONTAINER  container do WordPress (padrão: revistaforum-app)
#   TSE_WP_PATH             raiz do WordPress no container (padrão: /var/www/html)
#   TSE_PLUGIN_REL          pasta do plugin relativa à raiz (padrão: wp-content/plugins/tse-apuracao)
# Os testes só alteram o banco de forma temporária e restauram no final.
set -eu
CONTAINER="${TSE_APURACAO_CONTAINER:-revistaforum-app}"
WP_PATH="${TSE_WP_PATH:-/var/www/html}"
REL="${TSE_PLUGIN_REL:-wp-content/plugins/tse-apuracao}"
status=0
for t in tests/admin-smoke.php tests/wp-integration.php tests/wp-collect.php tests/wp-kick.php tests/wp-latency.php tests/wp-import-zip.php; do
	echo "== $t"
	docker exec -u www-data "$CONTAINER" wp --path="$WP_PATH" eval-file "$REL/$t" || status=1
done
echo "== tests/http-admin.sh"
"$(dirname "$0")/http-admin.sh" || status=1
exit $status
