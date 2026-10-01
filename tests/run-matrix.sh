#!/bin/sh
# Roda as suítes em PHP e WordPress antigos, em containers descartáveis (MariaDB e WordPress próprios,
# nada do site local é tocado). Use com a pasta da branch certa (git worktree):
#   git worktree add /tmp/plugin-php74 php7.4 && tests/run-matrix.sh php74 /tmp/plugin-php74
#   git worktree add /tmp/plugin-php72 php7.2 && tests/run-matrix.sh php72 /tmp/plugin-php72
#   tests/run-matrix.sh php72-wp49 /tmp/plugin-php72        # WordPress 4.9 (o mais antigo suportado)
# Alvos: php82 (imagem wordpress:php8.2-apache), php74, php72, php72-wp49.
set -eu
TARGET="${1:?uso: tests/run-matrix.sh <php82|php74|php72|php72-wp49> <pasta-do-plugin>}"
PLUGIN="$(cd "${2:?informe a pasta do plugin}" && pwd)"
case "$TARGET" in
	php82) IMAGE=wordpress:php8.2-apache ;;
	php74) IMAGE=wordpress:php7.4-apache ;;
	php72) IMAGE=wordpress:php7.2-apache ;;
	php72-wp49) IMAGE=wordpress:4.9-php7.2-apache ;;
	*) echo "alvo desconhecido: $TARGET" >&2; exit 2 ;;
esac
NAME="tse-matrix-$$"
cleanup() { docker rm -f "$NAME-wp" "$NAME-db" >/dev/null 2>&1 || true; docker network rm "$NAME" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker network create "$NAME" >/dev/null
docker run -d --name "$NAME-db" --network "$NAME" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp mariadb:10.11 >/dev/null
docker run -d --name "$NAME-wp" --network "$NAME" -e WORDPRESS_DB_HOST="$NAME-db" -e WORDPRESS_DB_USER=root -e WORDPRESS_DB_PASSWORD=root -e WORDPRESS_DB_NAME=wp \
	-v "$PLUGIN":/var/www/html/wp-content/plugins/tse-apuracao:ro "$IMAGE" >/dev/null
# O MariaDB sobe duas vezes (servidor temporário de inicialização, depois o definitivo): espera o segundo "ready for connections".
for i in $(seq 1 90); do [ "$(docker logs "$NAME-db" 2>&1 | grep -c "ready for connections")" -ge 2 ] && break; sleep 2; done
for i in $(seq 1 60); do docker exec "$NAME-wp" test -f /var/www/html/wp-config.php >/dev/null 2>&1 && break; sleep 1; done
WP="docker exec $NAME-wp wp --allow-root --path=/var/www/html"
docker exec "$NAME-wp" sh -c 'curl -sSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /usr/local/bin/wp'
$WP core install --url=http://localhost --title=matriz --admin_user=admin --admin_password=admin --admin_email=a@example.com --skip-email >/dev/null
$WP plugin activate tse-apuracao
echo "== $TARGET: $($WP eval 'echo "PHP " . PHP_VERSION . " · WordPress " . get_bloginfo("version");')"
status=0
for t in admin-smoke wp-integration wp-collect wp-latency wp-import-zip; do
	echo "== tests/$t.php"
	$WP eval-file wp-content/plugins/tse-apuracao/tests/$t.php || status=1
done
exit $status
