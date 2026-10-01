#!/bin/sh
# Roda os testes que precisam de php-zip (importação do ZIP real) num container DESCARTÁVEL: a mesma
# imagem do site, com o pacote php8.2-zip instalado só nele. Não altera o container do site.
#   TSE_APURACAO_CONTAINER  container do WordPress de onde copiar imagem, rede e pasta (padrão: revistaforum-app)
#   TSE_WP_PATH             raiz do WordPress no container (padrão: /var/www/html)
#   TSE_PLUGIN_REL          pasta do plugin relativa à raiz (padrão: wp-content/plugins/tse-apuracao)
#   TSE_ZIP_PACKAGE         pacote apt (padrão: php8.2-zip)
set -eu
CONTAINER="${TSE_APURACAO_CONTAINER:-revistaforum-app}"
WP_PATH="${TSE_WP_PATH:-/var/www/html}"
REL="${TSE_PLUGIN_REL:-wp-content/plugins/tse-apuracao}"
PKG="${TSE_ZIP_PACKAGE:-php8.2-zip}"
IMAGE=$(docker inspect -f '{{.Config.Image}}' "$CONTAINER")
SRC=$(docker inspect -f '{{range .Mounts}}{{if eq .Destination "'"$WP_PATH"'"}}{{.Source}}{{end}}{{end}}' "$CONTAINER")
NET=$(docker inspect -f '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}' "$CONTAINER" | tr ' ' '\n' | grep -i database | head -1)
[ -n "$SRC" ] && [ -n "$NET" ] || { echo "run-with-zip: não achei a pasta do WordPress ou a rede do banco em $CONTAINER" >&2; exit 1; }
docker run --rm --entrypoint sh --network "$NET" -e DB_HOST=database -v "$SRC":"$WP_PATH" "$IMAGE" -c "
  APT='-o Acquire::http::Timeout=20 -o Acquire::https::Timeout=20 -o Acquire::Retries=3'; apt-get \$APT update -qq >/dev/null 2>&1 && apt-get \$APT install -y -qq $PKG >/dev/null 2>&1
  php -m | grep -qi '^zip\$' || { echo 'run-with-zip: não consegui instalar $PKG' >&2; exit 1; }
  status=0
  for t in tests/wp-import-zip.php tests/wp-integration.php tests/wp-collect.php; do
    echo \"== \$t (com php-zip)\"
    su -s /bin/sh www-data -c \"wp --path=$WP_PATH eval-file $REL/\$t\" || status=1
  done
  exit \$status"
