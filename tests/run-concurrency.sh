#!/bin/sh
# Teste de concorrência do worker: 4 processos disputando a mesma fila de 24 coletas (TSE falso, 100 ms de
# rede). Sai com código diferente de zero se algum job rodar duas vezes, se faltar snapshot ou se o lock
# for segurado por dois processos ao mesmo tempo. Mesmas variáveis do tests/run-in-docker.sh.
set -eu
CONTAINER="${TSE_APURACAO_CONTAINER:-revistaforum-app}"
WP_PATH="${TSE_WP_PATH:-/var/www/html}"
REL="${TSE_PLUGIN_REL:-wp-content/plugins/tse-apuracao}"
WORKERS="${TSE_CONC_WORKERS:-4}"
run() { docker exec -u www-data -e TSE_CONC_MODE="$1" "$CONTAINER" wp --path="$WP_PATH" eval-file "$REL/tests/wp-concurrency.php"; }
trap 'run teardown >/dev/null 2>&1 || true' EXIT
run setup
i=0
while [ "$i" -lt "$WORKERS" ]; do run work & i=$((i + 1)); done
wait
run check
